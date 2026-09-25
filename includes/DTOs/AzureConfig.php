<?php
/**
 * Azure provider configuration value object.
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload\DTOs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Azure Configuration DTO
 *
 * Immutable value object for Azure Blob Storage configuration
 *
 * @package DiluxOneOffload\DTOs
 * @since 1.0.0
 */
class AzureConfig {

	/** @var string */
	private string $storageAccount;
	/** @var string */
	private string $containerName;
	/** @var string */
	private string $accessKey;
	/** @var string */
	private string $endpoint;

	/**
	 * Constructor
	 *
	 * @param string $storageAccount
	 * @param string $containerName
	 * @param string $accessKey
	 *
	 * @throws \InvalidArgumentException When any required field is empty.
	 */
	public function __construct( string $storageAccount, string $containerName, string $accessKey ) {
		if ( empty( $storageAccount ) ) {
			throw new \InvalidArgumentException( 'Storage account cannot be empty' );
		}
		if ( empty( $containerName ) ) {
			throw new \InvalidArgumentException( 'Container name cannot be empty' );
		}
		if ( empty( $accessKey ) ) {
			throw new \InvalidArgumentException( 'Access key cannot be empty' );
		}

		$this->storageAccount = $storageAccount;
		$this->containerName  = $containerName;
		$this->accessKey      = $accessKey;
		$this->endpoint       = "https://{$storageAccount}.blob.core.windows.net";
	}

	/**
	 * Create from array configuration
	 *
	 * @param array<string, mixed> $config
	 * @return self
	 * @throws \InvalidArgumentException When the array is missing required keys or values are invalid.
	 */
	public static function fromArray( array $config ): self {
		return new self(
			$config['storage_account'] ?? '',
			$config['container_name'] ?? '',
			$config['access_key'] ?? ''
		);
	}

	/**
	 * Get storage account name
	 *
	 * @return string
	 */
	public function getStorageAccount(): string {
		return $this->storageAccount;
	}

	/**
	 * Get container name
	 *
	 * @return string
	 */
	public function getContainerName(): string {
		return $this->containerName;
	}

	/**
	 * Get access key
	 *
	 * @return string
	 */
	public function getAccessKey(): string {
		return $this->accessKey;
	}

	/**
	 * Get endpoint URL
	 *
	 * @return string
	 */
	public function getEndpoint(): string {
		return $this->endpoint;
	}

	/**
	 * Convert to array format
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'storage_account' => $this->storageAccount,
			'container_name'  => $this->containerName,
			'access_key'      => $this->accessKey,
		);
	}

	/**
	 * Check if configuration is valid
	 *
	 * @return bool
	 */
	public function isValid(): bool {
		return ! empty( $this->storageAccount )
			&& ! empty( $this->containerName )
			&& ! empty( $this->accessKey );
	}
}
