<?php
/**
 * Single source of truth for plugin configuration and connection-health state.
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload;

use DiluxOneOffload\Enums\PluginState;
use DiluxOneOffload\Factories\CloudStorageFactory;
use DiluxOneOffload\CloudStreamWrapper;
use DiluxOneOffload\DTOs\PluginConfig;
use DiluxOneOffload\DTOs\ProviderConfig;
use DiluxOneOffload\DTOs\PluginSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Configuration Manager - Centralized Storage System
 *
 * ARCHITECTURE OVERVIEW:
 * =====================
 * This class manages plugin configuration using WordPress options table (wp_options).
 * Different types of data use different storage strategies for optimal performance.
 *
 * STORAGE MAP:
 * ============
 *
 * 1. CONFIGURATION (diluxone_offload_config) - autoload=TRUE
 *    - Azure credentials, plugin settings
 *    - Small (~500 bytes), needed on every request (stream wrapper)
 *    - Accessed via: ConfigManager::get_config(), ConfigManager::save_config()
 *
 * 2. PLUGIN STATE (diluxone_offload_plugin_state) - autoload=TRUE
 *    - Current state: CONFIGURED, SYNCING, SYNCED, OFFLOADING_ACTIVE
 *    - Tiny (~10 bytes), checked frequently in admin UI
 *    - Accessed via: ConfigManager::get_state(), ConfigManager::set_state()
 *
 * 3. SYNC METADATA (diluxone_offload_sync_meta) - autoload=FALSE
 *    - Progress info: total_files, processed_files, start_time, etc.
 *    - Large (~1-2 KB), changes constantly during sync, temporary
 *    - Accessed DIRECTLY via get_option/update_option (not via ConfigManager)
 *    - Used by: SyncManager, Admin, Plugin
 *
 * 4. FILE TRACKING (wp_diluxone_offload_files table) - MySQL custom table
 *    - Individual file status: synced, deleted, errors, etc.
 *    - Accessed via: DiluxOneOffloadDB class
 *
 * WHY AUTOLOAD MATTERS:
 * =====================
 * - autoload=TRUE: Option loaded in memory on EVERY WordPress request (fast, but uses RAM)
 * - autoload=FALSE: Option requires DB query each time (slower, but saves RAM)
 *
 * Use autoload=TRUE for:
 *   - Small options (<1KB)
 *   - Frequently accessed options
 *   - Options needed on every page load
 *
 * Use autoload=FALSE for:
 *   - Large options (>1KB)
 *   - Rarely accessed options
 *   - Temporary/operational data
 */
class ConfigManager {

	/** @var string Option name for configuration - Stores Azure credentials and plugin settings */
	const CONFIG_OPTION = 'diluxone_offload_config';

	/** @var string Option name for plugin state - Current state: CONFIGURED, SYNCING, SYNCED, OFFLOADING_ACTIVE */
	const STATE_OPTION = 'diluxone_offload_plugin_state';

	/** @var string Option name for sync metadata - Progress info, temporary operational data */
	const SYNC_META_OPTION = 'diluxone_offload_sync_meta';

	/** @var string Option name for failed files (persistent) */
	const FAILED_FILES_OPTION = 'diluxone_offload_failed_files';

	/** @var string Option name for connection health status */
	const HEALTH_OPTION = 'diluxone_offload_connection_health';

	/** @var array<string, mixed> Default connection health values */
	const DEFAULT_HEALTH = array(
		'status'               => 'unknown',
		'last_check'           => 0,
		'last_success'         => 0,
		'error_code'           => '',
		'error_message'        => '',
		'error_source'         => '',
		'consecutive_failures' => 0,
	);

	/**
	 * Provider-config field names that hold sensitive credentials and must be
	 * encrypted at rest.
	 */
	const ENCRYPTED_FIELDS = array(
		'access_key',  // Azure Blob Storage account key
		'account_key', // legacy Azure (pre-1.0)
	);

