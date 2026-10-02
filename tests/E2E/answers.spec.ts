import { test, expect, Page } from './helpers/test';
import { configureUnreachableProvider, emptyTracking, muPlugin, resetPlugin, wp } from './helpers/wp';
import { configureFakeS3, forgetStats, installFakeS3, pluginState, uninstallFakeS3 } from './helpers/fake-s3';
import { answerAjax, ok, refused } from './helpers/ajax';

/**
 * Every answer a screen's script can get from admin-ajax.php and has to show:
 * refusals, dropped connections, timeouts, races with another tab and the
 * rarer shapes of a success, which the journey's real server seldom or never
 * gives. The plugin is put in the state the screen needs through WP-CLI; the
 * request behind the button is answered by the test (helpers/ajax.ts); what
 * the user sees, and that nothing changed underneath when nothing should
 * have, is checked.
 */
const ADMIN = '/wp-admin/admin.php';
const OVERVIEW = `${ ADMIN }?page=diluxone-offload`;
const CONNECTION = `${ ADMIN }?page=diluxone-offload-provider&tab=connection`;
const CREDENTIALS = `${ ADMIN }?page=diluxone-offload-provider&tab=credentials`;
const SYNC = `${ ADMIN }?page=diluxone-offload-sync&tab=sync`;
const OFFLOADING = `${ ADMIN }?page=diluxone-offload-sync&tab=offloading`;
const DISCONNECT = `${ ADMIN }?page=diluxone-offload-sync&tab=disconnect`;
const HEALTH = `${ ADMIN }?page=diluxone-offload-status&tab=health`;

const notification = ( page: Page ) => page.locator( '#diluxone-offload-notification' );
const container = ( page: Page ) => page.locator( '#sync-container' );
const summary = ( page: Page ) => page.locator( '#sync-modal-summary' );

function setState( state: 'CONFIGURED' | 'SYNCING' | 'SYNCED' | 'OFFLOADING_ACTIVE' ): void {
	wp( [ 'eval', `\\DiluxOneOffload\\ConfigManager::set_state( \\DiluxOneOffload\\Enums\\PluginState::${ state } );` ] );
}

/** Tracked files: synced, pending, or failed (pending with an error). */
function track( files: Array< [ string, 'synced' | 'pending' | 'failed' ] > ): void {
	const php = files.map( ( [ path, kind ] ) => `\\DiluxOneOffload\\DiluxOneOffloadDB::add_file( '${ path }', 4096 );` +
		( kind === 'synced' ? ` \\DiluxOneOffload\\DiluxOneOffloadDB::mark_synced( '${ path }' );` : '' ) +
		( kind === 'failed' ? ` \\DiluxOneOffload\\DiluxOneOffloadDB::increment_error( '${ path }', 'HTTP 500 in the test' );` : '' ) ).join( ' ' );
	wp( [ 'eval', php ] );
}

/** Wait for the reload `act` causes (the URL does not change). */
async function reloadsAfter( page: Page, act: () => Promise< unknown >, timeout = 30_000 ): Promise< void > {
	const reloaded = page.waitForEvent( 'load', { timeout } );
	await act();
	await reloaded;
}

/** The text of the next dialog, answered with `accept`. */
function dialogText( page: Page, accept = true ): Promise< string > {
	return new Promise( ( resolve ) => {
		page.once( 'dialog', ( d ) => {
			resolve( d.message() );
			void ( accept ? d.accept() : d.dismiss() );
		} );
	} );
}

const COMPLETED = { status: 'completed', total_files: 3, processed_files: 3, successful_uploads: 3, failed_uploads: 0, percentage: 100 };

test.beforeAll( async () => {
	resetPlugin();
	emptyTracking();
	await installFakeS3();
} );

test.afterAll( async () => {
	muPlugin( 'dev-mode', null );
	resetPlugin();
	emptyTracking();
	await uninstallFakeS3();
} );

