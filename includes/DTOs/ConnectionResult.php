<?php
/**
 * Result envelope for provider connection tests.
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload\DTOs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connection Test Result DTO
 *
 * Immutable value object for connection test results
 *
 * @package DiluxOneOffload\DTOs
 * @since 1.0.0
 */
class ConnectionResult {

	/** @var bool */
	private bool $success;
	/** @var string */
	private string $message;

	/**
	 * Constructor
	 *
	 * @param bool   $success
	 * @param string $message
	 */
	public function __construct( bool $success, string $message ) {
		$this->success = $success;
		$this->message = $message;
	}

	/**
	 * Create successful connection result
	 *
	 * @param string $message
	 * @return self
	 */
	public static function success( string $message = 'Connection successful' ): self {
		return new self( true, $message );
	}

	/**
	 * Create failed connection result
	 *
	 * @param string $message
	 * @return self
	 */
	public static function failure( string $message ): self {
		return new self( false, $message );
	}

	/**
	 * Check if connection was successful
	 *
	 * @return bool
	 */
	public function isSuccess(): bool {
		return $this->success;
	}

	/**
	 * Check if connection failed
	 *
	 * @return bool
	 */
	public function isFailure(): bool {
		return ! $this->success;
	}

	/**
	 * Get result message
	 *
	 * @return string
	 */
	public function getMessage(): string {
		return $this->message;
	}

	/**
	 * Convert to legacy array format
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'success' => $this->success,
			'message' => $this->message,
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
			$data['message'] ?? ''
		);
	}
}
