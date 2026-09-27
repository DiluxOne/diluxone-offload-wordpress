<?php
/**
 * Cloud-provider configuration value object.
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload\DTOs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider Configuration DTO
 *
 * Immutable value object for cloud provider settings and credentials
 *
 * @package DiluxOneOffload\DTOs
 * @since 1.0.0
 */
class ProviderConfig {

	/**
	 * The form fields each provider posts, besides `cloud_provider`: the
	 * Connection form, Test Connection and the Credentials tab all send these
	 * names, and only these reach fromPost().
	 */
	const FORM_FIELDS = array(
		'azure' => array( 'account_name', 'account_key', 'container_name' ),
		's3'    => array( 's3_preset', 's3_region', 's3_endpoint', 's3_bucket', 's3_access_key_id', 's3_secret_access_key', 's3_public_url', 's3_object_acl', 's3_path_style' ),
	);

	/** @var string */
	private string $cloudProvider;
	/**
	 * @var array<string, mixed>
	 */
	private array $providerConfig;

	/**
	 * Constructor
	 *
	 * @param string               $cloudProvider Provider name (azure)
	 * @param array<string, mixed> $providerConfig Provider-specific configuration
	 */
	public function __construct(
		string $cloudProvider = '',
		array $providerConfig = array()
	) {
		$this->cloudProvider  = $cloudProvider;
		$this->providerConfig = $providerConfig;
	}

	/**
	 * Create from array configuration
	 *
	 * @param array<string, mixed> $config
	 * @return self
	 * @throws \InvalidArgumentException When provider_config is not an array.
	 */
	public static function fromArray( array $config ): self {
		$provider_config = $config['provider_config'] ?? array();
		if ( ! is_array( $provider_config ) ) {
			throw new \InvalidArgumentException( 'provider_config must be an array' );
		}
		return new self(
			(string) ( $config['cloud_provider'] ?? '' ),
			$provider_config
		);
	}

	/**
	 * Create from POST data
	 *
	 * @param array<string, mixed> $post POST data from form
	 * @return self
	 * @throws \InvalidArgumentException When required POST fields are missing or invalid.
	 */
	public static function fromPost( array $post ): self {
		// The caller hands over the named form fields, already unslashed and
		// sanitized as text; this validates them.
		$cloud_provider = sanitize_text_field( (string) ( $post['cloud_provider'] ?? '' ) );

		if ( empty( $cloud_provider ) ) {
			throw new \InvalidArgumentException( 'Cloud provider is required' );
		}

		// Build provider-specific configuration
		$provider_config = array();

		switch ( $cloud_provider ) {
			case 'azure':
				$provider_config = array(
					'storage_account' => sanitize_text_field( (string) ( $post['account_name'] ?? '' ) ),
					'access_key'      => sanitize_text_field( (string) ( $post['account_key'] ?? '' ) ),
					'container_name'  => sanitize_text_field( (string) ( $post['container_name'] ?? '' ) ),
				);

				self::validate_azure_config( $provider_config );
				break;

			case 's3':
				$preset          = (string) ( $post['s3_preset'] ?? '' );
				$provider_config = array(
					'preset'            => $preset,
					'endpoint'          => self::normalise_url( esc_url_raw( (string) ( $post['s3_endpoint'] ?? '' ), array( 'http', 'https' ) ) ),
					'region'            => \DiluxOneOffload\Providers\S3Presets::region_is_fixed( $preset ) ? \DiluxOneOffload\Providers\S3Presets::default_region( $preset ) : strtolower( trim( (string) ( $post['s3_region'] ?? '' ) ) ),
					'bucket'            => trim( (string) ( $post['s3_bucket'] ?? '' ) ),
					'access_key_id'     => trim( (string) ( $post['s3_access_key_id'] ?? '' ) ),
					'secret_access_key' => (string) ( $post['s3_secret_access_key'] ?? '' ),
					'public_url'        => self::normalise_url( esc_url_raw( (string) ( $post['s3_public_url'] ?? '' ), array( 'http', 'https' ) ) ),
					// Advanced: the addressing style is the preset's, editable only
					// under Custom; the object ACL only where the service honours one.
					'path_style'        => 'custom' === $preset ? 'virtual' !== ( $post['s3_path_style'] ?? '' ) : \DiluxOneOffload\Providers\S3Presets::path_style( $preset ),
					'object_acl'        => \DiluxOneOffload\Providers\S3Presets::offers_acl( $preset ) && '1' === (string) ( $post['s3_object_acl'] ?? '' ),
				);

				self::validate_s3_config( $provider_config );
				break;

			default:
				throw new \InvalidArgumentException( 'Unsupported cloud provider: ' . esc_html( $cloud_provider ) );
		}

		return new self( $cloud_provider, $provider_config );
	}