	/** @var array<string, mixed> Default configuration values */
	const DEFAULT_CONFIG = array(
		// Cloud Provider Settings
		'cloud_provider'           => '',           // azure
		'provider_config'          => array(),          // Provider-specific config

		// Plugin Settings
		'debug_enabled'            => false,
		'keep_local_files'         => true,       // Keep local files as backup after sync
		'auto_activate_offloading' => true, // Auto-activate after sync
		// Re-applies https:// to URLs WP emits for the cloud_host. Needed when the WP
		// site is served over plain http: WP core's set_url_scheme() downgrades the
		// attachment URL to http, and Azure Blob refuses HTTP with a 400.
		'force_https_on_cloud'     => true,

		// Upload Settings
		// NOTE: use_https removed - HTTPS is always enforced (Azure requirement)
		'timeout'                  => 60,                  // Upload timeout in seconds
		'max_file_size'            => 20971520,      // 20 MB in bytes — safe cap for most hosts
		'allowed_file_types'       => '*',       // All file types allowed
	);

	/**
	 * Get plugin configuration as array (public API)
	 *
	 * @return array<string, mixed>
	 */
	public static function get_config() {
		$plugin_config = self::get_plugin_config_dto();
		return $plugin_config->toArray();
	}

	/**
	 * Get plugin configuration as DTO (internal use)
	 *
	 * @return PluginConfig
	 */
	private static function get_plugin_config_dto(): PluginConfig {
		$config_array = get_option( self::CONFIG_OPTION, self::DEFAULT_CONFIG );
		$config_array = self::decrypt_credentials( $config_array );
		$merged       = array_merge( self::DEFAULT_CONFIG, $config_array );

		// NOTE: use_https forced value removed - HTTPS is always enforced in provider

		try {
			return PluginConfig::fromArray( $merged );
		} catch ( \InvalidArgumentException $e ) {
			Logger::error( '[DiluxOne Offload ConfigManager] Invalid config in database, using defaults: ' . $e->getMessage() );
			return PluginConfig::fromArray( self::DEFAULT_CONFIG );
		}
	}

	/**
	 * Encrypt sensitive credentials inside a config array before persisting.
	 * Idempotent — already-encrypted values are left untouched.
	 *
	 * @param array<string, mixed> $config
	 * @return array<string, mixed>
	 */
	private static function encrypt_credentials( array $config ): array {
		if ( empty( $config['provider_config'] ) || ! is_array( $config['provider_config'] ) ) {
			return $config;
		}
		foreach ( self::ENCRYPTED_FIELDS as $field ) {
			$value = $config['provider_config'][ $field ] ?? '';
			if ( is_string( $value ) && $value !== '' && ! Crypto::is_encrypted( $value ) ) {
				$encrypted = Crypto::encrypt( $value );
				if ( $encrypted === '' ) {
					Logger::error( '[DiluxOne Offload ConfigManager] Refusing to persist credential "' . $field . '" — encryption failed (openssl missing?).' );
					$config['provider_config'][ $field ] = '';
				} else {
					$config['provider_config'][ $field ] = $encrypted;
				}
			}
		}
		return $config;
	}

