<?php
/**
 * Validation helpers for sync operations.
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload;

use DiluxOneOffload\Enums\PluginState;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validation Helper
 *
 * The single place every sync operation is validated. It runs twice per
 * operation:
 * 1. Pre-check: before the modal with the options is shown.
 * 2. Execution-check: before the action runs, once the user has confirmed.
 *
 * Running it twice is what keeps a slow confirmation from racing another tab.
 */
class ValidationHelper {

	/**
	 * Decide whether a sync operation may run.
	 *
	 * @param string $requesting_session_id Session id of the tab asking.
	 * @param string $operation_type One of: 'start_sync', 'retry_failed', 'clear_and_enable', 'enable_offloading', 'disconnect', 'cancel_sync'.
	 * @return array<string, mixed> ['passed' => bool, 'reason' => string, 'details' => array]
	 */
	public static function validate_sync_operation( $requesting_session_id, $operation_type ) {
		Logger::debug( '[DiluxOne Offload Validation] Validating operation: ' . $operation_type . ' from session: ' . $requesting_session_id );

		// 1. Multi-tab check (only for operations that change the sync).
		if ( self::requires_multi_tab_check( $operation_type ) ) {
			$multi_tab_result = self::validate_multi_tab( $requesting_session_id );
			if ( ! $multi_tab_result['passed'] ) {
				return $multi_tab_result;
			}
		}

		// 2. Plugin state check
		$state_result = self::validate_plugin_state( $operation_type );
		if ( ! $state_result['passed'] ) {
			return $state_result;
		}

		// 3. Files check (per operation).
		$files_result = self::validate_files_state( $operation_type );
		if ( ! $files_result['passed'] ) {
			return $files_result;
		}

		// Everything passed.
		Logger::info( '[DiluxOne Offload Validation] All validations PASSED for operation: ' . $operation_type );
		return array(
			'passed'  => true,
			'reason'  => '',
			'details' => array(),
		);
	}

	/**
	 * Whether the operation needs the multi-tab check.
	 *
	 * @param mixed $operation_type
	 * @return bool
	 */
	private static function requires_multi_tab_check( $operation_type ) {
		return in_array(
			$operation_type,
			array(
				'start_sync',
				'retry_failed',
				'clear_and_enable',
				'disconnect',
				'prepare_resync',
				'cancel_sync', // Reset Sync needs the multi-tab check too.
			),
			true
		);
	}

	/**
	 * Check that no other browser tab is driving the sync.
	 *
	 * @param mixed $requesting_session_id
	 * @return array<string, mixed>
	 */
	private static function validate_multi_tab( $requesting_session_id ): array {
		$sync_meta = get_option( 'diluxone_offload_sync_meta', array() );

		if ( empty( $sync_meta ) ) {
			// No sync running: nothing to collide with.
			return array(
				'passed'  => true,
				'reason'  => '',
				'details' => array(),
			);
		}

		$active_session  = $sync_meta['sync_session_id'] ?? '';
		$status          = $sync_meta['status'] ?? '';
		$last_heartbeat  = $sync_meta['last_heartbeat'] ?? 0;
		$is_reverse_sync = $sync_meta['is_reverse_sync'] ?? false;

		// Finished syncs (completed, failed, completed_with_errors) are checked
		// before the timeout, so a completed sync is never cleaned up as stale.
		if ( in_array( $status, array( 'completed', 'completed_with_errors', 'failed' ), true ) ) {
			// Finished: let it through. Clearing it is activate_offloading()'s job.
			return array(
				'passed'  => true,
				'reason'  => '',
				'details' => array(),
			);
		}

		// The heartbeat only matters while a sync is actually running.
		if ( $status === 'started' ) {
			// 90 seconds without a heartbeat means the tab is gone.
			$heartbeat_timeout = 90;
			if ( time() - $last_heartbeat > $heartbeat_timeout ) {
				// A reverse sync keeps the plugin in OFFLOADING_ACTIVE: drop the
				// metadata only.
				if ( $is_reverse_sync ) {
					Logger::warning( '[DiluxOne Offload Validation] Reverse sync session expired (no heartbeat for ' . ( time() - $last_heartbeat ) . 's), clearing metadata but preserving OFFLOADING_ACTIVE state' );
					delete_option( 'diluxone_offload_sync_meta' );
					return array(
						'passed'  => true,
						'reason'  => '',
						'details' => array(),
					);
				}

				// A forward sync that died leaves the plugin back at CONFIGURED.
				Logger::warning( '[DiluxOne Offload Validation] Forward sync session expired (no heartbeat for ' . ( time() - $last_heartbeat ) . 's), cleaning up' );
				ConfigManager::set_state( PluginState::CONFIGURED );
				ConfigManager::clear_sync_progress();
				return array(
					'passed'  => true,
					'reason'  => '',
					'details' => array(),
				);
			}
		}

		// A sync is running: only the tab that owns it may act.
		if ( $active_session !== $requesting_session_id ) {
			// Someone else owns it.
			Logger::error( '[DiluxOne Offload Validation] FAILED: Another tab is active (active: ' . $active_session . ', requesting: ' . $requesting_session_id . ')' );
			return array(
				'passed'  => false,
				'reason'  => 'sync_active_in_another_tab',
				'details' => array( 'sync_meta' => $sync_meta ),
			);
		}

		// This tab owns it.
		return array(
			'passed'  => true,
			'reason'  => '',
			'details' => array(),
		);
	}

