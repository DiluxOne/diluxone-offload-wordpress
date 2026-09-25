<?php
/**
 * Single-file upload result envelope.
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload\DTOs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Upload Result DTO
 *
 * Immutable value object for upload operation results
 * Note: Uses composition instead of inheritance to avoid method signature conflicts
 *
 * @package DiluxOneOffload\DTOs
 * @since 1.0.0
 */
class UploadResult {

	/** @var bool */
	private bool $success;
	/** @var string */
	private string $url;
	/** @var string */
	private string $remotePath;
	/** @var string */
	private string $error;

	/**
	 * Constructor
	 *
	 * @param bool   $success
	 * @param string $url
	 * @param string $remotePath
	 * @param string $error
	 */
	public function __construct( bool $success, string $url = '', string $remotePath = '', string $error = '' ) {
		$this->success    = $success;
		$this->url        = $url;
		$this->remotePath = $remotePath;
		$this->error      = $error;
	}

	/**
	 * Create successful upload result
	 *
	 * @param string $url
	 * @param string $remotePath
	 * @return self
	 */
	public static function success( string $url, string $remotePath ): self {
		return new self( true, $url, $remotePath, '' );
	}

	/**
	 * Create failed upload result
	 *
	 * @param string $error
	 * @return self
	 */
	public static function failure( string $error ): self {
		return new self( false, '', '', $error );
	}

	/**
	 * Get uploaded file URL
	 *
	 * @return string
	 */
	public function getUrl(): string {
		return $this->url;
	}

	/**
	 * Get remote path
	 *
	 * @return string
	 */
	public function getRemotePath(): string {
		return $this->remotePath;
	}

	/**
	 * Convert to legacy array format
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'success'     => $this->isSuccess(),
			'url'         => $this->url,
			'remote_path' => $this->remotePath,
			'error'       => $this->getError(),
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
			$data['success'] ?? false,
			$data['url'] ?? '',
			$data['remote_path'] ?? '',
			$data['error'] ?? ''
		);
	}

	/**
	 * Check if operation was successful
	 *
	 * @return bool
	 */
	public function isSuccess(): bool {
		return $this->success;
	}

	/**
	 * Check if operation failed
	 *
	 * @return bool
	 */
	public function isFailure(): bool {
		return ! $this->success;
	}

	/**
	 * Get error message
	 *
	 * @return string
	 */
	public function getError(): string {
		return $this->error;
	}
}
