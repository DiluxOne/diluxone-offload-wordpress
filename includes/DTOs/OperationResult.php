<?php
/**
 * Generic success/error result envelope used across providers.
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload\DTOs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base Operation Result DTO
 *
 * Immutable value object for operation results
 *
 * @package DiluxOneOffload\DTOs
 * @since 1.0.0
 */
class OperationResult {

	/** @var bool */
	private bool $success;
	/** @var string */
	private string $error;

	/**
	 * Constructor
	 *
	 * @param bool   $success
	 * @param string $error
	 */
	public function __construct( bool $success, string $error = '' ) {
		$this->success = $success;
		$this->error   = $error;
	}

	/**
	 * Create successful result
	 *
	 * @param string $message Optional success message
	 * @return self
	 */
	public static function success( string $message = '' ): self {
		return new self( true, $message );
	}

	/**
	 * Create failed result
	 *
	 * @param string $error
	 * @return self
	 */
	public static function failure( string $error ): self {
		return new self( false, $error );
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

	/**
	 * Convert to legacy array format
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'success' => $this->success,
			'error'   => $this->error,
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
			$data['error'] ?? ''
		);
	}
}
