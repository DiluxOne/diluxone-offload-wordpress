<?php
/**
 * The services the S3-compatible provider knows by name.
 *
 * One table, handed to the Connection form's JavaScript as is, so the
 * prefill in the browser and the checks on the server never diverge. A
 * preset only fills fields in: the user can overwrite every value it gives.
 *
 * The patterns below are the addresses of the storage the site owner picks
 * and pays for, filled into a form they submit themselves; the plugin loads
 * nothing of its own from them. Plugin Check's rule against offloading a
 * plugin's own assets to a remote service does not apply, and is suppressed
 * for this file only:
 *
 * phpcs:disable PluginCheck.CodeAnalysis.Offloading.OffloadedContent
 *
 * @package DiluxOneOffload\Providers
 * @since 2.0.0
 */

namespace DiluxOneOffload\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Endpoint, region rule, addressing style and public URL pattern per service.
 */
class S3Presets {

	/**
	 * The presets, in the order the form lists them.
	 *
	 * `endpoint` and `public_url` are patterns with `{region}` and `{bucket}`;
	 * an empty pattern means the user types the value (R2's account endpoint
	 * and public URL, everything under Custom). `region` is the value the
	 * form starts with; `region_fixed` means the service ignores it and the
	 * field is not editable. `acl` means the service honours a per-object
	 * `x-amz-acl: public-read`, so the form offers it (off by default: Amazon
	 * S3 buckets created since April 2023 refuse it). `max_parts` is the most
	 * parts one multipart upload may have, when the service allows fewer than
	 * Amazon's 10,000 (Scaleway: 1,000); a large file then goes in larger parts.
	 *
	 * @return array<string, array{label: string, endpoint: string, region: string, region_fixed: bool, path_style: bool, public_url: string, http: bool, acl: bool, max_parts?: int}>
	 */
	public static function all(): array {
		return array(
			'aws'      => array(
				'label'        => __( 'Amazon S3', 'diluxone-offload' ),
				'endpoint'     => 'https://s3.{region}.amazonaws.com',
				'region'       => 'us-east-1',
				'region_fixed' => false,
				'path_style'   => false,
				'public_url'   => 'https://{bucket}.s3.{region}.amazonaws.com',
				'http'         => false,
				'acl'          => true,
			),
			'r2'       => array(
				'label'        => __( 'Cloudflare R2', 'diluxone-offload' ),
				'endpoint'     => '',
				'region'       => 'auto',
				'region_fixed' => true,
				'path_style'   => true,
				'public_url'   => '',
				'http'         => false,
				'acl'          => false,
			),
			'b2'       => array(
				'label'        => __( 'Backblaze B2', 'diluxone-offload' ),
				'endpoint'     => 'https://s3.{region}.backblazeb2.com',
				'region'       => 'us-west-004',
				'region_fixed' => false,
				'path_style'   => true,
				'public_url'   => 'https://{bucket}.s3.{region}.backblazeb2.com',
				'http'         => false,
				'acl'          => false,
			),
			'spaces'   => array(
				'label'        => __( 'DigitalOcean Spaces', 'diluxone-offload' ),
				'endpoint'     => 'https://{region}.digitaloceanspaces.com',
				'region'       => 'nyc3',
				'region_fixed' => false,
				'path_style'   => true,
				'public_url'   => 'https://{bucket}.{region}.digitaloceanspaces.com',
				'http'         => false,
				'acl'          => true,
			),
			'wasabi'   => array(
				'label'        => __( 'Wasabi', 'diluxone-offload' ),
				'endpoint'     => 'https://s3.{region}.wasabisys.com',
				'region'       => 'us-east-1',
				'region_fixed' => false,
				'path_style'   => true,
				'public_url'   => 'https://s3.{region}.wasabisys.com/{bucket}',
				'http'         => false,
				'acl'          => true,
			),
			'gcs'      => array(
				'label'        => __( 'Google Cloud Storage (HMAC keys)', 'diluxone-offload' ),
				'endpoint'     => 'https://storage.googleapis.com',
				'region'       => 'auto',
				'region_fixed' => true,
				'path_style'   => true,
				'public_url'   => 'https://storage.googleapis.com/{bucket}',
				'http'         => false,
				'acl'          => false,
			),
			'hetzner'  => array(
				'label'        => __( 'Hetzner Object Storage', 'diluxone-offload' ),
				'endpoint'     => 'https://{region}.your-objectstorage.com',
				'region'       => 'fsn1',
				'region_fixed' => false,
				'path_style'   => true,
				'public_url'   => 'https://{bucket}.{region}.your-objectstorage.com',
				'http'         => false,
				'acl'          => true,
			),
			'linode'   => array(
				'label'        => __( 'Akamai (Linode) Object Storage', 'diluxone-offload' ),
				'endpoint'     => 'https://{region}.linodeobjects.com',
				'region'       => 'us-east-1',
				'region_fixed' => false,
				'path_style'   => true,
				'public_url'   => 'https://{bucket}.{region}.linodeobjects.com',
				'http'         => false,
				'acl'          => false,
			),
			'vultr'    => array(
				'label'        => __( 'Vultr Object Storage', 'diluxone-offload' ),
				'endpoint'     => 'https://{region}.vultrobjects.com',
				'region'       => 'ewr1',
				'region_fixed' => false,
				'path_style'   => true,
				'public_url'   => 'https://{bucket}.{region}.vultrobjects.com',
				'http'         => false,
				'acl'          => true,
			),
			'scaleway' => array(
				'label'        => __( 'Scaleway Object Storage', 'diluxone-offload' ),
				'endpoint'     => 'https://s3.{region}.scw.cloud',
				'region'       => 'fr-par',
				'region_fixed' => false,
				'path_style'   => true,
				'public_url'   => 'https://{bucket}.s3.{region}.scw.cloud',
				'http'         => false,
				'acl'          => true,
				'max_parts'    => 1000,
			),
			'ovh'      => array(
				'label'        => __( 'OVHcloud Object Storage', 'diluxone-offload' ),
				'endpoint'     => 'https://s3.{region}.io.cloud.ovh.net',
				'region'       => 'gra',
				'region_fixed' => false,
				'path_style'   => true,
				'public_url'   => 'https://{bucket}.s3.{region}.io.cloud.ovh.net',
				'http'         => false,
				'acl'          => true,
			),
			'idrive'   => array(
				'label'        => __( 'IDrive e2', 'diluxone-offload' ),
				'endpoint'     => 'https://s3.{region}.idrivee2.com',
				'region'       => 'us-east-1',
				'region_fixed' => false,
				'path_style'   => true,
				'public_url'   => '',
				'http'         => false,
				'acl'          => false,
			),
			'custom'   => array(
				'label'        => __( 'Custom (MinIO, Ceph, …)', 'diluxone-offload' ),
				'endpoint'     => '',
				'region'       => 'us-east-1',
				'region_fixed' => false,
				'path_style'   => true,
				'public_url'   => '',
				'http'         => true,
				'acl'          => false,
			),
		);
	}

