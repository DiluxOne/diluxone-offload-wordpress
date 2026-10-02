import { execFileSync } from 'node:child_process';

/**
 * WP-CLI against the wp-env dev site the mock suite drives. Used only to put
 * the site in a state the browser cannot reach without a cloud account, and
 * to put it back.
 */
export function wp( args: string[] ): string {
	const out = execFileSync( 'npx', [ 'wp-env', 'run', 'cli', 'wp', ...args ], {
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
		env: { ...process.env, WP_ENV_DEBUG: '' },
	} );
	return out.split( '\n' ).filter( ( line ) => ! /^[ℹ✔✖]/.test( line ) ).join( '\n' ).trim();
}

/** A shell command in the dev site's CLI container (WP-CLI on the PATH, /var/www/html shared with the web server). */
export function shell( command: string ): string {
	const out = execFileSync( 'npx', [ 'wp-env', 'run', 'cli', 'bash', '-c', command ], {
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
		env: { ...process.env, WP_ENV_DEBUG: '' },
	} );
	return out.split( '\n' ).filter( ( line ) => ! /^[ℹ✔✖]/.test( line ) ).join( '\n' ).trim();
}

/**
 * A must-use plugin on the dev site with `php` as its body (without the
 * opening tag), or none when `php` is null. For what only a constant or a
 * filter set before the plugin loads can reach (DEV MODE).
 */
export function muPlugin( name: string, php: string | null ): void {
	const file = `/var/www/html/wp-content/mu-plugins/diluxone-offload-e2e-${ name }.php`;
	shell( php === null ? `rm -f ${ file }` : `mkdir -p /var/www/html/wp-content/mu-plugins && printf '%s' '${ ( '<?php ' + php ).replace( /'/g, `'\\''` ) }' > ${ file }` );
}

/**
 * Store a provider that looks right and cannot be reached: a storage account
 * that does not exist, with the longest name Azure allows. Every check against
 * it fails at once, which is what a test of the failure path wants. `state`
 * puts the plugin further along, to see a screen that only shows there.
 */
export function configureUnreachableProvider( state: 'CONFIGURED' | 'OFFLOADING_ACTIVE' = 'CONFIGURED' ): void {
	const key = Buffer.from( 'a key of the right shape that opens nothing at all........' ).toString( 'base64' );
	wp( [ 'eval', `\\DiluxOneOffload\\ConfigManager::save_config( array( 'cloud_provider' => 'azure', 'provider_config' => array( 'storage_account' => 'diluxonee2enosuchaccount', 'access_key' => '${ key }', 'container_name' => 'nowhere' ) ) ); \\DiluxOneOffload\\ConfigManager::set_state( \\DiluxOneOffload\\Enums\\PluginState::${ state } );` ] );
}

/** Back to an unconfigured plugin, the state every other mock spec expects. */
export function resetPlugin(): void {
	wp( [ 'eval', '\\DiluxOneOffload\\ConfigManager::reset();' ] );
}

/** No file tracked: the Sync tab then offers the first "Start Sync" (the big hero button). */
export function emptyTracking(): void {
	wp( [ 'eval', 'global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->prefix}diluxone_offload_files" );' ] );
}
