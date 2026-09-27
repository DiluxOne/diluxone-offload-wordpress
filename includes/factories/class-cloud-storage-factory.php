<?php
/**
 * Factory that instantiates a cloud-storage provider by name.
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload\Factories;

use DiluxOneOffload\Interfaces\CloudStorageClientInterface;
use DiluxOneOffload\Providers\AzureProvider;
use DiluxOneOffload\Providers\S3CompatibleProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cloud Storage Factory
 *
 * Factory class to create cloud storage provider instances
 */
class CloudStorageFactory {

	/**
	 * Create cloud storage client instance
	 *
	 * @param string               $provider Provider name (azure, s3)
	 * @param array<string, mixed> $config Configuration array
	 * @return CloudStorageClientInterface|null
	 * @throws \Exception When the provider name is not supported or not yet implemented.
	 */
	public static function create( $provider, $config = array() ) {
		switch ( strtolower( $provider ) ) {
			case 'azure':
				return new AzureProvider( $config );

			case 's3':
				return new S3CompatibleProvider( $config );

			default:
				throw new \Exception( 'Unsupported cloud storage provider: ' . esc_html( $provider ) );
		}
	}

	/**
	 * Get list of supported providers
	 *
	 * @return array<string, mixed>
	 */
	public static function get_supported_providers() {
		return array(
			'azure' => array(
				'name'          => 'Microsoft Azure Blob Storage',
				'implemented'   => true,
				'config_fields' => array(
					'storage_account' => 'Storage Account Name',
					'container_name'  => 'Container Name',
					'access_key'      => 'Access Key',
				),
			),
			's3'    => array(
				'name'          => 'S3-compatible storage',
				'implemented'   => true,
				// path_style is not listed: false is a valid value, and every
				// field here must be non-empty for the provider to count as set.
				'config_fields' => array(
					'preset'            => 'Service',
					'endpoint'          => 'Endpoint',
					'region'            => 'Region',
					'bucket'            => 'Bucket',
					'access_key_id'     => 'Access Key ID',
					'secret_access_key' => 'Secret Access Key',
					'public_url'        => 'Public URL',
				),
			),
		);
	}

	/**
	 * The name the screens show for a provider, '' for an unknown one.
	 *
	 * @param string $provider Provider key.
	 * @return string
	 */
	public static function get_provider_label( $provider ) {
		// Translated here, not in get_supported_providers(): that list is read
		// while the plugin loads, before translations may be.
		switch ( $provider ) {
			case 'azure':
				return __( 'Microsoft Azure Blob Storage', 'diluxone-offload' );
			case 's3':
				return __( 'S3-compatible storage', 'diluxone-offload' );
			default:
				return '';
		}
	}

	/**
	 * Check if provider is supported and implemented
	 *
	 * @param string $provider
	 * @return bool
	 */
	public static function is_provider_supported( $provider ) {
		$providers = self::get_supported_providers();
		return isset( $providers[ $provider ] ) && $providers[ $provider ]['implemented'];
	}

	/**
	 * Get provider configuration fields
	 *
	 * @param string $provider
	 * @return array<string, mixed>
	 */
	public static function get_provider_config_fields( $provider ) {
		$providers = self::get_supported_providers();
		return $providers[ $provider ]['config_fields'] ?? array();
	}
}