	/**
	 * Decrypt sensitive credentials when loading config from storage.
	 * On decryption failure (rotated salts, corrupted payload) the field is
	 * cleared and an error is logged — the user will see the provider as
	 * unconfigured and must re-enter the credential. There is intentionally
	 * no fallback to plaintext.
	 *
	 * @param array<string, mixed> $config
	 * @return array<string, mixed>
	 */
	private static function decrypt_credentials( array $config ): array {
		if ( empty( $config['provider_config'] ) || ! is_array( $config['provider_config'] ) ) {
			return $config;
		}
		$first_failure_field = null;
		foreach ( self::ENCRYPTED_FIELDS as $field ) {
			$value = $config['provider_config'][ $field ] ?? '';
			if ( ! is_string( $value ) || $value === '' || ! Crypto::is_encrypted( $value ) ) {
				continue;
			}
			$plain = Crypto::decrypt( $value );
			if ( $plain === null ) {
				Logger::error( '[DiluxOne Offload ConfigManager] Failed to decrypt "' . $field . '" — credential will need to be re-entered.' );
				$config['provider_config'][ $field ] = '';
				$first_failure_field                 = $first_failure_field ?? $field;
			} else {
				$config['provider_config'][ $field ] = $plain;
			}
		}

		// Surface decrypt failures through the connection-health system so the
		// existing red banner fires automatically. We use a distinguishable
		// error_code ('decrypt_failed') so the banner can show a tailored
		// message ("re-enter your credentials") instead of the generic one.
		//
		// Recorded once per failure cycle: while the unhealthy state is
		// already 'decrypt_failed' we don't bump consecutive_failures on
		// every page load.
		if ( $first_failure_field !== null ) {
			$health           = self::get_connection_health();
			$already_recorded = $health['status'] === 'unhealthy'
				&& $health['error_code'] === 'decrypt_failed';
			if ( ! $already_recorded ) {
				self::record_connection_failure(
					'decrypt_failed',
					sprintf(
						/* translators: %s: provider config field name (e.g. access_key) */
						__( 'Stored credential "%s" cannot be decrypted. WordPress salts may have changed since this credential was saved.', 'diluxone-offload' ),
						$first_failure_field
					),
					'crypto'
				);
			}
		}

		return $config;
	}

	/**
	 * Get current provider configuration
	 *
	 * @return array<string, mixed> Provider-specific configuration
	 */
	public static function get_current_provider_config() {
		$plugin_config = self::get_plugin_config_dto();
		return $plugin_config->getProviderConfig();
	}

	/**
	 * Write an option, treating "already stored this exact value" as success.
	 *
	 * WordPress's update_option() returns false both when the write fails AND
	 * when the new value is identical to the stored one (it skips the query). Every
	 * caller here reported that second case as "Failed to save settings to
	 * database", so opening Settings and pressing Save without changing
	 * anything showed an error for a save that had nothing to do. Compare
	 * first, and only trust the return value when a write was actually needed.
	 *
	 * @param string $name     Option name.
	 * @param mixed  $value    Value to store.
	 * @param bool   $autoload Whether to autoload the option.
	 * @return bool True when the option now holds $value, false on a real failure.
	 */
	private static function persist_option( string $name, $value, bool $autoload ): bool {
		$sentinel = new \stdClass();
		$current  = get_option( $name, $sentinel );

		if ( $current !== $sentinel && $current === $value ) {
			return true;
		}

		return update_option( $name, $value, $autoload );
	}

	/**
	 * Save plugin configuration
	 * Uses autoload=true because config is needed on every request (stream wrapper)
	 *
	 * @param array<string, mixed> $config
	 * @return bool
	 */
	public static function save_config( $config ) {
		$current_config = self::get_config();
		$new_config     = array_merge( $current_config, $config );

		// Create DTO to validate configuration
		try {
			$plugin_config = PluginConfig::fromArray( $new_config );
		} catch ( \InvalidArgumentException $e ) {
			Logger::error( '[DiluxOne Offload ConfigManager] Invalid config: ' . $e->getMessage() );
			return false;
		}

		// Additional validation for cloud provider
		$validation_result = self::validate_config( $new_config );
		if ( ! $validation_result['valid'] ) {
			Logger::error( '[DiluxOne Offload ConfigManager] Invalid config: ' . $validation_result['error'] );
			return false;
		}

		// Encrypt sensitive credentials (the Azure access_key)
		// before they touch wp_options. Idempotent — already-encrypted values pass through.
		$payload = self::encrypt_credentials( $plugin_config->toArray() );

		// Save with autoload=true (config needed in every request for stream wrapper)
		$saved = self::persist_option( self::CONFIG_OPTION, $payload, true );

		if ( $saved ) {
			Logger::refresh();
			Logger::info( '[DiluxOne Offload ConfigManager] Configuration saved successfully' );

			// Update plugin state based on config
			self::update_state_from_config( $new_config );
		}

		return $saved;
	}

