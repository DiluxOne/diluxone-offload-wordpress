<?php
/**
 * PluginState enum — values for the plugin top-level state machine.
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload\Enums;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin State Enum
 *
 * Defines all possible states of the plugin. A reverse sync (Disconnect)
 * runs while OFFLOADING_ACTIVE; errors are tracked per file, not as a
 * plugin state.
 */
class PluginState {

	const NOT_CONFIGURED    = 'not_configured';       // No cloud provider configured
	const CONFIGURED        = 'configured';               // Cloud provider configured but not synced
	const SYNCING           = 'syncing';                    // Initial sync in progress
	const SYNCED            = 'synced';                      // Initial sync complete, ready for offloading
	const OFFLOADING_ACTIVE = 'offloading_active'; // Stream wrapper active, files go to cloud

	/**
	 * Get all available states
	 *
	 * @return array<int, string>
	 */
	public static function get_all_states(): array {
		return array(
			self::NOT_CONFIGURED,
			self::CONFIGURED,
			self::SYNCING,
			self::SYNCED,
			self::OFFLOADING_ACTIVE,
		);
	}

	/**
	 * Get state display name
	 *
	 * @param string $state State constant
	 * @return string Human readable name
	 */
	public static function get_state_name( $state ) {
		$names = array(
			self::NOT_CONFIGURED    => __( 'Not Configured', 'diluxone-offload' ),
			self::CONFIGURED        => __( 'Configured', 'diluxone-offload' ),
			self::SYNCING           => __( 'Syncing Files', 'diluxone-offload' ),
			self::SYNCED            => __( 'Synced', 'diluxone-offload' ),
			self::OFFLOADING_ACTIVE => __( 'Offloading Active', 'diluxone-offload' ),
		);

		return $names[ $state ] ?? $state;
	}

	/**
	 * Check if state allows sync to start
	 *
	 * @param string $state
	 * @return bool
	 */
	public static function can_start_sync( $state ) {
		return $state === self::CONFIGURED;
	}

	/**
	 * Check if state allows offloading activation
	 *
	 * @param string $state
	 * @return bool
	 */
	public static function can_activate_offloading( $state ) {
		return $state === self::SYNCED;
	}

	/**
	 * Check if offloading is currently active
	 *
	 * @param string $state
	 * @return bool
	 */
	public static function is_offloading_active( $state ) {
		return $state === self::OFFLOADING_ACTIVE;
	}

	/**
	 * Check if state allows disconnection (reverse sync)
	 *
	 * Note: Reverse sync (downloading files from cloud) happens WHILE
	 * the state remains OFFLOADING_ACTIVE. There is no separate
	 * DISCONNECTING state. After reverse sync completes, user must
	 * manually "Deactivate Offloading" to transition to SYNCED.
	 *
	 * @param string $state
	 * @return bool
	 */
	public static function can_disconnect( $state ) {
		return in_array( $state, array( self::SYNCED, self::OFFLOADING_ACTIVE ), true );
	}
}
