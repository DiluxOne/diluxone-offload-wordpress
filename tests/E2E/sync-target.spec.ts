import { test, expect } from '@playwright/test';
import { configureUnreachableProvider, emptyTracking, resetPlugin, wp } from './helpers/wp';

/**
 * Before the first sync the Sync tab checks what the container or bucket
 * already holds under this site's folder. Without a cloud account the check
 * cannot list anything: the screen says so and lets the sync start, instead
 * of holding it forever. What happens when files are there (continue, empty
 * with the typed name, use another) runs in the real suites.
 */
const SYNC = '/wp-admin/admin.php?page=diluxone-offload-sync&tab=sync';

test.describe.serial( 'Sync › a target that cannot be listed', () => {
	test.beforeAll( () => {
		configureUnreachableProvider();
		emptyTracking();
	} );
	test.afterAll( () => resetPlugin() );

	test( 'says the check failed and leaves Start Sync usable', async ( { page } ) => {
		await page.goto( SYNC );
		const callout = page.locator( '#diluxone-offload-target' );
		await expect( callout ).toBeVisible( { timeout: 60_000 } );
		await expect( page.locator( '#diluxone-offload-target-status' ) ).toContainText( 'could not be checked' );
		await expect( page.locator( '#diluxone-offload-target-empty-open' ) ).toBeHidden();
		await expect( page.locator( '#start-sync-btn' ) ).toBeEnabled();
	} );

	test( 'a check that fails on the way shows only the reason, like a refused one', async ( { page } ) => {
		await page.route( '**/admin-ajax.php', ( route ) =>
			( route.request().postData() ?? '' ).includes( 'action=diluxone_offload_inspect_target' )
				? route.fulfill( { status: 502, body: 'Bad Gateway' } )
				: route.continue()
		);
		await page.goto( SYNC );
		await expect( page.locator( '#diluxone-offload-target-status' ) ).toContainText( 'could not be checked (HTTP)' );
		await expect( page.locator( '#diluxone-offload-target-continue' ) ).toBeHidden();
		await expect( page.locator( '#diluxone-offload-target-empty-open' ) ).toBeHidden();
		await expect( page.locator( '#start-sync-btn' ) ).toBeEnabled();
	} );

	test( 'emptying is refused without the right name', async ( { page } ) => {
		await page.goto( SYNC );
		const refused = await page.evaluate( async () => {
			const ask = async ( confirm: string ) => {
				const body = new URLSearchParams( { action: 'diluxone_offload_empty_target', nonce: ( window as any ).diluxOneOffloadAdmin.nonce, confirm } );
				const r = await fetch( ( window as any ).ajaxurl, { method: 'POST', body } );
				return r.json();
			};
			return [ await ask( 'wrong-name' ), await ask( '' ) ];
		} );
		for ( const r of refused ) {
			expect( r.success ).toBe( false );
			expect( r.data ).toContain( 'not the name of the container or bucket' );
		}
	} );

	test( 'the check is not made once something is tracked', async ( { page } ) => {
		wp( [ 'eval', '\\DiluxOneOffload\\DiluxOneOffloadDB::add_file( "/2025/01/tracked.jpg", 10 );' ] );
		try {
			await page.goto( SYNC );
			await expect( page.locator( '#diluxone-offload-target' ) ).toHaveCount( 0 );
		} finally {
			emptyTracking();
		}
	} );
} );