	// ========================================================================
	// NEW: Granular configuration methods (Provider vs Settings)
	// ========================================================================

	/**
	 * Get provider configuration (credentials only)
	 *
	 * @return array<string, mixed> Provider configuration array
	 */
	public static function get_provider_config() {
		$plugin_config = self::get_plugin_config_dto();
		return $plugin_config->getProvider()->toArray();
	}

	/**
	 * Get plugin settings (non-provider settings only)
	 *
	 * @return array<string, mixed> Plugin settings array
	 */
	public static function get_plugin_settings() {
		$plugin_config = self::get_plugin_config_dto();
		return $plugin_config->getSettings()->toArray();
	}

	/**
	 * Save provider configuration (preserves existing settings)
	 *
	 * @param ProviderConfig|array<string, mixed> $provider_config ProviderConfig DTO or array
	 * @return bool
	 */
	public static function save_provider_config( $provider_config ) {
		// Convert array to DTO if needed
		if ( is_array( $provider_config ) ) {
			try {
				$provider_config = ProviderConfig::fromArray( $provider_config );
			} catch ( \InvalidArgumentException $e ) {
				Logger::error( '[DiluxOne Offload ConfigManager] Invalid provider config: ' . $e->getMessage() );
				return false;
			}
		}

		// Load existing full config
		$current_plugin_config = self::get_plugin_config_dto();

		// Create new config with updated provider, preserving settings
		$new_plugin_config = $current_plugin_config->withProvider( $provider_config );

		// Validate the new provider config
		$provider_array = $provider_config->toArray();
		if ( ! empty( $provider_array['cloud_provider'] ) ) {
			if ( ! CloudStorageFactory::is_provider_supported( $provider_array['cloud_provider'] ) ) {
				Logger::error( '[DiluxOne Offload ConfigManager] Unsupported cloud provider: ' . $provider_array['cloud_provider'] );
				return false;
			}

			if ( ! self::validate_provider_config( $provider_array['cloud_provider'], $provider_array['provider_config'] ?? array() ) ) {
				Logger::error( '[DiluxOne Offload ConfigManager] Invalid provider configuration' );
				return false;
			}
		}

		// Save merged config (with credentials encrypted at rest)
		$payload = self::encrypt_credentials( $new_plugin_config->toArray() );
		$saved   = self::persist_option( self::CONFIG_OPTION, $payload, true );

		if ( $saved ) {
			Logger::info( '[DiluxOne Offload ConfigManager] Provider configuration saved successfully' );

			// Update plugin state
			self::update_state_from_config( $new_plugin_config->toArray() );
		}

		return $saved;
	}

	/**
	 * Save plugin settings (preserves existing provider config)
	 *
	 * @param PluginSettings|array<string, mixed> $settings PluginSettings DTO or array
	 * @return bool
	 */
	public static function save_plugin_settings( $settings ) {
		// Convert array to DTO if needed
		if ( is_array( $settings ) ) {
			try {
				$settings = PluginSettings::fromArray( $settings );
			} catch ( \InvalidArgumentException $e ) {
				Logger::error( '[DiluxOne Offload ConfigManager] Invalid plugin settings: ' . $e->getMessage() );
				return false;
			}
		}

		// Load existing full config
		$current_plugin_config = self::get_plugin_config_dto();

		// Create new config with updated settings, preserving provider
		$new_plugin_config = $current_plugin_config->withSettings( $settings );

		// Save merged config — re-encrypt credentials so the existing provider
		// section keeps its protection even when only settings changed.
		$payload = self::encrypt_credentials( $new_plugin_config->toArray() );
		$saved   = self::persist_option( self::CONFIG_OPTION, $payload, true );

		if ( $saved ) {
			// Setting toggle may have changed — pick it up without requiring a reload.
			Logger::refresh();
			Logger::info( '[DiluxOne Offload ConfigManager] Plugin settings saved successfully' );
		}

		return $saved;
	}

