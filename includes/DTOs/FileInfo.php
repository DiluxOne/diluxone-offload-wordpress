<?php
/**
 * File metadata value object (path, size, mtime, hash).
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload\DTOs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * File Information DTO
 *
 * Immutable value object for file metadata
 *
 * @package DiluxOneOffload\DTOs
 * @since 1.0.0
 */
class FileInfo {

	/** @var int */
	private int $size;
	/** @var ?string */
	private ?string $md5;
	/** @var ?string */
	private ?string $lastModified;
	/** @var string */
	private string $path;

	/**
	 * Constructor
	 *
	 * @param string      $path
	 * @param int         $size
	 * @param string|null $md5
	 * @param string|null $lastModified
	 */
	public function __construct( string $path, int $size, ?string $md5 = null, ?string $lastModified = null ) {
		$this->path         = $path;
		$this->size         = $size;
		$this->md5          = $md5;
		$this->lastModified = $lastModified;
	}

	/**
	 * Get file path
	 *
	 * @return string
	 */
	public function getPath(): string {
		return $this->path;
	}

	/**
	 * Get file size in bytes
	 *
	 * @return int
	 */
	public function getSize(): int {
		return $this->size;
	}

	/**
	 * Get MD5 hash
	 *
	 * @return string|null
	 */
	public function getMd5(): ?string {
		return $this->md5;
	}

	/**
	 * Get last modified timestamp
	 *
	 * @return string|null
	 */
	public function getLastModified(): ?string {
		return $this->lastModified;
	}

	/**
	 * Check if file has MD5 hash
	 *
	 * @return bool
	 */
	public function hasMd5(): bool {
		return $this->md5 !== null;
	}

	/**
	 * Convert to legacy array format
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'path'          => $this->path,
			'size'          => $this->size,
			'md5'           => $this->md5,
			'last_modified' => $this->lastModified,
		);
	}

	/**
	 * Create from legacy array format
	 *
	 * @param array<string, mixed> $data
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		return new self(
			$data['path'] ?? '',
			$data['size'] ?? 0,
			$data['md5'] ?? null,
			$data['last_modified'] ?? null
		);
	}
}
