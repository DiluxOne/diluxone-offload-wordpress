import { execFileSync } from 'node:child_process';

/**
 * The two wp-env sites the real suite drives: the dev site is a single
 * site, the tests site is a multisite network (`make env-multisite`). Both
 * mount the repository root as the plugin folder.
 */
export type Site = 'single' | 'network';

export const BASE_URL: Record< Site, string > = {
	single: process.env.REAL_SINGLE_URL ?? 'http://localhost:8888',
	network: process.env.REAL_NETWORK_URL ?? 'http://localhost:8889',
};

const CLI_CONTAINER: Record< Site, string > = { single: 'cli', network: 'tests-cli' };

export const PLUGIN_SLUG = 'diluxone-offload-wordpress';
export const REPO_IN_CONTAINER = `/var/www/html/wp-content/plugins/${ PLUGIN_SLUG }`;

const NOISE = /^[ℹ✔✖]/;

function run( site: Site, args: string[] ): string {
	const out = execFileSync( 'npx', [ 'wp-env', 'run', CLI_CONTAINER[ site ], ...args ], {
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
		maxBuffer: 64 * 1024 * 1024,
		env: { ...process.env, WP_ENV_DEBUG: '' },
	} );
	return out
		.split( '\n' )
		.filter( ( line ) => ! NOISE.test( line ) )
		.join( '\n' )
		.trim();
}

/** Run a WP-CLI command against a site (a `url` picks the site of the network). */
export function wp( site: Site, args: string[], url?: string ): string {
	return run( site, [ 'wp', ...args, ...( url ? [ `--url=${ url }` ] : [] ) ] );
}

/** Run a shell command inside the site's CLI container. */
export function shell( site: Site, command: string ): string {
	return run( site, [ 'bash', '-c', command ] );
}

/** The state the plugin reports, which is NOT_CONFIGURED when the option is absent (Remove Provider deletes it). */
export function pluginState( site: Site, url?: string ): string {
	return wp( site, [ 'eval', 'echo \\DiluxOneOffload\\ConfigManager::get_state();' ], url );
}

/** The uploads directory on disk, whatever the wrapper is doing to wp_upload_dir(). */
export function nativeUploadsDir( site: Site, url?: string ): string {
	return wp( site, [ 'eval', 'echo \\DiluxOneOffload\\CloudStreamWrapper::native_upload_basedir();' ], url );
}

/** Regular files under a directory inside the container, relative to it. */
export function filesUnder( site: Site, dir: string ): string[] {
	const out = shell( site, `cd "${ dir }" 2>/dev/null && find . -type f | sed 's|^\\./||' | sort` );
	return out ? out.split( '\n' ).filter( Boolean ) : [];
}

export function md5Inside( site: Site, file: string ): string {
	return shell( site, `md5sum "${ file }" | cut -d' ' -f1` );
}

export function sizeInside( site: Site, file: string ): number {
	return Number( shell( site, `stat -c %s "${ file }"` ) );
}

/** wp_get_attachment_url() as the site reports it. */
export function attachmentUrl( site: Site, id: number, url?: string ): string {
	return wp( site, [ 'eval', `echo wp_get_attachment_url( ${ id } );` ], url );
}

export function attachedFile( site: Site, id: number, url?: string ): string {
	return wp( site, [ 'eval', `echo get_post_meta( ${ id }, '_wp_attached_file', true );` ], url );
}