	/**
	 * Get current plugin state
	 *
	 * @return string
	 */
	public static function get_state() {
		return get_option( self::STATE_OPTION, PluginState::NOT_CONFIGURED );
	}

	/**
	 * Set plugin state
	 * Uses autoload=true because state is checked frequently in admin
	 *
	 * @param string $state
	 * @return bool
	 */
	public static function set_state( $state ) {
		if ( ! in_array( $state, PluginState::get_all_states(), true ) ) {
			Logger::error( '[DiluxOne Offload ConfigManager] Invalid state: ' . $state );
			return false;
		}

		$old_state = self::get_state();

		// Save with autoload=true (state checked frequently in admin UI)
		$updated = self::persist_option( self::STATE_OPTION, $state, true );

		if ( $updated ) {
			// Get caller information for debugging — only when verbose logging is on,
			// since debug_backtrace() is mildly expensive and intended for diagnostics.
			$verbose = ( defined( 'WP_DEBUG' ) && WP_DEBUG )
				|| ( defined( 'DILUXONE_OFFLOAD_VERBOSE_LOGGING' ) && DILUXONE_OFFLOAD_VERBOSE_LOGGING );

			if ( $verbose ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Diagnostic info only logged when verbose logging is explicitly enabled.
				$backtrace   = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 3 );
				$frame       = $backtrace[1] ?? array();
				$caller      = $frame['function'] ?? 'unknown';
				$caller_file = isset( $frame['file'] ) ? basename( $frame['file'] ) : 'unknown';
				$caller_line = $frame['line'] ?? 'unknown';

				Logger::info( '[DiluxOne Offload ConfigManager] State changed: ' . $old_state . ' → ' . $state . ' (called from ' . $caller . ' in ' . $caller_file . ':' . $caller_line . ')' );
			} else {
				Logger::info( '[DiluxOne Offload ConfigManager] State changed: ' . $old_state . ' → ' . $state );
			}
		}

