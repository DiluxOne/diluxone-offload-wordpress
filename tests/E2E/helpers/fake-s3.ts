import fs from 'node:fs';
import path from 'node:path';
import { expect, Page } from '@playwright/test';
import { png } from '../../E2E-real/helpers/fixtures';
import { shell, wp } from './wp';

/**
 * A storage service on the dev site itself, so the mock suite can drive every
 * screen through a real provider without a cloud account.
 *
 * tests/E2E/fixtures/fake-s3/router.php is a small S3-compatible server; it
 * is copied to /e2e-bucket/ on the dev site and the plugin is configured with
 * the Custom S3 service against it. The plugin's own S3 provider talks to it
 * as it talks to any S3 service: Test Connection, the sync's curl_multi pool,
 * listings, downloads, deletes. Its objects can be read, and failures asked
 * for, over HTTP (see the router's header).
 */
export const BASE = process.env.WP_BASE_URL ?? 'http://localhost:8888';
export const FAKE = {
	endpoint: BASE,
	bucket: 'e2e-bucket',
	region: 'us-east-1',
	keyId: 'E2EKEYID',
	secret: 'e2e-secret-never-shown',
	publicUrl: `${ BASE }/e2e-bucket`,
};

const CONTAINER_PLUGIN = '/var/www/html/wp-content/plugins/diluxone-offload-wordpress';
const BUCKET_URL = `${ BASE }/e2e-bucket/`;

/** Put the server in place on the dev site and empty it. */
export async function installFakeS3(): Promise< void > {
	shell( `mkdir -p /var/www/html/e2e-bucket && cp ${ CONTAINER_PLUGIN }/tests/E2E/fixtures/fake-s3/router.php ${ CONTAINER_PLUGIN }/tests/E2E/fixtures/fake-s3/.htaccess /var/www/html/e2e-bucket/` );
	await resetBucket();
}

/** Empty the bucket, drop the failure rules and take the server away. */
export async function uninstallFakeS3(): Promise< void > {
	await resetBucket().catch( () => undefined );
	shell( 'rm -rf /var/www/html/e2e-bucket /var/www/html/wp-content/e2e-s3' );
}

async function control( action: string, body?: string, extra = '' ): Promise< any > {
	const res = await fetch( `${ BUCKET_URL }?e2e=${ action }${ extra }`, { method: body === undefined && action === 'list' ? 'GET' : 'POST', body } );
	expect( res.ok, `fake S3 control "${ action }"` ).toBe( true );
	return res.json();
}

export async function resetBucket(): Promise< void > {
	await control( 'reset', '' );
}

/** What the bucket holds: key => [size, md5]. */
export async function bucketObjects(): Promise< Record< string, [ number, string ] > > {
	return control( 'list' );
}

export interface FailureRule {
	method?: 'GET' | 'PUT' | 'POST' | 'DELETE' | 'HEAD';
	match?: string;
	status: number;
	code?: string;
	message?: string;
}

/** Make the server refuse what matches; an empty list makes it healthy again. */
export async function failWith( rules: FailureRule[] ): Promise< void > {
	await control( 'rules', JSON.stringify( rules ) );
}

/** An object someone else put in the bucket. */
export async function putObject( key: string, body: string ): Promise< void > {
	await control( 'put', body, `&key=${ encodeURIComponent( key ) }` );
}

/** The plugin connected to the fake bucket without the form, in `state`. */
export function configureFakeS3( state: 'CONFIGURED' | 'SYNCED' | 'OFFLOADING_ACTIVE' = 'CONFIGURED' ): void {
	wp( [ 'eval', `\\DiluxOneOffload\\ConfigManager::save_config( array( 'cloud_provider' => 's3', 'provider_config' => array( 'preset' => 'custom', 'region' => '${ FAKE.region }', 'endpoint' => '${ FAKE.endpoint }', 'bucket' => '${ FAKE.bucket }', 'access_key_id' => '${ FAKE.keyId }', 'secret_access_key' => '${ FAKE.secret }', 'public_url' => '${ FAKE.publicUrl }', 'path_style' => true, 'object_acl' => false ) ) ); \\DiluxOneOffload\\ConfigManager::set_state( \\DiluxOneOffload\\Enums\\PluginState::${ state } ); \\DiluxOneOffload\\ConfigManager::record_connection_success();` ] );
}

