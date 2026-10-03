import { test, expect, request } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { readRun, RealRun, listKeys, putObject, deleteObject, blobExists, blobMd5, bytesDiffer, fileMd5, createPrivateContainer, deleteNamedContainer, canMakePrivateContainer, startJourney, form, wrongSecret, secret, secretField, identity, servedFromHost, publicUrlPrefix, privateRefusal, objectProps, infrequentClass, unfinishedParts, noUnfinishedParts } from './helpers/storage';
import { BASE_URL, wp, shell, shortBatches, pluginState, nativeUploadsDir, filesUnder, md5Inside, attachmentUrl, attachedFile, REPO_IN_CONTAINER, trackedRow, trackedRowsLike, TrackedRow, setStoredSecret, connectionHealth, importAs, attachmentFiles } from './helpers/wp';
import { FIXTURES, DISK_FIXTURES, FIXTURE_DIR, generateFixtures, seedMediaLibrary, placeDiskFixtures } from './helpers/fixtures';
import * as ui from './helpers/plugin';

/**
 * One user, one single site, every screen of the plugin, against a real
 * container or bucket (REAL_PROVIDER): configure, sync, cancel, reset, fail on a bad key and retry,
 * offload, upload through the Media Library, delete the local copies,
 * cancel and resume a Disconnect, disconnect, rotate the key, resync,
 * remove the provider, uninstall. Every transfer is checked byte for byte
 * on the other side, and so is what only the storage can show: the headers
 * an object was stored with, a large file taken up where an interrupted
 * sync left it, uploads paused while the key fails, an image edited with
 * no local copy, a rename, a delete, a folder left out, and the unfinished
 * upload an uninstall cancels.
 */
const site = 'single';
const base = BASE_URL.single;
const DEFAULT_CACHE_CONTROL = 'public, max-age=604800';

/** The tracking row of the 60 MB video while a batch has left it half sent, or null. */
function halfSentVideo(): TrackedRow | null {
	const row = trackedRow( site, 'video-60m.mp4' );
	return row && row.synced === 0 && row.upload !== '' ? row : null;
}