		return $updated;
	}

	/**
	 * Check if plugin is configured
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$plugin_config = self::get_plugin_config_dto();

		return $plugin_config->hasCloudProvider() &&
				! empty( $plugin_config->getProviderConfig() ) &&
				self::validate_provider_config(
					$plugin_config->getCloudProvider(),
					$plugin_config->getProviderConfig()
				);
	}

	/**
	 * Transient each provider caches its usage stats under.
	 *
	 * Kept here, not in the providers, because the admin needs to read the
	 * cached value without instantiating a client — see get_cached_cloud_stats().
	 */
	const STATS_TRANSIENTS = array(
		'azure' => 'diluxone_offload_azure_stats',
	);

	/**
	 * Cloud usage stats, but only if they are already cached.
	 *
	 * Fetching them costs a full container listing — 12 seconds against a
	 * container with ~14k blobs, and it grows with the library. Doing that
	 * while rendering an admin page means the page does not paint until the
	 * cloud answers, which is what made Overview and Cloud Provider feel hung.
	 *
	 * So page rendering calls this, never the provider: it returns what is in
	 * the transient or null, and never touches the network. On null the
	 * template paints a skeleton and the browser asks for the real numbers
	 * through the refresh_stats AJAX endpoint.
	 *
	 * @return array<string, mixed>|null Cached stats payload, or null when nothing is cached.
	 */
	public static function get_cached_cloud_stats(): ?array {
		$provider = self::get_config()['cloud_provider'] ?? '';

		if ( ! isset( self::STATS_TRANSIENTS[ $provider ] ) ) {
			return null;
		}

		$cached = get_transient( self::STATS_TRANSIENTS[ $provider ] );

		return $cached === false ? null : array(
			'success' => true,
			'data'    => $cached,
		);
	}

	/**
	 * Get cloud storage client instance
	 *
	 * @return \DiluxOneOffload\Interfaces\CloudStorageClientInterface|null
	 */
	public static function get_cloud_client() {
		/**
		 * Short-circuit the provider lookup.
		 *
		 * Mirrors WordPress's pre_* convention: return a client here and the
		 * saved configuration is not consulted at all. This is the seam for
		 * a custom or wrapped provider — and for tests, which use it to drive
		 * the sync engine against a stand-in without a cloud account.
		 *
		 * @param \DiluxOneOffload\Interfaces\CloudStorageClientInterface|null $pre Client to use, or null to continue.
		 */
		$pre = apply_filters( 'diluxone_offload_pre_cloud_client', null );
		if ( $pre instanceof \DiluxOneOffload\Interfaces\CloudStorageClientInterface ) {
			return $pre;
		}

		if ( ! self::is_configured() ) {
			return null;
		}

		$plugin_config = self::get_plugin_config_dto();

		try {
			// The provider config carries the credentials; the "Upload Timeout"
			// from the Settings tab travels with it so the provider's upload
			// requests honour it.
			return CloudStorageFactory::create(
				$plugin_config->getCloudProvider(),
				array_merge(
					$plugin_config->getProviderConfig(),
					array( 'upload_timeout' => $plugin_config->getTimeout() )
				)
			);
		} catch ( \Exception $e ) {
			Logger::error( '[DiluxOne Offload ConfigManager] Failed to create cloud client: ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * Test cloud connection
	 *
	 * @return array<string, mixed> ['success' => bool, 'message' => string]
	 */
	public static function test_connection() {
		$client = self::get_cloud_client();

		if ( ! $client ) {
			return array(
				'success' => false,
				'message' => 'Cloud client not configured',
			);
		}

		return $client->test_connection();
	}

	/**
	 * Get sync progress metadata
	 * Uses autoload=false (large, temporary operational data)
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_sync_progress() {
		return get_option( self::SYNC_META_OPTION, null );
	}

	/**
	 * Save sync progress metadata
	 * Uses autoload=false (changes constantly during sync)
	 *
	 * @param array<string, mixed> $progress
	 * @return bool
	 */
	public static function save_sync_progress( $progress ) {
		return self::persist_option( self::SYNC_META_OPTION, $progress, false );
	}

	/**
	 * Clear sync progress metadata
	 *
	 * @return bool
	 */
	public static function clear_sync_progress() {
		return delete_option( self::SYNC_META_OPTION );
	}

	/**
	 * Get failed files (persistent across syncs)
	 *
	 * @return array<string, mixed>
	 */
	public static function get_failed_files() {
		return get_option( self::FAILED_FILES_OPTION, array() );
	}

	/**
	 * Save failed files (persistent)
	 *
	 * @param array<int, mixed> $failed_files List of failed-file entries
	 * @return bool
	 */
	public static function save_failed_files( $failed_files ) {
		return self::persist_option( self::FAILED_FILES_OPTION, $failed_files, false );
	}

	/**
	 * Add failed files to persistent list
	 *
	 * @param array<string, mixed> $new_failed_files
	 * @return bool
	 */
	public static function add_failed_files( $new_failed_files ) {
		$existing = self::get_failed_files();
		$merged   = array_merge( $existing, $new_failed_files );

		// Remove duplicates based on file path
		$unique = array();
		foreach ( $merged as $file ) {
			$path = $file['file'] ?? $file['local_path'] ?? '';
			if ( $path ) {
				$unique[ $path ] = $file;
			}
		}

		return self::save_failed_files( array_values( $unique ) );
	}

	/**
	 * Clear failed files list
	 *
	 * @return bool
	 */
	public static function clear_failed_files() {
		return delete_option( self::FAILED_FILES_OPTION );
	}

	/**
	 * Validate configuration
	 *
	 * @param array<string, mixed> $config
	 * @return array<string, mixed> ['valid' => bool, 'error' => string]
	 */
	private static function validate_config( $config ) {
		// Check cloud provider
		if ( ! empty( $config['cloud_provider'] ) ) {
			if ( ! CloudStorageFactory::is_provider_supported( $config['cloud_provider'] ) ) {
				return array(
					'valid' => false,
					'error' => 'Unsupported cloud provider: ' . $config['cloud_provider'],
				);
			}

			// Validate provider-specific config
			if ( ! self::validate_provider_config( $config['cloud_provider'], $config['provider_config'] ?? array() ) ) {
				return array(
					'valid' => false,
					'error' => 'Invalid provider configuration',
				);
			}
		}

		return array(
			'valid' => true,
			'error' => '',
		);
	}

	/**
	 * Validate provider-specific configuration
	 *
	 * @param string               $provider
	 * @param array<string, mixed> $provider_config
	 * @return bool
	 */
	private static function validate_provider_config( $provider, $provider_config ) {
		$required_fields = CloudStorageFactory::get_provider_config_fields( $provider );

		foreach ( $required_fields as $field => $label ) {
			if ( empty( $provider_config[ $field ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Update plugin state based on configuration
	 *
	 * @param array<string, mixed> $config
	 */
	private static function update_state_from_config( $config ): void {
		$current_state = self::get_state();

		// If we just configured the plugin for the first time
		if ( $current_state === PluginState::NOT_CONFIGURED && self::is_configured() ) {
			self::set_state( PluginState::CONFIGURED );
		}

		// If we lost configuration
		if ( $current_state !== PluginState::NOT_CONFIGURED && ! self::is_configured() ) {
			self::set_state( PluginState::NOT_CONFIGURED );
		}
	}

	/**
	 * Check if offloading is currently enabled
	 *
	 * @return bool
	 */
	public static function is_offloading_enabled() {
		$state = self::get_state();
		return $state === PluginState::OFFLOADING_ACTIVE;
	}

	/**
	 * Enable offloading (activate stream wrapper)
	 * Only possible if synced
	 *
	 * @return bool
	 */
	public static function enable_offloading() {
		$state = self::get_state();

		if ( ! PluginState::can_activate_offloading( $state ) ) {
			Logger::warning( '[DiluxOne Offload ConfigManager] Cannot enable offloading in state: ' . $state );
			return false;
		}

		// Activate stream wrapper
		if ( CloudStreamWrapper::register() && CloudStreamWrapper::activate_offloading() ) {
			self::set_state( PluginState::OFFLOADING_ACTIVE );
			Logger::info( '[DiluxOne Offload ConfigManager] Offloading enabled successfully' );
			return true;
		}

		Logger::error( '[DiluxOne Offload ConfigManager] Failed to enable offloading' );
		return false;
	}

	/**
	 * Disable offloading (deactivate stream wrapper)
	 *
	 * @return bool
	 */
	public static function disable_offloading() {
		$state = self::get_state();

		if ( $state === PluginState::OFFLOADING_ACTIVE ) {
			// Deactivate stream wrapper
			if ( CloudStreamWrapper::deactivate_offloading() && CloudStreamWrapper::unregister() ) {
				self::set_state( PluginState::SYNCED );
				Logger::info( '[DiluxOne Offload ConfigManager] Offloading disabled successfully' );
				return true;
			}
		}

		Logger::error( '[DiluxOne Offload ConfigManager] Failed to disable offloading or not active' );
		return false;
	}

	// ========================================================================
	// Connection Health System
	// ========================================================================

	/**
	 * Get connection health status from wp_options.
	 *
	 * @return array<string, mixed> Connection health data
	 */
	public static function get_connection_health(): array {
		$health = get_option( self::HEALTH_OPTION, self::DEFAULT_HEALTH );
		return wp_parse_args( $health, self::DEFAULT_HEALTH );
	}

	/**
	 * Check connection health with 5-minute TTL cache.
	 * Performs actual test_connection() if stale.
	 *
	 * @return array<string, mixed> Connection health data
	 */
	public static function check_connection_health(): array {
		$health = self::get_connection_health();
		$now    = time();

		// If checked within last 5 minutes, return cached
		if ( $health['last_check'] > 0 && ( $now - $health['last_check'] ) < 300 ) {
			return $health;
		}

		// Not configured = nothing to check
		if ( ! self::is_configured() ) {
			return $health;
		}

		// Perform actual connection test
		try {
			$client = self::get_cloud_client();
			if ( ! $client ) {
				return $health;
			}

			$result = $client->test_connection();
			if ( $result['success'] ) {
				self::record_connection_success();
			} else {
				$msg = $result['message'] ?? 'Unknown error';
				self::record_connection_failure( self::error_code_from_message( $msg ), $msg, 'health_check' );
			}
		} catch ( \Exception $e ) {
			self::record_connection_failure( 'exception', $e->getMessage(), 'health_check' );
		}

		return self::get_connection_health();
	}

	/**
	 * The error code the health banner keys on, read from a provider's
	 * failure message.
	 *
	 * A status is a 4xx or 5xx standing on its own (never digits inside a
	 * name or a size: "60000 milliseconds" is not a 600) and it is read
	 * first, because a message carries file names and one can be called
	 * timeout-banner.jpg. Only a message with no status can be a timeout,
	 * which is its own code because the fix is a setting and not a
	 * credential. Anything else is ''.
	 *
	 * @param string $message The failure message.
	 * @return string A three-digit HTTP status, 'timeout', or ''.
	 */
	public static function error_code_from_message( string $message ): string {
		if ( preg_match( '/\b([45]\d{2})\b/', $message, $matches ) ) {
			return $matches[1];
		}
		if ( stripos( $message, 'timed out' ) !== false || stripos( $message, 'timeout' ) !== false ) {
			return 'timeout';
		}
		return '';
	}

	/**
	 * Record a connection failure from any source.
	 * Marks unhealthy, increments failures, clears stats transients.
	 *
	 * @param string $error_code HTTP status code or error type
	 * @param string $error_message Human-readable error message
	 * @param string $source Where the error was detected
	 */
	public static function record_connection_failure( string $error_code, string $error_message, string $source ): void {
		$health                         = self::get_connection_health();
		$health['status']               = 'unhealthy';
		$health['last_check']           = time();
		$health['error_code']           = $error_code;
		$health['error_message']        = $error_message;
		$health['error_source']         = $source;
		$health['consecutive_failures'] = ( $health['consecutive_failures'] ?? 0 ) + 1;

		update_option( self::HEALTH_OPTION, $health, true );

		// Clear stats transients to prevent stale data
		delete_transient( 'diluxone_offload_azure_stats' );

		Logger::warning( '[DiluxOne Offload ConfigManager] Connection failure recorded: ' . $error_code . ' - ' . $error_message . ' (source: ' . $source . ', consecutive: ' . $health['consecutive_failures'] . ')' );
	}

	/**
	 * Record a successful connection (auto-recovery).
	 * Marks healthy, resets failures, clears error fields.
	 */
	public static function record_connection_success(): void {
		$health = self::get_connection_health();

		// Only log recovery if was previously unhealthy
		if ( $health['status'] === 'unhealthy' ) {
			Logger::info( '[DiluxOne Offload ConfigManager] Connection recovered — marking healthy' );
		}

		$health['status']               = 'healthy';
		$health['last_check']           = time();
		$health['last_success']         = time();
		$health['error_code']           = '';
		$health['error_message']        = '';
		$health['error_source']         = '';
		$health['consecutive_failures'] = 0;

		update_option( self::HEALTH_OPTION, $health, true );
	}

	/**
	 * Clear connection health data (used on provider removal/reset).
	 */
	public static function clear_connection_health(): void {
		delete_option( self::HEALTH_OPTION );
	}

	/**
	 * Reset plugin to initial state
	 *
	 * @return bool
	 */
	public static function reset() {
		$deleted_config   = delete_option( self::CONFIG_OPTION );
		$deleted_state    = delete_option( self::STATE_OPTION );
		$deleted_progress = delete_option( self::SYNC_META_OPTION );
		delete_option( self::HEALTH_OPTION );

		if ( $deleted_config || $deleted_state || $deleted_progress ) {
			Logger::info( '[DiluxOne Offload ConfigManager] Plugin reset completed' );
		}

		return true;
	}
}