	/**
	 * Validate an Azure provider_config array fresh off a request.
	 *
	 * Called by fromPost(). fromArray() deliberately does not run it — it also
	 * reconstructs config read back from the database (ConfigManager::get_config()),
	 * and rejecting a stored config the moment its shape drifts from today's
	 * rules would silently blank out a working site's credentials on every page
	 * load. Any other call site building a provider_config from a fresh request
	 * (not from storage) must call this itself before handing the array to
	 * fromArray() — a storage account name isn't just cosmetic here: it becomes
	 * the hostname the plugin sends the access key's signature to.
	 *
	 * @param array<string, mixed> $provider_config
	 * @throws \InvalidArgumentException When a required field is missing or malformed.
	 */
	public static function validate_azure_config( array $provider_config ): void {
		if ( empty( $provider_config['storage_account'] ) ) {
			throw new \InvalidArgumentException( 'Storage Account Name is required' );
		}
		if ( empty( $provider_config['access_key'] ) ) {
			throw new \InvalidArgumentException( 'Access Key is required' );
		}
		if ( empty( $provider_config['container_name'] ) ) {
			throw new \InvalidArgumentException( 'Container Name is required' );
		}

		if ( ! preg_match( '/^[a-z0-9]{3,24}$/', (string) $provider_config['storage_account'] ) ) {
			throw new \InvalidArgumentException( 'Storage Account Name must be 3-24 lowercase letters and numbers only' );
		}
		if ( ! preg_match( '/^[a-z0-9](?:[a-z0-9]|[-](?![.])){1,61}[a-z0-9]$/', (string) $provider_config['container_name'] ) ) {
			throw new \InvalidArgumentException( 'Container Name must be lowercase letters, numbers, and hyphens only (3-63 characters)' );
		}
	}

	/**
	 * Validate an S3-compatible provider_config array fresh off a request.
	 *
	 * The same contract as validate_azure_config(): called by fromPost(), not
	 * by fromArray(). The endpoint is where the signed requests go, so it
	 * must be https, except under the Custom preset (MinIO on a LAN or in CI).
	 * Messages name the field, never its value.
	 *
	 * @param array<string, mixed> $provider_config
	 * @throws \InvalidArgumentException When a field is missing or malformed.
	 */
	public static function validate_s3_config( array $provider_config ): void {
		$preset = (string) ( $provider_config['preset'] ?? '' );
		if ( ! \DiluxOneOffload\Providers\S3Presets::exists( $preset ) ) {
			throw new \InvalidArgumentException( 'Service is required' );
		}

		$required = array(
			'endpoint'          => 'Endpoint',
			'region'            => 'Region',
			'bucket'            => 'Bucket',
			'access_key_id'     => 'Access Key ID',
			'secret_access_key' => 'Secret Access Key',
			'public_url'        => 'Public URL',
		);
		foreach ( $required as $field => $label ) {
			if ( '' === (string) ( $provider_config[ $field ] ?? '' ) ) {
				throw new \InvalidArgumentException( esc_html( $label ) . ' is required' );
			}
		}

		if ( ! preg_match( '/^[a-z0-9-]{1,32}$/', (string) $provider_config['region'] ) ) {
			throw new \InvalidArgumentException( 'Region must be lowercase letters, numbers and hyphens only' );
		}
		$bucket = (string) $provider_config['bucket'];
		if ( ! preg_match( '/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $bucket ) || false !== filter_var( $bucket, FILTER_VALIDATE_IP ) ) {
			throw new \InvalidArgumentException( 'Bucket must be 3-63 lowercase letters, numbers, dots and hyphens, and not an IP address' );
		}
		if ( 'aws' === $preset && false !== strpos( $bucket, '.' ) ) {
			// Amazon addresses the bucket in the host name, and its https
			// certificate covers one level only: bucket.with.dots fails TLS.
			throw new \InvalidArgumentException( 'Bucket names with dots do not work over https on Amazon S3; use a name without dots' );
		}
		$scheme = (string) wp_parse_url( (string) $provider_config['endpoint'], PHP_URL_SCHEME );
		if ( 'https' !== $scheme && ! ( 'http' === $scheme && \DiluxOneOffload\Providers\S3Presets::allows_http( $preset ) ) ) {
			throw new \InvalidArgumentException( 'Endpoint must be an https:// URL (http:// only with the Custom service)' );
		}
		if ( ! in_array( (string) wp_parse_url( (string) $provider_config['public_url'], PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
			throw new \InvalidArgumentException( 'Public URL must be an http:// or https:// URL' );
		}
		if ( ! preg_match( '/^[\x21-\x7e]{1,128}$/', (string) $provider_config['access_key_id'] ) ) {
			throw new \InvalidArgumentException( 'Access Key ID must be 1-128 printable characters' );
		}
	}

	/**
	 * A URL as it is stored: scheme and host lower-case, no trailing slash,
	 * no query, no fragment; a path (a CDN folder) is kept.
	 *
	 * @param string $url URL, already through esc_url_raw().
	 * @return string '' when it is not a URL with a host.
	 */
	private static function normalise_url( string $url ): string {
		$parts = wp_parse_url( trim( $url ) );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return '';
		}
		return strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] )
			. ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' )
			. rtrim( (string) ( $parts['path'] ?? '' ), '/' );
	}

