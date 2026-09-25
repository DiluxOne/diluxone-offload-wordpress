import { test as setup, expect } from '@playwright/test';
import { credentialsFromEnv, createContainers, writeRun, RealRun } from './helpers/azure';
import { BASE_URL, wp, Site } from './helpers/wp';
import { generateFixtures } from './helpers/fixtures';

/**
 * One container per journey, one login per site, the fixture set on disk.
 *
 * The container names carry the run id so two runs never share objects,
 * and the teardown deletes them whatever happened in between.
 */
const runId = ( process.env.GITHUB_RUN_ID ?? String( Date.now() ) ).toLowerCase();
const containers = { single: `e2e-${ runId }-single`, network: `e2e-${ runId }-network` };
const run: RealRun = { ...credentialsFromEnv(), container: containers.single, containers, runId };

setup( 'create the run containers and the fixture set', async () => {
	// The run file goes first: if a creation fails half-way, the teardown
	// still knows which containers to delete.
	writeRun( run );
	await createContainers( run );
	generateFixtures();
} );

for ( const site of [ 'single', 'network' ] as Site[] ) {
	setup( `log in to the ${ site } site`, async ( { page } ) => {
		const base = BASE_URL[ site ];
		const user = process.env.WP_USER ?? 'admin';
		const pass = process.env.WP_PASS ?? 'password';
		await page.goto( `${ base }/wp-login.php` );
		await page.waitForLoadState( 'networkidle' );
		// wp-login.php moves focus around while it loads; fill by id and check
		// the values landed where they belong before submitting.
		await page.locator( '#user_login' ).fill( user );
		await page.locator( '#user_pass' ).fill( pass );
		await expect( page.locator( '#user_login' ) ).toHaveValue( user );
		await expect( page.locator( '#user_pass' ) ).toHaveValue( pass );
		await page.locator( '#wp-submit' ).click();
		await expect( page ).toHaveURL( /wp-admin/ );
		await page.context().storageState( { path: `build/real-auth-${ site }.json` } );
	} );
}

setup( 'both sites start clean', async () => {
	// Whatever a previous run left — provider, rows, media, a run site — goes,
	// so every step starts from the state it expects. These are throwaway
	// wp-env sites; their media library is the suite's to use.
	for ( const site of [ 'single', 'network' ] as Site[] ) {
		const network = site === 'network' ? [ '--network' ] : [];
		try { wp( site, [ 'plugin', 'deactivate', 'diluxone-offload-wordpress', ...network ] ); } catch { /* was inactive */ }
		try { wp( site, [ 'plugin', 'uninstall', 'diluxone-offload-wordpress', '--skip-delete' ] ); } catch { /* nothing to remove */ }
		wp( site, [ 'plugin', 'activate', 'diluxone-offload-wordpress', ...network ] );
		const ids = wp( site, [ 'post', 'list', '--post_type=attachment', '--format=ids' ] );
		if ( ids ) wp( site, [ 'post', 'delete', ...ids.split( ' ' ), '--force' ] );
	}
	// Sites an aborted run may have left on the network.
	const sites = wp( 'network', [ 'site', 'list', '--fields=blog_id,url', '--format=csv' ] ).split( '\n' ).slice( 1 );
	for ( const row of sites ) {
		const [ id, url ] = row.split( ',' );
		if ( url && url.includes( '/real-' ) ) wp( 'network', [ 'site', 'delete', id, '--yes' ] );
	}
} );
