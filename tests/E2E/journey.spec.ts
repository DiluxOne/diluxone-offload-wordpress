import { test, expect, Page } from './helpers/test';
import { emptyTracking, muPlugin, resetPlugin, wp } from './helpers/wp';
import {
	FAKE,
	bucketObjects,
	clearLibrary,
	failWith,
	fillFakeS3Form,
	forgetStats,
	installFakeS3,
	localUploads,
	md5Local,
	pluginState,
	placeLocalFile,
	pngFixture,
	putObject,
	seedLibrary,
	trackingCounts,
	uninstallFakeS3,
} from './helpers/fake-s3';

/**
 * The plugin's whole life, clicked through as a user clicks it, against a
 * storage service on the dev site (tests/E2E/fixtures/fake-s3): connect,
 * check what the bucket holds, sync (cancelled, reset, with a failing file,
 * retried), enable offloading, upload through the Media Library, delete the
 * local copies, lose and regain the connection, disconnect (forced and
 * downloaded), complete the sync again, resync and remove the provider.
 * Every step checks what the screen says and what changed underneath: the
 * plugin's state, the tracking table, the bucket and the files on disk.
 *
 * The steps depend on each other (serial): a failure stops the journey
 * where the plugin stopped behaving.
 */
const ADMIN = '/wp-admin/admin.php';
const CONNECTION = `${ ADMIN }?page=diluxone-offload-provider&tab=connection`;
const CREDENTIALS = `${ ADMIN }?page=diluxone-offload-provider&tab=credentials`;
const OVERVIEW = `${ ADMIN }?page=diluxone-offload`;
const SYNC = `${ ADMIN }?page=diluxone-offload-sync&tab=sync`;
const OFFLOADING = `${ ADMIN }?page=diluxone-offload-sync&tab=offloading`;
const DISCONNECT = `${ ADMIN }?page=diluxone-offload-sync&tab=disconnect`;
const TRANSFERS = `${ ADMIN }?page=diluxone-offload-settings&tab=transfers`;
const HEALTH = `${ ADMIN }?page=diluxone-offload-status&tab=health`;
const STATUS_SYSTEM = `${ ADMIN }?page=diluxone-offload-status&tab=system`;

/** Two dozen small images: a sync takes more than one round, so there is a mid-way to act in. */
const LIBRARY = [
	...Array.from( { length: 23 }, ( _, i ) => ( { name: `journey-${ String( i + 1 ).padStart( 2, '0' ) }.png`, bytes: 6000 + i * 300 } ) ),
	{ name: 'journey-broken.png', bytes: 7000 },
];

const wrap = ( page: Page ) => page.locator( '.wrap.diluxone-offload-admin' );
const card = ( page: Page, title: string ) => page.locator( '.status-card', { has: page.locator( 'h3', { hasText: title } ) } );
const bignum = ( page: Page, label: string ) =>
	page.locator( '.diluxone-offload-bignum', { has: page.locator( '.diluxone-offload-bignum__k', { hasText: new RegExp( `^${ label }$` ) } ) } );
const num = ( text: string ) => Number( text.replace( /\D/g, '' ) );

/** Click what reloads the page and wait for the reload (the URL does not change). */
async function clickAndAwaitReload( page: Page, selector: string, timeout = 60_000 ): Promise< void > {
	const reloaded = page.waitForEvent( 'load', { timeout } );
	await page.locator( selector ).first().click();
	await reloaded;
}

/** Every batch of `action` after the first waits five seconds: long enough to act mid-way. */
async function slowAfterFirst( page: Page, action: string ): Promise< void > {
	let n = 0;
	await page.route( '**/wp-admin/admin-ajax.php', async ( route ) => {
		if ( ( route.request().postData() ?? '' ).includes( `action=${ action }` ) && n++ > 0 ) {
			await new Promise( ( r ) => setTimeout( r, 5000 ) );
		}
		await route.continue();
	} );
}

/** Wait for the first-sync check of the bucket; continue with what is there. */
async function passTargetCheck( page: Page ): Promise< void > {
	if ( ! ( await page.locator( '#diluxone-offload-target' ).count() ) ) return;
	const start = page.locator( '#start-sync-btn' );
	const found = page.locator( '#diluxone-offload-target-continue' );
	await expect( start.and( page.locator( ':enabled' ) ).or( found.and( page.locator( ':visible' ) ) ).first() ).toBeVisible( { timeout: 60_000 } );
	if ( await found.isVisible() ) {
		await found.click();
	}
	await expect( start ).toBeEnabled();
}

/** Wait for the sync's summary; returns which way it ended. */
async function syncSummary( page: Page ): Promise< 'success' | 'errors' | 'failed' > {
	const summary = page.locator( '#sync-modal-summary' );
	await expect( summary.locator( '#enable-offloading-btn, #accept-errors-btn, #sync-complete-close-btn' ).first() ).toBeVisible( { timeout: 120_000 } );
	if ( await summary.locator( '#enable-offloading-btn' ).count() ) return 'success';
	if ( await summary.locator( '#accept-errors-btn' ).count() ) return 'errors';
	return 'failed';
}

/** The local media files the sync takes (what the bucket must mirror): not the empty one, not the excluded folder. */
function syncable(): string[] {
	return localUploads().filter( ( f ) => ! f.startsWith( 'cache/' ) && ! f.endsWith( 'empty.txt' ) );
}

