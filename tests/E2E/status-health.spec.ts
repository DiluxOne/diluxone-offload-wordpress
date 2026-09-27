import { test, expect } from '@playwright/test';
import { configureUnreachableProvider, emptyTracking, resetPlugin } from './helpers/wp';

/**
 * Status › Health and the data screens without a cloud account: what they
 * say when nothing is connected, and what "Check now" does when the
 * provider cannot be reached. The success path runs in the real suite.
 */
const ADMIN = '/wp-admin/admin.php';
const HEALTH = `${ ADMIN }?page=diluxone-offload-status&tab=health`;

test.describe( 'Status › Health without a provider', () => {
	test( 'says there is nothing to check and offers no button', async ( { page } ) => {
		await page.goto( HEALTH );
		await expect( page.locator( '#connection-health' ) ).toContainText( 'No provider is connected, so there is no connection to check.' );
		await expect( page.locator( '#check-health-now' ) ).toHaveCount( 0 );
	} );

	test( 'the Sync, Offloading and Disconnect tabs ask for a provider first', async ( { page } ) => {
		for ( const tab of [ 'sync', 'offloading', 'disconnect' ] ) {
			await page.goto( `${ ADMIN }?page=diluxone-offload-sync&tab=${ tab }` );
			await expect( page.locator( '.wrap.diluxone-offload-admin' ) ).toContainText( 'Connect a cloud provider in Cloud Provider › Connection first.' );
			await expect( page.locator( '.diluxone-offload-bignum' ) ).toHaveCount( 0 );
		}
	} );
} );

test.describe.serial( 'Status › Health with a provider that cannot be reached', () => {
	test.beforeAll( () => configureUnreachableProvider() );
	test.afterAll( () => resetPlugin() );

	test( 'Check now runs the check, reports Unhealthy and stamps the time', async ( { page } ) => {
		await page.goto( HEALTH );
		const button = page.locator( '#check-health-now' );
		await expect( button ).toBeVisible();
		await button.click();
		// The button is disabled while the request runs, then comes back.
		await expect( button ).toBeEnabled( { timeout: 60_000 } );
		await expect( page.locator( '#health-status' ) ).toContainText( /Unhealthy/ );
		await expect( page.locator( '#health-last-check' ) ).toHaveText( /just now/ );
		await expect( page.locator( '#health-failures strong' ) ).toHaveText( /^[1-9]\d*$/ );
		// The table on a fresh load agrees with what the button wrote.
		await page.reload();
		await expect( page.locator( '#health-status' ) ).toContainText( /Unhealthy/ );
		await expect( page.locator( '#health-last-check' ) ).toContainText( /ago/ );
	} );

	test( 'after a failed check every screen carries the banner that says why', async ( { page } ) => {
		for ( const url of [ `${ ADMIN }?page=diluxone-offload-provider&tab=connection`, `${ ADMIN }?page=diluxone-offload`, `${ ADMIN }?page=diluxone-offload-sync&tab=sync` ] ) {
			await page.goto( url );
			const wrap = page.locator( '.wrap.diluxone-offload-admin' );
			await expect( wrap ).toContainText( 'Cloud Connection Error' );
		}
	} );
} );

test.describe.serial( 'Sync & Offloading › Offloading with the longest account name', () => {
	test.beforeAll( () => configureUnreachableProvider( 'OFFLOADING_ACTIVE' ) );
	test.afterAll( () => resetPlugin() );

	test( 'the hostname media is served from fits its card in at most three lines', async ( { page } ) => {
		await page.goto( `${ ADMIN }?page=diluxone-offload-sync&tab=offloading` );
		const card = page.locator( '.diluxone-offload-bignum', { has: page.locator( '.diluxone-offload-bignum__k', { hasText: /^Served from$/ } ) } );
		const value = card.locator( '.diluxone-offload-bignum__v' );
		await expect( value ).toHaveText( 'diluxonee2enosuchaccount.blob.core.windows.net' );
		const lines = await value.evaluate( ( el ) => Math.round( el.getBoundingClientRect().height / parseFloat( getComputedStyle( el ).lineHeight ) ) );
		expect( lines ).toBeLessThanOrEqual( 3 );
		// The figures beside it keep their size: only a name is set smaller.
		const number = page.locator( '.diluxone-offload-bignum__v:not(.diluxone-offload-bignum__v--text)' ).first();
		const size = ( el: HTMLElement | SVGElement ) => parseFloat( getComputedStyle( el ).fontSize );
		expect( await value.evaluate( size ) ).toBeLessThan( await number.evaluate( size ) );
	} );
} );

test.describe.serial( 'Buttons with an icon', () => {
	test.beforeAll( () => {
		configureUnreachableProvider();
		emptyTracking();
	} );
	test.afterAll( () => resetPlugin() );

	/** Vertical distance between the centre of a button's icon and the centre of its label. */
	const offset = ( button: import( '@playwright/test' ).Locator ) =>
		button.evaluate( ( el ) => {
			const icon = ( el.querySelector( '.dashicons' ) as HTMLElement ).getBoundingClientRect();
			const text = Array.from( el.childNodes ).filter( ( n ) => n.nodeType === Node.TEXT_NODE && ( n.textContent ?? '' ).trim() !== '' );
			const range = document.createRange();
			range.selectNode( text[ text.length - 1 ] );
			const label = range.getBoundingClientRect();
			// The glyph is drawn on the icon's line: a line-height taller than
			// the icon (WordPress sets 2.3 on hero buttons) moves it below the
			// box even when the box is centred.
			const style = getComputedStyle( el.querySelector( '.dashicons' ) as HTMLElement );
			const drift = parseFloat( style.lineHeight ) - parseFloat( style.fontSize );
			return Math.abs( icon.top + icon.height / 2 - ( label.top + label.height / 2 ) ) + Math.max( 0, drift );
		} );

	test( 'the icon sits on the label\'s line, centred with it, on the screen and in the sync modal', async ( { page } ) => {
		await page.goto( `${ ADMIN }?page=diluxone-offload-sync&tab=sync` );
		const start = page.locator( '#start-sync-btn' );
		await expect( start ).toBeVisible();
		await expect( start, 'nothing tracked: the first Start Sync, a hero button' ).toHaveClass( /button-hero/ );
		expect( await offset( start ), 'Start Sync' ).toBeLessThanOrEqual( 1 );
		await start.click();
		const scratch = page.locator( '#scratch-upload-btn' );
		await expect( scratch ).toBeVisible( { timeout: 60_000 } );
		expect( await offset( scratch ), 'Upload from Scratch, a tall button' ).toBeLessThanOrEqual( 1 );
	} );

	test( 'the Connection offers rotating the key and deleting the provider as buttons', async ( { page } ) => {
		await page.goto( `${ ADMIN }?page=diluxone-offload-provider&tab=connection` );
		const actions = page.locator( '.diluxone-offload-provider-actions' );
		await expect( actions.getByRole( 'link', { name: 'Rotate the key' } ) ).toHaveAttribute( 'href', /tab=credentials#update-credentials$/ );
		await actions.getByRole( 'link', { name: 'Delete Cloud Provider' } ).click();
		await expect( page ).toHaveURL( /tab=credentials#delete-provider$/ );
		await expect( page.locator( '#delete-provider' ) ).toBeVisible();
	} );
} );
