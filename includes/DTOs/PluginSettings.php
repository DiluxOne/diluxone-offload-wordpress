<?php
/**
 * Plugin user-facing settings value object.
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload\DTOs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin Settings DTO
 *
 * Immutable value object for general plugin settings (non-provider specific)
 *
 * @package DiluxOneOffload\DTOs
 * @since 1.0.0
 */
class PluginSettings {

	/** @var bool */
	private bool $debugEnabled;
	/** @var bool */
	private bool $keepLocalFiles;
	/** @var bool */
	private bool $autoActivateOffloading;
	/** @var bool */
	private bool $forceHttpsOnCloud;
	/** @var int Seconds one transfer request (upload or download) may take: the "Transfer Timeout" setting. */
	private int $timeout;
	/** @var int */
	private int $maxFileSize;
	/** @var string */
	private string $allowedFileTypes;
	/** @var bool Whether new uploads carry a Cache-Control header. */
	private bool $cacheControlEnabled;
	/** @var string The Cache-Control value new uploads carry. */
	private string $cacheControl;
	/** @var string `standard` or `infrequent`: the storage class or access tier of new uploads. */
	private string $storageClass;
	/** @var string[] Folders under uploads/ the initial sync leaves out, each ending in `/`. */
	private array $excludedFolders;
	/** @var bool Whether the site's administrator e-mail hears when uploads pause and when they resume. */
	private bool $notifyEmail;

	/** One week in browsers' and CDNs' caches: what a site's media rarely outlives. */
	const DEFAULT_CACHE_CONTROL = 'public, max-age=604800';

	/** The storage classes a site can choose, by the name the settings store. */
	const STORAGE_CLASSES = array( 'standard', 'infrequent' );

	/**
	 * Constructor
	 *
	 * @param bool     $debugEnabled
	 * @param bool     $keepLocalFiles
	 * @param bool     $autoActivateOffloading
	 * @param bool     $forceHttpsOnCloud
	 * @param int      $timeout
	 * @param int      $maxFileSize
	 * @param string   $allowedFileTypes
	 * @param bool     $cacheControlEnabled
	 * @param string   $cacheControl
	 * @param string   $storageClass
	 * @param string[] $excludedFolders
	 * @param bool     $notifyEmail
	 *
	 * @throws \InvalidArgumentException When timeout or maxFileSize are negative.
	 */
	public function __construct(
		bool $debugEnabled = false,
		bool $keepLocalFiles = true,
		bool $autoActivateOffloading = true,
		bool $forceHttpsOnCloud = true,
		int $timeout = 60,
		int $maxFileSize = 20971520,
		string $allowedFileTypes = '*',
		bool $cacheControlEnabled = true,
		string $cacheControl = self::DEFAULT_CACHE_CONTROL,
		string $storageClass = 'standard',
		array $excludedFolders = array(),
		bool $notifyEmail = true
	) {
		// Validations
		if ( $timeout <= 0 ) {
			throw new \InvalidArgumentException( 'Timeout must be greater than 0' );
		}
		if ( $maxFileSize <= 0 ) {
			throw new \InvalidArgumentException( 'Max file size must be greater than 0' );
		}

		$this->debugEnabled           = $debugEnabled;
		$this->keepLocalFiles         = $keepLocalFiles;
		$this->autoActivateOffloading = $autoActivateOffloading;
		$this->forceHttpsOnCloud      = $forceHttpsOnCloud;
		// NOTE: use_https removed - HTTPS is always enforced
		$this->timeout          = $timeout;
		$this->maxFileSize      = $maxFileSize;
		$this->allowedFileTypes = $allowedFileTypes;

		$this->cacheControlEnabled = $cacheControlEnabled;
		$this->cacheControl        = self::clean_cache_control( $cacheControl );
		$this->storageClass        = in_array( $storageClass, self::STORAGE_CLASSES, true ) ? $storageClass : 'standard';
		$this->excludedFolders     = self::clean_folders( $excludedFolders );
		$this->notifyEmail         = $notifyEmail;
	}