	/**
	 * The most parts one multipart upload may have on the service: 10,000 as
	 * on Amazon S3 unless the preset says fewer.
	 *
	 * @param string $preset Preset key.
	 * @return int
	 */
	public static function max_parts( string $preset ): int {
		return (int) ( self::all()[ $preset ]['max_parts'] ?? 10000 );
	}

	/**
	 * Whether a preset key is one of the table's.
	 *
	 * @param string $preset Preset key.
	 * @return bool
	 */
	public static function exists( string $preset ): bool {
		return isset( self::all()[ $preset ] );
	}

	/**
	 * The service's name as the screens show it.
	 *
	 * @param string $preset Preset key.
	 * @return string
	 */
	public static function label( string $preset ): string {
		return self::all()[ $preset ]['label'] ?? '';
	}

	/**
	 * The endpoint the preset derives from a region; '' when the user types it.
	 *
	 * @param string $preset Preset key.
	 * @param string $region Region.
	 * @return string
	 */
	public static function endpoint( string $preset, string $region ): string {
		return self::fill( self::all()[ $preset ]['endpoint'] ?? '', '', $region );
	}

	/**
	 * The public URL the preset derives from a bucket and a region; '' when
	 * it cannot be derived.
	 *
	 * @param string $preset Preset key.
	 * @param string $bucket Bucket.
	 * @param string $region Region.
	 * @return string
	 */
	public static function public_url( string $preset, string $bucket, string $region ): string {
		return self::fill( self::all()[ $preset ]['public_url'] ?? '', $bucket, $region );
	}

	/**
	 * The region the form starts with; for a fixed-region service, the only one.
	 *
	 * @param string $preset Preset key.
	 * @return string
	 */
	public static function default_region( string $preset ): string {
		return self::all()[ $preset ]['region'] ?? '';
	}

	/**
	 * Whether the service ignores the region (R2, Google Cloud Storage), so
	 * the preset's value is always the one stored.
	 *
	 * @param string $preset Preset key.
	 * @return bool
	 */
	public static function region_is_fixed( string $preset ): bool {
		return (bool) ( self::all()[ $preset ]['region_fixed'] ?? false );
	}

	/**
	 * Whether requests address the bucket in the path (`host/bucket/key`)
	 * rather than in the host name (`bucket.host/key`).
	 *
	 * @param string $preset Preset key.
	 * @return bool
	 */
	public static function path_style( string $preset ): bool {
		return (bool) ( self::all()[ $preset ]['path_style'] ?? true );
	}

	/**
	 * Whether the preset accepts a plain-http endpoint (Custom only: MinIO on a LAN or in CI).
	 *
	 * @param string $preset Preset key.
	 * @return bool
	 */
	public static function allows_http( string $preset ): bool {
		return (bool) ( self::all()[ $preset ]['http'] ?? false );
	}

	/**
	 * Whether the service honours a per-object public-read ACL (Amazon S3
	 * with ACLs enabled, DigitalOcean Spaces, Wasabi, Hetzner, Vultr,
	 * Scaleway, OVHcloud). R2, B2, Google's uniform buckets and IDrive e2
	 * decide public read per bucket, Akamai's newer endpoints ignore object
	 * ACLs, and MinIO ignores it.
	 *
	 * @param string $preset Preset key.
	 * @return bool
	 */
	public static function offers_acl( string $preset ): bool {
		return (bool) ( self::all()[ $preset ]['acl'] ?? false );
	}

	/**
	 * @param string $pattern Pattern with {bucket} and {region}.
	 * @param string $bucket  Bucket.
	 * @param string $region  Region.
	 * @return string
	 */
	private static function fill( string $pattern, string $bucket, string $region ): string {
		return str_replace( array( '{bucket}', '{region}' ), array( $bucket, $region ), $pattern );
	}
}
