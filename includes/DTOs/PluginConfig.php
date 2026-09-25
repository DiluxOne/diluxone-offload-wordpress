<?php
/**
 * Plugin-level configuration value object.
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload\DTOs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin Configuration DTO
 *
 * Immutable value object that composes provider configuration and plugin settings
 * This is the main configuration object used throughout the plugin.
 *
 * @package DiluxOneOffload\DTOs
 * @since 1.0.0
 */
class PluginConfig {

	/** @var ProviderConfig */
	private ProviderConfig $provider;
	/** @var PluginSettings */
	private PluginSettings $settings;

	/**
	 * Constructor
	 *
	 * @param ProviderConfig $provider Provider configuration
	 * @param PluginSettings $settings Plugin settings
	 */
	public function __construct(
		ProviderConfig $provider,
		PluginSettings $settings
	) {
		$this->provider = $provider;
		$this->settings = $settings;
	}

	/**
	 * Create from array configuration
	 *
	 * @param array<string, mixed> $config
	 * @return self
	 */
	public static function fromArray( array $config ): self {
		$provider = ProviderConfig::fromArray( $config );
		$settings = PluginSettings::fromArray( $config );

		return new self( $provider, $settings );
	}

	/**
	 * Get provider configuration object
	 *
	 * @return ProviderConfig
	 */
	public function getProvider(): ProviderConfig {
		return $this->provider;
	}

	/**
	 * Get plugin settings object
	 *
	 * @return PluginSettings
	 */
	public function getSettings(): PluginSettings {
		return $this->settings;
	}

	// ========================================================================
	// Provider delegation methods (for backward compatibility)
	// ========================================================================

	/**
	 * Get cloud provider name
	 *
	 * @return string
	 */
	public function getCloudProvider(): string {
		return $this->provider->getCloudProvider();
	}

	/**
	 * Get provider-specific configuration
	 *
	 * @return array<string, mixed>
	 */
	public function getProviderConfig(): array {
		return $this->provider->getProviderConfig();
	}

	/**
	 * Check if plugin is configured with a cloud provider
	 *
	 * @return bool
	 */
	public function hasCloudProvider(): bool {
		return $this->provider->isConfigured();
	}

	// ========================================================================
	// Settings delegation methods (for backward compatibility)
	// ========================================================================

	/**
	 * Check if debug is enabled
	 *
	 * @return bool
	 */
	public function isDebugEnabled(): bool {
		return $this->settings->isDebugEnabled();
	}

	/**
	 * Check if should keep local files
	 *
	 * @return bool
	 */
	public function shouldKeepLocalFiles(): bool {
		return $this->settings->shouldKeepLocalFiles();
	}

	/**
	 * Check if should auto-activate offloading after sync
	 *
	 * @return bool
	 */
	public function shouldAutoActivateOffloading(): bool {
		return $this->settings->shouldAutoActivateOffloading();
	}

	/**
	 * Get timeout in seconds
	 *
	 * @return int
	 */
	public function getTimeout(): int {
		return $this->settings->getTimeout();
	}

	/**
	 * Get max file size in bytes
	 *
	 * @return int
	 */
	public function getMaxFileSize(): int {
		return $this->settings->getMaxFileSize();
	}

	/**
	 * Get max file size in megabytes
	 *
	 * @return float
	 */
	public function getMaxFileSizeMB(): float {
		return $this->settings->getMaxFileSizeMB();
	}

	/**
	 * Get allowed file types
	 *
	 * @return string
	 */
	public function getAllowedFileTypes(): string {
		return $this->settings->getAllowedFileTypes();
	}

	// ========================================================================
	// Serialization
	// ========================================================================

	/**
	 * Convert to array format (merges provider + settings)
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array_merge(
			$this->provider->toArray(),
			$this->settings->toArray()
		);
	}

	/**
	 * Create new instance with updated provider
	 *
	 * @param ProviderConfig $provider
	 * @return self
	 */
	public function withProvider( ProviderConfig $provider ): self {
		return new self( $provider, $this->settings );
	}

	/**
	 * Create new instance with updated settings
	 *
	 * @param PluginSettings $settings
	 * @return self
	 */
	public function withSettings( PluginSettings $settings ): self {
		return new self( $this->provider, $settings );
	}
}
