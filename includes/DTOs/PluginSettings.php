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

	/**
	 * Constructor
	 *
	 * @param bool   $debugEnabled
	 * @param bool   $keepLocalFiles
	 * @param bool   $autoActivateOffloading
	 * @param bool   $forceHttpsOnCloud
	 * @param int    $timeout
	 * @param int    $maxFileSize
	 * @param string $allowedFileTypes
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
		string $allowedFileTypes = '*'
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
			$config['allowed_file_types'] ?? '*'
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