test.describe.serial( 'Overview: the storage numbers', () => {
	test.beforeAll( () => {
		emptyTracking();
		configureFakeS3( 'CONFIGURED' );
	} );

	test( 'a cold cache draws the pie chart from the answer, Refresh redraws it, and an empty bucket hides it', async ( { page } ) => {
		forgetStats();
		let answer: object = ok( { fileCount: 4, storageUsedBytes: 3 * 1024 * 1024, filesByType: { images: 2, videos: 1, audio: 1, other: 0 } } );
		await answerAjax( page, { refresh_stats: () => answer } );
		await page.goto( OVERVIEW );
		await expect( page.locator( '#stat-file-count' ) ).toHaveText( '4' );
		await expect( page.locator( '#stat-storage-detail' ) ).toHaveText( '3 MB' );
		const legend = page.locator( '#stat-pie-section .diluxone-offload-legend-item' );
		await expect( legend ).toHaveText( [ 'Images 2 (50.0%)', 'Videos 1 (25.0%)', 'Audio 1 (25.0%)', 'Other 0 (0.0%)' ] );
		await expect( page.locator( '#stat-pie-section .diluxone-offload-pie' ) ).toHaveAttribute( 'style', /conic-gradient\(#2271b1 0% 50%/ );
		await expect( page.locator( '#stat-last-updated' ) ).toHaveText( 'Last updated: just now' );
		await expect( page.locator( '#refresh-stats-btn' ) ).toBeEnabled();
		await expect( page.locator( '#stats-loading' ) ).toBeHidden();

		answer = ok( { fileCount: 10, storageUsedBytes: 1536, filesByType: { images: 5, videos: 0, audio: 0, other: 5 } } );
		await page.locator( '#refresh-stats-btn' ).click();
		await expect( page.locator( '#stat-file-count' ) ).toHaveText( '10' );
		await expect( page.locator( '#stat-storage-detail' ) ).toHaveText( '1.5 KB' );
		await expect( legend ).toHaveText( [ 'Images 5 (50.0%)', 'Videos 0 (0.0%)', 'Audio 0 (0.0%)', 'Other 5 (50.0%)' ] );
		await expect( legend.locator( '.diluxone-offload-legend-dot' ) ).toHaveCount( 4 );

		answer = ok( { fileCount: 0, storageUsedBytes: 0, filesByType: { images: 0, videos: 0, audio: 0, other: 0 } } );
		await page.locator( '#refresh-stats-btn' ).click();
		await expect( page.locator( '#stat-file-count' ) ).toHaveText( '0' );
		await expect( page.locator( '#stat-storage-detail' ) ).toHaveText( '0 B' );
		await expect( page.locator( '#stat-pie-section' ) ).toBeHidden();
	} );

	test( 'a refused refresh says the numbers could not be loaded; a dropped one says it was the network', async ( { page } ) => {
		let answer: object | null = refused( null );
		await answerAjax( page, { refresh_stats: () => answer } );
		await page.goto( OVERVIEW );
		await page.locator( '#refresh-stats-btn' ).click();
		await expect( page.locator( '.diluxone-offload-stats-why' ) ).toHaveText( 'The statistics could not be loaded. Try again later.' );
		await expect( page.locator( '#stats-content .is-unavailable' ) ).toHaveText( [ 'Not available', 'Not available' ] );

		answer = null;
		await page.locator( '#refresh-stats-btn' ).click();
		await expect( page.locator( '.diluxone-offload-stats-why' ) ).toHaveText( /^Network error: / );
		await expect( page.locator( '#refresh-stats-btn' ) ).toBeEnabled();
	} );

	test( 'a refresh that never answers times out after 30 seconds with its own message', async ( { page } ) => {
		await page.clock.install();
		await answerAjax( page, { refresh_stats: 'hang' } );
		// A cold cache: the page asks for the numbers by itself.
		forgetStats();
		await page.goto( OVERVIEW );
		await expect( page.locator( '#stats-loading' ) ).toBeVisible();
		await expect( page.locator( '#refresh-stats-btn' ) ).toBeDisabled();
		await page.clock.runFor( 31_000 );
		await expect( page.locator( '.diluxone-offload-stats-why' ) ).toHaveText( 'Request timed out. Try again later.' );
		await expect( page.locator( '#stats-loading' ) ).toBeHidden();
		await expect( page.locator( '#refresh-stats-btn' ) ).toBeEnabled();
	} );
} );

test.describe.serial( 'Status', () => {
	test.beforeAll( () => configureFakeS3( 'CONFIGURED' ) );

	test( 'the Health screen reloads itself every five minutes', async ( { page } ) => {
		await page.clock.install();
		await page.goto( HEALTH );
		await page.clock.runFor( 299_000 );
		let reloaded = false;
		page.once( 'load', () => {
			reloaded = true;
		} );
		await page.waitForTimeout( 500 );
		expect( reloaded, 'no reload before five minutes' ).toBe( false );
		await reloadsAfter( page, () => page.clock.runFor( 2_000 ) );
		await expect( page.locator( '#check-health-now' ) ).toBeVisible();
	} );

	test( 'Check now with an unhealthy answer and no reason shows the error code, and leaves the last success alone', async ( { page } ) => {
		await answerAjax( page, { check_health: ok( { status: 'unhealthy', reason: '', error_code: 'E2E_CODE', consecutive_failures: 4 } ) } );
		await page.goto( HEALTH );
		const lastSuccess = await page.locator( '#health-last-success' ).innerText();
		await page.locator( '#check-health-now' ).click();
		await expect( page.locator( '#health-status .diluxone-offload-pill--pending' ) ).toHaveText( 'Unhealthy (E2E_CODE)' );
		await expect( page.locator( '#health-last-check' ) ).toHaveText( 'just now' );
		await expect( page.locator( '#health-last-success' ) ).toHaveText( lastSuccess );
		await expect( page.locator( '#health-failures strong' ) ).toHaveText( '4' );
	} );

	test( 'Check now refused without a message says the check could not run', async ( { page } ) => {
		await answerAjax( page, { check_health: refused( null ) } );
		await page.goto( HEALTH );
		await page.locator( '#check-health-now' ).click();
		await expect( page.locator( '#health-status' ) ).toHaveText( 'The check could not run. Try again.' );
		await expect( page.locator( '#check-health-now' ) ).toHaveText( 'Check now' );
	} );
} );

test.describe.serial( 'Cloud Provider', () => {
	test( 'Connection: a test that cannot reach the server fails and keeps Save disabled; a pass without a message still enables it', async ( { page } ) => {
		resetPlugin();
		let answer: object | null = null;
		await answerAjax( page, { test_connection: () => answer } );
		await page.goto( CONNECTION );
		await page.locator( '#cloud_provider' ).selectOption( 'azure' );
		await page.locator( '#account_name' ).fill( 'diluxonee2e' );
		await page.locator( '#account_key' ).fill( Buffer.from( 'k'.repeat( 64 ) ).toString( 'base64' ) );
		await page.locator( '#container_name' ).fill( 'media' );
		const result = page.locator( '#azure-config .connection-result' );
		await page.locator( '#azure-config .test-connection-btn' ).click();
		await expect( result.locator( '.notice-error' ) ).toContainText( 'Connection Failed' );
		await expect( page.locator( '#submit' ) ).toBeDisabled();
		await expect( page.locator( '#azure-config .test-connection-btn' ) ).toHaveText( /Test Connection/ );

		answer = ok( {} );
		await page.locator( '#azure-config .test-connection-btn' ).click();
		await expect( result.locator( '.notice-success' ) ).toContainText( 'Connection Successful' );
		await expect( page.locator( '#submit' ) ).toBeEnabled();
		expect( pluginState() ).toBe( 'not_configured' );
	} );

	test( 'Credentials with Azure: the account and container are shown and posted with the new key, which Show key reveals', async ( { page } ) => {
		configureUnreachableProvider( 'CONFIGURED' );
		const posted: Array< Record< string, string > > = [];
		await answerAjax( page, {
			test_connection: ( fields ) => {
				posted.push( Object.fromEntries( fields ) );
				return ok( { message: 'reached (test)' } );
			},
			save_updated_credentials: ( fields ) => {
				posted.push( Object.fromEntries( fields ) );
				return refused( { message: 'not saved (test)' } );
			},
		} );
		await page.goto( CREDENTIALS );
		await expect( page.locator( '#credentials_account_name' ) ).toHaveText( 'diluxonee2enosuchaccount' );
		await expect( page.locator( '#credentials_container_name' ) ).toHaveText( 'nowhere' );
		const key = page.locator( '#new_account_key' );
		await expect( key ).toHaveAttribute( 'type', 'password' );
		await page.locator( '#show_new_account_key' ).check();
		await expect( key ).toHaveAttribute( 'type', 'text' );
		await key.fill( 'bmV3IGtleQ==' );
		await page.locator( '#test-new-credentials' ).click();
		await expect( page.locator( '#new-credentials-result .notice-success' ) ).toContainText( 'reached (test)' );
		const said = dialogText( page );
		await page.locator( '#save-new-credentials' ).click();
		expect( await said ).toBe( 'Error saving credentials: not saved (test)' );
		for ( const fields of posted ) {
			expect( fields ).toMatchObject( { provider: 'azure', account_name: 'diluxonee2enosuchaccount', container_name: 'nowhere', account_key: 'bmV3IGtleQ==' } );
		}
		expect( posted.map( ( f ) => f.action ) ).toEqual( [ 'diluxone_offload_test_connection', 'diluxone_offload_save_updated_credentials' ] );
		resetPlugin();
	} );

	test( 'Credentials: a key save or a provider deletion that cannot reach the server is reported, and nothing changes', async ( { page } ) => {
		configureFakeS3( 'SYNCED' );
		await answerAjax( page, { test_connection: ok( { message: 'fine' } ), save_updated_credentials: null, ajax_remove_provider: null } );
		await page.goto( CREDENTIALS );
		await page.locator( '#new_secret_access_key' ).fill( 'another' );
		await page.locator( '#test-new-credentials' ).click();
		await expect( page.locator( '#save-new-credentials' ) ).toBeEnabled();
		let said = dialogText( page );
		await page.locator( '#save-new-credentials' ).click();
		expect( await said ).toMatch( /^Error saving credentials: / );
		await expect( page.locator( '#save-new-credentials' ) ).toBeEnabled();
		await expect( page.locator( '#save-new-credentials' ) ).toHaveText( /Save/ );

		await page.locator( '#remove-provider' ).click();
		said = dialogText( page );
		await page.locator( '#confirm-delete-provider' ).click();
		expect( await said ).toMatch( /^Error deleting configuration: / );
		await expect( page.locator( '#confirm-delete-provider' ) ).toBeEnabled();
		await expect( page.locator( '#confirm-delete-provider .button-text' ) ).toHaveText( 'Yes, Delete Configuration' );
		expect( pluginState() ).toBe( 'synced' );
	} );
} );

test.describe.serial( 'Sync: before the first sync, the bucket check', () => {
	test.beforeEach( () => {
		emptyTracking();
		configureFakeS3( 'CONFIGURED' );
	} );

	test( 'a listing the server refuses shows its reason, and Start Sync is released', async ( { page } ) => {
		await answerAjax( page, { inspect_target: refused( 'AccessDenied in the test' ) } );
		await page.goto( SYNC );
		await expect( page.locator( '#diluxone-offload-target-status' ) ).toContainText( 'could not be checked (AccessDenied in the test)' );
		await expect( page.locator( '#diluxone-offload-target-continue' ) ).toBeHidden();
		await expect( page.locator( '#start-sync-btn' ) ).toBeEnabled();
	} );

	test( 'emptying takes as many rounds as the listing needs, counting down, then releases Start Sync', async ( { page } ) => {
		const markers: string[] = [];
		await answerAjax( page, {
			inspect_target: ok( { files: 3, size: '12 KB', prefix: 'uploads/', target: 'e2e-bucket' } ),
			empty_target: ( fields, n ) => {
				markers.push( fields.get( 'marker' ) ?? '' );
				return n === 1 ? ok( { deleted: 2, failed: 0, done: false, next: 'after-2' } ) : ok( { deleted: 1, failed: 0, done: true } );
			},
		} );
		await page.goto( SYNC );
		await expect( page.locator( '#diluxone-offload-target-found' ) ).toContainText( '3 files (12 KB) already sit under uploads/ in e2e-bucket' );
		await expect( page.locator( '#start-sync-btn' ) ).toBeDisabled();
		await page.locator( '#diluxone-offload-target-empty-open' ).click();
		await page.locator( '#diluxone-offload-target-confirm' ).fill( 'e2e-bucket' );
		await page.locator( '#diluxone-offload-target-empty-go' ).click();
		await expect( page.locator( '#diluxone-offload-target-status' ) ).toHaveText( 'Done: 3 deleted. The folder is empty; start the sync when you are ready.' );
		expect( markers, 'the second round resumes where the first stopped' ).toEqual( [ '', 'after-2' ] );
		await expect( page.locator( '#diluxone-offload-target-found' ) ).toHaveText( '' );
		await expect( page.locator( '#start-sync-btn' ) ).toBeEnabled();
	} );

	test( 'a partial failure, a refusal and a dropped request each say so and give the buttons back', async ( { page } ) => {
		let answer: object | null = ok( { deleted: 1, failed: 2, done: false, errors: [ 'AccessDenied' ] } );
		await answerAjax( page, {
			inspect_target: ok( { files: 1, size: '4 KB', prefix: 'uploads/', target: 'e2e-bucket' } ),
			empty_target: () => answer,
		} );
		await page.goto( SYNC );
		await expect( page.locator( '#diluxone-offload-target-found' ) ).toContainText( '1 file (4 KB) already sits under uploads/ in e2e-bucket' );
		await page.locator( '#diluxone-offload-target-empty-open' ).click();
		await expect( page.locator( '#diluxone-offload-target-confirm' ) ).toBeFocused();
		await page.locator( '#diluxone-offload-target-confirm' ).fill( 'e2e-bucket' );
		const status = page.locator( '#diluxone-offload-target-status' );
		const go = page.locator( '#diluxone-offload-target-empty-go' );
		await go.click();
		await expect( status ).toHaveText( '1 deleted, 2 could not be (AccessDenied). Nothing else was touched; try again, or continue with what is left.' );
		await expect( go ).toBeEnabled();
		await expect( page.locator( '#diluxone-offload-target-continue' ) ).toBeEnabled();

		answer = refused( 'The name does not match (test).' );
		await go.click();
		await expect( status ).toHaveText( 'The name does not match (test).' );
		answer = refused( null );
		await go.click();
		await expect( status ).toHaveText( 'The request failed. Nothing more was deleted; try again.' );
		await status.evaluate( ( el ) => {
			el.textContent = '';
		} );
		answer = null;
		await go.click();
		await expect( status ).toHaveText( 'The request failed. Nothing more was deleted; try again.' );
		await expect( go ).toBeEnabled();
		await expect( page.locator( '#start-sync-btn' ) ).toBeDisabled();
	} );

	test( 'the auto-start from the Connection tab waits when the bucket holds files', async ( { page } ) => {
		let started = 0;
		await answerAjax( page, {
			inspect_target: ok( { files: 2, size: '8 KB', prefix: 'uploads/', target: 'e2e-bucket' } ),
			start_sync: () => {
				started++;
				return refused( 'should not start' );
			},
		} );
		await page.goto( `${ SYNC }&auto-start=1` );
		await expect( page.locator( '#diluxone-offload-target-found' ) ).toContainText( '2 files (8 KB)' );
		await expect( page ).not.toHaveURL( /auto-start/ );
		await page.waitForTimeout( 1500 );
		expect( started ).toBe( 0 );
		await expect( page.locator( '#sync-modal' ) ).toBeHidden();
	} );
} );

test.describe.serial( 'Sync: what Start Sync is told', () => {
	test.beforeEach( () => {
		emptyTracking();
		configureFakeS3( 'CONFIGURED' );
		track( [ [ '/2026/10/one.png', 'synced' ], [ '/2026/10/two.png', 'pending' ] ] );
	} );
	test.afterEach( () => expect( pluginState() ).toBe( 'configured' ) );

	const confirmation = ( data: object ) => ok( { requires_confirmation: true, data: { total_files: 10, total_size_formatted: '40 KB', synced_size_formatted: '16 KB', new_files_size_formatted: '8 KB', pending_size_formatted: '12 KB', ...data } } );

	test( 'the options list new and pending files; Continue, Scan and Complete or only From Scratch, as the numbers allow', async ( { page } ) => {
		let answer: object = confirmation( { synced_files: 4, new_files: 2, pending_files: 3 } );
		await answerAjax( page, { start_sync: () => answer } );
		await page.goto( SYNC );
		await page.locator( '#start-sync-btn' ).click();
		const rows = container( page ).locator( '.diluxone-offload-kv > div' );
		await expect( rows ).toHaveText( [ 'Total files:10 (40 KB)', 'Already uploaded:4 (16 KB)', 'New files:2 (8 KB)', 'Pending:3 (12 KB)' ] );
		await expect( container( page ).locator( '#continue-upload-btn' ) ).toHaveText( 'Continue Upload' );
		await expect( container( page ).locator( '#scratch-upload-btn' ) ).not.toHaveClass( /button-primary/ );
		await expect( page.locator( '#upload-concurrency-select option' ) ).toHaveText( [ 'Balanced (5 parallel)', 'Fast (20 parallel)', 'Intensive (40 parallel)' ] );
		await page.locator( '#close-sync-options-btn' ).click();
		await expect( page.locator( '#sync-modal' ) ).toBeHidden();
		await expect( container( page ) ).toBeEmpty();

		answer = confirmation( { synced_files: 4, new_files: 2, pending_files: 0 } );
		await page.locator( '#start-sync-btn' ).click();
		await expect( container( page ).locator( '#continue-upload-btn' ) ).toHaveText( 'Scan and Complete Sync' );
		await expect( rows ).toHaveCount( 3 );
		await page.locator( '#close-sync-options-btn' ).click();

		answer = confirmation( { synced_files: 0, new_files: 0, pending_files: 10 } );
		await page.locator( '#start-sync-btn' ).click();
		await expect( container( page ).locator( '#continue-upload-btn' ) ).toHaveCount( 0 );
		await expect( container( page ).locator( '#scratch-upload-btn' ) ).toHaveClass( /button-primary/ );
		await page.locator( '#close-sync-options-btn' ).click();
	} );

	test( 'an answer that neither validates nor asks for confirmation is an unexpected response', async ( { page } ) => {
		await answerAjax( page, { start_sync: ok( { something: 'else' } ) } );
		await page.goto( SYNC );
		await page.locator( '#start-sync-btn' ).click();
		await expect( notification( page ) ).toHaveText( 'Unexpected response from server' );
		await expect( notification( page ) ).toHaveClass( /notice-error/ );
		await expect( page.locator( '#sync-modal' ) ).toBeHidden();
	} );

	test( 'each validation refusal says what is in the way; the two about the state reload the page', async ( { page } ) => {
		let answer: object = ok( { validation_failed: true, reason: 'files_not_synced', details: { failed_count: 2, pending_count: 3 } } );
		await answerAjax( page, { start_sync: () => answer } );
		await page.goto( SYNC );
		await page.locator( '#start-sync-btn' ).click();
		await expect( notification( page ) ).toHaveText( 'Cannot proceed: 2 failed and 3 pending files. Please resolve them first.' );
		await expect( page.locator( '#sync-modal' ) ).toBeHidden();

		answer = ok( { validation_failed: true, reason: 'something_new', details: {} } );
		await page.locator( '#start-sync-btn' ).click();
		await expect( notification( page ) ).toHaveText( 'Operation not allowed: something_new' );

		answer = ok( { validation_failed: true, reason: 'state_conflict', details: {} } );
		await reloadsAfter( page, async () => {
			await page.locator( '#start-sync-btn' ).click();
			await expect( notification( page ) ).toHaveText( 'Plugin state conflict. Refreshing page...' );
			await expect( notification( page ) ).toHaveClass( /notice-warning/ );
		} );

		answer = ok( { validation_failed: true, reason: 'sync_already_active', details: {} } );
		await reloadsAfter( page, async () => {
			await page.locator( '#start-sync-btn' ).click();
			await expect( notification( page ) ).toHaveText( 'Sync is already active. Please wait or refresh the page.' );
		} );
	} );

	test( 'the second, confirmed request: refused, re-validated, not run, or dropped', async ( { page } ) => {
		let second: object | null = refused( 'no longer allowed (test)' );
		await answerAjax( page, {
			start_sync: ( fields ) => fields.get( 'confirmed' ) === '0' ? confirmation( { synced_files: 1, new_files: 0, pending_files: 1 } ) : second,
		} );
		await page.goto( SYNC );
		const run = async () => {
			await page.locator( '#start-sync-btn' ).click();
			await page.locator( '#continue-upload-btn' ).click();
		};
		await run();
		await expect( notification( page ) ).toHaveText( 'Error: no longer allowed (test)' );
		await expect( page.locator( '#sync-modal' ) ).toBeHidden();

		second = ok( { validation_failed: true, reason: 'files_not_synced', details: {} } );
		await run();
		await expect( notification( page ) ).toHaveText( 'Cannot proceed: 0 failed and 0 pending files. Please resolve them first.' );

		second = ok( { action_executed: false } );
		await run();
		await expect( notification( page ) ).toHaveText( 'Unexpected response from server' );

		second = null;
		await run();
		await expect( notification( page ) ).toHaveText( 'Connection error. Please try again.' );
	} );
} );

test.describe.serial( 'Sync: the batches', () => {
	test.beforeEach( () => {
		emptyTracking();
		configureFakeS3( 'CONFIGURED' );
		track( [ [ '/2026/10/one.png', 'synced' ], [ '/2026/10/two.png', 'pending' ] ] );
	} );

	/** Start Sync answered by the test up to the first batch, which `batch` answers. */
	async function startWith( page: Page, answers: Parameters< typeof answerAjax >[ 1 ] ): Promise< void > {
		await answerAjax( page, {
			start_sync: ( fields ) => fields.get( 'confirmed' ) === '0'
				? ok( { requires_confirmation: true, data: { total_files: 3, total_size_formatted: '12 KB', synced_files: 1, synced_size_formatted: '4 KB', new_files: 0, pending_files: 2, pending_size_formatted: '8 KB' } } )
				: ok( { action_executed: true } ),
			mark_sync_complete: ok(),
			...answers,
		} );
		await page.goto( SYNC );
		await page.locator( '#start-sync-btn' ).click();
		await page.locator( '#continue-upload-btn' ).click();
	}

	test( 'a batch the server refuses is reported with its reason, and the run stops', async ( { page } ) => {
		let batches = 0;
		await startWith( page, { process_batch: () => {
			batches++;
			return refused( { message: 'lock lost (test)' } );
		} } );
		await expect( notification( page ) ).toHaveText( 'Error processing batch: lock lost (test)' );
		await page.waitForTimeout( 1000 );
		expect( batches ).toBe( 1 );
		expect( pluginState() ).toBe( 'configured' );
	} );

	test( 'a dropped batch says it retries, retries after a second, and the sync ends normally', async ( { page } ) => {
		await startWith( page, { process_batch: ( _f, n ) => n === 1 ? null : ok( COMPLETED ) } );
		await expect( page.locator( '#sync-modal-progress-label' ) ).toHaveText( 'Connection error. Retrying in 1 s (attempt 1 of 6)...' );
		await expect( summary( page ) ).toContainText( 'Sync Completed Successfully!', { timeout: 15_000 } );
		await expect( summary( page ).locator( '.diluxone-offload-kv > div' ) ).toHaveText( [ 'Total files:3', 'Successful:3' ] );
	} );

	test( 'after six retries with a growing wait, the sync stops and says so', async ( { page } ) => {
		await page.clock.install();
		let batches = 0;
		await startWith( page, { process_batch: () => {
			batches++;
			return null;
		} } );
		const label = page.locator( '#sync-modal-progress-label' );
		const waits = [ 1, 5.656, 15.588, 32, 55.901, 88.181 ];
		for ( let attempt = 1; attempt <= 6; attempt++ ) {
			await expect( label ).toHaveText( `Connection error. Retrying in ${ waits[ attempt - 1 ] } s (attempt ${ attempt } of 6)...` );
			await page.clock.runFor( waits[ attempt - 1 ] * 1000 + 50 );
		}
		await expect( notification( page ) ).toContainText( 'Max retries exceeded. Sync stopped.' );
		await expect( notification( page ) ).toHaveClass( /notice-error/ );
		expect( batches ).toBe( 7 );
	} );

	test( 'a batch that says there is no sync any more reloads the page to show it', async ( { page } ) => {
		await reloadsAfter( page, () => startWith( page, { process_batch: ok( { status: 'error' } ) } ) );
		await expect( page.locator( '#sync-modal' ) ).toBeHidden();
	} );

	test( 'a batch that says this tab lost the sync watches the state instead; a session that expired reloads with a warning', async ( { page } ) => {
		await startWith( page, {
			process_batch: ok( { status: 'session_lost' } ),
			get_sync_state: ( _f, n ) => n === 1 ? 'continue' : ok( { state: 'expired' } ),
		} );
		await expect( notification( page ) ).toHaveText( 'Sync session expired due to inactivity. The page will reload...' );
		await expect( notification( page ) ).toHaveClass( /notice-warning/ );
		await page.waitForEvent( 'load', { timeout: 10_000 } );
	} );

	test( 'Cancel during a sync reloads even when resetting the state cannot reach the server', async ( { page } ) => {
		// The batch in flight answers after Cancel, and the reset is dropped
		// later still: the label can be read, and no batch follows the answer.
		let batches = 0;
		await startWith( page, {
			process_batch: async ( _f, n ) => {
				batches = n;
				await new Promise( ( r ) => setTimeout( r, n === 1 ? 1500 : 0 ) );
				return ok( { status: 'processing', total_files: 3, processed_files: 1, successful_uploads: 1, failed_uploads: 0, percentage: 33 } );
			},
			reset_state_to_configured: async () => {
				await new Promise( ( r ) => setTimeout( r, 2500 ) );
				return null;
			},
		} );
		await expect( page.locator( '#sync-modal-progress' ) ).toBeVisible();
		await reloadsAfter( page, async () => {
			await page.locator( '#sync-modal-cancel' ).click();
			await expect( page.locator( '#sync-modal-progress-label' ) ).toHaveText( 'Cancelling sync...' );
			await expect( page.locator( '#sync-modal-cancel' ) ).toBeDisabled();
			await expect( page.locator( '#sync-modal-progress-text' ) ).toHaveText( '1 / 3 files (33%)' );
		} );
		expect( batches, 'no batch after Cancel' ).toBe( 1 );
		expect( pluginState() ).toBe( 'configured' );
	} );

	test( 'Enable Offloading waits for the sync to be recorded before it asks to enable', async ( { page } ) => {
		const order: string[] = [];
		await startWith( page, {
			process_batch: ok( COMPLETED ),
			mark_sync_complete: async () => {
				await new Promise( ( r ) => setTimeout( r, 2000 ) );
				order.push( 'recorded' );
				return ok();
			},
			get_failed_files_count: () => {
				order.push( 'checked' );
				return ok( { failed_count: 0, pending_count: 0 } );
			},
			activate_offloading: () => {
				order.push( 'enabled' );
				return ok();
			},
		} );
		await expect( summary( page ).locator( '#enable-offloading-btn' ) ).toBeVisible();
		await reloadsAfter( page, async () => {
			await summary( page ).locator( '#enable-offloading-btn' ).click();
			await expect( notification( page ) ).toHaveText( 'Offloading enabled successfully!' );
		} );
		expect( order ).toEqual( [ 'recorded', 'checked', 'enabled' ] );
	} );
} );

test.describe.serial( 'Sync: another tab', () => {
	const META = { percentage: 40, processed_files: 4, total_files: 10, successful_uploads: 4, failed_uploads: 0 };

	test.beforeEach( () => {
		emptyTracking();
		configureFakeS3( 'CONFIGURED' );
		track( [ [ '/2026/10/one.png', 'synced' ], [ '/2026/10/two.png', 'pending' ] ] );
	} );

	/** Start Sync refused because another tab holds it; get_sync_state answered by `state` after the page's own first check. */
	async function heldElsewhere( page: Page, answers: Parameters< typeof answerAjax >[ 1 ] ): Promise< void > {
		await answerAjax( page, {
			start_sync: ok( { validation_failed: true, reason: 'sync_active_in_another_tab', details: { sync_meta: META } } ),
			...answers,
		} );
		await page.goto( SYNC );
		await page.locator( '#start-sync-btn' ).click();
		await expect( container( page ) ).toContainText( 'Sync Active in Another Tab' );
		await expect( container( page ).locator( '.diluxone-offload-kv' ) ).toHaveText( 'Progress:4 / 10 files (40.0%)' );
	}

	test( 'Continue Here refused shows why and the same choice again; dropped, it says the connection failed', async ( { page } ) => {
		let take: object | null = refused( 'held (test)' );
		// The state watch never answers, so only Continue Here changes the dialog.
		await heldElsewhere( page, {
			get_sync_state: ( _f, n ) => n === 1 ? 'continue' : 'hang',
			take_control: () => take,
		} );
		await page.locator( '#continue-here-btn' ).click();
		await expect( notification( page ) ).toHaveText( 'Failed to take control: held (test)' );
		await expect( container( page ).locator( '#continue-here-btn' ) ).toBeVisible();
		await expect( container( page ) ).toContainText( 'Progress:0 / 0 files (0.0%)' );

		take = null;
		await page.locator( '#continue-here-btn' ).click();
		await expect( notification( page ) ).toHaveText( 'Connection error while taking control.' );
		await expect( container( page ) ).toBeEmpty();
	} );

	test( 'Continue Here taken: the progress comes even when reading it fails, and the batches run to the summary', async ( { page } ) => {
		await heldElsewhere( page, {
			get_sync_state: ( _f, n ) => n === 1 ? 'continue' : ( n === 2 ? ok( { state: 'inactive', sync_meta: META } ) : null ),
			take_control: ok(),
			process_batch: ok( COMPLETED ),
			mark_sync_complete: ok(),
		} );
		await page.locator( '#continue-here-btn' ).click();
		await expect( summary( page ) ).toContainText( 'Sync Completed Successfully!' );
		await expect( summary( page ).locator( '#later-btn' ) ).toBeVisible();
	} );

	test( 'the watched state: the sync gone reloads; this tab handed the sync back runs it', async ( { page } ) => {
		// The first look fails (and is only logged); the next, five seconds on, finds the sync gone.
		await heldElsewhere( page, { get_sync_state: ( _f, n ) => n === 1 ? 'continue' : ( n === 2 ? null : ok( { state: 'no_sync' } ) ) } );
		await expect( container( page ) ).toContainText( 'Sync Active in Another Tab' );
		await expect( page.locator( '#sync-modal' ) ).toBeHidden( { timeout: 15_000 } );
		await page.waitForEvent( 'load', { timeout: 10_000 } );

		await page.unrouteAll( { behavior: 'ignoreErrors' } );
		let batches = 0;
		await heldElsewhere( page, {
			get_sync_state: ( _f, n ) => n === 1 ? 'continue' : ok( { state: 'active' } ),
			process_batch: () => {
				batches++;
				return ok( COMPLETED );
			},
			mark_sync_complete: ok(),
		} );
		await expect( summary( page ) ).toContainText( 'Sync Completed Successfully!' );
		expect( batches ).toBe( 1 );
	} );

	// The other tab's sync ended without a file uploaded or failed: the summary
	// says it failed, with the server's reason, and Close reloads.
	test( 'a sync the other tab ended without uploading anything shows Sync Failed with its reason, and Close reloads', async ( { page } ) => {
		await heldElsewhere( page, {
			get_sync_state: ( _f, n ) => n === 1 ? 'continue' : ok( { state: 'terminated', sync_meta: { status: 'failed', total_files: 3, processed_files: 0, successful_uploads: 0, failed_uploads: 0, message: 'stopped by the other tab (test)' } } ),
		} );
		await expect( summary( page ) ).toContainText( 'Sync Failed' );
		await expect( summary( page ) ).toContainText( 'stopped by the other tab (test)' );
		await expect( summary( page ).locator( '.diluxone-offload-outcome--failed' ) ).toBeVisible();
		await reloadsAfter( page, () => summary( page ).locator( '#sync-complete-close-btn' ).click() );
		expect( pluginState() ).toBe( 'configured' );
	} );

	test( 'Reset Sync while another tab holds the sync shows that tab\'s progress instead of the reset dialog', async ( { page } ) => {
		await answerAjax( page, { get_sync_state: ( _f, n ) => n === 1 ? 'continue' : ok( { state: 'inactive', sync_meta: META } ) } );
		await page.goto( SYNC );
		await page.locator( '#cancel-all-sync-btn' ).click();
		await expect( container( page ) ).toContainText( 'Sync Active in Another Tab' );
		await expect( page.locator( '#cancel-sync-modal' ) ).toBeHidden();
	} );

	test( 'Reset Sync when the state cannot be read, or the server cannot be reached, says so; a dropped reset offers Close', async ( { page } ) => {
		let state: object | null = refused( null );
		await answerAjax( page, { get_sync_state: ( _f, n ) => n === 1 ? 'continue' : state, cancel_sync: null } );
		await page.goto( SYNC );
		await page.locator( '#cancel-all-sync-btn' ).click();
		await expect( notification( page ) ).toHaveText( 'Error checking sync state. Please refresh the page.' );
		await expect( page.locator( '#sync-modal' ) ).toBeHidden();

		state = null;
		await page.locator( '#cancel-all-sync-btn' ).click();
		await expect( notification( page ) ).toHaveText( 'Connection error. Please refresh the page.' );

		state = ok( { state: 'no_sync' } );
		await page.locator( '#cancel-all-sync-btn' ).click();
		await page.locator( '#confirm-cancel-sync' ).click();
		await expect( container( page ) ).toContainText( 'Connection error while cancelling sync' );
		await container( page ).locator( '.diluxone-offload-modal-close' ).click();
		await expect( page.locator( '#sync-modal' ) ).toBeHidden();
		expect( pluginState() ).toBe( 'configured' );
	} );
} );

test.describe.serial( 'Sync: a page opened while the plugin is syncing', () => {
	// A sync that beat a moment ago: the screen keeps it (one silent for 90
	// seconds is reset to Synced on load).
	test.beforeEach( () => {
		emptyTracking();
		configureFakeS3( 'CONFIGURED' );
		track( [ [ '/2026/10/one.png', 'synced' ], [ '/2026/10/two.png', 'pending' ] ] );
		wp( [ 'eval', "update_option( 'diluxone_offload_sync_meta', array( 'last_heartbeat' => time() ) );" ] );
		setState( 'SYNCING' );
	} );
	test.afterAll( () => {
		wp( [ 'eval', "delete_option( 'diluxone_offload_sync_meta' );" ] );
		setState( 'CONFIGURED' );
	} );

	test( 'this tab still holds the sync (a refresh): the progress shows and the batches go on', async ( { page } ) => {
		await answerAjax( page, {
			get_sync_state: ok( { state: 'active', sync_meta: { percentage: 50, processed_files: 1, total_files: 2, successful_uploads: 1, failed_uploads: 0 } } ),
			process_batch: 'hang',
		} );
		await page.goto( SYNC );
		await expect( page.locator( '#sync-modal-progress' ) ).toBeVisible();
		await expect( page.locator( '#sync-modal-progress-text' ) ).toHaveText( '1 / 2 files (50%)' );
		await expect( page.locator( '#sync-modal-progress-bar' ) ).toHaveAttribute( 'style', /width: 50%/ );
	} );

	for ( const [ name, answer ] of [ [ 'a sync that just ended', ok( { state: 'terminated' } ) ], [ 'no sync at all', ok( { state: 'no_sync' } ) ], [ 'a state that cannot be read', null ] ] as const ) {
		test( `the waiting dialog shows while the state is read, and ${ name } takes it away`, async ( { page } ) => {
			await answerAjax( page, { get_sync_state: async () => {
				await new Promise( ( r ) => setTimeout( r, 1500 ) );
				return answer;
			} } );
			await page.goto( SYNC );
			await expect( page.locator( '.wrap.diluxone-offload-admin' ) ).toContainText( 'Sync in Progress' );
			await expect( container( page ) ).toContainText( 'Analyzing Sync Status' );
			await expect( container( page ) ).toContainText( 'Checking for active synchronization...' );
			await expect( page.locator( '#sync-modal' ) ).toBeHidden();
			await expect( container( page ) ).toBeEmpty();
		} );
	}
} );

test.describe.serial( 'Sync: synced, with failed files', () => {
	test.beforeEach( () => {
		emptyTracking();
		configureFakeS3( 'SYNCED' );
		track( [ [ '/2026/10/one.png', 'synced' ], [ '/2026/10/bad.png', 'failed' ] ] );
	} );
	test.afterEach( () => expect( pluginState() ).toBe( 'synced' ) );

	test( 'Retry Failed Files: new files are listed apart; a refused or dropped calculation closes with the reason', async ( { page } ) => {
		let answer: object | null = ok( { pending_files: 1, pending_size: 4096, pending_size_formatted: '4 KB', new_files: 2, new_files_size: 2048, new_files_size_formatted: '2 KB' } );
		await answerAjax( page, { calculate_sync: () => answer } );
		await page.goto( SYNC );
		const retry = page.locator( '.retry-failed-btn' ).first();
		await retry.click();
		await expect( summary( page ).locator( '.diluxone-offload-kv > div' ) ).toHaveText( [ 'Failed files to retry:3 (6 KB)', 'Previously failed:1 (4 KB)', 'New files found:2 (2 KB)' ] );
		await summary( page ).locator( '#sync-modal-cancel' ).click();
		await expect( page.locator( '#sync-modal' ) ).toBeHidden();

		answer = ok( { pending_files: 0, pending_size: 0, new_files: 0, new_files_size: 0 } );
		await retry.click();
		await expect( summary( page ).locator( '.diluxone-offload-kv > div' ) ).toHaveText( [ 'Failed files to retry:0 (0 B)' ] );
		await summary( page ).locator( '#sync-modal-cancel' ).click();

		answer = refused( null );
		await retry.click();
		await expect( notification( page ) ).toHaveText( 'Error calculating failed files' );
		await expect( page.locator( '#sync-modal' ) ).toBeHidden();
		await expect( retry ).toBeEnabled();

		answer = null;
		await retry.click();
		await expect( notification( page ) ).toHaveText( 'Connection error' );
		await expect( retry ).toBeEnabled();
	} );

	test( 'Clear Failed & Enable: a refused or dropped discard, and a dropped enable, each show their message in the dialog', async ( { page } ) => {
		let discard: object | null = refused( null );
		await answerAjax( page, { discard_failed_files: () => discard, activate_offloading: null } );
		await page.goto( SYNC );
		const message = page.locator( '#clear-enable-error-message' );
		const once = async ( expected: string ) => {
			await page.locator( '#discard-and-enable-static-btn' ).click();
			await page.locator( '#confirm-clear-and-enable' ).click();
			await expect( page.locator( '#clear-enable-error-view' ) ).toBeVisible();
			await expect( message ).toHaveText( expected );
			await page.locator( '#clear-enable-error-view .close-clear-enable-modal' ).click();
			await expect( page.locator( '#clear-and-enable-modal' ) ).toBeHidden();
		};
		await once( 'Failed to discard files' );
		discard = null;
		await once( 'Connection error' );
		discard = ok();
		await once( 'Connection error while enabling offloading' );
		expect( pluginState() ).toBe( 'synced' );
	} );

	test( 'Resync All that cannot reach the server closes its dialog and says so', async ( { page } ) => {
		await answerAjax( page, { prepare_resync: null } );
		wp( [ 'eval', 'global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->prefix}diluxone_offload_files WHERE errors > 0" );' ] );
		await page.goto( SYNC );
		await page.locator( '.resync-all-btn' ).click();
		await page.locator( '#resync-confirm-btn' ).click();
		await expect( page.locator( '#sync-modal' ) ).toBeHidden();
		await expect( notification( page ) ).toHaveText( 'Connection error' );
	} );

	test( 'Enable Offloading on the Offloading tab: failed and pending files block it; a refused check lets the server decide', async ( { page } ) => {
		let check: object | null = ok( { failed_count: 2, pending_count: 3 } );
		let activate: object | null = refused( 'refused (test)' );
		await answerAjax( page, { get_failed_files_count: () => check, activate_offloading: () => activate } );
		wp( [ 'eval', 'global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->prefix}diluxone_offload_files WHERE errors > 0" );' ] );
		await page.goto( OFFLOADING );
		const enable = page.locator( '#enable-offloading-btn' );
		const original = await enable.innerHTML();
		await enable.click();
		await expect( notification( page ) ).toHaveText( 'Cannot enable offloading: 2 failed files and 3 pending files. Please resolve errors first using "Clear Failed & Enable" or retry failed files.' );

		check = ok( { failed_count: 0, pending_count: 1 } );
		await enable.click();
		await expect( notification( page ) ).toHaveText( /^Cannot enable offloading: 1 pending files\. / );

		check = refused( null );
		await enable.click();
		await expect( notification( page ) ).toHaveText( 'Error: refused (test)' );
		await expect( enable ).toBeEnabled();
		expect( await enable.innerHTML() ).toBe( original );

		check = null;
		activate = null;
		await enable.click();
		await expect( notification( page ) ).toHaveText( 'Connection error' );
		await expect( enable ).toBeEnabled();
	} );
} );

test.describe.serial( 'Offloading active', () => {
	test.beforeEach( () => {
		emptyTracking();
		configureFakeS3( 'OFFLOADING_ACTIVE' );
		track( [ [ '/2026/10/one.png', 'synced' ], [ '/2026/10/two.png', 'synced' ], [ '/2026/10/bad.png', 'failed' ] ] );
	} );
	test.afterEach( () => expect( pluginState() ).toBe( 'offloading_active' ) );

	test( 'Clear List refused or dropped says so, and the button comes back', async ( { page } ) => {
		let answer: object | null = refused( 'kept (test)' );
		await answerAjax( page, { clear_failed: () => answer } );
		await page.goto( SYNC );
		const clear = page.locator( '.clear-failed-btn' ).first();
		page.once( 'dialog', ( d ) => void d.accept() );
		await clear.click();
		await expect( notification( page ) ).toHaveText( 'Error: kept (test)' );
		await expect( clear ).toBeEnabled();
		await expect( clear ).toHaveText( 'Clear List' );

		answer = null;
		page.once( 'dialog', ( d ) => void d.accept() );
		await clear.click();
		await expect( notification( page ) ).toHaveText( 'Connection error' );
		await expect( clear ).toHaveText( 'Clear List' );
	} );

	test( 'Delete Local Files: the numbers that cannot be read show as 0 or Error, and Start stays possible', async ( { page } ) => {
		let stats: object | null = refused( null );
		await answerAjax( page, { get_deletable_stats: () => stats } );
		await page.goto( OFFLOADING );
		await page.locator( '#delete-local-files-btn' ).click();
		await expect( page.locator( '#delete-modal-total-files' ) ).toHaveText( '0' );
		await expect( page.locator( '#delete-modal-total-size' ) ).toHaveText( '0 B' );
		await expect( page.locator( '#delete-modal-start' ) ).toBeEnabled();
		await page.locator( '#delete-modal-cancel' ).click();

		stats = null;
		await page.locator( '#delete-local-files-btn' ).click();
		await expect( page.locator( '#delete-modal-total-files' ) ).toHaveText( 'Error' );
		await expect( page.locator( '#delete-modal-total-size' ) ).toHaveText( 'Error' );
		await page.locator( '#delete-modal-cancel' ).click();
		await expect( page.locator( '#delete-modal' ) ).toBeHidden();
	} );

	test( 'Delete Local Files: a refused batch, a dropped batch, and a run with failures each end as they should', async ( { page } ) => {
		let batch: Parameters< typeof answerAjax >[ 1 ][ string ] = refused( { message: 'not now (test)' } );
		await answerAjax( page, { get_deletable_stats: ok( { files: 4, size_formatted: '16 KB' } ), process_delete_batch: ( f, n ) => typeof batch === 'function' ? batch( f, n ) : batch } );
		await page.goto( OFFLOADING );
		const open = async () => {
			await page.locator( '#delete-local-files-btn' ).click();
			await expect( page.locator( '#delete-modal-total-files' ) ).toHaveText( '4' );
			await page.locator( '#delete-modal-start' ).click();
		};
		await open();
		await expect( notification( page ) ).toHaveText( 'Error: not now (test)' );
		await expect( page.locator( '#delete-modal' ) ).toBeHidden();

		batch = null;
		await open();
		await expect( notification( page ) ).toHaveText( 'Connection error. Deletion interrupted.' );
		await expect( page.locator( '#delete-modal' ) ).toBeHidden();

		let round = 0;
		batch = () => ++round === 1 ? ok( { deleted_this_batch: 2, failed_this_batch: 1, status: 'processing' } ) : ok( { deleted_this_batch: 1, failed_this_batch: 0, status: 'completed' } );
		await open();
		const done = page.locator( '#delete-modal-summary' );
		await expect( done ).toContainText( 'Deletion Completed with Errors' );
		await expect( done.locator( '.diluxone-offload-kv > div' ) ).toHaveText( [ 'Total files:4', 'Deleted:3', 'Failed:1' ] );
		await expect( page.locator( '#delete-modal-cancel' ) ).toBeHidden();
		await expect( page.locator( '#delete-modal-progress-text' ) ).toHaveText( '4 / 4 (100%)' );
		await reloadsAfter( page, () => done.locator( '#delete-accept-btn' ).click() );
	} );

	test( 'Delete Local Files: Cancel while a batch runs stops asking for more', async ( { page } ) => {
		let batches = 0;
		await answerAjax( page, {
			get_deletable_stats: ok( { files: 4, size_formatted: '16 KB' } ),
			process_delete_batch: async () => {
				batches++;
				await new Promise( ( r ) => setTimeout( r, 1500 ) );
				return ok( { deleted_this_batch: 1, failed_this_batch: 0, status: 'processing' } );
			},
		} );
		await page.goto( OFFLOADING );
		await page.locator( '#delete-local-files-btn' ).click();
		await expect( page.locator( '#delete-modal-total-files' ) ).toHaveText( '4' );
		await page.locator( '#delete-modal-start' ).click();
		await expect( page.locator( '#delete-modal-progress' ) ).toBeVisible();
		await page.locator( '#delete-modal-cancel' ).click();
		await expect( page.locator( '#delete-modal' ) ).toBeHidden();
		await page.waitForTimeout( 3000 );
		expect( batches ).toBe( 1 );
	} );
} );

test.describe.serial( 'Disconnect', () => {
	test.beforeEach( () => {
		emptyTracking();
		configureFakeS3( 'OFFLOADING_ACTIVE' );
		track( [ [ '/2026/10/one.png', 'synced' ], [ '/2026/10/two.png', 'synced' ] ] );
	} );
	test.afterEach( () => expect( pluginState() ).toBe( 'offloading_active' ) );

	const error = ( page: Page ) => page.locator( '#disconnect-error-message' );
	const confirm = async ( page: Page ) => {
		await page.locator( '#disconnect-from-cloud-btn' ).click();
		await page.locator( '#confirm-disconnect' ).click();
	};
	const closeError = async ( page: Page ) => {
		await page.locator( '#disconnect-error-view .close-disconnect-modal' ).click();
		await expect( page.locator( '#disconnect-modal' ) ).toBeHidden();
	};
	const CALC = ok( { pending: 2, total_cloud: 3, total_size_formatted: '12 KB', already_local: 1, local_size_formatted: '4 KB', pending_size_formatted: '8 KB' } );

	test( 'the scan and the calculation: refused or dropped, each shows its message in the dialog', async ( { page } ) => {
		let scan: object | null = null;
		let calc: object | null = refused( null );
		await answerAjax( page, { scan_remote: () => scan, calculate_download: () => calc } );
		await page.goto( DISCONNECT );
		await confirm( page );
		await expect( error( page ) ).toHaveText( 'Connection error while scanning cloud storage' );
		await closeError( page );

		scan = refused( null );
		await confirm( page );
		await expect( error( page ) ).toHaveText( 'Failed to scan cloud storage' );
		await closeError( page );

		scan = ok();
		await confirm( page );
		await expect( error( page ) ).toHaveText( 'Failed to calculate download requirements' );
		await closeError( page );

		calc = null;
		await confirm( page );
		await expect( error( page ) ).toHaveText( 'Connection error while calculating downloads' );
		await closeError( page );
		// Opened again, the dialog starts from its first question.
		await page.locator( '#disconnect-from-cloud-btn' ).click();
		await expect( page.locator( '#disconnect-confirm-view' ) ).toBeVisible();
		await expect( page.locator( '#disconnect-error-view' ) ).toBeHidden();
	} );

	test( 'nothing to download but the switch-off dropped: the dialog says to disable it by hand', async ( { page } ) => {
		await answerAjax( page, { scan_remote: ok(), calculate_download: ok( { pending: 0, total_cloud: 2 } ), deactivate_offloading: null } );
		await page.goto( DISCONNECT );
		await confirm( page );
		await expect( error( page ) ).toHaveText( 'Files are already local but failed to disable offloading. Please disable manually.' );
	} );

	test( 'Force Disconnect refused or dropped gives the button back with the reason', async ( { page } ) => {
		let force: object | null = refused( 'kept (test)' );
		await answerAjax( page, { scan_remote: refused( 'no listing (test)' ), deactivate_offloading: () => force } );
		await page.goto( DISCONNECT );
		await confirm( page );
		await expect( error( page ) ).toHaveText( 'no listing (test)' );
		const btn = page.locator( '#force-disconnect-btn' );
		await btn.click();
		await expect( error( page ) ).toHaveText( 'kept (test)' );
		await expect( btn ).toBeEnabled();
		await expect( btn ).toHaveText( 'Force Disconnect Without Sync' );

		force = null;
		await btn.click();
		await expect( error( page ) ).toHaveText( 'Connection error' );
		await expect( btn ).toBeEnabled();
	} );

	test( 'starting and running the download: refused or dropped, each shows its message', async ( { page } ) => {
		let start: object | null = refused( 'busy (test)' );
		let batch: object | null = refused( 'gone (test)' );
		await answerAjax( page, { scan_remote: ok(), calculate_download: CALC, start_reverse_sync: () => start, process_reverse_batch: () => batch } );
		await page.goto( DISCONNECT );
		const run = async ( expected: string ) => {
			await confirm( page );
			await expect( page.locator( '#disconnect-stats .diluxone-offload-kv > div' ) ).toHaveText( [ 'Total files in cloud:3 (12 KB)', 'Already local:1 (4 KB)', 'Pending download:2 (8 KB)' ] );
			await page.locator( '#start-disconnect' ).click();
			await expect( error( page ) ).toHaveText( expected );
			await closeError( page );
		};
		await run( 'Failed to start download: busy (test)' );
		start = null;
		await run( 'Connection error while starting download' );
		start = ok();
		await run( 'Download failed: gone (test)' );
		batch = null;
		await run( 'Connection error during download' );
	} );

	test( 'a download that ends with failed and skipped files keeps offloading on, lists them, and Close reloads', async ( { page } ) => {
		await answerAjax( page, {
			scan_remote: ok(),
			calculate_download: CALC,
			start_reverse_sync: ok(),
			process_reverse_batch: ok( { status: 'completed', total_files: 3, processed_files: 1, failed: 1, skipped: 1, remaining_files: 0 } ),
		} );
		await page.goto( DISCONNECT );
		await confirm( page );
		await page.locator( '#start-disconnect' ).click();
		const dialog = page.locator( '#disconnect-modal .diluxone-offload-modal__dialog' );
		await expect( dialog ).toContainText( 'Download Completed with Errors' );
		await expect( dialog.locator( '.diluxone-offload-kv > div' ) ).toHaveText( [ 'Total files:3', 'Downloaded:1', 'Failed:1', 'Skipped:1' ] );
		await reloadsAfter( page, () => dialog.locator( '.close-disconnect-modal' ).click() );
		await expect( page.locator( '#disconnect-from-cloud-btn' ) ).toBeVisible();
	} );

	test( 'a batch that stops reporting progress with nothing left completes; switching off dropped is reported', async ( { page } ) => {
		await answerAjax( page, {
			scan_remote: ok(),
			calculate_download: CALC,
			start_reverse_sync: ok(),
			process_reverse_batch: ok( { status: 'idle', total_files: 2, processed_files: 2, remaining_files: 0 } ),
			deactivate_offloading: null,
		} );
		await page.goto( DISCONNECT );
		await confirm( page );
		await page.locator( '#start-disconnect' ).click();
		await expect( error( page ) ).toHaveText( 'Files downloaded but failed to disable offloading. Please disable manually.' );
		await expect( page.locator( '#disconnect-progress-text' ) ).toHaveText( '2 / 2 files (100%)' );
	} );
} );

test.describe.serial( 'DEV MODE buttons', () => {
	test.beforeAll( () => muPlugin( 'dev-mode', "define( 'DILUXONE_OFFLOAD_DEV_MODE', true );" ) );
	test.afterAll( () => muPlugin( 'dev-mode', null ) );

	test( 'Enable Without Sync refused or dropped gives the button back', async ( { page } ) => {
		emptyTracking();
		configureFakeS3( 'CONFIGURED' );
		let answer: object | null = refused( 'no (test)' );
		await answerAjax( page, { dev_enable_without_sync: () => answer } );
		await page.goto( SYNC );
		const btn = page.locator( '#dev-enable-without-sync-btn' );
		const original = await btn.innerHTML();
		page.once( 'dialog', ( d ) => void d.accept() );
		await btn.click();
		await expect( notification( page ) ).toHaveText( 'Error: no (test)' );
		await expect( btn ).toBeEnabled();
		expect( await btn.innerHTML() ).toBe( original );
		answer = null;
		page.once( 'dialog', ( d ) => void d.accept() );
		await btn.click();
		await expect( notification( page ) ).toHaveText( 'Connection error' );
		expect( pluginState() ).toBe( 'configured' );
	} );

	test( 'Disconnect Without Sync: dismissed it does nothing; refused or dropped it gives the button back', async ( { page } ) => {
		configureFakeS3( 'OFFLOADING_ACTIVE' );
		let calls = 0;
		let answer: object | null = refused( 'no (test)' );
		await answerAjax( page, { dev_disconnect_without_sync: () => {
			calls++;
			return answer;
		} } );
		await page.goto( DISCONNECT );
		const btn = page.locator( '#dev-disconnect-without-sync-btn' );
		const asked = dialogText( page, false );
		await btn.click();
		expect( await asked ).toContain( 'DEV MODE: Disconnect without downloading files?' );
		expect( calls ).toBe( 0 );
		page.once( 'dialog', ( d ) => void d.accept() );
		await btn.click();
		await expect( notification( page ) ).toHaveText( 'Error: no (test)' );
		await expect( btn ).toBeEnabled();
		answer = null;
		page.once( 'dialog', ( d ) => void d.accept() );
		await btn.click();
		await expect( notification( page ) ).toHaveText( 'Connection error' );
		expect( pluginState() ).toBe( 'offloading_active' );
	} );
} );

test.describe.serial( 'A finished sync, revisited later', () => {
	test.afterAll( () => {
		wp( [ 'eval', "delete_option( 'diluxone_offload_sync_meta' );" ] );
	} );

	// The Sync tab asks diluxone_offload_get_sync_state on every load. A sync
	// that finished keeps its metadata (status "completed", its last heartbeat
	// frozen); more than 90 seconds later the handler's heartbeat-expiry check
	// (Plugin_Enhanced::ajax_get_sync_state, before the "terminated" check)
	// takes it for an abandoned sync and sets the plugin to CONFIGURED. Just
	// opening the Sync tab then forgets a finished sync and, with offloading
	// on, turns offloading off. Seen in the journey too, when its tests take
	// longer than 90 s ("synced: every file is in the bucket…" finds
	// "configured").
	for ( const state of [ 'SYNCED', 'OFFLOADING_ACTIVE' ] as const ) {
		test.fixme( `opening the Sync tab two minutes after a sync finished leaves the plugin ${ state.toLowerCase() }`, async ( { page } ) => {
			emptyTracking();
			configureFakeS3( state );
			track( [ [ '/2026/10/one.png', 'synced' ] ] );
			wp( [ 'eval', "update_option( 'diluxone_offload_sync_meta', array( 'sync_session_id' => 'sync_e2e_old_tab', 'status' => 'completed', 'start_time' => time() - 300, 'end_time' => time() - 120, 'last_heartbeat' => time() - 120, 'total_files' => 1 ), false );" ] );
			const checked = page.waitForResponse( ( r ) => ( r.request().postData() ?? '' ).includes( 'action=diluxone_offload_get_sync_state' ) );
			await page.goto( SYNC );
			await checked;
			expect( pluginState() ).toBe( state.toLowerCase() );
		} );
	}
} );
