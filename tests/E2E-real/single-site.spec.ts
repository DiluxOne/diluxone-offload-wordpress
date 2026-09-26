import { test, expect, request } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { readRun, RealRun, listKeys, blobExists, blobMd5, fileMd5, createPrivateContainer, deleteNamedContainer } from './helpers/azure';
import { BASE_URL, wp, shell, pluginState, nativeUploadsDir, filesUnder, md5Inside, attachmentUrl, attachedFile, REPO_IN_CONTAINER } from './helpers/wp';
import { FIXTURES, DISK_FIXTURES, FIXTURE_DIR, generateFixtures, seedMediaLibrary, placeDiskFixtures } from './helpers/fixtures';
import * as ui from './helpers/plugin';

/**
 * One user, one single site, every screen of the plugin, against a real
 * container: configure, sync, cancel, reset, fail on a bad key and retry,
 * offload, upload through the Media Library, delete the local copies,
 * cancel and resume a Disconnect, disconnect, rotate the key, resync,
 * remove the provider, uninstall. Every transfer is checked byte for byte
 * on the other side.
 */
const site = 'single';
const base = BASE_URL.single;

test.describe.serial( 'single site journey', () => {
	let run: RealRun;
	let uploadsDir = '';
	let seeded: number[] = [];
	let uiUploadId = 0;
	let wrongKey = '';

	test.beforeAll( () => {
		run = readRun( 'single' );
		uploadsDir = nativeUploadsDir( site );
		wrongKey = Buffer.from( 'not the key, but the right shape for one....................' ).toString( 'base64' );
	} );

	test( 'every screen and tab renders before anything is configured', async ( { page } ) => {
		for ( const view of Object.keys( ui.VIEWS ) ) {
			await ui.goTab( page, base, view );
		}
		await ui.goTab( page, base, 'overview' );
		await expect( page.locator( '.wrap.diluxone-offload-admin' ) ).toContainText( /Not Configured/ );
	} );

	test( 'a wrong key is refused and Save stays disabled', async ( { page } ) => {
		await ui.goTab( page, base, 'connection' );
		const result = await ui.testConnection( page, { account: run.account, key: wrongKey, container: run.container } );
		expect( result ).not.toMatch( /success/i );
		await expect( page.locator( '#submit' ) ).toBeDisabled();
	} );

	test( 'a container that does not exist is refused', async ( { page } ) => {
		await ui.goTab( page, base, 'connection' );
		const result = await ui.testConnection( page, { account: run.account, key: run.key, container: `${ run.container }-missing` } );
		expect( result ).not.toMatch( /success/i );
		await expect( page.locator( '#submit' ) ).toBeDisabled();
	} );

	test( 'a private container is refused with an explanation, even with the right key', async ( { page } ) => {
		const name = `${ run.container }-private`;
		await createPrivateContainer( run, name );
		try {
			await ui.goTab( page, base, 'connection' );
			const result = await ui.testConnection( page, { account: run.account, key: run.key, container: name } );
			expect( result ).toMatch( /private/i );
			expect( result ).toMatch( /public access level/i );
			await expect( page.locator( '#submit' ) ).toBeDisabled();
		} finally {
			await deleteNamedContainer( run, name );
		}
	} );

	test( 'the right credentials pass Test Connection and save', async ( { page } ) => {
		await ui.goTab( page, base, 'connection' );
		const warning = page.locator( '#azure-config .test-status-message' );
		// A failed test keeps the "test before saving" warning and Save disabled...
		expect( await ui.testConnection( page, { account: run.account, key: wrongKey, container: run.container } ) ).not.toMatch( /success/i );
		await expect( warning ).toBeVisible();
		await expect( page.locator( '#submit' ) ).toBeDisabled();
		// ...a passing one clears the warning and enables Save.
		const result = await ui.testConnection( page, { account: run.account, key: run.key, container: run.container } );
		expect( result ).toMatch( /success/i );
		await expect( warning ).toBeHidden();
		await ui.saveProvider( page );
		await expect( page.locator( '#provider-info' ) ).toContainText( run.account );
		await expect( page.locator( '#provider-info' ) ).toContainText( run.container );
		expect( pluginState( site ) ).toBe( 'configured' );
		await ui.goTab( page, base, 'overview' );
		await expect( page.locator( '.wrap.diluxone-offload-admin' ) ).toContainText( /Configured/ );
	} );

	test( 'settings round-trip through the three tabs and into the option', async ( { page } ) => {
		await ui.goTab( page, base, 'transfers' );
		await page.locator( '#timeout' ).fill( '120' );
		await page.locator( '#max_file_size' ).fill( '100' );
		await page.getByRole( 'button', { name: /Save Settings/ } ).click();
		await expect( page.locator( '.notice-success, .updated' ).first() ).toBeVisible();
		await ui.goTab( page, base, 'logging' );
		await page.locator( 'input[name="enable_debug_logging"]' ).check();
		await page.getByRole( 'button', { name: /Save Settings/ } ).click();
		await expect( page.locator( '.notice-success, .updated' ).first() ).toBeVisible();
		await ui.goTab( page, base, 'serving' );
		await page.locator( 'input[name="force_https_on_cloud"]' ).check();
		await page.getByRole( 'button', { name: /Save Settings/ } ).click();
		await expect( page.locator( '.notice-success, .updated' ).first() ).toBeVisible();
		await ui.goTab( page, base, 'transfers' );
		await expect( page.locator( '#timeout' ) ).toHaveValue( '120' );
		await expect( page.locator( '#max_file_size' ) ).toHaveValue( '100' );
		await ui.goTab( page, base, 'logging' );
		await expect( page.locator( 'input[name="enable_debug_logging"]' ) ).toBeChecked();
		const config = JSON.parse( wp( site, [ 'option', 'get', 'diluxone_offload_config', '--format=json' ] ) );
		expect( config.timeout ).toBe( 120 );
		expect( config.max_file_size ).toBe( 100 * 1048576 );
		expect( config.debug_enabled ).toBe( true );
		expect( config.force_https_on_cloud ).toBe( true );
	} );

	test( 'the media library is seeded with every size and kind', async () => {
		generateFixtures();
		seeded = seedMediaLibrary( site );
		expect( seeded.length ).toBe( FIXTURES.length );
		// A file that never went through WordPress's name sanitising, the way an
		// FTP upload or a migration leaves them: spaces, an accent, parentheses.
		const subdir = wp( site, [ 'eval', 'echo wp_upload_dir()["subdir"];' ] );
		shell( site, `cp "${ REPO_IN_CONTAINER }/build/real-fixtures/café photo 3900k.png" "${ uploadsDir }${ subdir }/legacy café (1).png"` );
		// And the ones WordPress would have refused or renamed: empty, without
		// an extension, with characters sanitize_file_name() strips.
		const placed = placeDiskFixtures( site, uploadsDir, subdir );
		const local = filesUnder( site, uploadsDir );
		// Originals plus the thumbnails WordPress made of the PNGs, plus the files put on disk.
		expect( local.length ).toBeGreaterThan( FIXTURES.length + DISK_FIXTURES.length );
		expect( local ).toContain( `${ subdir.replace( /^\//, '' ) }/legacy café (1).png` );
		for ( const rel of placed ) expect( local ).toContain( rel );
	} );

	test( 'a sync can be cancelled and then reset to a clean start', async ( { page } ) => {
		await ui.goTab( page, base, 'sync' );
		await ui.startSyncAndCancel( page );
		expect( pluginState( site ) ).toBe( 'configured' );
		expect( ( await listKeys( run, 'uploads/' ) ).length ).toBeGreaterThan( 0 );
		await ui.resetSync( page );
		expect( pluginState( site ) ).toBe( 'configured' );
	} );

	test( 'a key that stops working fails the sync visibly, and Retry finishes it once fixed', async ( { page } ) => {
		// Swap the stored key for a wrong one the way a rotated key would look.
		wp( site, [ 'eval', `$c = \\DiluxOneOffload\\ConfigManager::get_current_provider_config(); $c['access_key'] = '${ wrongKey }'; \\DiluxOneOffload\\ConfigManager::save_config( array( 'cloud_provider' => 'azure', 'provider_config' => $c ) );` ] );
		await ui.goTab( page, base, 'sync' );
		const outcome = await ui.runSyncToCompletion( page, 'scratch' );
		expect( outcome ).not.toBe( 'success' );
		await page.locator( '#accept-errors-btn, #sync-complete-close-btn' ).first().click();
		await page.waitForURL( ui.onScreen( 'sync' ) );
		await expect( page.locator( '.wrap.diluxone-offload-admin' ) ).toContainText( /Synced with Errors/ );

		await page.locator( '.view-failed-btn' ).first().click();
		await expect( page.locator( '#failed-files-modal' ) ).toBeVisible();
		await expect( page.locator( '#failed-files-modal tbody tr' ).first() ).toContainText( /403|Forbidden|Upload failed/i );
		await page.locator( '#close-failed-modal' ).click();

		// The real key comes back and the failed files are retried, nothing else.
		wp( site, [ 'eval', `$c = \\DiluxOneOffload\\ConfigManager::get_current_provider_config(); $c['access_key'] = '${ run.key }'; \\DiluxOneOffload\\ConfigManager::save_config( array( 'cloud_provider' => 'azure', 'provider_config' => $c ) );` ] );
		await ui.goTab( page, base, 'sync' );
		expect( await ui.retryFailedFiles( page ) ).toBe( 'success' );
		await page.locator( '#later-btn' ).click();
		await page.waitForURL( ui.onScreen( 'sync' ) );
		expect( pluginState( site ) ).toBe( 'synced' );
		await ui.resetSync( page );
	} );

	test( 'the initial sync puts every local file in the container, byte for byte', async ( { page } ) => {
		await ui.goTab( page, base, 'sync' );
		expect( await ui.runSyncToCompletion( page, 'scratch' ) ).toBe( 'success' );
		const local = filesUnder( site, uploadsDir );
		const keys = await listKeys( run, 'uploads/' );
		for ( const rel of local ) {
			// An empty file is skipped by the scan, on purpose, and reported as
			// such: nothing to serve, nothing to upload. It stays where it is.
			if ( rel.endsWith( '/empty-0b.txt' ) ) {
				expect( keys, `${ rel } is not uploaded` ).not.toContain( `uploads/${ rel }` );
				continue;
			}
			expect( keys, `${ rel } is in the container` ).toContain( `uploads/${ rel }` );
			expect( await blobMd5( run, `uploads/${ rel }` ), `${ rel } bytes` ).toBe( md5Inside( site, `${ uploadsDir }/${ rel }` ) );
		}
		await ui.enableOffloadingFromModal( page );
		expect( pluginState( site ) ).toBe( 'offloading_active' );

		// Through the wrapper, the names WordPress never sanitised — spaces,
		// an accent, a quote, an ampersand, no extension — and the empty file
		// are readable files like any other, at their exact size.
		// Sizes from the files themselves: a generated PNG is only roughly its requested size.
		const expectedSize = new Map( [ [ 'legacy café (1).png', fs.statSync( path.join( FIXTURE_DIR, 'café photo 3900k.png' ) ).size ], ...DISK_FIXTURES.filter( ( f ) => f.bytes > 0 ).map( ( f ): [ string, number ] => [ f.name, fs.statSync( path.join( FIXTURE_DIR, f.name ) ).size ] ) ] );
		for ( const [ name, size ] of expectedSize ) {
			const rel = local.find( ( f ) => f.endsWith( '/' + name ) ) ?? '';
			expect( rel, name ).not.toBe( '' );
			const php = `$p = 'diluxoneoffload://uploads/' . base64_decode( '${ Buffer.from( rel ).toString( 'base64' ) }' ); echo ( file_exists( $p ) ? 'exists' : 'missing' ) . ' ' . strlen( (string) file_get_contents( $p ) );`;
			expect( wp( site, [ 'eval', php ] ), name ).toBe( `exists ${ size }` );
		}
	} );

	test( 'the Overview, the Connection and Status › System report the container', async ( { page } ) => {
		await ui.goTab( page, base, 'overview' );
		const shown = await ui.refreshStats( page );
		expect( shown ).toBe( ( await listKeys( run, 'uploads/' ) ).length );
		await expect( page.locator( '.wrap.diluxone-offload-admin' ) ).toContainText( /Active/ );
		await ui.goTab( page, base, 'connection' );
		await expect( page.locator( '#provider-info' ) ).toContainText( run.container );
		await expect( page.locator( '#provider-info' ) ).toContainText( /Connected/ );
		await ui.goTab( page, base, 'system' );
		const body = await page.locator( '.wrap.diluxone-offload-admin' ).innerText();
		expect( body ).toContain( run.account );
		expect( body ).toContain( run.container );
		await ui.goTab( page, base, 'health' );
		await expect( page.locator( '#connection-health' ) ).toContainText( /Healthy/ );
	} );

	test( 'a Media Library upload lands in the container with its thumbnails and a public URL', async ( { page } ) => {
		const source = path.join( FIXTURE_DIR, 'small-300k.png' );
		const upload = path.join( FIXTURE_DIR, 'ui-upload.png' );
		fs.copyFileSync( source, upload );
		uiUploadId = await ui.uploadThroughMediaLibrary( page, base, upload );
		expect( uiUploadId ).toBeGreaterThan( 0 );

		const file = attachedFile( site, uiUploadId );
		expect( await blobExists( run, `uploads/${ file }` ) ).toBe( true );
		expect( await blobMd5( run, `uploads/${ file }` ) ).toBe( fileMd5( upload ) );

		const sizes = JSON.parse( wp( site, [ 'eval', `echo wp_json_encode( array_keys( (array) ( wp_get_attachment_metadata( ${ uiUploadId } )['sizes'] ?? array() ) ) );` ] ) );
		expect( sizes.length ).toBeGreaterThan( 0 );
		for ( const size of sizes ) {
			const thumb = wp( site, [ 'eval', `$m = wp_get_attachment_metadata( ${ uiUploadId } ); echo dirname( get_post_meta( ${ uiUploadId }, '_wp_attached_file', true ) ) . '/' . $m['sizes']['${ size }']['file'];` ] );
			expect( await blobExists( run, `uploads/${ thumb }` ), `${ size } thumbnail in the container` ).toBe( true );
		}

		const url = attachmentUrl( site, uiUploadId );
		expect( url ).toMatch( new RegExp( `^https://${ run.account }\\.blob\\.core\\.windows\\.net/${ run.container }/uploads/` ) );
		const http = await request.newContext();
		const head = await http.head( url );
		expect( head.status(), 'the public URL answers' ).toBe( 200 );
		const front = await http.get( `${ base }/?attachment_id=${ uiUploadId }` );
		expect( await front.text(), 'the front end links the cloud URL' ).toContain( url );
		await http.dispose();
	} );

	test( 'deleting the local copies frees the disk and the site keeps serving', async ( { page } ) => {
		await ui.goTab( page, base, 'offloading' );
		const { total } = await ui.deleteLocalFiles( page );
		expect( total ).toBeGreaterThan( 0 );
		// Only the empty file the scan skipped is left: it was never tracked, so it is never deleted.
		expect( filesUnder( site, uploadsDir ).filter( ( f ) => ! f.endsWith( '.dlxpart' ) ).map( ( f ) => path.basename( f ) ) ).toEqual( [ 'empty-0b.txt' ] );
		const http = await request.newContext();
		expect( ( await http.head( attachmentUrl( site, uiUploadId ) ) ).status() ).toBe( 200 );
		await http.dispose();
		expect( pluginState( site ) ).toBe( 'offloading_active' );
	} );

	test( 'a download can be cancelled and resumed', async ( { page } ) => {
		await ui.goTab( page, base, 'disconnect' );
		await ui.startDisconnectAndCancel( page );
		expect( pluginState( site ) ).toBe( 'offloading_active' );
		// The batch in flight when the page reloaded finishes on the server; once
		// it has, every part file has become its attachment and none is left.
		await expect
			.poll( () => filesUnder( site, uploadsDir ).filter( ( f ) => f.endsWith( '.dlxpart' ) ).length, { timeout: 120_000, message: 'no part files are left behind' } )
			.toBe( 0 );
		expect( filesUnder( site, uploadsDir ).length ).toBeGreaterThan( 0 );
	} );

	test( 'disconnecting brings every file back, byte for byte, and turns offloading off', async ( { page } ) => {
		await ui.goTab( page, base, 'disconnect' );
		await ui.disconnectToCompletion( page );
		// Offloading is off and the plugin asks for a sync before it comes back.
		expect( pluginState( site ) ).toBe( 'configured' );
		await ui.goTab( page, base, 'sync' );
		await expect( page.locator( '#start-sync-btn' ) ).toContainText( /Complete Sync/i );
		const keys = await listKeys( run, 'uploads/' );
		const local = filesUnder( site, uploadsDir );
		for ( const key of keys ) {
			const rel = key.replace( /^uploads\//, '' );
			expect( local, `${ rel } is back on disk` ).toContain( rel );
			expect( md5Inside( site, `${ uploadsDir }/${ rel }` ), `${ rel } bytes` ).toBe( await blobMd5( run, key ) );
		}
		expect( local.filter( ( f ) => f.endsWith( '.dlxpart' ) ).length ).toBe( 0 );
	} );

	test( 'the access key can be rotated on the Credentials tab', async ( { page } ) => {
		await ui.goTab( page, base, 'credentials' );
		await ui.updateKey( page, wrongKey, false );
		await ui.updateKey( page, run.key, true );
		await expect( page.locator( '#credentials_account_name' ) ).toContainText( run.account );
		expect( pluginState( site ) ).toBe( 'configured' );
	} );

	test( 'Complete Sync finds nothing new, Later leaves it synced, Resync All starts over, and offloading comes back', async ( { page } ) => {
		// Complete Sync: everything is already in the cloud, so the summary comes straight away.
		await ui.goTab( page, base, 'sync' );
		expect( await ui.runSyncToCompletion( page, 'continue' ) ).toBe( 'success' );
		// Later records the finished sync and then reloads; the URL does not
		// change, so the state is what proves the reload happened.
		await page.locator( '#sync-modal-summary #later-btn' ).click();
		await expect.poll( () => pluginState( site ) ).toBe( 'synced' );
		await ui.goTab( page, base, 'offloading' );
		await expect( page.locator( '#enable-offloading-btn[data-confirm]' ) ).toBeVisible();
		await ui.goTab( page, base, 'sync' );

		// Resync All clears the tracking and asks for a fresh sync.
		await ui.resyncAll( page );
		expect( pluginState( site ) ).toBe( 'configured' );
		expect( await ui.runSyncToCompletion( page, 'scratch' ) ).toBe( 'success' );
		await ui.enableOffloadingFromModal( page );
		expect( pluginState( site ) ).toBe( 'offloading_active' );
		await ui.goTab( page, base, 'disconnect' );
		await ui.disconnectToCompletion( page );
		expect( pluginState( site ) ).toBe( 'configured' );
	} );

	test( 'removing the provider resets the plugin to unconfigured', async ( { page } ) => {
		await ui.goTab( page, base, 'credentials' );
		await ui.removeProvider( page );
		expect( pluginState( site ) ).toBe( 'not_configured' );
		expect( wp( site, [ 'eval', "echo get_option( 'diluxone_offload_config' ) === false ? 'gone' : 'still there';" ] ) ).toBe( 'gone' );
		await ui.goTab( page, base, 'overview' );
		await expect( page.locator( '.wrap.diluxone-offload-admin' ) ).toContainText( /Not Configured/ );
	} );

	test( 'uninstalling leaves nothing of the plugin in the database', async () => {
		wp( site, [ 'plugin', 'deactivate', 'diluxone-offload-wordpress' ] );
		wp( site, [ 'plugin', 'uninstall', 'diluxone-offload-wordpress', '--skip-delete' ] );
		expect( wp( site, [ 'db', 'query', "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE '%diluxone_offload%'", '--skip-column-names' ] ).trim() ).toBe( '0' );
		expect( wp( site, [ 'db', 'query', "SHOW TABLES LIKE 'wp_diluxone_offload_files'", '--skip-column-names' ] ).trim() ).toBe( '' );
		wp( site, [ 'plugin', 'activate', 'diluxone-offload-wordpress' ] );
	} );

	test.afterAll( () => {
		// Leave the site as it was found: the seeded media and the UI upload go.
		for ( const id of [ ...seeded, uiUploadId ].filter( Boolean ) ) {
			try { wp( site, [ 'post', 'delete', String( id ), '--force' ] ); } catch { /* already gone */ }
		}
	} );
} );
