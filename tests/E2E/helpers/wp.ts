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

/**
 * Store a provider that looks right and cannot be reached: a storage account
 * that does not exist. Every check against it fails at once, which is what a
 * test of the failure path wants.
 */
export function configureUnreachableProvider(): void {
	const key = Buffer.from( 'a key of the right shape that opens nothing at all........' ).toString( 'base64' );
	wp( [ 'eval', `\\DiluxOneOffload\\ConfigManager::save_config( array( 'cloud_provider' => 'azure', 'provider_config' => array( 'storage_account' => 'diluxonee2enosuchaccount', 'access_key' => '${ key }', 'container_name' => 'nowhere' ) ) ); \\DiluxOneOffload\\ConfigManager::set_state( \\DiluxOneOffload\\Enums\\PluginState::CONFIGURED );` ] );
}

/** Back to an unconfigured plugin, the state every other mock spec expects. */
export function resetPlugin(): void {
	wp( [ 'eval', '\\DiluxOneOffload\\ConfigManager::reset();' ] );
}