/** Fill the Connection form for the fake bucket (Custom service); does not test or save. */
export async function fillFakeS3Form( page: Page, overrides: Partial< typeof FAKE > = {} ): Promise< void > {
	const v = { ...FAKE, ...overrides };
	await page.locator( '#cloud_provider' ).selectOption( 's3' );
	await page.locator( '#s3_preset' ).selectOption( 'custom' );
	await page.locator( '#s3_region' ).fill( v.region );
	await page.locator( '#s3_bucket' ).fill( v.bucket );
	await page.locator( '#s3_endpoint' ).fill( v.endpoint );
	await page.locator( '#s3_access_key_id' ).fill( v.keyId );
	await page.locator( '#s3_secret_access_key' ).fill( v.secret );
	await page.locator( '#s3_public_url' ).fill( v.publicUrl );
}

/** The plugin's state as stored. */
export function pluginState(): string {
	return wp( [ 'eval', 'echo \\DiluxOneOffload\\ConfigManager::get_state();' ] );
}

/** One wp_options value as JSON. */
export function option( name: string ): any {
	const out = wp( [ 'option', 'get', name, '--format=json' ] );
	return out ? JSON.parse( out ) : null;
}

/** The tracking table in numbers: every row, synced, pending, failed (pending with an error), deleted locally. */
export function trackingCounts(): { total: number; synced: number; pending: number; failed: number; deleted: number } {
	const out = wp( [ 'eval', 'global $wpdb; $t = $wpdb->prefix . "diluxone_offload_files"; echo wp_json_encode( $wpdb->get_row( "SELECT COUNT(*) AS total, COALESCE(SUM(synced = 1),0) AS synced, COALESCE(SUM(synced = 0),0) AS pending, COALESCE(SUM(synced = 0 AND errors > 0),0) AS failed, COALESCE(SUM(deleted = 1),0) AS deleted FROM {$t}", ARRAY_A ) );' ] );
	const row = JSON.parse( out );
	return { total: Number( row.total ), synced: Number( row.synced ), pending: Number( row.pending ), failed: Number( row.failed ), deleted: Number( row.deleted ) };
}

/** Regular files under uploads/, relative to it. */
export function localUploads(): string[] {
	const out = shell( 'cd /var/www/html/wp-content/uploads 2>/dev/null && find . -type f | sed "s|^\\./||" | sort' );
	return out ? out.split( '\n' ).filter( Boolean ) : [];
}

export function md5Local( relative: string ): string {
	return shell( `md5sum "/var/www/html/wp-content/uploads/${ relative }" | cut -d' ' -f1` );
}

const FIXTURE_DIR = path.resolve( __dirname, '../../../build/e2e-fixtures' );

/** A PNG of about `bytes` bytes on the host, for a browser upload; returns its path. */
export function pngFixture( name: string, bytes: number ): string {
	fs.mkdirSync( FIXTURE_DIR, { recursive: true } );
	const file = path.join( FIXTURE_DIR, name );
	// Written in one step: 'wx' fails when the file is already there (same
	// name, same bytes) instead of checking first and writing after.
	try {
		fs.writeFileSync( file, png( bytes ), { flag: 'wx' } );
	} catch ( e ) {
		if ( ( e as NodeJS.ErrnoException ).code !== 'EEXIST' ) throw e;
	}
	return file;
}

/**
 * A clean library: no attachment, nothing under uploads/, nothing tracked,
 * then `names` imported through WP-CLI (thumbnails and all). Returns the IDs.
 */
export function seedLibrary( names: Array< { name: string; bytes: number } > ): number[] {
	clearLibrary();
	for ( const f of names ) pngFixture( f.name, f.bytes );
	const inside = names.map( ( f ) => `${ CONTAINER_PLUGIN }/build/e2e-fixtures/${ f.name }` );
	const out = wp( [ 'media', 'import', ...inside, '--porcelain' ] );
	return out.split( '\n' ).map( ( s ) => Number( s.trim() ) ).filter( ( n ) => n > 0 );
}

/** Forget the cached storage statistics, so the Overview reads the bucket again. */
export function forgetStats(): void {
	wp( [ 'eval', 'foreach ( \\DiluxOneOffload\\ConfigManager::STATS_TRANSIENTS as $t ) { delete_transient( $t ); }' ] );
}

/** No attachment, nothing under uploads/, nothing tracked. */
export function clearLibrary(): void {
	shell( 'ids=$(wp post list --post_type=attachment --post_status=any --format=ids); [ -n "$ids" ] && wp post delete $ids --force >/dev/null; rm -rf /var/www/html/wp-content/uploads/*; true' );
	wp( [ 'eval', 'global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->prefix}diluxone_offload_files" );' ] );
}

/** A file put straight into uploads/ (an FTP upload): `relative` to uploads/, `bytes` long. */
export function placeLocalFile( relative: string, bytes: number ): void {
	shell( `mkdir -p "$(dirname "/var/www/html/wp-content/uploads/${ relative }")" && head -c ${ bytes } /dev/urandom > "/var/www/html/wp-content/uploads/${ relative }"` );
}
