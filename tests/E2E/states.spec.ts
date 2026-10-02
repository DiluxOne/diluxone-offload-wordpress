import { test, expect, Page } from './helpers/test';
import { configureUnreachableProvider, emptyTracking, muPlugin, resetPlugin, wp } from './helpers/wp';
import {
	FAKE,
	clearLibrary,
	configureFakeS3,
	forgetStats,
	installFakeS3,
	pluginState,
	putObject,
	resetBucket,
	seedLibrary,
	trackingCounts,
	uninstallFakeS3,
} from './helpers/fake-s3';

/**
 * The states the journey passes through too quickly to look at, or never
 * reaches: each connection-health pause and its banner, credentials that
 * cannot be decrypted, a sync in progress, a sync run by another tab, failed
 * files while offloading, DEV MODE, the auto-start from the Connection tab,
 * and what every screen says before anything is configured. A state is put
 * in place through WP-CLI; what the screen says and what its buttons change
 * is then checked in the browser.
 */
const ADMIN = '/wp-admin/admin.php';
const OVERVIEW = `${ ADMIN }?page=diluxone-offload`;
const CONNECTION = `${ ADMIN }?page=diluxone-offload-provider&tab=connection`;
const CREDENTIALS = `${ ADMIN }?page=diluxone-offload-provider&tab=credentials`;
const SYNC = `${ ADMIN }?page=diluxone-offload-sync&tab=sync`;
const OFFLOADING = `${ ADMIN }?page=diluxone-offload-sync&tab=offloading`;
const DISCONNECT = `${ ADMIN }?page=diluxone-offload-sync&tab=disconnect`;
const HEALTH = `${ ADMIN }?page=diluxone-offload-status&tab=health`;
const SYSTEM = `${ ADMIN }?page=diluxone-offload-status&tab=system`;
const TRANSFERS = `${ ADMIN }?page=diluxone-offload-settings&tab=transfers`;

const wrap = ( page: Page ) => page.locator( '.wrap.diluxone-offload-admin' );
const card = ( page: Page, title: string ) => page.locator( '.status-card', { has: page.locator( 'h3', { hasText: title } ) } );
const stateCard = ( page: Page, title: string ) => page.locator( '.state-card', { has: page.locator( 'h3', { hasText: title } ) } );
const banner = ( page: Page ) => page.locator( '.diluxone-offload-health-banner' );

/** The connection health as recorded, checked just now (so no screen runs a check of its own). */
function setHealth( health: { status: 'healthy' | 'unhealthy'; error_code?: string; error_message?: string; failures?: number; last_success_ago?: number } ): void {
	const lastSuccess = health.last_success_ago === undefined ? 0 : `time() - ${ health.last_success_ago }`;
	wp( [ 'eval', `update_option( \\DiluxOneOffload\\ConfigManager::HEALTH_OPTION, array( 'status' => '${ health.status }', 'last_check' => time(), 'last_success' => ${ lastSuccess }, 'error_code' => '${ health.error_code ?? '' }', 'error_message' => '${ health.error_message ?? '' }', 'error_source' => 'e2e', 'consecutive_failures' => ${ health.failures ?? 0 }, 'paused_notified' => true ) );` ] );
}

/** A tracked file, synced or failed with `error`. */
function track( path: string, synced: boolean, error = '' ): void {
	wp( [ 'eval', `\\DiluxOneOffload\\DiluxOneOffloadDB::add_file( '${ path }', 4096 ); ${ synced ? `\\DiluxOneOffload\\DiluxOneOffloadDB::mark_synced( '${ path }' );` : `\\DiluxOneOffload\\DiluxOneOffloadDB::increment_error( '${ path }', '${ error }' );` }` ] );
}