test.describe.serial( 'single site journey', () => {
	let run: RealRun;
	let uploadsDir = '';
	let seeded: number[] = [];
	let uiUploadId = 0;
	let wrongKey = '';

	test.beforeAll( async () => {
		run = readRun( 'single' );
		await startJourney( run );
		uploadsDir = nativeUploadsDir( site );
		wrongKey = wrongSecret( run );
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
		const result = await ui.testConnection( page, form( run, run.container, wrongKey ) );
		expect( result ).not.toMatch( /success/i );
		await expect( page.locator( '#submit' ) ).toBeDisabled();
	} );

	test( 'a container that does not exist is refused', async ( { page } ) => {
		await ui.goTab( page, base, 'connection' );
		const result = await ui.testConnection( page, form( run, `${ run.container }-missing` ) );
		expect( result ).not.toMatch( /success/i );
		await expect( page.locator( '#submit' ) ).toBeDisabled();
	} );

	test( 'a private container is refused with an explanation, even with the right key', async ( { page } ) => {
		test.skip( ! canMakePrivateContainer( run ), 'the keys of a fixed bucket cannot create a private one; the local server and Azure cover this' );
		const name = `${ run.container }-private`;
		await createPrivateContainer( run, name );
		try {
			await ui.goTab( page, base, 'connection' );
			const result = await ui.testConnection( page, form( run, name ) );
			expect( result ).toMatch( privateRefusal( run ) );
			await expect( page.locator( '#submit' ) ).toBeDisabled();
		} finally {
			await deleteNamedContainer( run, name );
		}
	} );

	test( 'the right credentials pass Test Connection and save', async ( { page } ) => {
		await ui.goTab( page, base, 'connection' );
		const warning = page.locator( `#${ run.provider }-config .test-status-message` );
		// A failed test keeps the "test before saving" warning and Save disabled...
		expect( await ui.testConnection( page, form( run, run.container, wrongKey ) ) ).not.toMatch( /success/i );
		await expect( warning ).toBeVisible();
		await expect( page.locator( '#submit' ) ).toBeDisabled();
		// ...a passing one clears the warning and enables Save.
		const result = await ui.testConnection( page, form( run ) );
		expect( result ).toMatch( /success/i );
		await expect( warning ).toBeHidden();
		await ui.saveProvider( page );
		for ( const name of identity( run ) ) await expect( page.locator( '#provider-info' ) ).toContainText( name );
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
		shortBatches( site, true );
		try {
			await ui.startSyncAndCancel( page );
		} finally {
			shortBatches( site, false );
			await page.unrouteAll( { behavior: 'ignoreErrors' } );
		}
		expect( pluginState( site ) ).toBe( 'configured' );
		expect( ( await listKeys( run, 'uploads/' ) ).length ).toBeGreaterThan( 0 );
		await ui.resetSync( page );
		expect( pluginState( site ) ).toBe( 'configured' );
	} );

	test( 'what a reset sync left in the container is announced, and emptied only under this site\'s folder', async ( { page } ) => {
		// The cancelled sync left objects under uploads/ and the reset forgot
		// them: to the plugin they are somebody else's now. One object outside
		// uploads/ proves the emptying never reaches past this site's folder.
		const outside = `e2e-keep/${ run.runId }.txt`;
		await putObject( run, outside, 'not the plugin\'s' );
		try {
			const left = ( await listKeys( run, 'uploads/' ) ).length;
			expect( left ).toBeGreaterThan( 0 );
			await ui.goTab( page, base, 'sync' );
			expect( await ui.targetFound( page ) ).toBe( left );
			await expect( page.locator( '#start-sync-btn' ) ).toBeDisabled();
			// The way to another target names it as the provider does and opens Delete provider.
			const change = page.locator( '#diluxone-offload-target-change' );
			await expect( change ).toHaveText( 'azure' === run.provider ? 'Change container' : 'Change bucket' );
			await expect( change ).toHaveAttribute( 'href', /tab=credentials#delete-provider$/ );

			expect( await ui.emptyTarget( page, `${ run.container }-not-it` ) ).toMatch( /not the name/ );
			expect( ( await listKeys( run, 'uploads/' ) ).length, 'a wrong name deletes nothing' ).toBe( left );

			await page.locator( '#diluxone-offload-target-confirm' ).fill( run.container );
			await page.locator( '#diluxone-offload-target-empty-go' ).click();
			await expect( page.locator( '#diluxone-offload-target-status' ) ).toHaveText( new RegExp( `Done: ${ left } deleted` ), { timeout: 120_000 } );
			await expect( page.locator( '#start-sync-btn' ) ).toBeEnabled();
			expect( await listKeys( run, 'uploads/' ) ).toEqual( [] );
			expect( await blobExists( run, outside ), 'nothing outside uploads/ was touched' ).toBe( true );

			// Emptied, the next visit finds nothing and holds nothing.
			await ui.goTab( page, base, 'sync' );
			expect( await ui.passTargetCheck( page ) ).toBe( 'clear' );
		} finally {
			await deleteObject( run, outside );
		}
	} );

	test( 'a key that stops working fails the sync visibly, and Retry finishes it once fixed', async ( { page } ) => {
		// Swap the stored key for a wrong one the way a rotated key would look.
		wp( site, [ 'eval', `$c = \\DiluxOneOffload\\ConfigManager::get_current_provider_config(); $c['${ secretField( run ) }'] = '${ wrongKey }'; \\DiluxOneOffload\\ConfigManager::save_config( array( 'cloud_provider' => '${ run.provider }', 'provider_config' => $c ) );` ] );
		await ui.goTab( page, base, 'sync' );
		const outcome = await ui.runSyncToCompletion( page, 'scratch' );
		expect( outcome ).not.toBe( 'success' );
		await ui.clickAndAwaitReload( page, '#accept-errors-btn, #sync-complete-close-btn >> nth=0' );
		await expect( page.locator( '.wrap.diluxone-offload-admin' ) ).toContainText( /Synced with Errors/ );

		await page.locator( '.view-failed-btn' ).first().click();
		await expect( page.locator( '#failed-files-modal' ) ).toBeVisible();
		await expect( page.locator( '#failed-files-modal tbody tr' ).first() ).toContainText( /403|Forbidden|Upload failed/i );
		await page.locator( '#close-failed-modal' ).click();

		// The real key comes back and the failed files are retried, nothing else.
		wp( site, [ 'eval', `$c = \\DiluxOneOffload\\ConfigManager::get_current_provider_config(); $c['${ secretField( run ) }'] = '${ secret( run ) }'; \\DiluxOneOffload\\ConfigManager::save_config( array( 'cloud_provider' => '${ run.provider }', 'provider_config' => $c ) );` ] );
		await ui.goTab( page, base, 'sync' );
		expect( await ui.retryFailedFiles( page ) ).toBe( 'success' );
		await ui.clickAndAwaitReload( page, '#later-btn' );
		expect( pluginState( site ) ).toBe( 'synced' );
		await ui.resetSync( page );
	} );

	test( 'an interrupted sync takes the large file up where it was left, and leaves nothing unfinished in the storage', async ( { page } ) => {
		await ui.goTab( page, base, 'sync' );
		const seen: { row: TrackedRow | null } = { row: null };
		shortBatches( site, true );
		try {
			await ui.startSyncAndInterruptMidFile( page, () => ( seen.row = halfSentVideo() ) !== null );
		} finally {
			shortBatches( site, false );
		}
		const half = seen.row as TrackedRow | null;
		expect( half, 'the video was left half sent' ).not.toBeNull();
		if ( ! half ) return;
		// The cancel keeps the upload, and the storage holds what was sent of it.
		expect( await unfinishedParts( run, half.key, half.upload ), 'the parts sent so far are in the storage' ).toBeGreaterThan( 0 );
		expect( trackedRow( site, 'video-60m.mp4' ), 'the row still names its upload' ).toMatchObject( { synced: 0, upload: half.upload } );

		// Continue finishes it: the file is whole, and no unfinished upload is left to bill.
		await ui.goTab( page, base, 'sync' );
		expect( await ui.runSyncToCompletion( page, 'continue' ) ).toBe( 'success' );
		expect( await bytesDiffer( run, half.key, md5Inside( site, `${ uploadsDir }${ half.file }` ) ), 'the video, byte for byte' ).toBe( '' );
		expect( await unfinishedParts( run, half.key, half.upload ), 'the upload was completed, not left behind' ).toBe( noUnfinishedParts( run ) );
		expect( trackedRow( site, 'video-60m.mp4' ) ).toMatchObject( { synced: 1, upload: '' } );

		// Back to a clean start for the initial sync.
		await ui.clickAndAwaitReload( page, '#sync-modal-summary #later-btn' );
		expect( pluginState( site ) ).toBe( 'synced' );
		await ui.resetSync( page );
		expect( pluginState( site ) ).toBe( 'configured' );
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
			expect( await bytesDiffer( run, `uploads/${ rel }`, md5Inside( site, `${ uploadsDir }/${ rel }` ) ), `${ rel } bytes` ).toBe( '' );
		}
		// Every object carries the Cache-Control of Settings › Serving and the
		// service's default class: one PUT, parts sent by the sync, a large video.
		for ( const name of [ 'tiny-10k.png', 'clip-12m.mp4', 'video-60m.mp4' ] ) {
			const rel = local.find( ( f ) => f.endsWith( '/' + name ) ) ?? '';
			const props = await objectProps( run, `uploads/${ rel }` );
			expect( props.cacheControl, `${ name } Cache-Control` ).toBe( DEFAULT_CACHE_CONTROL );
			expect( props.storageClass, `${ name } is in the default class` ).not.toBe( infrequentClass( run ) || 'STANDARD_IA' );
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
		for ( const name of identity( run ) ) expect( body ).toContain( name );
		await ui.goTab( page, base, 'health' );
		await expect( page.locator( '#connection-health' ) ).toContainText( /Healthy/ );
	} );

	test( 'the screens show what is where: the figures, the skipped file, the timestamps, Check now', async ( { page } ) => {
		const inCloud = ( await listKeys( run, 'uploads/' ) ).length;
		// Sync: everything synced, every copy still here, nothing uploaded through the site yet.
		await ui.goTab( page, base, 'sync' );
		expect( await ui.bignumCount( page, 'Synced' ) ).toBe( inCloud );
		expect( await ui.bignumCount( page, 'Local copies left' ) ).toBe( inCloud );
		expect( await ui.bignumCount( page, 'Not on this server' ) ).toBe( 0 );
		expect( ( await ui.bignum( page, 'Last upload' ) ).value ).toBe( 'none yet' );
		await expect( page.locator( '.diluxone-offload-bar-head' ) ).toContainText( '100%' );
		// The empty file is the one thing the scan left out, and the screen says so by name.
		await expect( page.locator( '.wrap.diluxone-offload-admin' ) ).toContainText( '1 file was left out by the last scan' );
		const skipped = page.locator( 'details.diluxone-offload-skipped' );
		await skipped.locator( 'summary' ).click();
		await expect( skipped ).toContainText( 'Empty files' );
		await expect( skipped.locator( 'li code' ) ).toHaveText( [ /\/empty-0b\.txt$/ ] );
		// Offloading: served from the account, every byte still has a copy here, since when.
		await ui.goTab( page, base, 'offloading' );
		expect( await ui.bignumTitle( page, 'Served from' ), 'the whole hostname is in the tooltip' ).toBe( servedFromHost( run ) );
		expect( await ui.bignumLines( page, 'Served from' ), 'the hostname fits its card' ).toBeLessThanOrEqual( 2 );
		expect( await ui.bignumCount( page, 'Local copies' ) ).toBe( inCloud );
		expect( await ui.bignumCount( page, 'Not on this server' ) ).toBe( 0 );
		expect( ( await ui.bignum( page, 'Offloading since' ) ).value ).not.toBe( '—' );
		await expect( page.locator( '.diluxone-offload-bar-head' ) ).toContainText( /All in the cloud · [\d,.]+ still ha(s|ve) a copy here/ );
		// Disconnect: nothing to bring back yet.
		await ui.goTab( page, base, 'disconnect' );
		expect( await ui.bignumCount( page, 'Files to bring back' ) ).toBe( 0 );
		// Connection: since when.
		await ui.goTab( page, base, 'connection' );
		await expect( page.locator( '#provider-info' ) ).toContainText( 'Connected since' );
		// Health: Check now asks the provider and answers on the spot.
		await ui.goTab( page, base, 'health' );
		expect( await ui.checkHealthNow( page ) ).toMatch( /Healthy/ );
		await expect( page.locator( '#health-last-success' ) ).toHaveText( /just now/ );
		await expect( page.locator( '#health-failures strong' ) ).toHaveText( '0' );
	} );

	test( 'a Media Library upload lands in the container with its thumbnails and a public URL', async ( { page } ) => {
		const source = path.join( FIXTURE_DIR, 'small-300k.png' );
		const upload = path.join( FIXTURE_DIR, 'ui-upload.png' );
		fs.copyFileSync( source, upload );
		uiUploadId = await ui.uploadThroughMediaLibrary( page, base, upload );
		expect( uiUploadId ).toBeGreaterThan( 0 );

		const file = attachedFile( site, uiUploadId );
		expect( await blobExists( run, `uploads/${ file }` ) ).toBe( true );
		expect( await bytesDiffer( run, `uploads/${ file }`, fileMd5( upload ) ), `${ file } bytes` ).toBe( '' );

		const sizes = JSON.parse( wp( site, [ 'eval', `echo wp_json_encode( array_keys( (array) ( wp_get_attachment_metadata( ${ uiUploadId } )['sizes'] ?? array() ) ) );` ] ) );
		expect( sizes.length ).toBeGreaterThan( 0 );
		for ( const size of sizes ) {
			const thumb = wp( site, [ 'eval', `$m = wp_get_attachment_metadata( ${ uiUploadId } ); echo dirname( get_post_meta( ${ uiUploadId }, '_wp_attached_file', true ) ) . '/' . $m['sizes']['${ size }']['file'];` ] );
			expect( await blobExists( run, `uploads/${ thumb }` ), `${ size } thumbnail in the container` ).toBe( true );
		}

		const url = attachmentUrl( site, uiUploadId );
		expect( url.startsWith( `${ publicUrlPrefix( run ) }uploads/` ), `${ url } is under the public URL` ).toBe( true );
		const http = await request.newContext();
		const head = await http.head( url );
		expect( head.status(), 'the public URL answers' ).toBe( 200 );
		// Settings › Serving's default: browsers keep a new upload for a week.
		expect( head.headers()[ 'cache-control' ], 'the upload was stored with its Cache-Control' ).toBe( 'public, max-age=604800' );
		const front = await http.get( `${ base }/?attachment_id=${ uiUploadId }` );
		expect( await front.text(), 'the front end links the cloud URL' ).toContain( url );
		await http.dispose();

		// The Sync tab knows about it without a scan: the upload and its
		// thumbnails are synced, cloud only, and the last of them is "Last upload".
		const inCloud = ( await listKeys( run, 'uploads/' ) ).length;
		await ui.goTab( page, base, 'sync' );
		expect( await ui.bignumCount( page, 'Synced' ) ).toBe( inCloud );
		expect( await ui.bignumCount( page, 'Not on this server' ) ).toBe( 1 + sizes.length );
		const last = await ui.bignum( page, 'Last upload' );
		expect( last.value ).toMatch( /ago|just now/ );
		expect( last.detail ).toMatch( /^ui-upload.*\.png · / );
	} );

	test( 'Settings › Serving decides the Cache-Control and the storage class the next uploads are stored with', async ( { page } ) => {
		const infrequent = infrequentClass( run );
		const saveSettings = async () => {
			await page.getByRole( 'button', { name: /Save Settings/ } ).click();
			await expect( page.locator( '.notice-success, .updated' ).first() ).toBeVisible();
		};
		const ids: number[] = [];
		try {
			// A value of one's own, and the cheaper class where the service has one.
			await ui.goTab( page, base, 'serving' );
			await page.locator( '#cache_control' ).fill( 'public, max-age=60' );
			if ( infrequent ) {
				await page.locator( '#storage_class' ).selectOption( 'infrequent' );
			} else {
				await expect( page.locator( '#storage_class' ), 'a service without a cheaper class is not offered one' ).toHaveCount( 0 );
			}
			await saveSettings();
			const custom = importAs( site, 'small-300k.png', `serving-custom-${ run.runId }.png` );
			expect( custom, 'the upload went through' ).toBeGreaterThan( 0 );
			ids.push( custom );
			const files = attachmentFiles( site, custom );
			expect( files.length, 'the original and its thumbnails' ).toBeGreaterThan( 1 );
			for ( const file of files ) {
				const props = await objectProps( run, `uploads/${ file }` );
				expect( props.cacheControl, `${ file } Cache-Control` ).toBe( 'public, max-age=60' );
				if ( infrequent ) expect( props.storageClass, `${ file } is in the cheaper class` ).toBe( infrequent );
				else expect( props.storageClass, `${ file } is in the default class` ).not.toBe( 'STANDARD_IA' );
			}
			const http = await request.newContext();
			const head = await http.head( attachmentUrl( site, custom ) );
			expect( head.status() ).toBe( 200 );
			expect( head.headers()[ 'cache-control' ], 'browsers get the stored header' ).toBe( 'public, max-age=60' );
			await http.dispose();

			// Switched off, and back to the default class: the next upload carries neither.
			await ui.goTab( page, base, 'serving' );
			await page.locator( 'input[name="cache_control_enabled"]' ).uncheck();
			if ( infrequent ) await page.locator( '#storage_class' ).selectOption( 'standard' );
			await saveSettings();
			const plain = importAs( site, 'tiny-10k.png', `serving-off-${ run.runId }.png` );
			expect( plain ).toBeGreaterThan( 0 );
			ids.push( plain );
			const props = await objectProps( run, `uploads/${ attachedFile( site, plain ) }` );
			// Some services answer a default of their own for an object stored without one; never ours.
			expect( [ 'public, max-age=60', DEFAULT_CACHE_CONTROL ], 'no Cache-Control was stored' ).not.toContain( props.cacheControl );
			if ( infrequent ) expect( props.storageClass ).not.toBe( infrequent );
		} finally {
			// The defaults again, for the rest of the journey, and the two uploads go.
			await ui.goTab( page, base, 'serving' );
			await page.locator( 'input[name="cache_control_enabled"]' ).check();
			await page.locator( '#cache_control' ).fill( DEFAULT_CACHE_CONTROL );
			if ( infrequent ) await page.locator( '#storage_class' ).selectOption( 'standard' );
			await saveSettings();
			for ( const id of ids ) {
				const files = attachmentFiles( site, id );
				wp( site, [ 'post', 'delete', String( id ), '--force' ] );
				for ( const file of files ) expect( await blobExists( run, `uploads/${ file }` ), `${ file } went with its attachment` ).toBe( false );
			}
		}
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

		// The figures follow: nothing left here, everything cloud only, and
		// Disconnect says what it would bring back and that the disk can take it.
		const inCloud = ( await listKeys( run, 'uploads/' ) ).length;
		await ui.goTab( page, base, 'offloading' );
		expect( await ui.bignumCount( page, 'Local copies' ) ).toBe( 0 );
		expect( await ui.bignumCount( page, 'Not on this server' ) ).toBe( inCloud );
		await expect( page.locator( '.diluxone-offload-bar-head' ) ).toContainText( 'All in the cloud only' );
		await ui.goTab( page, base, 'disconnect' );
		expect( await ui.bignumCount( page, 'Files to bring back' ) ).toBe( inCloud );
		expect( ( await ui.bignum( page, 'Size to bring back' ) ).value ).not.toMatch( /^0 B$/ );
		expect( ( await ui.bignum( page, 'Free disk here' ) ).detail ).toBe( 'enough for what is in the cloud' );
	} );

	test( 'a key that stops working pauses uploads after three failures, nothing is written anywhere, and they resume once it works', async ( { page } ) => {
		const name = ( n: number ) => `paused-${ run.runId }-${ n }.png`;
		const before = filesUnder( site, uploadsDir );
		const subdir = wp( site, [ 'eval', 'echo ltrim( wp_upload_dir()["subdir"], "/" );' ] );
		setStoredSecret( site, run.provider, secretField( run ), wrongKey );
		try {
			for ( let n = 1; n <= 3; n++ ) {
				expect( importAs( site, 'tiny-10k.png', name( n ) ), `upload ${ n } is refused by the storage` ).toBe( 0 );
			}
			const health = connectionHealth( site );
			expect( health.status ).toBe( 'unhealthy' );
			expect( health.consecutive_failures ).toBeGreaterThanOrEqual( 3 );
			expect( health.error_code, 'the storage\'s own answer' ).toBe( '403' );
			// Paused: the next write is refused before it starts.
			expect( wp( site, [ 'eval', `echo false === @file_put_contents( 'diluxoneoffload://uploads/${ subdir }/${ name( 4 ) }', 'x' ) ? 'refused' : 'written';` ] ) ).toBe( 'refused' );
			// No local fallback and no object: nothing of the four uploads is anywhere.
			expect( filesUnder( site, uploadsDir ), 'nothing was written on this server' ).toEqual( before );
			expect( ( await listKeys( run, 'uploads/' ) ).filter( ( k ) => k.includes( `paused-${ run.runId }` ) ), 'nothing reached the storage' ).toEqual( [] );
			expect( trackedRowsLike( site, `paused-${ run.runId }` ) ).toBe( 0 );

			// The screens say it, and Check now with the key still wrong keeps it so.
			await ui.goTab( page, base, 'health' );
			await expect( page.locator( '#health-status' ) ).toContainText( 'Unhealthy' );
			await expect( page.locator( '#health-failures' ) ).toContainText( 'uploads refused from 3' );
			await expect( page.locator( '.wrap.diluxone-offload-admin' ) ).toContainText( 'New uploads are refused until the connection recovers.' );
			expect( await ui.checkHealthNow( page ) ).toContain( 'Unhealthy' );
		} finally {
			setStoredSecret( site, run.provider, secretField( run ), secret( run ) );
		}

		// The key works again: Check now finds it, and the next upload goes straight to the storage.
		await ui.goTab( page, base, 'health' );
		expect( await ui.checkHealthNow( page ) ).toMatch( /^Healthy/ );
		await expect( page.locator( '#health-failures strong' ) ).toHaveText( '0' );
		const id = importAs( site, 'tiny-10k.png', name( 5 ) );
		expect( id, 'uploads resumed' ).toBeGreaterThan( 0 );
		const key = `uploads/${ attachedFile( site, id ) }`;
		expect( await bytesDiffer( run, key, fileMd5( path.join( FIXTURE_DIR, 'tiny-10k.png' ) ) ) ).toBe( '' );
		expect( filesUnder( site, uploadsDir ), 'and only there' ).toEqual( before );
		wp( site, [ 'post', 'delete', String( id ), '--force' ] );
		expect( await blobExists( run, key ) ).toBe( false );
	} );

	test( 'with no local copy, a video over one part, a rename, an image edit and a delete all happen in the storage', async () => {
		const ids: number[] = [];
		const http = await request.newContext();
		try {
			// A video over one part goes up through the wrapper in parts, byte for byte, with its header.
			const videoName = `live-${ run.runId }.mp4`;
			const video = importAs( site, 'clip-12m.mp4', videoName );
			expect( video ).toBeGreaterThan( 0 );
			ids.push( video );
			const videoKey = `uploads/${ attachedFile( site, video ) }`;
			const videoMd5 = fileMd5( path.join( FIXTURE_DIR, 'clip-12m.mp4' ) );
			expect( await bytesDiffer( run, videoKey, videoMd5 ) ).toBe( '' );
			expect( ( await objectProps( run, videoKey ) ).cacheControl ).toBe( DEFAULT_CACHE_CONTROL );
			expect( trackedRow( site, videoName ), 'tracked as synced, with no local copy' ).toMatchObject( { key: videoKey, synced: 1 } );

			// A rename through the wrapper moves the object on the service, bytes and
			// header with it, and the tracking row follows; then back, for the attachment.
			const renamedKey = videoKey.replace( /\.mp4$/, '-renamed.mp4' );
			const rename = ( from: string, to: string ) => wp( site, [ 'eval', `echo rename( 'diluxoneoffload://${ from }', 'diluxoneoffload://${ to }' ) ? 'moved' : 'failed';` ] );
			expect( rename( videoKey, renamedKey ) ).toBe( 'moved' );
			expect( await blobExists( run, videoKey ), 'the old name is gone' ).toBe( false );
			expect( await bytesDiffer( run, renamedKey, videoMd5 ) ).toBe( '' );
			expect( ( await objectProps( run, renamedKey ) ).cacheControl, 'the copy keeps the header' ).toBe( DEFAULT_CACHE_CONTROL );
			expect( trackedRow( site, path.basename( renamedKey ) )?.key ).toBe( renamedKey );
			expect( trackedRow( site, videoName ) ).toBeNull();
			expect( rename( renamedKey, videoKey ) ).toBe( 'moved' );
			expect( await bytesDiffer( run, videoKey, videoMd5 ) ).toBe( '' );
			expect( await blobExists( run, renamedKey ) ).toBe( false );

			// WordPress's image editor, with the original only in the cloud: the
			// edited image and its sizes are written there, the original is kept.
			const imageName = `edit-${ run.runId }.png`;
			const image = importAs( site, 'small-300k.png', imageName );
			expect( image ).toBeGreaterThan( 0 );
			ids.push( image );
			const originalKey = `uploads/${ attachedFile( site, image ) }`;
			const originalMd5 = await blobMd5( run, originalKey );
			const saved = wp( site, [ 'eval', `require_once ABSPATH . 'wp-admin/includes/image-edit.php'; wp_set_current_user( 1 ); $_REQUEST['history'] = wp_slash( '[{"r":90}]' ); $_REQUEST['target'] = 'all'; $_REQUEST['context'] = ''; $_REQUEST['do'] = 'save'; $r = wp_save_image( ${ image } ); echo empty( $r->error ) ? 'saved' : $r->error;` ] );
			expect( saved ).toBe( 'saved' );
			const editedKey = `uploads/${ attachedFile( site, image ) }`;
			expect( editedKey ).toMatch( /-e\d+\.png$/ );
			const imageFiles = attachmentFiles( site, image );
			expect( imageFiles.length, 'the edit, its sizes and the original kept' ).toBeGreaterThan( 2 );
			for ( const file of imageFiles ) expect( await blobExists( run, `uploads/${ file }` ), `${ file } is in the storage` ).toBe( true );
			expect( await blobMd5( run, originalKey ), 'the original is kept as it was' ).toBe( originalMd5 );
			const served = await http.get( attachmentUrl( site, image ) );
			expect( served.status() ).toBe( 200 );
			const servedMd5 = createHash( 'md5' ).update( await served.body() ).digest( 'hex' );
			expect( servedMd5, 'the site serves the edited image' ).toBe( await blobMd5( run, editedKey ) );
			expect( servedMd5, 'which is not the original' ).not.toBe( originalMd5 );
			// None of it touched this server's disk.
			expect( filesUnder( site, uploadsDir ).filter( ( f ) => ! f.endsWith( '.dlxpart' ) ).map( ( f ) => path.basename( f ) ) ).toEqual( [ 'empty-0b.txt' ] );

			// Deleting the attachments deletes every object WordPress names for them, and their rows.
			const all = [ ...imageFiles, ...attachmentFiles( site, video ) ];
			for ( const id of ids.splice( 0 ) ) wp( site, [ 'post', 'delete', String( id ), '--force' ] );
			for ( const file of all ) expect( await blobExists( run, `uploads/${ file }` ), `${ file } went with its attachment` ).toBe( false );
			expect( trackedRowsLike( site, `live-${ run.runId }` ) + trackedRowsLike( site, `edit-${ run.runId }` ), 'their rows went too' ).toBe( 0 );
		} finally {
			await http.dispose();
			for ( const id of ids ) {
				try { wp( site, [ 'post', 'delete', String( id ), '--force' ] ); } catch { /* gone */ }
			}
		}
	} );

	test( 'a download can be cancelled and resumed', async ( { page } ) => {
		await ui.goTab( page, base, 'disconnect' );
		shortBatches( site, true );
		try {
			await ui.startDisconnectAndCancel( page );
			// Read while batches are still slow: against a fast server, a
			// download the reloaded screen picks up could otherwise finish first.
			expect( pluginState( site ) ).toBe( 'offloading_active' );
		} finally {
			shortBatches( site, false );
			await page.unrouteAll( { behavior: 'ignoreErrors' } );
		}
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
			expect( await bytesDiffer( run, key, md5Inside( site, `${ uploadsDir }/${ rel }` ) ), `${ rel } bytes` ).toBe( '' );
		}
		expect( local.filter( ( f ) => f.endsWith( '.dlxpart' ) ).length ).toBe( 0 );
		// Every row has its copy back: the Sync tab counts no file as cloud only.
		expect( await ui.bignumCount( page, 'Synced' ) ).toBe( keys.length );
		expect( await ui.bignumCount( page, 'Local copies left' ) ).toBe( keys.length );
		expect( await ui.bignumCount( page, 'Not on this server' ) ).toBe( 0 );
	} );

	test( 'the key can be rotated on the Credentials tab', async ( { page } ) => {
		await ui.goTab( page, base, 'credentials' );
		await ui.updateKey( page, wrongKey, false );
		await ui.updateKey( page, secret( run ), true );
		await expect( page.locator( '#provider-credentials' ) ).toContainText( identity( run )[ 0 ] );
		expect( pluginState( site ) ).toBe( 'configured' );
	} );

	test( 'Complete Sync finds nothing new, Later leaves it synced, Resync All starts over, and offloading comes back', async ( { page } ) => {
		// Complete Sync: everything is already in the cloud, so the summary comes straight away.
		await ui.goTab( page, base, 'sync' );
		expect( await ui.runSyncToCompletion( page, 'continue' ) ).toBe( 'success' );
		// Later records the finished sync and then reloads the page.
		await ui.clickAndAwaitReload( page, '#sync-modal-summary #later-btn' );
		expect( pluginState( site ) ).toBe( 'synced' );
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

	test( 'a folder Settings › Transfers leaves out stays out of the storage, and the next scan says so', async ( { page } ) => {
		const subdir = wp( site, [ 'eval', 'echo ltrim( wp_upload_dir()["subdir"], "/" );' ] );
		const folder = `${ subdir }/e2e-excluded/`;
		const fixtures = `${ REPO_IN_CONTAINER }/build/real-fixtures`;
		shell( site, `mkdir -p "${ uploadsDir }/${ folder }deeper" && cp "${ fixtures }/notes.txt" "${ uploadsDir }/${ folder }kept-here.txt" && cp "${ fixtures }/tiny-10k.png" "${ uploadsDir }/${ folder }deeper/also-kept.png"` );
		const setExcluded = async ( value: string ) => {
			await ui.goTab( page, base, 'transfers' );
			await page.locator( '#excluded_folders' ).fill( value );
			await page.getByRole( 'button', { name: /Save Settings/ } ).click();
			await expect( page.locator( '.notice-success, .updated' ).first() ).toBeVisible();
		};
		try {
			await setExcluded( folder );
			// Complete Sync scans the disk again: the two new files are in the folder, so nothing is new.
			await ui.goTab( page, base, 'sync' );
			expect( await ui.runSyncToCompletion( page, 'continue' ) ).toBe( 'success' );
			await ui.clickAndAwaitReload( page, '#sync-modal-summary #later-btn' );
			expect( await listKeys( run, `uploads/${ folder }` ), 'nothing of the folder reached the storage' ).toEqual( [] );
			expect( trackedRowsLike( site, '/e2e-excluded/' ), 'and the folder is not tracked' ).toBe( 0 );
			// The Sync screen names what the scan left out, and why.
			await ui.goTab( page, base, 'sync' );
			const skipped = page.locator( 'details.diluxone-offload-skipped' );
			await skipped.locator( 'summary' ).click();
			await expect( skipped ).toContainText( 'In a folder Settings › Transfers leaves out' );
			await expect( skipped ).toContainText( `${ folder }kept-here.txt` );
			await expect( skipped ).toContainText( `${ folder }deeper/also-kept.png` );
		} finally {
			await setExcluded( '' );
			shell( site, `rm -rf "${ uploadsDir }/${ folder }"` );
		}
	} );

	// A file added after the sync finished, offloading still off: Scan and
	// Complete Sync on the synced screen uploads it (the screen used to offer
	// only Resync All, and nothing ever looked for it).
	test( 'Scan and Complete Sync on a synced library uploads a file added to uploads/ since', async ( { page } ) => {
		expect( pluginState( site ) ).toBe( 'synced' );
		const subdir = wp( site, [ 'eval', 'echo ltrim( wp_upload_dir()["subdir"], "/" );' ] );
		const added = `${ subdir }/added-${ run.runId }.txt`;
		shell( site, `cp "${ REPO_IN_CONTAINER }/build/real-fixtures/notes.txt" "${ uploadsDir }/${ added }"` );
		try {
			await ui.goTab( page, base, 'sync' );
			await expect( page.locator( '#start-sync-btn' ) ).toContainText( 'Scan and Complete Sync' );
			expect( await ui.runSyncToCompletion( page, 'continue' ) ).toBe( 'success' );
			await ui.clickAndAwaitReload( page, '#sync-modal-summary #later-btn' );
			expect( await blobExists( run, `uploads/${ added }` ), 'the new file is in the storage' ).toBe( true );
			expect( await bytesDiffer( run, `uploads/${ added }`, md5Inside( site, `${ uploadsDir }/${ added }` ) ) ).toBe( '' );
			expect( trackedRow( site, path.basename( added ) )?.synced ).toBe( 1 );
		} finally {
			shell( site, `rm -f "${ uploadsDir }/${ added }"` );
		}
	} );

	// Enable Offloading on a synced library with a file added since: it says
	// so, uploads it, and only then turns offloading on. It used to switch on
	// with the file never uploaded, a broken URL.
	test( 'Enable Offloading uploads a file added since the sync before it turns offloading on', async ( { page } ) => {
		expect( pluginState( site ) ).toBe( 'synced' );
		const subdir = wp( site, [ 'eval', 'echo ltrim( wp_upload_dir()["subdir"], "/" );' ] );
		const added = `${ subdir }/before-offloading-${ run.runId }.txt`;
		shell( site, `cp "${ REPO_IN_CONTAINER }/build/real-fixtures/notes.txt" "${ uploadsDir }/${ added }"` );
		try {
			await ui.goTab( page, base, 'offloading' );
			await page.locator( '#enable-offloading-btn' ).click();
			await expect( page.locator( '#sync-modal-summary' ) ).toContainText( '1 file was added since the last sync', { timeout: 60_000 } );
			expect( pluginState( site ), 'nothing switched on yet' ).toBe( 'synced' );
			await ui.clickAndAwaitReload( page, '#sync-modal-summary #upload-new-and-enable-btn', 300_000 );
			expect( pluginState( site ) ).toBe( 'offloading_active' );
			expect( await bytesDiffer( run, `uploads/${ added }`, md5Inside( site, `${ uploadsDir }/${ added }` ) ), 'uploaded before offloading' ).toBe( '' );
		} finally {
			// Offloading off again (every file still has its local copy), for the steps after this one.
			wp( site, [ 'eval', '\\DiluxOneOffload\\ConfigManager::disable_offloading();' ] );
			shell( site, `rm -f "${ uploadsDir }/${ added }"` );
		}
		expect( pluginState( site ) ).toBe( 'synced' );
	} );

	test( 'removing the provider resets the plugin to unconfigured', async ( { page } ) => {
		await ui.goTab( page, base, 'credentials' );
		await ui.removeProvider( page );
		expect( pluginState( site ) ).toBe( 'not_configured' );
		expect( wp( site, [ 'eval', "echo get_option( 'diluxone_offload_config' ) === false ? 'gone' : 'still there';" ] ) ).toBe( 'gone' );
		await ui.goTab( page, base, 'overview' );
		await expect( page.locator( '.wrap.diluxone-offload-admin' ) ).toContainText( /Not Configured/ );
	} );

	// Uninstalling cancels the multipart upload a cancelled sync left: the
	// autoloader, all uninstall.php has, now maps DiluxOneOffloadDB.
	test( 'uninstalling cancels the multipart upload a cancelled sync left half sent', async ( { page } ) => {
		test.skip( run.provider === 'azure', 'Azure has no upload to cancel: the plugin\'s abort is a no-op there, and the service discards uncommitted blocks on its own after seven days' );
		// Connected again (the step before removed the provider), and a sync cancelled mid-video.
		await ui.goTab( page, base, 'connection' );
		expect( await ui.testConnection( page, form( run ) ) ).toMatch( /success/i );
		await ui.saveProvider( page );
		// Removing the provider took the settings with it: the video is over the default size limit.
		await ui.goTab( page, base, 'transfers' );
		await page.locator( '#max_file_size' ).fill( '100' );
		await page.getByRole( 'button', { name: /Save Settings/ } ).click();
		await expect( page.locator( '.notice-success, .updated' ).first() ).toBeVisible();
		await ui.goTab( page, base, 'sync' );
		const seen: { row: TrackedRow | null } = { row: null };
		shortBatches( site, true );
		try {
			await ui.startSyncAndInterruptMidFile( page, () => ( seen.row = halfSentVideo() ) !== null );
		} finally {
			shortBatches( site, false );
		}
		const half = seen.row as TrackedRow | null;
		expect( half, 'the video was left half sent' ).not.toBeNull();
		if ( ! half ) return;
		expect( await unfinishedParts( run, half.key, half.upload ), 'the service holds the parts sent so far' ).toBeGreaterThan( 0 );

		wp( site, [ 'plugin', 'deactivate', 'diluxone-offload-wordpress' ] );
		wp( site, [ 'plugin', 'uninstall', 'diluxone-offload-wordpress', '--skip-delete' ] );
		try {
			expect( await unfinishedParts( run, half.key, half.upload ), 'the upload nobody could finish any more was cancelled' ).toBe( noUnfinishedParts( run ) );
		} finally {
			wp( site, [ 'plugin', 'activate', 'diluxone-offload-wordpress' ] );
		}
		expect( pluginState( site ) ).toBe( 'not_configured' );
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
