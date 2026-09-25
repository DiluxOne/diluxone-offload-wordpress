<?php
/**
 * Filter parameters for sync queries.
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload\DTOs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sync Filter DTO
 *
 * Immutable value object for file filtering during sync
 *
 * @package DiluxOneOffload\DTOs
 * @since 1.0.0
 */
class SyncFilter {

	/** @var string */
	private string $allowedFileTypes;
	/** @var int */
	private int $maxFileSize;
	/**
	 * @var array<string, mixed>
	 */
	private array $excludedPaths;
	/** @var bool */
	private bool $includeHiddenFiles;
	/** @var bool */
	private bool $includeSystemFiles;

	/**
	 * Constructor
	 *
	 * @param string               $allowedFileTypes Comma-separated extensions or '*' for all
	 * @param int                  $maxFileSize Maximum file size in bytes (0 = no limit)
	 * @param array<string, mixed> $excludedPaths Array of paths/patterns to exclude
	 * @param bool                 $includeHiddenFiles Whether to include files starting with '.'
	 * @param bool                 $includeSystemFiles Whether to include system files (.htaccess, index.php, etc.)
	 *
	 * @throws \InvalidArgumentException When maxFileSize is negative.
	 */
	public function __construct(
		string $allowedFileTypes = '*',
		int $maxFileSize = 0,
		array $excludedPaths = array(),
		bool $includeHiddenFiles = false,
		bool $includeSystemFiles = true
	) {
		if ( $maxFileSize < 0 ) {
			throw new \InvalidArgumentException( 'Max file size cannot be negative' );
		}

		$this->allowedFileTypes   = $allowedFileTypes;
		$this->maxFileSize        = $maxFileSize;
		$this->excludedPaths      = $excludedPaths;
		$this->includeHiddenFiles = $includeHiddenFiles;
		$this->includeSystemFiles = $includeSystemFiles;
	}

	/**
	 * Create from configuration array
	 *
	 * @param array<string, mixed> $config
	 * @return self
	 */
	public static function fromConfig( array $config ): self {
		return new self(
			$config['allowed_file_types'] ?? '*',
			$config['max_file_size'] ?? 0,
			$config['excluded_paths'] ?? array(),
			$config['include_hidden_files'] ?? false,
			$config['include_system_files'] ?? true
		);
	}

	/**
	 * Create permissive filter (sync everything)
	 *
	 * @return self
	 */
	public static function allowAll(): self {
		return new self( '*', 0, array(), true, true );
	}

	/**
	 * Create media-only filter
	 *
	 * @return self
	 */
	public static function mediaOnly(): self {
		return new self(
			'jpg,jpeg,png,gif,webp,svg,pdf,mp4,mp3,zip',
			0,
			array(),
			false,
			false
		);
	}

	/**
	 * Check if file should be included in sync
	 *
	 * @param string $filePath
	 * @param int    $fileSize
	 * @return bool
	 */
	public function shouldIncludeFile( string $filePath, int $fileSize ): bool {
		// Check file size
		if ( $this->maxFileSize > 0 && $fileSize > $this->maxFileSize ) {
			return false;
		}

		// Check excluded paths
		foreach ( $this->excludedPaths as $excludedPath ) {
			if ( strpos( $filePath, $excludedPath ) !== false ) {
				return false;
			}
		}

		// Check hidden files
		$fileName = basename( $filePath );
		if ( ! $this->includeHiddenFiles && strpos( $fileName, '.' ) === 0 ) {
			return false;
		}

		// Check system files
		if ( ! $this->includeSystemFiles ) {
			$systemFiles = array( '.htaccess', 'index.php', 'wp-config.php', '.env' );
			if ( in_array( $fileName, $systemFiles, true ) ) {
				return false;
			}
		}

		// Check file extension
		if ( $this->allowedFileTypes === '*' ) {
			return true;  // Allow all types
		}

		$extension         = strtolower( pathinfo( $filePath, PATHINFO_EXTENSION ) );
		$allowedExtensions = array_map( 'trim', explode( ',', strtolower( $this->allowedFileTypes ) ) );

		return in_array( $extension, $allowedExtensions, true );
	}

	/**
	 * Get reason why file was excluded (for debugging)
	 *
	 * @param string $filePath
	 * @param int    $fileSize
	 * @return string|null Returns null if file should be included
	 */
	public function getExclusionReason( string $filePath, int $fileSize ): ?string {
		// Check file size
		if ( $this->maxFileSize > 0 && $fileSize > $this->maxFileSize ) {
			return 'File size exceeds limit (' . $this->formatBytes( $fileSize ) . ' > ' . $this->formatBytes( $this->maxFileSize ) . ')';
		}

		// Check excluded paths
		foreach ( $this->excludedPaths as $excludedPath ) {
			if ( strpos( $filePath, $excludedPath ) !== false ) {
				return 'Path matches exclusion pattern: ' . $excludedPath;
			}
		}

		// Check hidden files
		$fileName = basename( $filePath );
		if ( ! $this->includeHiddenFiles && strpos( $fileName, '.' ) === 0 ) {
			return 'Hidden file (starts with .)';
		}

		// Check system files
		if ( ! $this->includeSystemFiles ) {
			$systemFiles = array( '.htaccess', 'index.php', 'wp-config.php', '.env' );
			if ( in_array( $fileName, $systemFiles, true ) ) {
				return 'System file';
			}
		}

		// Check file extension
		if ( $this->allowedFileTypes !== '*' ) {
			$extension         = strtolower( pathinfo( $filePath, PATHINFO_EXTENSION ) );
			$allowedExtensions = array_map( 'trim', explode( ',', strtolower( $this->allowedFileTypes ) ) );

			if ( ! in_array( $extension, $allowedExtensions, true ) ) {
				return 'File extension not allowed: .' . $extension;
			}
		}

		return null;  // File should be included
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
	 * Get max file size in bytes
	 *
	 * @return int
	 */
	public function getMaxFileSize(): int {
		return $this->maxFileSize;
	}

	/**
	 * Get excluded paths
	 *
	 * @return array<string, mixed>
	 */
	public function getExcludedPaths(): array {
		return $this->excludedPaths;
	}

	/**
	 * Check if allows all file types
	 *
	 * @return bool
	 */
	public function allowsAllTypes(): bool {
		return $this->allowedFileTypes === '*';
	}

	/**
	 * Check if has file size limit
	 *
	 * @return bool
	 */
	public function hasFileSizeLimit(): bool {
		return $this->maxFileSize > 0;
	}

	/**
	 * Format bytes to human readable format
	 *
	 * @param int $bytes
	 * @return string
	 */
	private function formatBytes( int $bytes ): string {
		if ( $bytes >= 1073741824 ) {
			return number_format( $bytes / 1073741824, 2 ) . ' GB';
		} elseif ( $bytes >= 1048576 ) {
			return number_format( $bytes / 1048576, 2 ) . ' MB';
		} elseif ( $bytes >= 1024 ) {
			return number_format( $bytes / 1024, 2 ) . ' KB';
		} else {
			return $bytes . ' bytes';
		}
	}

	/**
	 * Convert to array
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'allowed_file_types'   => $this->allowedFileTypes,
			'max_file_size'        => $this->maxFileSize,
			'excluded_paths'       => $this->excludedPaths,
			'include_hidden_files' => $this->includeHiddenFiles,
			'include_system_files' => $this->includeSystemFiles,
		);
	}
}