test.describe.serial( 'A whole journey against a storage service on the dev site', () => {
	test.setTimeout( 180_000 );

	test.beforeAll( async () => {
		resetPlugin();
		emptyTracking();
		forgetStats();
		await installFakeS3();
		seedLibrary( LIBRARY );
		placeLocalFile( '2026/01/empty.txt', 0 );
		placeLocalFile( 'cache/left-out.bin', 2048 );
		// Short rounds: a sync request fills the parallel slots once, so there is a mid-way to act in.
		muPlugin( 'short-batches', "add_filter( 'diluxone_offload_sync_batch_seconds', function () { return 0.0; } );" );
	} );

	test.afterAll( async () => {
		muPlugin( 'short-batches', null );
		resetPlugin();
		forgetStats();
		clearLibrary();
		await uninstallFakeS3();
	} );

	test( 'the server refuses to save a provider that has not passed Test Connection', async ( { page } ) => {
		// A passing test is remembered for five minutes: forget one an earlier run left.
		wp( [ 'eval', 'delete_transient( "diluxone_offload_connection_test_passed_" . get_user_by( "login", "admin" )->ID );' ] );
		await page.goto( CONNECTION );
		await fillFakeS3Form( page );
		await expect( page.locator( '#submit' ) ).toBeDisabled();
		// The button is only disabled in the browser: the server is the one that must say no.
		// Enabled and pressed in one go, so no change event can disable it again in between.
		const posted = page.waitForResponse( ( r ) => r.url().includes( 'admin-post.php' ) );
		await page.locator( '#submit' ).evaluate( ( b ) => {
			const button = b as HTMLButtonElement;
			button.disabled = false;
			button.form!.requestSubmit( button );
		} );
		await posted;
		await page.waitForLoadState();
		await expect( page ).toHaveURL( /page=diluxone-offload-provider&tab=connection/ );
		await expect( page.locator( '.notice-error' ).first() ).toContainText( 'Test the connection before saving' );
		await expect( page.locator( '#cloud_provider' ) ).toBeVisible();
		expect( pluginState() ).toBe( 'not_configured' );
	} );

	test( 'Test Connection fails while the service refuses the key, then passes, and Save follows the last test', async ( { page } ) => {
		await page.goto( CONNECTION );
		await fillFakeS3Form( page );
		const result = page.locator( '#s3-config .connection-result' );
		const test = page.locator( '#s3-config .test-connection-btn' );

		await failWith( [ { status: 403, code: 'InvalidAccessKeyId', message: 'The access key does not exist.' } ] );
		await test.click();
		await expect( result ).toContainText( 'Connection Failed', { timeout: 60_000 } );
		await expect( result ).toContainText( '403' );
		await expect( result ).not.toContainText( FAKE.secret );
		await expect( page.locator( '#submit' ) ).toBeDisabled();

		await failWith( [] );
		await test.click();
		await expect( result ).toContainText( 'Connection Successful', { timeout: 60_000 } );
		await expect( page.locator( '#submit' ) ).toBeEnabled();
		// A field changed after the test: that configuration has not been tested.
		await page.locator( '#s3_region' ).fill( 'eu-west-1' );
		await expect( page.locator( '#submit' ) ).toBeDisabled();
		await page.locator( '#s3_region' ).fill( FAKE.region );
		await test.click();
		await expect( result ).toContainText( 'Connection Successful', { timeout: 60_000 } );
		// The probe the test wrote was deleted again.
		expect( Object.keys( await bucketObjects() ) ).toEqual( [] );
	} );

	test( 'saving the tested provider shows the connection read-only, with a notice that can be dismissed', async ( { page } ) => {
		await page.goto( CONNECTION );
		await fillFakeS3Form( page );
		await page.locator( '#s3-config .test-connection-btn' ).click();
		await expect( page.locator( '#s3-config .connection-result' ) ).toContainText( 'Connection Successful', { timeout: 60_000 } );
		await page.locator( '#submit' ).click();

		await expect( page ).toHaveURL( /page=diluxone-offload-provider&tab=connection/ );
		const notice = page.locator( '.notice-success.is-dismissible' ).first();
		await expect( notice ).toContainText( 'Provider configuration saved.' );
		await notice.locator( '.notice-dismiss' ).click();
		await expect( notice ).toHaveCount( 0 );

		const info = page.locator( '#provider-info' );
		await expect( info.locator( '.diluxone-offload-pill' ) ).toHaveText( 'Connected' );
		await expect( info ).toContainText( FAKE.bucket );
		await expect( info ).toContainText( FAKE.endpoint );
		await expect( info ).toContainText( 'Connected since' );
		await expect( info ).not.toContainText( FAKE.secret );
		await expect( info.getByRole( 'link', { name: 'Sync Files to Cloud' } ) ).toHaveAttribute( 'href', /tab=sync&auto-start=1|auto-start=1/ );
		await expect( page.locator( '.diluxone-offload-rail-state__line' ) ).toHaveText( /Configured for the .* bucket e2e-bucket\. Nothing synced yet\./ );
		await expect( page.locator( '.diluxone-offload-rail-state .diluxone-offload-pill' ) ).toContainText( 'Pending' );

		expect( pluginState() ).toBe( 'configured' );
		// The secret is stored encrypted, never as typed.
		expect( wp( [ 'option', 'get', 'diluxone_offload_config', '--format=json' ] ) ).not.toContain( FAKE.secret );
		expect( wp( [ 'eval', 'echo \\DiluxOneOffload\\ConfigManager::get_current_provider_config()["secret_access_key"];' ] ) ).toBe( FAKE.secret );
	} );

	test( 'the Overview and Status › System report the configured provider before any sync', async ( { page } ) => {
		await page.goto( OVERVIEW );
		await expect( card( page, 'Configuration' ).locator( '.status-label' ) ).toHaveText( 'Configured' );
		await expect( card( page, 'Configuration' ) ).toContainText( 'Provider:' );
		await expect( card( page, 'Synchronization' ).locator( '.status-label' ) ).toHaveText( 'Not Synced' );
		await expect( card( page, 'Synchronization' ) ).toContainText( 'Ready to sync your files' );
		await expect( card( page, 'Synchronization' ).getByRole( 'link', { name: 'Start Sync' } ) ).toHaveAttribute( 'href', /page=diluxone-offload-sync&tab=sync/ );
		await expect( card( page, 'Offloading' ).locator( '.status-label' ) ).toHaveText( 'Inactive' );
		await expect( card( page, 'Offloading' ) ).toContainText( 'Sync files first to enable' );
		await expect( page.locator( '.getting-started-section' ) ).toHaveCount( 0 );

		// Cold cache: the panel loads itself, and Refresh lists the bucket again.
		await expect( page.locator( '#stat-file-count' ) ).toHaveText( '0', { timeout: 60_000 } );
		await expect( page.locator( '#stat-last-updated' ) ).toHaveText( /Last updated:\s+just now/ );
		await expect( page.locator( '#stats-loading' ) ).toBeHidden();
		await page.locator( '#refresh-stats-btn' ).click();
		await expect( page.locator( '#refresh-stats-btn' ) ).toBeEnabled( { timeout: 60_000 } );
		await expect( page.locator( '.diluxone-offload-stats-wrap' ) ).toHaveAttribute( 'aria-busy', 'false' );
		await expect( page.locator( '#stat-file-count' ) ).toHaveText( '0' );
		const links = page.locator( '.quick-links a' );
		await expect( links ).toHaveCount( 2 );
		await expect( links.first() ).toHaveAttribute( 'href', 'https://diluxone.com/support' );
		await expect( links.first() ).toHaveAttribute( 'target', '_blank' );

		await page.goto( STATUS_SYSTEM );
		await expect( wrap( page ) ).toContainText( 'System Information' );
		await expect( wrap( page ) ).toContainText( FAKE.bucket );
	} );

	test( 'the key is rotated on the Credentials tab only after a passing test', async ( { page } ) => {
		await page.goto( CREDENTIALS );
		const rows = page.locator( '#update-credentials' );
		await expect( rows.locator( 'code[data-field="s3_bucket"]' ) ).toHaveText( FAKE.bucket );
		await expect( rows.locator( 'code[data-field="s3_endpoint"]' ) ).toHaveText( FAKE.endpoint );
		await expect( page.locator( '#new_access_key_id' ) ).toHaveValue( FAKE.keyId );
		const save = page.locator( '#save-new-credentials' );
		const result = page.locator( '#new-credentials-result' );
		await expect( save ).toBeDisabled();

		await page.locator( '#test-new-credentials' ).click();
		await expect( result ).toContainText( 'Please enter the new access key.' );

		const secret = page.locator( '#new_secret_access_key' );
		await secret.fill( 'rotated-secret' );
		await page.locator( '#show_new_account_key' ).check();
		await expect( secret ).toHaveAttribute( 'type', 'text' );
		await page.locator( '#show_new_account_key' ).uncheck();
		await expect( secret ).toHaveAttribute( 'type', 'password' );

		await failWith( [ { status: 403, code: 'SignatureDoesNotMatch', message: 'The request signature we calculated does not match.' } ] );
		await page.locator( '#test-new-credentials' ).click();
		await expect( result ).toContainText( 'Connection Failed', { timeout: 60_000 } );
		await expect( save ).toBeDisabled();

		await failWith( [] );
		await page.locator( '#test-new-credentials' ).click();
		await expect( result ).toContainText( 'Connection Successful', { timeout: 60_000 } );
		await expect( save ).toBeEnabled();
		await secret.fill( 'rotated-secret-2' );
		await expect( save, 'a key changed after its test' ).toBeDisabled();
		await page.locator( '#test-new-credentials' ).click();
		await expect( result ).toContainText( 'Connection Successful', { timeout: 60_000 } );
		await save.click();
		await page.waitForURL( /tab=credentials/ );
		await expect( page.locator( '.notice-success' ).first() ).toContainText( 'Credentials updated.' );
		expect( wp( [ 'eval', 'echo \\DiluxOneOffload\\ConfigManager::get_current_provider_config()["secret_access_key"];' ] ) ).toBe( 'rotated-secret-2' );
		expect( pluginState() ).toBe( 'configured' );
	} );

	test( 'the Delete Cloud Provider dialog closes on Cancel and on its backdrop without deleting', async ( { page } ) => {
		await page.goto( CREDENTIALS );
		const modal = page.locator( '#remove-provider-modal' );
		await page.locator( '#remove-provider' ).click();
		await expect( modal ).toBeVisible();
		await expect( modal ).toContainText( 'This action cannot be undone.' );
		await modal.locator( '.cancel-remove' ).click();
		await expect( modal ).toBeHidden();
		await page.locator( '#remove-provider' ).click();
		await modal.locator( '.diluxone-offload-modal-overlay' ).click( { position: { x: 5, y: 5 } } );
		await expect( modal ).toBeHidden();
		expect( pluginState() ).toBe( 'configured' );
	} );

	test( 'an excluded folder is saved on Transfers for the scan to leave out', async ( { page } ) => {
		await page.goto( TRANSFERS );
		await page.locator( '#excluded_folders' ).fill( 'cache/' );
		await page.getByRole( 'button', { name: 'Save Settings' } ).click();
		await expect( page.locator( '.notice-success' ).first() ).toContainText( 'Settings saved.' );
		await expect( page.locator( '#excluded_folders' ) ).toHaveValue( 'cache/' );
	} );

	test( 'before the first sync, what someone else left in the bucket is announced, kept or emptied', async ( { page } ) => {
		// Nothing there: no callout, Start Sync free.
		await page.goto( SYNC );
		await expect( wrap( page ) ).toContainText( 'Cloud Provider Configured' );
		await expect( page.locator( '#start-sync-btn' ) ).toBeEnabled( { timeout: 60_000 } );
		await expect( page.locator( '#diluxone-offload-target' ) ).toBeHidden();

		await putObject( 'uploads/2020/01/foreign.jpg', 'someone else' );
		await putObject( 'elsewhere/keep.txt', 'not this site' );
		await page.reload();
		const callout = page.locator( '#diluxone-offload-target' );
		await expect( callout ).toBeVisible( { timeout: 60_000 } );
		await expect( page.locator( '#diluxone-offload-target-found' ) ).toContainText( /^1 file \(.+\) already sits under uploads\/ in .*e2e-bucket/ );
		await expect( page.locator( '#start-sync-btn' ) ).toBeDisabled();
		await expect( page.locator( '#diluxone-offload-target-change' ) ).toHaveText( /Change bucket/ );
		await expect( page.locator( '#diluxone-offload-target-change' ) ).toHaveAttribute( 'href', /tab=credentials#delete-provider$/ );
		await page.locator( '#diluxone-offload-target-continue' ).click();
		await expect( callout ).toBeHidden();
		await expect( page.locator( '#start-sync-btn' ) ).toBeEnabled();

		// Empty it: refused with the wrong name, done with the right one, only under this site's folder.
		await page.reload();
		await expect( callout ).toBeVisible( { timeout: 60_000 } );
		await page.locator( '#diluxone-offload-target-empty-open' ).click();
		const confirm = page.locator( '#diluxone-offload-target-confirm' );
		await expect( confirm ).toBeFocused();
		await expect( page.locator( '#diluxone-offload-target-empty' ) ).toContainText( `Type ${ FAKE.bucket } to delete` );
		const go = page.locator( '#diluxone-offload-target-empty-go' );
		await expect( go ).toBeDisabled();
		await confirm.fill( 'wrong-bucket' );
		await go.click();
		const status = page.locator( '#diluxone-offload-target-status' );
		await expect( status ).toContainText( 'not the name of the container or bucket' );
		await expect( go ).toBeEnabled();
		await expect( page.locator( '#diluxone-offload-target-continue' ) ).toBeEnabled();
		expect( Object.keys( await bucketObjects() ) ).toContain( 'uploads/2020/01/foreign.jpg' );

		await confirm.fill( FAKE.bucket );
		await go.click();
		await expect( status ).toHaveText( /^Done: 1 deleted\./, { timeout: 60_000 } );
		await expect( page.locator( '.diluxone-offload-target-actions' ) ).toBeHidden();
		await expect( page.locator( '#start-sync-btn' ) ).toBeEnabled();
		expect( Object.keys( await bucketObjects() ) ).toEqual( [ 'elsewhere/keep.txt' ] );
	} );

	test( 'Start Sync shows what it will upload; Cancel closes the choice without starting', async ( { page } ) => {
		await page.goto( SYNC );
		await passTargetCheck( page );
		await page.locator( '#start-sync-btn' ).click();
		const choice = page.locator( '#sync-container' );
		await expect( choice ).toContainText( 'Upload Summary', { timeout: 60_000 } );
		await expect( choice ).toContainText( `Total files:${ LIBRARY.length }` );
		await expect( choice ).toContainText( 'Already uploaded:0' );
		await expect( page.locator( '#upload-concurrency-select option' ) ).toHaveCount( 3 );
		await expect( page.locator( '#continue-upload-btn' ), 'nothing uploaded yet: only From Scratch' ).toHaveCount( 0 );
		await page.locator( '#close-sync-options-btn' ).click();
		await expect( page.locator( '#sync-modal' ) ).toBeHidden();
		expect( pluginState() ).toBe( 'configured' );
		expect( trackingCounts().synced ).toBe( 0 );
	} );

	test( 'a sync cancelled mid-way offers to continue; Reset asks first and then forgets the progress', async ( { page } ) => {
		await page.goto( SYNC );
		await slowAfterFirst( page, 'diluxone_offload_process_batch' );
		await passTargetCheck( page );
		await page.locator( '#start-sync-btn' ).click();
		await page.locator( '#upload-concurrency-select' ).selectOption( '5' );
		await page.locator( '#scratch-upload-btn' ).click();
		await expect( page.locator( '#sync-modal-progress' ) ).toBeVisible();
		await expect( page.locator( '#sync-modal-progress' ) ).toContainText( 'DO NOT CLOSE THIS WINDOW' );
		await expect.poll( async () => num( await page.locator( '#sync-modal-stats-processed' ).innerText() ), { timeout: 60_000 } ).toBeGreaterThan( 0 );
		await expect( page.locator( '#sync-modal-progress-text' ) ).toHaveText( new RegExp( ` / ${ LIBRARY.length } files` ) );
		await clickAndAwaitReload( page, '#sync-modal-cancel' );
		await page.unrouteAll( { behavior: 'ignoreErrors' } );

		const done = trackingCounts();
		expect( done.synced ).toBeGreaterThan( 0 );
		expect( done.pending ).toBeGreaterThan( 0 );
		expect( pluginState() ).toBe( 'configured' );
		await expect( page.locator( '#start-sync-btn' ) ).toContainText( 'Continue Sync' );
		await expect( wrap( page ) ).toContainText( 'Sync Not Completed' );
		await expect( wrap( page ) ).toContainText( `You have ${ done.synced } files already synced and ${ done.pending } files pending` );
		await expect( bignum( page, 'Synced' ).locator( '.diluxone-offload-bignum__v' ) ).toHaveText( String( done.synced ) );
		await expect( page.locator( '.diluxone-offload-bar-head' ) ).toContainText( `${ done.synced } of ${ done.total }` );
		await expect( page.locator( '.diluxone-offload-legend' ) ).toContainText( `Still to upload · ${ done.pending }` );
		await expect( page.locator( '.diluxone-offload-rail-state__line' ) ).toContainText( `A sync was interrupted: ${ done.synced } files done, ${ done.pending } pending.` );

		// Reset: "No, Keep Progress" leaves everything as it was.
		const modal = page.locator( '#cancel-sync-modal' );
		await page.locator( '#cancel-all-sync-btn' ).click();
		await expect( modal ).toBeVisible( { timeout: 30_000 } );
		await expect( modal ).toContainText( 'This action will completely reset the synchronization' );
		await modal.locator( '.close-cancel-sync-modal' ).click();
		await expect( modal ).toBeHidden();
		expect( trackingCounts().synced ).toBe( done.synced );

		await page.locator( '#cancel-all-sync-btn' ).click();
		await expect( modal ).toBeVisible( { timeout: 30_000 } );
		const reloaded = page.waitForEvent( 'load', { timeout: 60_000 } );
		await page.locator( '#confirm-cancel-sync' ).click();
		await expect( page.locator( '#sync-container' ) ).toContainText( /reset/i );
		await reloaded;
		await expect( page.locator( '#start-sync-btn' ) ).toContainText( 'Start Sync' );
		expect( trackingCounts().total ).toBe( 0 );
		expect( pluginState() ).toBe( 'configured' );
	} );

	test( 'a file the service refuses ends the sync with errors, listed with its reason, and the scan says what it left out', async ( { page } ) => {
		await failWith( [ { method: 'PUT', match: 'journey-broken', status: 400, code: 'BadDigest', message: 'The Content-MD5 you specified did not match what we received.' } ] );
		await page.goto( SYNC );
		// What the cancelled sync uploaded is in the bucket now: the check announces it.
		await expect( page.locator( '#diluxone-offload-target-found' ) ).toContainText( /files? \(/, { timeout: 60_000 } );
		await passTargetCheck( page );
		await page.locator( '#start-sync-btn' ).click();
		await page.locator( '#scratch-upload-btn' ).click();
		expect( await syncSummary( page ) ).toBe( 'errors' );
		const summary = page.locator( '#sync-modal-summary' );
		await expect( summary ).toContainText( 'Sync Completed with Errors' );
		await expect( summary ).toContainText( 'Failed:1' );
		await clickAndAwaitReload( page, '#accept-errors-btn' );

		expect( pluginState() ).toBe( 'synced' );
		expect( trackingCounts() ).toMatchObject( { failed: 1, synced: LIBRARY.length - 1 } );
		await expect( wrap( page ) ).toContainText( 'Synced with Errors' );
		await expect( wrap( page ) ).toContainText( 'Synchronization completed but 1 files could not be uploaded.' );
		await expect( page.locator( '.diluxone-offload-legend' ) ).toContainText( 'Failed · 1' );
		await expect( page.locator( '.diluxone-offload-meter__part--failed' ).first() ).toBeVisible();

		// What the scan left out: the empty file and the excluded folder, with their paths.
		const skipped = page.locator( 'details.diluxone-offload-skipped' );
		await expect( wrap( page ) ).toContainText( /2 files were left out by the last scan/ );
		await skipped.locator( 'summary' ).click();
		await expect( skipped ).toContainText( 'empty.txt' );
		await expect( skipped ).toContainText( 'cache/left-out.bin' );

		// View Failed Files: the file, its attempts and the service's reason.
		const failed = page.locator( '#failed-files-modal' );
		await page.locator( '.view-failed-btn' ).click();
		await expect( failed ).toBeVisible();
		await expect( failed.locator( 'h2' ) ).toHaveText( 'Failed Files (1)' );
		await expect( failed.locator( 'tbody tr' ) ).toHaveCount( 1 );
		await expect( failed.locator( 'tbody' ) ).toContainText( 'journey-broken.png' );
		await expect( failed.locator( 'tbody' ) ).toContainText( /400|BadDigest|Content-MD5/ );
		await page.locator( '#close-failed-modal' ).click();
		await expect( failed ).toBeHidden();

		// Clear Failed & Enable asks first; Cancel changes nothing.
		const clear = page.locator( '#clear-and-enable-modal' );
		await page.locator( '#discard-and-enable-static-btn' ).click();
		await expect( clear ).toBeVisible();
		await expect( clear ).toContainText( 'The 1 failed files will be removed from the sync queue' );
		await clear.locator( '#clear-enable-confirm-view .close-clear-enable-modal' ).click();
		await expect( clear ).toBeHidden();
		expect( trackingCounts().failed ).toBe( 1 );

		// The Offloading tab sends the owner back to Sync.
		await page.goto( OFFLOADING );
		await expect( wrap( page ) ).toContainText( 'Synchronization completed but 1 files could not be uploaded.' );
		await page.getByRole( 'link', { name: 'Go to Sync' } ).click();
		await expect( page ).toHaveURL( /tab=sync/ );
	} );

	test( 'Retry Failed Files shows what it takes, fails again while the service refuses, and succeeds once it accepts', async ( { page } ) => {
		await page.goto( SYNC );
		// The retry's summary: Cancel closes it.
		await page.locator( '.retry-failed-btn' ).first().click();
		await expect( page.locator( '#sync-modal-summary' ) ).toContainText( 'Failed files to retry:1', { timeout: 60_000 } );
		await expect( page.locator( '#retry-concurrency-select option' ) ).toHaveCount( 3 );
		await page.locator( '#sync-modal-summary #sync-modal-cancel' ).click();
		await expect( page.locator( '#sync-modal' ) ).toBeHidden();

		// Still refused: errors again.
		await page.locator( '.retry-failed-btn' ).first().click();
		await page.locator( '#retry-upload-btn' ).click();
		// The retry goes through the same choice as a sync: continue with the one file left.
		const resume = page.locator( '#continue-upload-btn' );
		await expect( resume ).toContainText( 'Continue Upload', { timeout: 60_000 } );
		await expect( page.locator( '#sync-container' ) ).toContainText( 'Pending:1' );
		await resume.click();
		expect( await syncSummary( page ) ).toBe( 'errors' );
		await clickAndAwaitReload( page, '#accept-errors-btn' );
		expect( trackingCounts().failed ).toBe( 1 );

		// Accepted now: the retry ends well; Later leaves offloading off.
		await failWith( [] );
		await page.locator( '.retry-failed-btn' ).first().click();
		await page.locator( '#retry-upload-btn' ).click();
		await expect( resume ).toBeVisible( { timeout: 60_000 } );
		await resume.click();
		expect( await syncSummary( page ) ).toBe( 'success' );
		await expect( page.locator( '#sync-modal-summary' ) ).toContainText( 'Sync Completed Successfully!' );
		await clickAndAwaitReload( page, '#later-btn' );
		expect( pluginState() ).toBe( 'synced' );
		expect( trackingCounts() ).toMatchObject( { failed: 0, pending: 0, synced: LIBRARY.length } );
	} );

	test( 'synced: every file is in the bucket byte for byte, and the screens say offloading is still off', async ( { page } ) => {
		const objects = await bucketObjects();
		const local = syncable();
		expect( local.length ).toBe( LIBRARY.length );
		for ( const file of local ) {
			expect( objects[ `uploads/${ file }` ]?.[ 1 ], file ).toBe( md5Local( file ) );
		}
		expect( Object.keys( objects ).some( ( k ) => k.includes( 'left-out' ) || k.endsWith( 'empty.txt' ) ), 'what the scan left out stays out' ).toBe( false );

		await page.goto( SYNC );
		await expect( wrap( page ) ).toContainText( 'Synced Successfully' );
		await expect( page.locator( '.diluxone-offload-bar-head' ) ).toContainText( '100%' );
		await expect( wrap( page ) ).toContainText( 'Nothing pending.' );
		await expect( page.locator( '.diluxone-offload-actions-card' ).getByRole( 'link', { name: 'Go to Offloading' } ) ).toHaveAttribute( 'href', /tab=offloading/ );
		await expect( page.locator( '.diluxone-offload-rail-state__line' ) ).toContainText( `${ LIBRARY.length } files synced to` );
		await expect( page.locator( '.diluxone-offload-rail-state .diluxone-offload-pill' ) ).toContainText( 'offloading off' );

		// Resync All asks first; Cancel keeps the sync.
		await page.locator( '.resync-all-btn' ).click();
		await expect( page.locator( '#sync-modal-summary' ) ).toContainText( 'Confirm Complete Resync' );
		await page.locator( '#resync-cancel-btn' ).click();
		await expect( page.locator( '#sync-modal' ) ).toBeHidden();
		expect( pluginState() ).toBe( 'synced' );

		await page.goto( OVERVIEW );
		await expect( card( page, 'Synchronization' ).locator( '.status-label' ) ).toHaveText( 'Synced' );
		await expect( card( page, 'Synchronization' ) ).toContainText( 'Your files are in the cloud' );
		await expect( card( page, 'Offloading' ) ).toContainText( 'Files still served locally' );
		await page.locator( '#refresh-stats-btn' ).click();
		await expect( page.locator( '#stat-file-count' ) ).toHaveText( String( LIBRARY.length ), { timeout: 60_000 } );
		await expect( page.locator( '#stat-pie-section' ) ).toContainText( `Images ${ LIBRARY.length } (100.0%)` );
		// A warm cache: the server paints the numbers and the pie, with no loading state and no request.
		let fetched = false;
		page.on( 'request', ( r ) => {
			if ( ( r.postData() ?? '' ).includes( 'action=diluxone_offload_refresh_stats' ) ) fetched = true;
		} );
		await page.reload();
		await expect( page.locator( '.diluxone-offload-stats-wrap' ) ).not.toHaveClass( /diluxone-offload-loading/ );
		await expect( page.locator( '#stat-file-count' ) ).toHaveText( String( LIBRARY.length ) );
		await expect( page.locator( '#stat-pie-section .diluxone-offload-legend-item' ) ).toHaveCount( 4 );
		await expect( page.locator( '#stat-pie-section' ) ).toContainText( `Images ${ LIBRARY.length } (100%)` );
		await expect( page.locator( '#stat-last-updated' ) ).toHaveText( /Last updated: (just now|\d+ minutes? ago)/ );
		expect( fetched, 'no listing on a warm cache' ).toBe( false );

		// Tools › Site Health runs the plugin's own test against the bucket.
		await page.goto( '/wp-admin/site-health.php' );
		await expect( page.locator( 'body' ) ).toContainText( 'DiluxOne Offload reaches its storage', { timeout: 60_000 } );
	} );

	test( 'Enable Cloud Storage turns offloading on, and the library is served from the bucket', async ( { page } ) => {
		await page.goto( OFFLOADING );
		await expect( wrap( page ) ).toContainText( 'Synced Successfully' );
		const reloaded = page.waitForEvent( 'load', { timeout: 60_000 } );
		await page.locator( '#enable-offloading-btn' ).click();
		await expect( page.locator( '#diluxone-offload-notification' ) ).toContainText( /Enabling cloud storage offloading|Offloading enabled successfully/ );
		await reloaded;
		expect( pluginState() ).toBe( 'offloading_active' );

		await expect( bignum( page, 'Served from' ).locator( '.diluxone-offload-bignum__v' ) ).toHaveText( 'localhost' );
		await expect( bignum( page, 'Local copies' ).locator( '.diluxone-offload-bignum__v' ) ).toHaveText( String( LIBRARY.length ) );
		await expect( page.locator( '#delete-local-files-btn' ) ).toBeVisible();
		await expect( wrap( page ) ).toContainText( `deleting ${ LIBRARY.length } local files` );

		const id = Number( wp( [ 'post', 'list', '--post_type=attachment', '--name=journey-01', '--field=ID' ] ).split( '\n' )[ 0 ] );
		const url = wp( [ 'eval', `echo wp_get_attachment_url( ${ id } );` ] );
		expect( url.startsWith( `${ FAKE.publicUrl }/uploads/` ) ).toBe( true );
		const served = await page.request.get( url );
		expect( served.status() ).toBe( 200 );

		// Settings › Serving: Force HTTPS leaves a provider whose own Public URL is plain http on http
		// (https there would break every image); it rewrites only an https provider's URLs.
		const serving = `${ ADMIN }?page=diluxone-offload-settings&tab=serving`;
		await page.goto( serving );
		await page.locator( 'input[name="force_https_on_cloud"]' ).setChecked( true );
		await page.getByRole( 'button', { name: 'Save Settings' } ).click();
		await expect( page.locator( '.notice-success' ).first() ).toContainText( 'Settings saved.' );
		await expect( page.locator( 'input[name="force_https_on_cloud"]' ) ).toBeChecked();
		expect( wp( [ 'eval', `echo wp_get_attachment_url( ${ id } );` ] ) ).toBe( url );
		await page.locator( 'input[name="force_https_on_cloud"]' ).setChecked( false );
		await page.getByRole( 'button', { name: 'Save Settings' } ).click();
		await expect( page.locator( 'input[name="force_https_on_cloud"]' ) ).not.toBeChecked();
		expect( wp( [ 'eval', `echo wp_get_attachment_url( ${ id } );` ] ) ).toBe( url );

		await page.goto( SYNC );
		await expect( wrap( page ) ).toContainText( 'Offloading Active' );
		await expect( page.locator( '.diluxone-offload-actions-card' ).getByRole( 'link', { name: 'Disconnect' } ) ).toHaveAttribute( 'href', /tab=disconnect/ );

		// While offloading the provider cannot be deleted: the tab says why and where to go.
		await page.goto( CREDENTIALS );
		await expect( page.locator( '#remove-provider' ) ).toHaveCount( 0 );
		await expect( page.locator( '#delete-provider' ) ).toContainText( 'While offloading is active the provider cannot be deleted' );
		await expect( page.locator( '#delete-provider' ).getByRole( 'link', { name: 'Sync & Offloading › Disconnect' } ) ).toHaveAttribute( 'href', /tab=disconnect/ );
	} );

	test( 'an upload through the Media Library goes to the bucket with its sizes, and the library shows it from there', async ( { page } ) => {
		const file = pngFixture( 'journey-upload-300k.png', 300 * 1024 );
		await page.goto( '/wp-admin/media-new.php?browser-uploader' );
		await page.locator( 'input[type="file"][name="async-upload"]' ).setInputFiles( file );
		await page.locator( '#html-upload' ).click();
		await page.waitForURL( /upload\.php/, { timeout: 120_000 } );
		await expect( page.locator( 'body' ) ).not.toContainText( /Fatal error|could not|error/i );

		const keys = Object.keys( await bucketObjects() ).filter( ( k ) => k.includes( 'journey-upload-300k' ) );
		expect( keys.length, 'the original and its intermediate sizes' ).toBeGreaterThan( 1 );

		await page.goto( '/wp-admin/upload.php?mode=list&orderby=date&order=desc' );
		const thumb = page.locator( 'table.media tbody tr' ).first().locator( 'img' ).first();
		await expect( thumb ).toHaveAttribute( 'src', new RegExp( `^${ FAKE.publicUrl }/uploads/.*journey-upload-300k` ) );
		expect( await thumb.evaluate( ( img ) => ( img as HTMLImageElement ).naturalWidth ), 'the browser loaded it from the bucket' ).toBeGreaterThan( 0 );

		await page.goto( SYNC );
		await expect( bignum( page, 'Last upload' ).locator( '.diluxone-offload-bignum__d' ) ).toContainText( 'journey-upload-300k' );
	} );

	test( 'Delete Local Files: Cancel keeps them; Start frees the disk and the site keeps serving from the bucket', async ( { page } ) => {
		await page.goto( OFFLOADING );
		const modal = page.locator( '#delete-modal' );
		await page.locator( '#delete-local-files-btn' ).click();
		await expect( page.locator( '#delete-modal-info' ) ).toBeVisible( { timeout: 60_000 } );
		const total = num( await page.locator( '#delete-modal-total-files' ).innerText() );
		expect( total ).toBeGreaterThanOrEqual( LIBRARY.length );
		await expect( page.locator( '#delete-modal-total-size' ) ).toHaveText( /\d.*B/ );
		await page.locator( '#delete-modal-cancel' ).click();
		await expect( modal ).toBeHidden();
		expect( syncable().length ).toBeGreaterThanOrEqual( LIBRARY.length );

		await page.locator( '#delete-local-files-btn' ).click();
		await expect( page.locator( '#delete-modal-start' ) ).toBeEnabled( { timeout: 60_000 } );
		await page.locator( '#delete-modal-start' ).click();
		await expect( page.locator( '#delete-modal-summary' ) ).toContainText( 'Deletion Completed Successfully!', { timeout: 120_000 } );
		await expect( page.locator( '#delete-modal-summary' ) ).toContainText( `Deleted:${ total }` );
		await expect( page.locator( '#delete-modal-cancel' ) ).toBeHidden();
		await clickAndAwaitReload( page, '#delete-accept-btn' );

		await expect( wrap( page ) ).toContainText( 'No local copies left to delete' );
		await expect( page.locator( '.diluxone-offload-bar-head' ) ).toContainText( 'All in the cloud only' );
		expect( syncable(), 'no media file left on the server' ).toEqual( [] );
		// Every synced row is now cloud-only: the library's files and the upload's, which never had a local copy kept.
		const rows = trackingCounts();
		expect( rows.deleted ).toBe( rows.synced );
		expect( rows.deleted ).toBeGreaterThanOrEqual( total );

		const id = Number( wp( [ 'post', 'list', '--post_type=attachment', '--name=journey-02', '--field=ID' ] ).split( '\n' )[ 0 ] );
		const served = await page.request.get( wp( [ 'eval', `echo wp_get_attachment_url( ${ id } );` ] ) );
		expect( served.status() ).toBe( 200 );
	} );

	test( 'Status › Health: Check now finds it healthy, then paused when the service refuses, and every screen says so until it recovers', async ( { page } ) => {
		await page.goto( HEALTH );
		await page.locator( '#check-health-now' ).click();
		await expect( page.locator( '#check-health-now' ) ).toBeEnabled( { timeout: 60_000 } );
		await expect( page.locator( '#health-status' ) ).toHaveText( 'Healthy' );
		await expect( page.locator( '#health-last-success' ) ).toHaveText( 'just now' );
		await expect( page.locator( '#health-failures strong' ) ).toHaveText( '0' );

		await failWith( [ { status: 403, code: 'AccessDenied', message: 'Access Denied' } ] );
		for ( let i = 1; i <= 3; i++ ) {
			await page.locator( '#check-health-now' ).click();
			await expect( page.locator( '#health-failures strong' ) ).toHaveText( String( i ), { timeout: 60_000 } );
		}
		await expect( page.locator( '#health-status' ) ).toHaveText( 'Unhealthy (permission denied)' );
		await page.reload();
		await expect( page.locator( '#health-failures' ) ).toContainText( '(uploads refused from 3)' );
		await expect( wrap( page ) ).toContainText( 'health_check' );

		const banner = page.locator( '.diluxone-offload-health-banner' );
		for ( const url of [ OVERVIEW, CONNECTION, SYNC, HEALTH ] ) {
			await page.goto( url );
			await expect( banner ).toContainText( 'Cloud Permission Denied' );
			await expect( banner ).toContainText( 'New uploads are refused until the connection recovers.' );
			await expect( banner.getByRole( 'link', { name: 'Update Credentials' } ) ).toHaveAttribute( 'href', /tab=credentials/ );
			await expect( page.locator( '.diluxone-offload-rail-state__line' ) ).toHaveText( /^Paused \(permission denied\): [3-9]\d* consecutive failures\. Uploads are refused until the next successful connection\.$/ );
			await expect( page.locator( '.diluxone-offload-pill__why' ) ).toHaveText( 'permission denied' );
		}
		await page.goto( OVERVIEW );
		for ( const title of [ 'Configuration', 'Synchronization', 'Offloading' ] ) {
			await expect( card( page, title ).locator( '.status-label' ) ).toHaveText( 'Paused (permission denied)' );
		}
		await expect( card( page, 'Offloading' ) ).toContainText( 'New uploads are refused until the connection recovers.' );
		// The panel says it cannot read the bucket, instead of showing stale numbers.
		await page.locator( '#refresh-stats-btn' ).click();
		await expect( page.locator( '#stats-content' ) ).toContainText( 'Not available', { timeout: 60_000 } );
		await expect( page.locator( '.diluxone-offload-stats-why' ) ).not.toBeEmpty();
		await page.goto( CONNECTION );
		await expect( page.locator( '#provider-info .diluxone-offload-pill' ) ).toHaveText( 'Unhealthy' );
		await page.goto( HEALTH );
		await expect( page.locator( '.state-card', { hasText: 'Offloading' } ) ).toContainText( 'Paused (permission denied)' );

		// Paused: an upload is refused, and nothing is written on the server instead.
		const before = localUploads();
		await page.goto( '/wp-admin/media-new.php?browser-uploader' );
		await page.locator( 'input[type="file"][name="async-upload"]' ).setInputFiles( pngFixture( 'journey-refused.png', 9000 ) );
		await page.locator( '#html-upload' ).click();
		await page.waitForLoadState();
		expect( Object.keys( await bucketObjects() ).some( ( k ) => k.includes( 'journey-refused' ) ) ).toBe( false );
		expect( localUploads().filter( ( f ) => ! before.includes( f ) ), 'no local fallback' ).toEqual( [] );

		// Recovered: one passing check clears the banner.
		await failWith( [] );
		await page.goto( HEALTH );
		await page.locator( '#check-health-now' ).click();
		await expect( page.locator( '#health-status' ) ).toHaveText( 'Healthy', { timeout: 60_000 } );
		await expect( page.locator( '#health-failures strong' ) ).toHaveText( '0' );
		await page.goto( OVERVIEW );
		await expect( banner ).toHaveCount( 0 );
		await expect( card( page, 'Offloading' ).locator( '.status-label' ) ).toHaveText( 'Active' );
	} );

	test( 'a disconnect whose scan fails offers Force Disconnect, which turns offloading off without downloading', async ( { page } ) => {
		await failWith( [ { method: 'GET', status: 403, code: 'AccessDenied', message: 'Access Denied' } ] );
		await page.goto( DISCONNECT );
		await expect( bignum( page, 'Files to bring back' ).locator( '.diluxone-offload-bignum__v' ) ).not.toHaveText( '0' );
		await page.locator( '#disconnect-from-cloud-btn' ).click();
		await page.locator( '#confirm-disconnect' ).click();
		await expect( page.locator( '#disconnect-error-view' ) ).toBeVisible( { timeout: 120_000 } );
		await expect( page.locator( '#disconnect-error-message' ) ).not.toBeEmpty();

		// Close resets the dialog to its first view.
		await page.locator( '#disconnect-error-view .close-disconnect-modal' ).click();
		await expect( page.locator( '#disconnect-modal' ) ).toBeHidden();
		await page.locator( '#disconnect-from-cloud-btn' ).click();
		await expect( page.locator( '#disconnect-confirm-view' ) ).toBeVisible();
		await page.locator( '#confirm-disconnect' ).click();
		await expect( page.locator( '#disconnect-error-view' ) ).toBeVisible( { timeout: 120_000 } );

		await failWith( [] );
		const reloaded = page.waitForEvent( 'load', { timeout: 60_000 } );
		await page.locator( '#force-disconnect-btn' ).click();
		await expect( page.locator( '#force-disconnect-btn' ) ).toHaveText( 'Disconnected. Reloading...' );
		await reloaded;
		expect( pluginState(), 'offloading off; the sync has to be completed again' ).toBe( 'configured' );
		await expect( page.locator( '#disconnect-from-cloud-btn' ) ).toHaveCount( 0 );
		await expect( wrap( page ) ).toContainText( 'Offloading is not active, so there is nothing to bring back' );
		expect( syncable(), 'nothing was downloaded' ).toEqual( [] );
		wp( [ 'eval', '\\DiluxOneOffload\\ConfigManager::record_connection_success();' ] );
	} );

	test( 'with every file synced, Complete Sync goes straight to Enable Offloading', async ( { page } ) => {
		await page.goto( SYNC );
		await expect( page.locator( '#start-sync-btn' ) ).toContainText( 'Complete Sync' );
		await expect( wrap( page ) ).toContainText( `All ${ trackingCounts().synced } files are synced!` );
		await page.locator( '#start-sync-btn' ).click();
		// Nothing pending: no choice to make, the summary comes straight away.
		expect( await syncSummary( page ) ).toBe( 'success' );
		await expect( page.locator( '#scratch-upload-btn' ) ).toHaveCount( 0 );
		const reloaded = page.waitForEvent( 'load', { timeout: 60_000 } );
		await page.locator( '#sync-modal-summary #enable-offloading-btn' ).click();
		await expect( page.locator( '#diluxone-offload-notification' ) ).toContainText( 'Offloading enabled successfully!' );
		await reloaded;
		expect( pluginState() ).toBe( 'offloading_active' );
		await expect( wrap( page ) ).toContainText( 'Offloading Active' );
	} );

	test( 'Disconnect: Cancel leaves it; the download can be cancelled; then every file comes back byte for byte', async ( { page } ) => {
		await page.goto( DISCONNECT );
		const modal = page.locator( '#disconnect-modal' );
		await page.locator( '#disconnect-from-cloud-btn' ).click();
		await expect( modal ).toBeVisible();
		await expect( modal ).toContainText( 'This action will download all files from cloud storage back to local storage' );
		await page.locator( '#disconnect-confirm-view .close-disconnect-modal' ).click();
		await expect( modal ).toBeHidden();

		// The options: what is in the cloud, what is pending; Cancel puts the dialog back to its start.
		await page.locator( '#disconnect-from-cloud-btn' ).click();
		await page.locator( '#confirm-disconnect' ).click();
		await expect( page.locator( '#disconnect-options-view' ) ).toBeVisible( { timeout: 120_000 } );
		await expect( page.locator( '#disconnect-stats' ) ).toContainText( 'Total files in cloud:' );
		await expect( page.locator( '#disconnect-stats' ) ).toContainText( 'Pending download:' );
		await expect( page.locator( '#download-concurrency-select option' ) ).toHaveCount( 3 );
		await page.locator( '#disconnect-options-view .close-disconnect-modal' ).click();
		await expect( modal ).toBeHidden();
		await page.locator( '#disconnect-from-cloud-btn' ).click();
		await expect( page.locator( '#disconnect-confirm-view' ) ).toBeVisible();
		await expect( page.locator( '#disconnect-options-view' ) ).toBeHidden();

		// Cancel Download while the first round is on its way: the page reloads, still offloading.
		await page.route( '**/wp-admin/admin-ajax.php', async ( route ) => {
			if ( ( route.request().postData() ?? '' ).includes( 'action=diluxone_offload_process_reverse_batch' ) ) {
				await new Promise( ( r ) => setTimeout( r, 4000 ) );
			}
			await route.continue();
		} );
		await page.locator( '#confirm-disconnect' ).click();
		await expect( page.locator( '#disconnect-options-view' ) ).toBeVisible( { timeout: 120_000 } );
		await page.locator( '#start-disconnect' ).click();
		await expect( page.locator( '#disconnect-progress-view' ) ).toBeVisible();
		await expect( page.locator( '#disconnect-progress-view' ) ).toContainText( 'DO NOT CLOSE THIS WINDOW' );
		const reloaded = page.waitForEvent( 'load', { timeout: 60_000 } );
		await page.locator( '#cancel-disconnect' ).click();
		await expect( page.locator( '#disconnect-progress-label' ) ).toHaveText( 'Cancelling download...' );
		await reloaded;
		await page.unrouteAll( { behavior: 'ignoreErrors' } );
		await expect( page.locator( '#disconnect-from-cloud-btn' ) ).toBeVisible();

		// The whole download.
		await page.locator( '#disconnect-from-cloud-btn' ).click();
		await page.locator( '#confirm-disconnect' ).click();
		await expect( page.locator( '#disconnect-options-view' ) ).toBeVisible( { timeout: 120_000 } );
		await page.locator( '#start-disconnect' ).click();
		await expect( page.locator( '#disconnect-success-view' ) ).toBeVisible( { timeout: 120_000 } );
		await expect( page.locator( '#disconnect-success-view' ) ).toContainText( 'Disconnected Successfully!' );
		await expect( page.locator( '#disconnect-from-cloud-btn' ) ).toHaveCount( 0, { timeout: 60_000 } );
		expect( pluginState() ).toBe( 'configured' );

		const objects = await bucketObjects();
		const local = syncable();
		expect( local.length ).toBeGreaterThanOrEqual( LIBRARY.length );
		for ( const file of local ) {
			expect( md5Local( file ), file ).toBe( objects[ `uploads/${ file }` ]?.[ 1 ] );
		}
	} );

	test( 'a disconnect with every file already here only turns offloading off', async ( { page } ) => {
		// Back on: Complete Sync, then Enable from its summary.
		await page.goto( SYNC );
		await expect( page.locator( '#start-sync-btn' ) ).toContainText( 'Complete Sync' );
		await page.locator( '#start-sync-btn' ).click();
		expect( await syncSummary( page ) ).toBe( 'success' );
		const back = page.waitForEvent( 'load', { timeout: 60_000 } );
		await page.locator( '#sync-modal-summary #enable-offloading-btn' ).click();
		await back;
		await expect.poll( () => pluginState(), { timeout: 30_000 } ).toBe( 'offloading_active' );

		await page.goto( DISCONNECT );
		await page.locator( '#disconnect-from-cloud-btn' ).click();
		await page.locator( '#confirm-disconnect' ).click();
		await expect( page.locator( '#disconnect-success-view' ) ).toContainText( 'files already exist locally. Offloading has been disabled.', { timeout: 120_000 } );
		await expect( page.locator( '#disconnect-from-cloud-btn' ) ).toHaveCount( 0, { timeout: 60_000 } );
		expect( pluginState() ).toBe( 'configured' );
	} );

	test( 'Resync All clears the sync and offers Start Sync again', async ( { page } ) => {
		// A disconnect leaves the plugin configured with every row synced: Complete Sync, and Later.
		await page.goto( SYNC );
		await page.locator( '#start-sync-btn' ).click();
		expect( await syncSummary( page ) ).toBe( 'success' );
		await clickAndAwaitReload( page, '#later-btn' );
		expect( pluginState() ).toBe( 'synced' );
		await page.locator( '.resync-all-btn' ).click();
		const reloaded = page.waitForEvent( 'load', { timeout: 60_000 } );
		await page.locator( '#resync-confirm-btn' ).click();
		await expect( page.locator( '#sync-modal-summary' ) ).toContainText( 'Sync Data Cleared' );
		await reloaded;
		await expect( page.locator( '#start-sync-btn' ) ).toContainText( 'Start Sync' );
		expect( pluginState() ).toBe( 'configured' );
		expect( trackingCounts().total ).toBe( 0 );
	} );

	test( 'deleting the provider returns the plugin to Not Configured and leaves the bucket alone', async ( { page } ) => {
		const kept = Object.keys( await bucketObjects() ).length;
		await page.goto( CREDENTIALS );
		await page.locator( '#remove-provider' ).click();
		await page.locator( '#confirm-delete-provider' ).click();
		await page.waitForURL( /page=diluxone-offload-provider&tab=connection/, { timeout: 60_000 } );
		await expect( page.locator( '#cloud_provider' ) ).toBeVisible();
		expect( pluginState() ).toBe( 'not_configured' );
		expect( Object.keys( await bucketObjects() ).length ).toBe( kept );
		await page.goto( OVERVIEW );
		await expect( card( page, 'Configuration' ).locator( '.status-label' ) ).toHaveText( 'Not Configured' );
	} );
} );