	/**
	 * Folders as the initial sync compares them: relative to uploads/, no
	 * leading slash, a trailing one, no `..`, no duplicates, at most 50.
	 * `backups`, `/backups/` and `uploads/backups` are all `backups/`.
	 *
	 * @param array<int|string, mixed> $folders The folders, one per entry.
	 * @return string[]
	 */
	public static function clean_folders( array $folders ): array {
		$clean = array();
		foreach ( $folders as $folder ) {
			$folder = trim( str_replace( '\\', '/', (string) $folder ) );
			$folder = (string) preg_replace( '#^/*(wp-content/)?uploads/#', '', '/' . ltrim( $folder, '/' ) );
			$folder = trim( (string) preg_replace( '#/+#', '/', $folder ), '/' );
			if ( '' === $folder || in_array( '..', explode( '/', $folder ), true ) ) {
				continue;
			}
			$clean[ $folder . '/' ] = true;
		}
		return array_slice( array_keys( $clean ), 0, 50 );
	}

	/**
	 * A Cache-Control value as it may travel in a header: the characters its
	 * directives use (letters, digits, spaces, commas, `=` and `-`), at most
	 * 200 of them; the default when nothing is left.
	 *
	 * @param string $value The value typed on the Serving tab.
	 * @return string
	 */
	public static function clean_cache_control( string $value ): string {
		$value = trim( (string) preg_replace( '/\s+/', ' ', (string) preg_replace( '/[^A-Za-z0-9 ,=\-]/', '', $value ) ) );
		return '' === $value ? self::DEFAULT_CACHE_CONTROL : substr( $value, 0, 200 );
	}

	/**
	 * Create from array configuration
	 *
	 * @param array<string, mixed> $config
	 * @return self
	 */
	public static function fromArray( array $config ): self {
		return new self(
			$config['debug_enabled'] ?? false,
			$config['keep_local_files'] ?? true,
			$config['auto_activate_offloading'] ?? true,
			$config['force_https_on_cloud'] ?? true,
			$config['timeout'] ?? 60,
			$config['max_file_size'] ?? 20971520,
			$config['allowed_file_types'] ?? '*',
			(bool) ( $config['cache_control_enabled'] ?? true ),
			(string) ( $config['cache_control'] ?? self::DEFAULT_CACHE_CONTROL ),
			(string) ( $config['storage_class'] ?? 'standard' ),
			(array) ( $config['excluded_folders'] ?? array() ),
			(bool) ( $config['notify_email'] ?? true )
		);
	}

	/**
	 * Create from POST data
	 *
	 * @param array<string, mixed> $post POST data from form
	 * @return self
	 */
	public static function fromPost( array $post ): self {
		// The caller hands over the named form fields, already unslashed and
		// sanitized as text. The two numbers are clamped to the range the
		// Settings form offers: a typo like 99999999999 MB would otherwise
		// overflow to a float and fatal on the typed constructor.
		return new self(
			isset( $post['enable_debug_logging'] ),
			true,
			true,
			isset( $post['force_https_on_cloud'] ),
			max( 30, min( 600, intval( $post['timeout'] ?? 60 ) ) ),
			max( 1, min( 500, intval( $post['max_file_size'] ?? 20 ) ) ) * 1048576, // Convert MB to bytes
			sanitize_text_field( (string) ( $post['allowed_file_types'] ?? '*' ) )
		);
	}

	/**
	 * The settings with one Settings tab's posted fields applied.
	 *
	 * Each tab of the Settings screen is its own form and posts only its own
	 * fields, so a save must not touch the others: an unchecked box on
	 * Serving is absent from a Transfers post, and absence there means
	 * "leave it", not "off". The numbers are clamped to the range the form
	 * offers, like fromPost().
	 *
	 * @param string               $group `transfers`, `serving` or `logging`.
	 * @param array<string, mixed> $post  The posted fields, unslashed and sanitized as text.
	 * @return self
	 * @throws \InvalidArgumentException For a group that is not a Settings tab.
	 */
	public function withPostedGroup( string $group, array $post ): self {
		switch ( $group ) {
			case 'transfers':
				return $this->merge(
					array(
						'timeout'          => max( 30, min( 600, intval( $post['timeout'] ?? $this->timeout ) ) ),
						'max_file_size'    => max( 1, min( 500, intval( $post['max_file_size'] ?? round( $this->maxFileSize / 1048576 ) ) ) ) * 1048576,
						'excluded_folders' => isset( $post['excluded_folders'] ) ? preg_split( '/\R/', (string) $post['excluded_folders'] ) : $this->excludedFolders,
					)
				);
			case 'serving':
				return $this->merge(
					array(
						'force_https_on_cloud'  => isset( $post['force_https_on_cloud'] ),
						'cache_control_enabled' => isset( $post['cache_control_enabled'] ),
						'cache_control'         => (string) ( $post['cache_control'] ?? $this->cacheControl ),
						'storage_class'         => (string) ( $post['storage_class'] ?? $this->storageClass ),
					)
				);
			case 'logging':
				return $this->merge(
					array(
						'debug_enabled' => isset( $post['enable_debug_logging'] ),
						'notify_email'  => isset( $post['notify_email'] ),
					)
				);
		}

		throw new \InvalidArgumentException( 'Unknown settings group: ' . esc_html( $group ) );
	}

