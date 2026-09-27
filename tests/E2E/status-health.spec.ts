import { test, expect } from '@playwright/test';
import { configureUnreachableProvider, resetPlugin } from './helpers/wp';

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
