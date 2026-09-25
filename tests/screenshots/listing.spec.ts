import { test, expect, Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { credentialsFromEnv, containerClient, RealRun } from '../E2E-real/helpers/azure';
import { BASE_URL, wp, REPO_IN_CONTAINER } from '../E2E-real/helpers/wp';
import { png, mp4, pdf } from '../E2E-real/helpers/fixtures';
import * as ui from '../E2E-real/helpers/plugin';

/**
 * The nine screenshots of the wordpress.org listing, in the order and with
 * the captions of readme.txt's == Screenshots ==. One journey on the dev
 * site: pick the provider, connect, save, sync, enable offloading, then
 * the Overview, Settings and Status tabs. The container is created for the
 * run and deleted after it; the site is left unconfigured and empty, the
 * state the other suites expect.
 */
const base = BASE_URL.single;
const OUT = path.resolve( __dirname, '../../.wordpress-org' );
const MEDIA_DIR = path.resolve( __dirname, '../../build/screenshot-media' );
// A fixed name, because it appears in the screenshots. The run owns it:
// it is recreated at the start and deleted at the end, so two runs on the
// same account must not overlap (the maintainer runs this by hand).
const CONTAINER = 'wordpress-media';

/**
 * 1600 × 900 of the plugin itself: from the left edge of the admin content
 * and the top of the plugin's .wrap, so the admin menu, the toolbar and any
 * core notice above the plugin stay out of the listing. `keepInView` names
 * an element that must be in the frame.
 */
const shot = async ( page: Page, n: number, keepInView?: string ) => {
	// Let transitions and the progress bar's width animation settle.
	await page.waitForTimeout( 400 );
	const content = await page.locator( '#wpcontent' ).boundingBox();
	const wrap = await page.locator( '.wrap.diluxone-offload-admin' ).boundingBox();
	if ( ! content || ! wrap ) throw new Error( 'The plugin screen is not on the page.' );
	let y = Math.max( 0, wrap.y - 12 );
	// When the thing the caption is about sits below the fold, move the
	// frame down just enough to show it.
	const focus = keepInView ? await page.locator( keepInView ).first().boundingBox() : null;
	if ( focus && focus.y + focus.height + 24 > y + 900 ) y = focus.y + focus.height + 24 - 900;
	await page.screenshot( {
		path: path.join( OUT, `screenshot-${ n }.png` ),
		clip: { x: content.x, y, width: 1600, height: 900 },
	} );
};

function resetSite(): void {
	try { wp( 'single', [ 'plugin', 'deactivate', 'diluxone-offload-wordpress' ] ); } catch { /* was inactive */ }
	try { wp( 'single', [ 'plugin', 'uninstall', 'diluxone-offload-wordpress', '--skip-delete' ] ); } catch { /* nothing to remove */ }
	const ids = wp( 'single', [ 'post', 'list', '--post_type=attachment', '--format=ids' ] );
	if ( ids ) wp( 'single', [ 'post', 'delete', ...ids.split( ' ' ), '--force' ] );
}

/** A media library big enough for the sync to be caught half-way: photos, a few videos and documents. */
function seedMedia(): void {
	fs.rmSync( MEDIA_DIR, { recursive: true, force: true } );
	fs.mkdirSync( MEDIA_DIR, { recursive: true } );
	const names: string[] = [];
	for ( let i = 1; i <= 160; i++ ) {
		const name = `photo-${ String( i ).padStart( 3, '0' ) }.png`;
		fs.writeFileSync( path.join( MEDIA_DIR, name ), png( 480 * 1024 ) );
		names.push( name );
	}
	for ( let i = 1; i <= 6; i++ ) {
		fs.writeFileSync( path.join( MEDIA_DIR, `clip-${ i }.mp4` ), mp4( 3 * 1024 * 1024 ) );
		names.push( `clip-${ i }.mp4` );
	}
	for ( let i = 1; i <= 12; i++ ) {
		fs.writeFileSync( path.join( MEDIA_DIR, `document-${ i }.pdf` ), pdf( 64 * 1024 ) );
		names.push( `document-${ i }.pdf` );
	}
	const inside = names.map( ( n ) => `${ REPO_IN_CONTAINER }/build/screenshot-media/${ n }` );
	for ( let i = 0; i < inside.length; i += 40 ) {
		wp( 'single', [ 'media', 'import', ...inside.slice( i, i + 40 ), '--porcelain' ] );
	}
}

test.describe.serial( 'wordpress.org listing screenshots', () => {
	let run: RealRun;

	test.beforeAll( async () => {
		const creds = credentialsFromEnv();
		run = { ...creds, container: CONTAINER, containers: { single: CONTAINER, network: CONTAINER }, runId: 'screenshots' };
		await containerClient( run ).deleteIfExists();
		await expect.poll( async () => {
			try {
				await containerClient( run ).createIfNotExists( { access: 'blob' } );
				return true;
			} catch {
				return false; // A container being deleted can't be recreated for a few seconds.
			}
		}, { timeout: 120_000, intervals: [ 5_000 ] } ).toBe( true );
		resetSite();
		wp( 'single', [ 'plugin', 'activate', 'diluxone-offload-wordpress' ] );
		seedMedia();
	} );

	test.afterAll( async () => {
		resetSite();
		wp( 'single', [ 'plugin', 'activate', 'diluxone-offload-wordpress' ] );
		fs.rmSync( MEDIA_DIR, { recursive: true, force: true } );
		if ( run ) await containerClient( run ).deleteIfExists();
	} );

	test( 'the journey', async ( { page } ) => {
		await page.goto( `${ base }/wp-login.php` );
		await page.locator( '#user_login' ).fill( process.env.WP_USER ?? 'admin' );
		await page.locator( '#user_pass' ).fill( process.env.WP_PASS ?? 'password' );
		await page.locator( '#wp-submit' ).click();
		await expect( page ).toHaveURL( /wp-admin/ );

		// 2. Cloud Provider selection.
		await ui.goTab( page, base, 'cloud-provider' );
		await page.locator( '#cloud_provider' ).selectOption( 'azure' );
		await expect( page.locator( '#azure-config' ) ).toBeVisible();
		await page.locator( '#cloud_provider' ).focus();
		await shot( page, 2 );

		// 3. Cloud Provider configuration in Azure Storage.
		const result = await ui.testConnection( page, { account: run.account, key: run.key, container: CONTAINER } );
		expect( result ).toMatch( /success/i );
		await page.locator( '.test-connection-btn' ).blur();
		await shot( page, 3, '.test-connection-section .connection-result' );

		// 4. Cloud Provider configured with sync option in Azure Storage.
		await ui.saveProvider( page );
		await shot( page, 4 );

		// 5. Syncing in real time (first time).
		await ui.goTab( page, base, 'sync-offloading' );
		await page.locator( '#start-sync-btn' ).click();
		await ui.confirmSyncOptions( page, 'scratch' );
		await expect( page.locator( '#sync-modal-progress' ) ).toBeVisible();
		const percent = async () => Number( ( await page.locator( '#sync-modal-progress-percent' ).innerText() ).replace( /\D/g, '' ) );
		await expect.poll( percent, { timeout: ui.LONG, intervals: [ 100 ] } ).toBeGreaterThanOrEqual( 60 );
		await shot( page, 5 );

		// 6. Syncing finished and ready to enable offloading.
		expect( await ui.waitForSyncSummary( page ) ).toBe( 'success' );
		await shot( page, 6 );

		// 7. Offloading enabled.
		await ui.enableOffloadingFromModal( page );
		await ui.goTab( page, base, 'sync-offloading' );
		await shot( page, 7 );

		// 1. Overview with offloading configured in Microsoft Azure Storage.
		await ui.goTab( page, base, 'overview' );
		await ui.refreshStats( page );
		await shot( page, 1 );

		// 8. Plugin settings.
		await ui.goTab( page, base, 'settings' );
		await shot( page, 8 );

		// 9. Plugin status.
		await ui.goTab( page, base, 'status' );
		await shot( page, 9 );
	} );
} );