	/**
	 * A fingerprint of the complete configuration, secret included.
	 *
	 * Test Connection stores it; both save paths recompute it from what they
	 * are about to save and refuse when it differs, so only the exact
	 * configuration that passed a test is ever saved. A hash, so the secret
	 * never sits in a transient.
	 *
	 * @return string
	 */
	public function fingerprint(): string {
		$config = $this->providerConfig;
		ksort( $config );
		return hash( 'sha256', (string) wp_json_encode( array( $this->cloudProvider, $config ) ) );
	}

	/**
	 * The rows the read-only screens show for this configuration, label to
	 * value, in order: where the media lives first (the Overview shows the
	 * first two rows), where it is served from last. Never the secret.
	 *
	 * @return array<string, string>
	 */
	public function describe(): array {
		switch ( $this->cloudProvider ) {
			case 'azure':
				$account   = (string) ( $this->providerConfig['storage_account'] ?? '' );
				$container = (string) ( $this->providerConfig['container_name'] ?? '' );
				return array(
					__( 'Storage Account', 'diluxone-offload' ) => $account,
					__( 'Container', 'diluxone-offload' ) => $container,
					__( 'Media served from', 'diluxone-offload' ) => sprintf( 'https://%s.blob.core.windows.net/%s/', $account, $container ),
				);
			case 's3':
				return array(
					__( 'Service', 'diluxone-offload' )  => \DiluxOneOffload\Providers\S3Presets::label( (string) ( $this->providerConfig['preset'] ?? '' ) ),
					__( 'Bucket', 'diluxone-offload' )   => (string) ( $this->providerConfig['bucket'] ?? '' ),
					__( 'Endpoint', 'diluxone-offload' ) => (string) ( $this->providerConfig['endpoint'] ?? '' ),
					__( 'Region', 'diluxone-offload' )   => (string) ( $this->providerConfig['region'] ?? '' ),
					__( 'Access Key ID', 'diluxone-offload' ) => (string) ( $this->providerConfig['access_key_id'] ?? '' ),
					__( 'Uploads are made public', 'diluxone-offload' ) => ! empty( $this->providerConfig['object_acl'] ) ? __( 'Yes, with a public-read ACL on each object', 'diluxone-offload' ) : __( 'No, the bucket decides', 'diluxone-offload' ),
					__( 'Media served from', 'diluxone-offload' ) => (string) ( $this->providerConfig['public_url'] ?? '' ) . '/',
				);
			default:
				return array();
		}
	}

	/**
	 * Get cloud provider name
	 *
	 * @return string
	 */
	public function getCloudProvider(): string {
		return $this->cloudProvider;
	}

	/**
	 * Get provider-specific configuration
	 *
	 * @return array<string, mixed>
	 */
	public function getProviderConfig(): array {
		// NOTE: use_https enforcement removed - HTTPS is now hardcoded in provider
		return $this->providerConfig;
	}

	/**
	 * Check if provider is configured
	 *
	 * @return bool
	 */
	public function isConfigured(): bool {
		return ! empty( $this->cloudProvider ) && ! empty( $this->providerConfig );
	}

	/**
	 * Get storage account name (Azure specific)
	 *
	 * @return string
	 */
	public function getStorageAccount(): string {
		return $this->providerConfig['storage_account'] ?? '';
	}

	/**
	 * Get container name (Azure specific)
	 *
	 * @return string
	 */
	public function getContainerName(): string {
		return $this->providerConfig['container_name'] ?? '';
	}

	/**
	 * Convert to array format
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'cloud_provider'  => $this->cloudProvider,
			'provider_config' => $this->providerConfig,
		);
	}

	/**
	 * Merge with another provider config (update fields)
	 *
	 * @param array<string, mixed> $updates Array of fields to update
	 * @return self New instance with updated fields
	 */
	public function merge( array $updates ): self {
		$current = $this->toArray();
		$merged  = array_merge( $current, $updates );

		// Handle nested provider_config merge
		if ( isset( $updates['provider_config'] ) ) {
			$merged['provider_config'] = array_merge(
				$current['provider_config'] ?? array(),
				$updates['provider_config']
			);
		}

		return self::fromArray( $merged );
	}
}