	/**
	 * Check the plugin state the operation needs.
	 *
	 * @param mixed $operation_type
	 * @return array<string, mixed>
	 */
	private static function validate_plugin_state( $operation_type ): array {
		$current_state = ConfigManager::get_state();

		switch ( $operation_type ) {
			case 'start_sync':
			case 'retry_failed':
				// A sync cannot start while one is already running.
				if ( $current_state === PluginState::SYNCING ) {
					Logger::error( '[DiluxOne Offload Validation] FAILED: Cannot start sync, state is already SYNCING' );
					return array(
						'passed'  => false,
						'reason'  => 'sync_already_active',
						'details' => array( 'current_state' => $current_state ),
					);
				}
				break;

			case 'enable_offloading':
				// Offloading can only be switched on once everything is synced.
				if ( $current_state !== PluginState::SYNCED ) {
					Logger::error( '[DiluxOne Offload Validation] FAILED: Cannot enable offloading, state is ' . $current_state . ' (required: SYNCED)' );
					return array(
						'passed'  => false,
						'reason'  => 'state_conflict',
						'details' => array(
							'current_state'  => $current_state,
							'required_state' => PluginState::SYNCED,
						),
					);
				}
				break;

			case 'disconnect':
				// Disconnecting only makes sense while offloading is active.
				if ( $current_state !== PluginState::OFFLOADING_ACTIVE ) {
					Logger::error( '[DiluxOne Offload Validation] FAILED: Cannot disconnect, state is ' . $current_state . ' (required: OFFLOADING_ACTIVE)' );
					return array(
						'passed'  => false,
						'reason'  => 'state_conflict',
						'details' => array(
							'current_state'  => $current_state,
							'required_state' => PluginState::OFFLOADING_ACTIVE,
						),
					);
				}
				break;
		}

		return array(
			'passed'  => true,
			'reason'  => '',
			'details' => array(),
		);
	}

	/**
	 * Check the tracked files the operation needs.
	 *
	 * @param mixed $operation_type
	 * @return array<string, mixed>
	 */
	private static function validate_files_state( $operation_type ): array {
		if ( $operation_type !== 'enable_offloading' ) {
			// Every other operation is indifferent to the file table.
			return array(
				'passed'  => true,
				'reason'  => '',
				'details' => array(),
			);
		}

		// Offloading may only start when nothing is pending or failed.
		require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';
		$stats = DiluxOneOffloadDB::get_stats();

		$failed_count  = (int) ( $stats['failed_files'] ?? 0 );
		$pending_count = (int) ( $stats['pending_files'] ?? 0 );

		if ( $failed_count > 0 || $pending_count > 0 ) {
			Logger::error( '[DiluxOne Offload Validation] FAILED: Cannot enable offloading, failed=' . $failed_count . ', pending=' . $pending_count );
			return array(
				'passed'  => false,
				'reason'  => 'files_not_synced',
				'details' => array(
					'failed_count'  => $failed_count,
					'pending_count' => $pending_count,
					'stats'         => $stats,
				),
			);
		}

		return array(
			'passed'  => true,
			'reason'  => '',
			'details' => array(),
		);
	}
}
