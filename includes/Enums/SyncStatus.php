<?php
/**
 * SyncStatus enum — values for the sync state machine.
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload\Enums;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sync Status Enum
 *
 * Represents the current state of a synchronization operation
 *
 * @package DiluxOneOffload\Enums
 * @since 1.0.0
 */
class SyncStatus {

	const IDLE      = 'idle';
	const STARTED   = 'started';
	const PAUSED    = 'paused';
	const COMPLETED = 'completed';
	const FAILED    = 'failed';
	const CANCELLED = 'cancelled';

	/**
	 * Get all valid statuses
	 *
	 * @return array<int, string>
	 */
	public static function getAll(): array {
		return array(
			self::IDLE,
			self::STARTED,
			self::PAUSED,
			self::COMPLETED,
			self::FAILED,
			self::CANCELLED,
		);
	}

	/**
	 * Check if status is valid
	 *
	 * @param string $status
	 * @return bool
	 */
	public static function isValid( string $status ): bool {
		return in_array( $status, self::getAll(), true );
	}

	/**
	 * Check if sync is in progress
	 *
	 * @param string $status
	 * @return bool
	 */
	public static function isInProgress( string $status ): bool {
		return in_array( $status, array( self::STARTED, self::PAUSED ), true );
	}

	/**
	 * Check if sync is finished (completed, failed, or cancelled)
	 *
	 * @return bool
	 * @param string $status
	 */
	public static function isFinished( string $status ): bool {
		return in_array( $status, array( self::COMPLETED, self::FAILED, self::CANCELLED ), true );
	}
}