	/**
	 * Check if debug is enabled
	 *
	 * @return bool
	 */
	public function isDebugEnabled(): bool {
		return $this->debugEnabled;
	}

	/**
	 * Check if should keep local files
	 *
	 * @return bool
	 */
	public function shouldKeepLocalFiles(): bool {
		return $this->keepLocalFiles;
	}

	/**
	 * Check if should auto-activate offloading after sync
	 *
	 * @return bool
	 */
	public function shouldAutoActivateOffloading(): bool {
		return $this->autoActivateOffloading;
	}

	/**
	 * Check if cloud-host URLs should be re-upgraded to https://.
	 *
	 * @return bool
	 */
	public function shouldForceHttpsOnCloud(): bool {
		return $this->forceHttpsOnCloud;
	}

	// NOTE: isHttpsEnabled() removed - HTTPS is always enforced (Azure requirement)

	/**
	 * Get timeout in seconds
	 *
	 * @return int
	 */
	public function getTimeout(): int {
		return $this->timeout;
	}

	/**
	 * Get max file size in bytes
	 *
	 * @return int
	 */
	public function getMaxFileSize(): int {
		return $this->maxFileSize;
	}

	/**
	 * Get max file size in megabytes
	 *
	 * @return float
	 */
	public function getMaxFileSizeMB(): float {
		return $this->maxFileSize / 1048576;  // 1024 * 1024
	}

	/**
	 * Get allowed file types
	 *
	 * @return string
	 */
	public function getAllowedFileTypes(): string {
		return $this->allowedFileTypes;
	}

	/**
	 * Whether new uploads carry a Cache-Control header.
	 *
	 * @return bool
	 */
	public function isCacheControlEnabled(): bool {
		return $this->cacheControlEnabled;
	}

	/**
	 * The Cache-Control value the setting holds, sent or not.
	 *
	 * @return string
	 */
	public function getCacheControl(): string {
		return $this->cacheControl;
	}

	/**
	 * The Cache-Control header new uploads carry; '' when the setting is off.
	 *
	 * @return string
	 */
	public function getCacheControlHeader(): string {
		return $this->cacheControlEnabled ? $this->cacheControl : '';
	}

	/**
	 * The storage class of new uploads: `standard` or `infrequent`.
	 *
	 * @return string
	 */
	public function getStorageClass(): string {
		return $this->storageClass;
	}

	/**
	 * Folders under uploads/ the initial sync leaves out, each ending in `/`.
	 *
	 * @return string[]
	 */
	public function getExcludedFolders(): array {
		return $this->excludedFolders;
	}

	/**
	 * Whether the administrator e-mail hears when uploads pause and resume.
	 *
	 * @return bool
	 */
	public function shouldNotifyEmail(): bool {
		return $this->notifyEmail;
	}

	/**
	 * Convert to array format
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'debug_enabled'            => $this->debugEnabled,
			'keep_local_files'         => $this->keepLocalFiles,
			'auto_activate_offloading' => $this->autoActivateOffloading,
			'force_https_on_cloud'     => $this->forceHttpsOnCloud,
			// NOTE: use_https removed - HTTPS is always enforced
			'timeout'                  => $this->timeout,
			'max_file_size'            => $this->maxFileSize,
			'allowed_file_types'       => $this->allowedFileTypes,
			'cache_control_enabled'    => $this->cacheControlEnabled,
			'cache_control'            => $this->cacheControl,
			'storage_class'            => $this->storageClass,
			'excluded_folders'         => $this->excludedFolders,
			'notify_email'             => $this->notifyEmail,
		);
	}

	/**
	 * Merge with updates (create new instance with updated fields)
	 *
	 * @param array<string, mixed> $updates Array of fields to update
	 * @return self New instance with updated fields
	 */
	public function merge( array $updates ): self {
		$current = $this->toArray();
		$merged  = array_merge( $current, $updates );
		return self::fromArray( $merged );
	}
}