test.describe( 'Before anything is configured', () => {
	test.beforeAll( () => {
		resetPlugin();
		emptyTracking();
	} );

	test( 'the Overview shows the three cards off and the getting-started steps, with their links', async ( { page } ) => {
		await page.goto( OVERVIEW );
		await expect( card( page, 'Configuration' ).locator( '.status-label' ) ).toHaveText( 'Not Configured' );
		await expect( card( page, 'Configuration' ) ).toContainText( 'Connect a cloud provider to get started' );
		await expect( card( page, 'Synchronization' ).locator( '.status-label' ) ).toHaveText( 'Not Synced' );
		await expect( card( page, 'Synchronization' ) ).toContainText( 'Configure cloud storage first' );
		await expect( card( page, 'Synchronization' ).getByRole( 'link' ) ).toHaveCount( 0 );
		await expect( card( page, 'Offloading' ).locator( '.status-label' ) ).toHaveText( 'Inactive' );
		await expect( card( page, 'Offloading' ) ).toContainText( 'Sync files first to enable' );
		await expect( page.locator( '.storage-overview-section' ) ).toHaveCount( 0 );
		await expect( page.locator( '.quick-links-section' ) ).toHaveCount( 0 );
		await expect( page.locator( '.setup-steps li' ) ).toHaveCount( 3 );
		const more = page.locator( '.welcome-header a' );
		await expect( more ).toHaveAttribute( 'href', 'https://diluxone.com/' );
		await expect( more ).toHaveAttribute( 'target', '_blank' );
		await expect( more ).toHaveAttribute( 'rel', /noopener/ );

		await card( page, 'Configuration' ).getByRole( 'link', { name: 'Configure Now' } ).click();
		await expect( page ).toHaveURL( /page=diluxone-offload-provider&tab=connection/ );
		await page.goto( OVERVIEW );
		await page.locator( '.getting-started-section' ).getByRole( 'link', { name: 'Go to Cloud Provider' } ).click();
		await expect( page ).toHaveURL( /page=diluxone-offload-provider&tab=connection/ );
		await expect( page.locator( '.diluxone-offload-rail-state__line' ) ).toHaveText( 'No cloud provider is connected. Media is served from this server.' );
		await expect( page.locator( '.diluxone-offload-rail-state .diluxone-offload-pill' ) ).toHaveText( 'Off' );
	} );

	test( 'the Credentials tab sends to Connection; Status shows Not Configured and no provider row', async ( { page } ) => {
		await page.goto( CREDENTIALS );
		await expect( page.locator( '#update-credentials' ) ).toHaveCount( 0 );
		await page.locator( '#provider-credentials' ).getByRole( 'link', { name: 'Go to Connection' } ).click();
		await expect( page ).toHaveURL( /tab=connection/ );

		await page.goto( HEALTH );
		await expect( stateCard( page, 'Configuration' ).locator( '.state-value' ) ).toContainText( 'Not Configured' );
		await expect( stateCard( page, 'Offloading' ).locator( '.state-value' ) ).toContainText( 'Inactive' );
		await expect( stateCard( page, 'Tracking table' ) ).toContainText( '0 files tracked' );

		await page.goto( `${ ADMIN }?page=diluxone-offload-sync&tab=sync` );
		await page.locator( '.wrap' ).getByRole( 'link', { name: 'Go to Cloud Provider' } ).click();
		await expect( page ).toHaveURL( /tab=connection/ );
	} );

	test( 'choosing no provider hides both forms; an Azure name the service would refuse is refused by Test Connection', async ( { page } ) => {
		await page.goto( CONNECTION );
		await page.locator( '#cloud_provider' ).selectOption( '' );
		await expect( page.locator( '#azure-config' ) ).toBeHidden();
		await expect( page.locator( '#s3-config' ) ).toBeHidden();

		await page.locator( '#cloud_provider' ).selectOption( 'azure' );
		await expect( page.locator( '#azure-config' ) ).toBeVisible();
		await page.locator( '#account_name' ).fill( 'Not_Valid!' );
		await page.locator( '#account_key' ).fill( Buffer.from( 'k'.repeat( 64 ) ).toString( 'base64' ) );
		await page.locator( '#container_name' ).fill( 'media' );
		await page.locator( '#azure-config .test-connection-btn' ).click();
		const result = page.locator( '#azure-config .connection-result' );
		await expect( result ).toContainText( 'Connection Failed', { timeout: 60_000 } );
		await expect( result ).toContainText( /account name|Storage Account/i );
		await expect( page.locator( '#submit' ) ).toBeDisabled();
		await expect( page.locator( '#azure-config .test-connection-btn' ) ).toBeEnabled();
		await expect( page.locator( '#azure-config .test-connection-btn' ) ).toHaveText( /Test Connection/ );
	} );

	test( 'every screen\'s rail has a note and links that open a working page', async ( { page } ) => {
		const screens = [
			OVERVIEW, CONNECTION, CREDENTIALS, SYNC, OFFLOADING, DISCONNECT, TRANSFERS,
			`${ ADMIN }?page=diluxone-offload-settings&tab=serving`, `${ ADMIN }?page=diluxone-offload-settings&tab=logging`, HEALTH, SYSTEM,
		];
		for ( const url of screens ) {
			await page.goto( url );
			await expect( page.locator( '.diluxone-offload-rail-state__label' ), url ).toHaveText( 'Right now' );
			await expect( page.locator( '.diluxone-offload-rail-note__title' ), url ).not.toBeEmpty();
			await expect( page.locator( '.diluxone-offload-rail-note p' ).first(), url ).not.toBeEmpty();
			const hrefs = await page.locator( '.diluxone-offload-rail-links a' ).evaluateAll( ( links ) => links.map( ( a ) => ( a as HTMLAnchorElement ).href ) );
			expect( hrefs.length, url ).toBeGreaterThan( 0 );
			for ( const href of hrefs ) {
				if ( href.startsWith( 'http://localhost' ) || href.includes( '/wp-admin/' ) ) {
					const res = await page.request.get( href );
					expect( res.status(), `${ url } → ${ href }` ).toBe( 200 );
					expect( await res.text(), href ).not.toMatch( /Fatal error|critical error/ );
				} else {
					expect( href, url ).toMatch( /^https:\/\/(wordpress\.org|github\.com)\// );
				}
			}
		}
	} );

	test( 'Transfers: the browser refuses numbers out of range, and the server clamps them when the browser is bypassed', async ( { page } ) => {
		await page.goto( TRANSFERS );
		const timeout = page.locator( '#timeout' );
		const size = page.locator( '#max_file_size' );
		await timeout.fill( '5' );
		await page.getByRole( 'button', { name: 'Save Settings' } ).click();
		expect( await timeout.evaluate( ( el ) => ( el as HTMLInputElement ).validity.rangeUnderflow ) ).toBe( true );
		await expect( page.locator( '.notice-success' ) ).toHaveCount( 0 );

		await page.locator( 'form' ).evaluate( ( f ) => f.setAttribute( 'novalidate', '' ) );
		await timeout.fill( '5' );
		await size.fill( '9999' );
		await page.getByRole( 'button', { name: 'Save Settings' } ).click();
		await expect( page.locator( '.notice-success' ).first() ).toContainText( 'Settings saved.' );
		await expect( timeout ).toHaveValue( '30' );
		await expect( size ).toHaveValue( '500' );
		// Back to the defaults.
		await timeout.fill( '60' );
		await size.fill( '20' );
		await page.getByRole( 'button', { name: 'Save Settings' } ).click();
		await expect( timeout ).toHaveValue( '60' );
	} );
} );

test.describe.serial( 'Connection health: each pause and its banner', () => {
	test.beforeAll( () => {
		resetPlugin();
		emptyTracking();
		configureUnreachableProvider( 'OFFLOADING_ACTIVE' );
	} );
	test.afterAll( () => resetPlugin() );

	const cases = [
		{ code: '403', title: 'Cloud Permission Denied', cta: 'Update Credentials', tab: /tab=credentials/, short: 'permission denied' },
		{ code: '401', title: 'Cloud Permission Denied', cta: 'Update Credentials', tab: /tab=credentials/, short: 'permission denied' },
		{ code: '404', title: 'Container or Bucket Not Found', cta: 'Open Cloud Provider Settings', tab: /tab=credentials/, short: 'container not found' },
		{ code: 'timeout', title: 'Cloud Transfer Timed Out', cta: 'Open Settings', tab: /tab=transfers/, short: 'transfer timed out' },
		{ code: 'exception', title: 'Cloud Connection Error', cta: 'Update your credentials in the Cloud Provider tab', tab: /tab=credentials/, short: 'connection error', message: 'cURL error 6: Could not resolve host' },
		{ code: '503', title: 'Cloud Connection Error', cta: 'Update your credentials in the Cloud Provider tab', tab: /tab=credentials/, short: 'cloud unreachable', message: 'HTTP 503 Service Unavailable' },
	];

	for ( const c of cases ) {
		test( `error ${ c.code }: "${ c.title }", its reason on the cards and the rail, and a link to the fix`, async ( { page } ) => {
			setHealth( { status: 'unhealthy', error_code: c.code, error_message: c.message ?? '', failures: 4, last_success_ago: 7200 } );
			await page.goto( OVERVIEW );
			await expect( banner( page ).locator( 'strong' ).first() ).toHaveText( c.title );
			if ( c.message ) await expect( banner( page ) ).toContainText( c.message );
			if ( c.code === 'timeout' ) await expect( banner( page ) ).toContainText( /Transfer Timeout \(\d+ seconds\)/ );
			await expect( banner( page ) ).toContainText( 'Last successful connection: 2 hours ago' );
			await expect( banner( page ) ).toContainText( 'New uploads are refused until the connection recovers.' );
			await expect( card( page, 'Configuration' ).locator( '.status-label' ) ).toHaveText( `Paused (${ c.short })` );
			await expect( page.locator( '.diluxone-offload-pill__why' ) ).toHaveText( c.short );
			await page.goto( HEALTH );
			await expect( page.locator( '#health-status' ) ).toHaveText( `Unhealthy (${ c.short })` );
			await expect( page.locator( '#health-failures' ) ).toContainText( '(uploads refused from 3)' );
			await expect( wrap( page ) ).toContainText( 'Error source' );
			await banner( page ).getByRole( 'link', { name: c.cta } ).click();
			await expect( page ).toHaveURL( c.tab );
		} );
	}

	test( 'a failure minutes after the last success does not print a "last successful connection" line', async ( { page } ) => {
		setHealth( { status: 'unhealthy', error_code: '403', failures: 1, last_success_ago: 60 } );
		await page.goto( OVERVIEW );
		await expect( banner( page ) ).toBeVisible();
		await expect( banner( page ) ).not.toContainText( 'Last successful connection' );
		await page.goto( HEALTH );
		await expect( page.locator( '#health-failures' ) ).not.toContainText( 'uploads refused' );
	} );

	test( 'a healthy connection: no banner, Active cards, and the Status table says so', async ( { page } ) => {
		setHealth( { status: 'healthy', last_success_ago: 0 } );
		await page.goto( OVERVIEW );
		await expect( banner( page ) ).toHaveCount( 0 );
		await expect( card( page, 'Offloading' ).locator( '.status-label' ) ).toHaveText( 'Active' );
		await expect( card( page, 'Offloading' ) ).toContainText( 'Files served from cloud storage' );
		await page.goto( HEALTH );
		await expect( page.locator( '#health-status' ) ).toHaveText( 'Healthy' );
		await expect( stateCard( page, 'Configuration' ) ).toContainText( 'Provider:' );
		await expect( stateCard( page, 'Offloading' ).locator( '.state-value' ) ).toContainText( 'Active' );
	} );
} );

test.describe.serial( 'Credentials that cannot be decrypted', () => {
	test.beforeAll( () => {
		resetPlugin();
		setHealth( { status: 'unhealthy', error_code: 'decrypt_failed', error_message: 'decrypt failed', failures: 1 } );
	} );
	test.afterAll( () => resetPlugin() );

	test( 'every screen says "Awaiting Re-entry" and leads to where the credentials are entered again', async ( { page } ) => {
		await page.goto( OVERVIEW );
		await expect( banner( page ).locator( 'strong' ).first() ).toHaveText( 'Stored Credentials Unreadable' );
		await expect( card( page, 'Configuration' ).locator( '.status-label' ) ).toHaveText( 'Awaiting Re-entry' );
		await card( page, 'Configuration' ).getByRole( 'link', { name: 'Re-enter Credentials' } ).click();
		await expect( page ).toHaveURL( /tab=connection/ );

		await page.goto( HEALTH );
		await expect( stateCard( page, 'Configuration' ).locator( '.state-value' ) ).toContainText( 'Awaiting Re-entry' );
		await expect( stateCard( page, 'Configuration' ).getByRole( 'link', { name: 'Re-enter Credentials' } ) ).toHaveAttribute( 'href', /tab=credentials/ );

		await page.goto( SYNC );
		await expect( wrap( page ) ).toContainText( 'Sync is paused because the saved cloud credentials cannot be decrypted.' );
		await expect( page.locator( '.diluxone-offload-studio__main' ).getByRole( 'link', { name: 'Re-enter Credentials' } ) ).toHaveAttribute( 'href', /tab=credentials/ );
		await expect( wrap( page ) ).not.toContainText( 'Steps to Enable Sync' );
	} );
} );

test.describe.serial( 'With the storage service on the dev site', () => {
	test.setTimeout( 120_000 );

	test.beforeAll( async () => {
		resetPlugin();
		emptyTracking();
		forgetStats();
		await installFakeS3();
	} );
	test.afterEach( async () => {
		await muPlugin( 'dev-mode', null );
		await muPlugin( 'short-batches', null );
	} );
	test.afterAll( async () => {
		resetPlugin();
		clearLibrary();
		forgetStats();
		await uninstallFakeS3();
	} );

	test( 'a configured plugin: Offloading and Disconnect say what has to happen first', async ( { page } ) => {
		configureFakeS3( 'CONFIGURED' );
		await page.goto( OFFLOADING );
		await expect( wrap( page ) ).toContainText( 'Offloading can be enabled once every file is in the cloud. Run the sync first.' );
		await page.locator( '.diluxone-offload-status-card' ).getByRole( 'link', { name: 'Go to Sync' } ).click();
		await expect( page ).toHaveURL( /tab=sync/ );
		await page.goto( DISCONNECT );
		await expect( wrap( page ) ).toContainText( 'Offloading is not active, so there is nothing to bring back' );
		await page.locator( '.diluxone-offload-status-card' ).getByRole( 'link', { name: 'Cloud Provider › Credentials' } ).click();
		await expect( page ).toHaveURL( /tab=credentials/ );
		await page.goto( SYSTEM );
		await expect( wrap( page ) ).toContainText( FAKE.bucket );
	} );

	test( 'Sync Files to Cloud on the Connection tab starts the sync by itself when the bucket is clear', async ( { page } ) => {
		resetPlugin();
		emptyTracking();
		await resetBucket();
		seedLibrary( [ { name: 'auto-a.png', bytes: 5000 }, { name: 'auto-b.png', bytes: 6000 } ] );
		configureFakeS3( 'CONFIGURED' );
		await page.goto( CONNECTION );
		await page.locator( '#provider-info' ).getByRole( 'link', { name: 'Sync Files to Cloud' } ).click();
		await expect( page.locator( '#scratch-upload-btn' ) ).toBeVisible( { timeout: 60_000 } );
		await expect( page.locator( '#sync-container' ) ).toContainText( 'Total files:2' );
		// The URL loses auto-start, so a reload does not start it again.
		expect( page.url() ).not.toContain( 'auto-start' );
		await page.locator( '#close-sync-options-btn' ).click();
		await page.reload();
		await expect( page.locator( '#start-sync-btn' ) ).toBeEnabled( { timeout: 60_000 } );
		await expect( page.locator( '#sync-modal' ) ).toBeHidden();
	} );

	test( 'with files already in the bucket the auto-start waits for the owner', async ( { page } ) => {
		// The choice above already scanned the library into the tracking table; the check is for a first sync only.
		emptyTracking();
		await putObject( 'uploads/2019/05/old.png', 'old' );
		await page.goto( `${ SYNC }&auto-start=1` );
		await expect( page.locator( '#diluxone-offload-target' ) ).toBeVisible( { timeout: 60_000 } );
		await page.waitForTimeout( 1500 );
		await expect( page.locator( '#sync-modal' ) ).toBeHidden();
		await expect( page.locator( '#start-sync-btn' ) ).toBeDisabled();
		await resetBucket();
	} );

	/**
	 * Tab A starts a sync, slowed after its first round; tab B opens the Sync
	 * tab, is offered the sync and takes it over with Continue Here, then
	 * runs it to the end. Returns tab B, showing the completion summary.
	 */
	async function takeOverInAnotherTab( page: Page, context: import( '@playwright/test' ).BrowserContext ): Promise< Page > {
		resetPlugin();
		emptyTracking();
		// The bucket starts empty, as on a first sync: a sync an earlier test
		// left there would hold Start Sync behind the "already holds files" check.
		await resetBucket();
		seedLibrary( Array.from( { length: 24 }, ( _, i ) => ( { name: `tab-${ String( i + 1 ).padStart( 2, '0' ) }.png`, bytes: 5000 + i * 200 } ) ) );
		configureFakeS3( 'CONFIGURED' );
		muPlugin( 'short-batches', "add_filter( 'diluxone_offload_sync_batch_seconds', function () { return 0.0; } );" );

		let n = 0;
		await page.route( '**/wp-admin/admin-ajax.php', async ( route ) => {
			if ( ( route.request().postData() ?? '' ).includes( 'action=diluxone_offload_process_batch' ) && n++ > 0 ) {
				await new Promise( ( r ) => setTimeout( r, 6000 ) );
			}
			await route.continue().catch( () => undefined );
		} );
		await page.goto( SYNC );
		await expect( page.locator( '#start-sync-btn' ) ).toBeEnabled( { timeout: 60_000 } );
		await page.locator( '#start-sync-btn' ).click();
		await page.locator( '#scratch-upload-btn' ).click();
		await expect.poll( async () => Number( ( await page.locator( '#sync-modal-stats-processed' ).innerText() ).replace( /\D/g, '' ) ), { timeout: 60_000 } ).toBeGreaterThan( 0 );
		expect( pluginState() ).toBe( 'syncing' );

		const other = await context.newPage();
		await other.goto( SYNC );
		await expect( other.locator( '.wrap.diluxone-offload-admin' ) ).toContainText( 'Sync in Progress' );
		await expect( other.locator( '.diluxone-offload-actions-card' ) ).toContainText( 'Sync controls are available in the modal dialog above.' );
		await expect( other.locator( '.diluxone-offload-rail-state__line' ) ).toContainText( 'A sync is in progress:' );
		await expect( other.locator( '#sync-container' ) ).toContainText( 'Sync Active in Another Tab', { timeout: 60_000 } );
		await expect( other.locator( '#sync-container' ) ).toContainText( /Progress:\d+ \/ 24 files \(/ );
		await other.locator( '#continue-here-btn' ).click();
		await expect( other.locator( '#sync-modal-progress' ) ).toBeVisible( { timeout: 60_000 } );
		await expect( other.locator( '#sync-modal-summary #enable-offloading-btn' ) ).toBeVisible( { timeout: 120_000 } );
		await expect( other.locator( '#sync-modal-summary' ) ).toContainText( 'Sync Completed Successfully!' );
		await page.unrouteAll( { behavior: 'ignoreErrors' } );
		return other;
	}

	test( 'a sync in progress: the screen says so, and a second tab can take it over with Continue Here', async ( { page, context } ) => {
		const other = await takeOverInAnotherTab( page, context );
		// Tab A is left behind: take it off the screen.
		await page.goto( 'about:blank' );
		const reloaded = other.waitForEvent( 'load', { timeout: 60_000 } );
		await other.locator( '#later-btn' ).click();
		await reloaded;
		expect( pluginState() ).toBe( 'synced' );
		expect( trackingCounts() ).toMatchObject( { synced: 24, pending: 0 } );
		await other.close();
	} );

	// The tab that lost the sync stops once the other tab has finished it: the
	// batch answers 'No active sync found', and the script used to ask again at
	// once, forever (253 requests in 8 seconds).
	test( 'the tab that lost the sync stops asking for batches once the other tab has finished it', async ( { page, context } ) => {
		const other = await takeOverInAnotherTab( page, context );
		let asked = 0;
		page.on( 'request', ( r ) => {
			if ( ( r.postData() ?? '' ).includes( 'action=diluxone_offload_process_batch' ) ) asked++;
		} );
		await page.waitForTimeout( 8000 );
		expect( asked, 'batch requests from the abandoned tab in 8 s' ).toBeLessThanOrEqual( 2 );
		await expect( page.locator( '#sync-modal-progress' ) ).toBeHidden();
		await other.close();
	} );

	test( 'enabling offloading is refused while a file is still unsynced, even if it appeared after the screen loaded', async ( { page } ) => {
		await page.goto( OFFLOADING );
		await expect( page.locator( '#enable-offloading-btn' ) ).toBeVisible();
		track( '/2026/09/late.png', false, 'HTTP 400 late' );
		await page.locator( '#enable-offloading-btn' ).click();
		await expect( page.locator( '#diluxone-offload-notification' ) ).toContainText( 'Cannot enable offloading:' );
		await expect( page.locator( '#diluxone-offload-notification' ) ).toContainText( 'Please resolve errors first' );
		await expect( page.locator( '#enable-offloading-btn' ) ).toBeEnabled();
		expect( pluginState() ).toBe( 'synced' );
	} );

	test( 'Clear Failed & Enable: an error enabling is shown in the dialog, which Close resets', async ( { page } ) => {
		await page.goto( SYNC );
		await expect( wrap( page ) ).toContainText( 'Synced with Errors' );
		await page.route( '**/wp-admin/admin-ajax.php', ( route ) =>
			( route.request().postData() ?? '' ).includes( 'action=diluxone_offload_activate_offloading' )
				? route.fulfill( { status: 200, contentType: 'application/json', body: '{"success":false,"data":"refused in the test"}' } )
				: route.continue()
		);
		const modal = page.locator( '#clear-and-enable-modal' );
		await page.locator( '#discard-and-enable-static-btn' ).click();
		await page.locator( '#confirm-clear-and-enable' ).click();
		await expect( page.locator( '#clear-enable-error-view' ) ).toBeVisible( { timeout: 60_000 } );
		await expect( page.locator( '#clear-enable-error-message' ) ).not.toBeEmpty();
		await page.locator( '#clear-enable-error-view .close-clear-enable-modal' ).click();
		await expect( modal ).toBeHidden();
		await page.locator( '#discard-and-enable-static-btn' ).click();
		await expect( page.locator( '#clear-enable-confirm-view' ) ).toBeVisible();
		await expect( page.locator( '#clear-enable-error-view' ) ).toBeHidden();
		await page.unrouteAll( { behavior: 'ignoreErrors' } );
		expect( pluginState() ).toBe( 'synced' );
	} );

	test( 'Clear Failed & Enable discards the failed files and turns offloading on', async ( { page } ) => {
		if ( trackingCounts().failed === 0 ) track( '/2026/09/late.png', false, 'HTTP 400 late' );
		await page.goto( SYNC );
		await page.locator( '#discard-and-enable-static-btn' ).click();
		const reloaded = page.waitForEvent( 'load', { timeout: 60_000 } );
		await page.locator( '#confirm-clear-and-enable' ).click();
		await expect( page.locator( '#clear-enable-success-view' ) ).toContainText( 'Successfully Enabled!', { timeout: 60_000 } );
		await reloaded;
		expect( pluginState() ).toBe( 'offloading_active' );
		expect( trackingCounts().pending ).toBe( 0 );
	} );

	test( 'while offloading, failed files are listed with Retry, View and Clear List, which asks first', async ( { page } ) => {
		track( '/2026/09/later-failure.png', false, 'HTTP 500 InternalError' );
		await page.goto( SYNC );
		const box = page.locator( '.diluxone-offload-spaced' );
		await expect( box ).toContainText( '1 files failed to sync' );
		await box.locator( '.view-failed-btn' ).click();
		await expect( page.locator( '#failed-files-modal tbody' ) ).toContainText( 'later-failure.png' );
		await expect( page.locator( '#failed-files-modal tbody' ) ).toContainText( 'HTTP 500 InternalError' );
		await page.locator( '#close-failed-modal' ).click();
		await expect( page.locator( '#failed-files-modal' ) ).toBeHidden();

		await box.locator( '.retry-failed-btn' ).click();
		await expect( page.locator( '#sync-modal-summary' ) ).toContainText( 'Failed files to retry:', { timeout: 60_000 } );
		await page.locator( '#sync-modal-summary #sync-modal-cancel' ).click();
		await expect( page.locator( '#sync-modal' ) ).toBeHidden();

		page.once( 'dialog', ( d ) => {
			expect( d.message() ).toBe( 'Are you sure you want to clear the failed files list?' );
			void d.dismiss();
		} );
		await box.locator( '.clear-failed-btn' ).click();
		await expect( box.locator( '.clear-failed-btn' ) ).toBeEnabled();
		expect( trackingCounts().failed ).toBe( 1 );

		page.once( 'dialog', ( d ) => void d.accept() );
		const reloaded = page.waitForEvent( 'load', { timeout: 60_000 } );
		await box.locator( '.clear-failed-btn' ).click();
		await expect( page.locator( '#diluxone-offload-notification' ) ).toContainText( 'Failed files list cleared.' );
		await reloaded;
		await expect( wrap( page ) ).toContainText( 'Offloading Active' );
	} );

	// Clear List removes the failed rows from the tracking table, which the
	// screen lists; it used to delete an option nothing reads any more.
	test( 'Clear List empties the failed list for good', async ( { page } ) => {
		configureFakeS3( 'OFFLOADING_ACTIVE' );
		if ( trackingCounts().failed === 0 ) track( '/2026/09/later-failure.png', false, 'HTTP 500 InternalError' );
		await page.goto( SYNC );
		page.once( 'dialog', ( d ) => void d.accept() );
		const reloaded = page.waitForEvent( 'load', { timeout: 60_000 } );
		await page.locator( '.diluxone-offload-spaced .clear-failed-btn' ).click();
		await reloaded;
		await expect( page.locator( '.diluxone-offload-spaced' ) ).toHaveCount( 0 );
		expect( trackingCounts().failed ).toBe( 0 );
	} );

	test( 'DEV MODE: the skip buttons ask first, and jump straight to offloading and back', async ( { page } ) => {
		muPlugin( 'dev-mode', "define( 'DILUXONE_OFFLOAD_DEV_MODE', true );" );
		wp( [ 'eval', '\\DiluxOneOffload\\ConfigManager::set_state( \\DiluxOneOffload\\Enums\\PluginState::CONFIGURED );' ] );
		emptyTracking();
		await page.goto( SYNC );
		await expect( wrap( page ) ).toContainText( 'DEV MODE' );
		const enable = page.locator( '#dev-enable-without-sync-btn' );
		page.once( 'dialog', ( d ) => void d.dismiss() );
		await enable.click();
		await expect( enable ).toBeEnabled();
		expect( pluginState() ).toBe( 'configured' );

		page.once( 'dialog', ( d ) => void d.accept() );
		let reloaded = page.waitForEvent( 'load', { timeout: 60_000 } );
		await enable.click();
		await expect( page.locator( '#diluxone-offload-notification' ) ).toContainText( 'DEV MODE: Offloading enabled without sync!' );
		await reloaded;
		expect( pluginState() ).toBe( 'offloading_active' );

		await page.goto( DISCONNECT );
		const disconnect = page.locator( '#dev-disconnect-without-sync-btn' );
		page.once( 'dialog', ( d ) => void d.accept() );
		reloaded = page.waitForEvent( 'load', { timeout: 60_000 } );
		await disconnect.click();
		await expect( page.locator( '#diluxone-offload-notification' ) ).toContainText( 'DEV MODE: Offloading disabled without sync!' );
		await reloaded;
		expect( pluginState() ).not.toBe( 'offloading_active' );
		await expect( page.locator( '#disconnect-from-cloud-btn' ) ).toHaveCount( 0 );
	} );

	test( 'a modal is closed by its buttons only: Escape leaves it open', async ( { page } ) => {
		configureFakeS3( 'SYNCED' );
		track( '/2026/09/escape.png', true );
		await page.goto( SYNC );
		await page.locator( '#cancel-all-sync-btn' ).click();
		const modal = page.locator( '#cancel-sync-modal' );
		await expect( modal ).toBeVisible( { timeout: 30_000 } );
		await page.keyboard.press( 'Escape' );
		await expect( modal ).toBeVisible();
		await modal.locator( '.close-cancel-sync-modal' ).click();
		await expect( modal ).toBeHidden();
		expect( pluginState() ).toBe( 'synced' );
	} );
} );

test.describe.serial( 'When a request behind a button fails', () => {
	test.beforeAll( async () => {
		resetPlugin();
		emptyTracking();
		await installFakeS3();
		configureFakeS3( 'SYNCED' );
		track( '/2026/09/kept.png', true );
	} );
	test.afterAll( async () => {
		resetPlugin();
		emptyTracking();
		await uninstallFakeS3();
	} );

	/** The text of the next alert(), which is accepted. */
	function alertText( page: Page ): Promise< string > {
		return new Promise( ( resolve ) => {
			page.once( 'dialog', ( d ) => {
				resolve( d.message() );
				void d.accept();
			} );
		} );
	}

	/** Answer `action` with `body` (or drop it when body is null) instead of the server. */
	async function answer( page: Page, action: string, body: string | null ): Promise< void > {
		await page.route( '**/wp-admin/admin-ajax.php', ( route ) =>
			( route.request().postData() ?? '' ).includes( `action=${ action }` )
				? ( body === null ? route.abort( 'connectionreset' ) : route.fulfill( { status: 200, contentType: 'application/json', body } ) )
				: route.continue()
		);
	}

	test( 'Check now that cannot reach the server says the check could not run', async ( { page } ) => {
		await answer( page, 'diluxone_offload_check_health', null );
		await page.goto( HEALTH );
		await page.locator( '#check-health-now' ).click();
		await expect( page.locator( '#health-status' ) ).toHaveText( 'The check could not run. Try again.' );
		await expect( page.locator( '#check-health-now' ) ).toBeEnabled();
		await expect( page.locator( '#check-health-now' ) ).toHaveText( 'Check now' );
	} );

	test( 'a key test that cannot reach the server fails, and Save New Key stays disabled', async ( { page } ) => {
		await answer( page, 'diluxone_offload_test_connection', null );
		await page.goto( CREDENTIALS );
		await page.locator( '#new_secret_access_key' ).fill( 'another' );
		await page.locator( '#test-new-credentials' ).click();
		// Written into the result box, and shown.
		await expect( page.locator( '#new-credentials-result .notice-error' ) ).toHaveCount( 1 );
		await expect( page.locator( '#save-new-credentials' ) ).toBeDisabled();
		await expect( page.locator( '#test-new-credentials' ) ).toBeEnabled();
	} );

	// Every answer of Test Connection on the Credentials tab is shown, as on the
	// Connection tab: it used to be written into a box that stayed hidden.
	test( 'the Credentials tab shows the answer of Test Connection', async ( { page } ) => {
		await page.goto( CREDENTIALS );
		await page.locator( '#test-new-credentials' ).click();
		await expect( page.locator( '#new-credentials-result' ) ).toBeVisible();
		await expect( page.locator( '#new-credentials-result' ) ).toHaveText( 'Please enter the new access key.' );
		await page.locator( '#new_secret_access_key' ).fill( FAKE.secret );
		await page.locator( '#test-new-credentials' ).click();
		await expect( page.locator( '#new-credentials-result' ) ).toBeVisible();
		await expect( page.locator( '#new-credentials-result' ) ).toContainText( 'Connection Successful', { timeout: 60_000 } );
	} );

	test( 'a refused key save is reported, and the button comes back', async ( { page } ) => {
		await page.goto( CREDENTIALS );
		await page.locator( '#new_secret_access_key' ).fill( FAKE.secret );
		await page.locator( '#test-new-credentials' ).click();
		await expect( page.locator( '#new-credentials-result' ) ).toContainText( 'Connection Successful', { timeout: 60_000 } );
		await answer( page, 'diluxone_offload_save_updated_credentials', '{"success":false,"data":{"message":"not saved in the test"}}' );
		const said = alertText( page );
		await page.locator( '#save-new-credentials' ).click();
		expect( await said ).toBe( 'Error saving credentials: not saved in the test' );
		await expect( page.locator( '#save-new-credentials' ) ).toBeEnabled();
	} );

	test( 'a refused provider deletion is reported, and the plugin keeps its provider', async ( { page } ) => {
		wp( [ 'eval', '\\DiluxOneOffload\\ConfigManager::set_state( \\DiluxOneOffload\\Enums\\PluginState::SYNCED );' ] );
		await answer( page, 'diluxone_offload_ajax_remove_provider', '{"success":false,"data":{"message":"kept in the test"}}' );
		await page.goto( CREDENTIALS );
		await page.locator( '#remove-provider' ).click();
		const said = alertText( page );
		await page.locator( '#confirm-delete-provider' ).click();
		expect( await said ).toBe( 'Error deleting configuration: kept in the test' );
		await expect( page.locator( '#confirm-delete-provider .button-text' ) ).toHaveText( 'Yes, Delete Configuration' );
		await expect( page.locator( '#confirm-delete-provider' ) ).toBeEnabled();
		expect( pluginState() ).toBe( 'synced' );
	} );

	test( 'Reset Sync refused because another tab holds the sync offers Refresh Page, which reloads', async ( { page } ) => {
		await answer( page, 'diluxone_offload_cancel_sync', '{"success":false,"data":{"validation_failed":true,"reason":"sync_active_in_another_tab","message":"Another tab is syncing (test)."}}' );
		await page.goto( SYNC );
		await page.locator( '#cancel-all-sync-btn' ).click();
		await page.locator( '#confirm-cancel-sync' ).click();
		await expect( page.locator( '#sync-container' ) ).toContainText( 'Cannot Reset' );
		await expect( page.locator( '#sync-container' ) ).toContainText( 'Another tab is syncing (test).' );
		await expect( page.locator( '#cancel-sync-modal' ) ).toBeHidden();
		const reloaded = page.waitForEvent( 'load' );
		await page.locator( '#sync-container .diluxone-offload-reload' ).click();
		await reloaded;
		expect( pluginState() ).toBe( 'synced' );
	} );

	test( 'Reset Sync that fails says so, and Close puts the dialog away', async ( { page } ) => {
		await answer( page, 'diluxone_offload_cancel_sync', '{"success":false,"data":{"message":"refused in the test"}}' );
		await page.goto( SYNC );
		await page.locator( '#cancel-all-sync-btn' ).click();
		await page.locator( '#confirm-cancel-sync' ).click();
		await expect( page.locator( '#sync-container' ) ).toContainText( 'Failed to cancel sync' );
		await expect( page.locator( '#sync-container' ) ).toContainText( 'refused in the test' );
		await page.locator( '#sync-container .diluxone-offload-modal-close' ).click();
		await expect( page.locator( '#sync-modal' ) ).toBeHidden();
		expect( trackingCounts().synced ).toBe( 1 );
	} );

	test( 'Resync All that fails closes its dialog and says so', async ( { page } ) => {
		await answer( page, 'diluxone_offload_prepare_resync', '{"success":false,"data":"no"}' );
		await page.goto( SYNC );
		await page.locator( '.resync-all-btn' ).click();
		await page.locator( '#resync-confirm-btn' ).click();
		await expect( page.locator( '#sync-modal' ) ).toBeHidden();
		await expect( page.locator( '#diluxone-offload-notification' ) ).toHaveClass( /notice-error/ );
		await expect( page.locator( '#diluxone-offload-notification' ) ).not.toBeEmpty();
		expect( pluginState() ).toBe( 'synced' );
	} );

	test( 'a sync whose first request fails closes the dialog with the reason, or with "try again" when the server is unreachable', async ( { page } ) => {
		wp( [ 'eval', '\\DiluxOneOffload\\ConfigManager::set_state( \\DiluxOneOffload\\Enums\\PluginState::CONFIGURED );' ] );
		await answer( page, 'diluxone_offload_start_sync', '{"success":false,"data":"refused in the test"}' );
		await page.goto( SYNC );
		await page.locator( '#start-sync-btn' ).click();
		await expect( page.locator( '#diluxone-offload-notification' ) ).toHaveText( 'Error: refused in the test' );
		await expect( page.locator( '#diluxone-offload-notification' ) ).toHaveClass( /notice-error/ );
		await expect( page.locator( '#sync-modal' ) ).toBeHidden();

		await page.unrouteAll( { behavior: 'ignoreErrors' } );
		await answer( page, 'diluxone_offload_start_sync', null );
		await page.reload();
		await page.locator( '#start-sync-btn' ).click();
		await expect( page.locator( '#diluxone-offload-notification' ) ).toHaveText( 'Connection error. Please try again.' );
		await expect( page.locator( '#sync-modal' ) ).toBeHidden();
		expect( pluginState() ).toBe( 'configured' );
	} );
} );
