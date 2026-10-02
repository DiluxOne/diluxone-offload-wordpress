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

/**
 * The smallest batches on a site, or back to the default (a must-use plugin
 * that sets the diluxone_offload_sync_batch_seconds filter to 0): a sync
 * request fills the parallel slots once and finishes what it started, a
 * disconnect request downloads one round of at most 12 MB. Against a fast
 * server a single 8-second batch moves the whole library, and there is
 * nothing left to cancel mid-way.
 */
export function shortBatches( site: Site, on: boolean ): void {
	const file = '/var/www/html/wp-content/mu-plugins/diluxone-offload-e2e-short-batches.php';
	shell(
		site,
		on
			? `mkdir -p /var/www/html/wp-content/mu-plugins && printf '%s' "<?php add_filter( 'diluxone_offload_sync_batch_seconds', function () { return 0.0; } );" > ${ file }`
			: `rm -f ${ file }`
	);
}

/** PHP that yields the string `s`, whatever quotes, spaces or accents it carries. */
export function phpString( s: string ): string {
	return `base64_decode( '${ Buffer.from( s ).toString( 'base64' ) }' )`;
}

export interface TrackedRow {
	/** The row's path below the site's uploads directory, with a leading slash. */
	file: string;
	/** The object key it stands for, under the site's prefix. */
	key: string;
	synced: number;
	/** The full name of the upload the row left unfinished, '' when none. */
	upload: string;
}

/** The tracking row of the file whose name ends in `/name`, or null when the table has none. */
export function trackedRow( site: Site, name: string, url?: string ): TrackedRow | null {
	const php = `global $wpdb; $r = $wpdb->get_row( $wpdb->prepare( 'SELECT file, synced, upload_id FROM ' . \\DiluxOneOffload\\DiluxOneOffloadDB::get_table_name() . ' WHERE file LIKE %s', '%/' . $wpdb->esc_like( ${ phpString( name ) } ) ) ); echo wp_json_encode( $r ? array( 'file' => (string) $r->file, 'key' => \\DiluxOneOffload\\DiluxOneOffloadDB::key_from_path( (string) $r->file ), 'synced' => (int) $r->synced, 'upload' => (string) \\DiluxOneOffload\\DiluxOneOffloadDB::upload_name_of( $r->upload_id ) ) : null );`;
	return JSON.parse( wp( site, [ 'eval', php ], url ) ) as TrackedRow | null;
}

/** How many tracking rows have a path containing `fragment`. */
export function trackedRowsLike( site: Site, fragment: string, url?: string ): number {
	const php = `global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \\DiluxOneOffload\\DiluxOneOffloadDB::get_table_name() . ' WHERE file LIKE %s', '%' . $wpdb->esc_like( ${ phpString( fragment ) } ) . '%' ) );`;
	return Number( wp( site, [ 'eval', php ], url ) );
}

/** Put `secret` in the saved provider config's `field`, the way a key rotated elsewhere looks to the site. */
export function setStoredSecret( site: Site, provider: string, field: string, secret: string, url?: string ): void {
	wp( site, [ 'eval', `$c = \\DiluxOneOffload\\ConfigManager::get_current_provider_config(); $c[${ phpString( field ) }] = ${ phpString( secret ) }; \\DiluxOneOffload\\ConfigManager::save_config( array( 'cloud_provider' => ${ phpString( provider ) }, 'provider_config' => $c ) );` ], url );
}

/** The connection health the plugin keeps (status, consecutive_failures, error_code…). */
export function connectionHealth( site: Site, url?: string ): { status: string; consecutive_failures: number; error_code: string } {
	return JSON.parse( wp( site, [ 'eval', 'echo wp_json_encode( \\DiluxOneOffload\\ConfigManager::get_connection_health() );' ], url ) );
}

/**
 * Import one host fixture into the Media Library through WP-CLI, under
 * `name`: with offloading on, that is an upload through the stream wrapper.
 * Returns the attachment ID, or 0 when WordPress refused the file.
 */
export function importAs( site: Site, fixture: string, name: string, url?: string ): number {
	const inside = `/tmp/dlx-import/${ name }`;
	shell( site, `mkdir -p /tmp/dlx-import && cp "${ REPO_IN_CONTAINER }/build/real-fixtures/${ fixture }" "${ inside }"` );
	try {
		return Number( wp( site, [ 'media', 'import', inside, '--porcelain' ], url ).split( '\n' ).pop()?.trim() ) || 0;
	} catch {
		return 0;
	} finally {
		shell( site, `rm -f "${ inside }"` );
	}
}

/** The attachment's files as WordPress names them: the attached file, every size and every backup an edit kept, relative to uploads/. */
export function attachmentFiles( site: Site, id: number, url?: string ): string[] {
	const php = `$f = (string) get_post_meta( ${ id }, '_wp_attached_file', true ); $d = dirname( $f ); $m = (array) wp_get_attachment_metadata( ${ id } ); $out = array( $f ); foreach ( (array) ( $m['sizes'] ?? array() ) as $s ) { $out[] = $d . '/' . $s['file']; } if ( ! empty( $m['original_image'] ) ) { $out[] = $d . '/' . $m['original_image']; } foreach ( (array) get_post_meta( ${ id }, '_wp_attachment_backup_sizes', true ) as $s ) { if ( is_array( $s ) && ! empty( $s['file'] ) ) { $out[] = $d . '/' . $s['file']; } } echo wp_json_encode( array_values( array_unique( $out ) ) );`;
	return JSON.parse( wp( site, [ 'eval', php ], url ) ) as string[];
}
