<?php
namespace Tests\Unit\CloudStorage;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\ValidationHelper;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\Enums\PluginState;

/**
 * Unit tests for ValidationHelper, the gate every sync action passes twice
 * (before its modal opens and again once the user confirms).
 *
 * It runs on the real ConfigManager over the option stubs: the state is the
 * `diluxone_offload_plugin_state` option and the running sync is the
 * `diluxone_offload_sync_meta` option, exactly what a second browser tab
 * would find. The one check that reads the files table (Enable offloading)
 * gets a scripted $wpdb whose get_row() answers the stats query.
 */
class ValidationHelperTest extends TestCase {

	/** @var mixed */
	private $previous_wpdb;

	/** @var array<string, int>|null What the stats query returns; null plays a missing table. */
	private ?array $stats_row = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! defined( 'ARRAY_A' ) ) {
			define( 'ARRAY_A', 'ARRAY_A' );
		}
		$GLOBALS['_test_wp_options'] = array();
		$this->previous_wpdb         = $GLOBALS['wpdb'] ?? null;
		$test                        = $this;
		$GLOBALS['wpdb']             = new class( $test ) {
			/** @var string */
			public $prefix = 'wp_';
			/** @var ValidationHelperTest */
			private $test;
			public function __construct( $test ) {
				$this->test = $test;
			}
			/** @return array<string, int>|null */
			public function get_row( $query, $output = null ) {
				return $this->test->statsRow();
			}
		};
	}

	protected function tearDown(): void {
		if ( null === $this->previous_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		}
		unset( $GLOBALS['_test_wp_options'] );
		parent::tearDown();
	}

	/** @return array<string, int>|null */
	public function statsRow(): ?array {
		return $this->stats_row;
	}

	private function state( string $state ): void {
		$GLOBALS['_test_wp_options'][ ConfigManager::STATE_OPTION ] = $state;
	}

	/** @param array<string, mixed> $meta */
	private function sync_meta( array $meta ): void {
		$GLOBALS['_test_wp_options'][ ConfigManager::SYNC_META_OPTION ] = $meta;
	}

	private function passes( array $result ): void {
		$this->assertSame( array( 'passed' => true, 'reason' => '', 'details' => array() ), $result );
	}

	// ── Multi-tab ───────────────────────────────────────────

	public function test_a_start_with_no_sync_running_passes(): void {
		$this->state( PluginState::CONFIGURED );
		$this->passes( ValidationHelper::validate_sync_operation( 'tab-a', 'start_sync' ) );
	}

	public function test_another_tab_cannot_drive_a_running_sync(): void {
		$this->state( PluginState::SYNCING );
		$meta = array( 'sync_session_id' => 'tab-a', 'status' => 'started', 'last_heartbeat' => time() );
		$this->sync_meta( $meta );

		$result = ValidationHelper::validate_sync_operation( 'tab-b', 'cancel_sync' );

		$this->assertFalse( $result['passed'] );
		$this->assertSame( 'sync_active_in_another_tab', $result['reason'] );
		$this->assertSame( $meta, $result['details']['sync_meta'] );
	}

	public function test_the_owning_tab_may_act_on_its_own_sync(): void {
		$this->state( PluginState::SYNCING );
		$this->sync_meta( array( 'sync_session_id' => 'tab-a', 'status' => 'started', 'last_heartbeat' => time() ) );

		$this->passes( ValidationHelper::validate_sync_operation( 'tab-a', 'cancel_sync' ) );
	}

	public function test_a_paused_sync_still_belongs_to_its_tab_whatever_its_heartbeat(): void {
		// Only a sync in `started` can go stale: a paused one keeps its owner.
		$this->state( PluginState::CONFIGURED );
		$this->sync_meta( array( 'sync_session_id' => 'tab-a', 'status' => 'paused', 'last_heartbeat' => time() - 3600 ) );

		$result = ValidationHelper::validate_sync_operation( 'tab-b', 'disconnect' );

		$this->assertSame( 'sync_active_in_another_tab', $result['reason'] );
	}

	/** @return array<string, array{string}> */
	public function finishedStatuses(): array {
		return array(
			'completed'             => array( 'completed' ),
			'completed with errors' => array( 'completed_with_errors' ),
			'failed'                => array( 'failed' ),
		);
	}

	/** @dataProvider finishedStatuses */
	public function test_a_finished_sync_never_blocks_another_tab_and_is_left_in_place( string $status ): void {
		$this->state( PluginState::SYNCED );
		$meta = array( 'sync_session_id' => 'tab-a', 'status' => $status, 'last_heartbeat' => 1 );
		$this->sync_meta( $meta );

		$this->passes( ValidationHelper::validate_sync_operation( 'tab-b', 'clear_and_enable' ) );
		$this->assertSame( $meta, get_option( ConfigManager::SYNC_META_OPTION ), 'clearing it is activate_offloading()\'s job' );
	}

	public function test_a_forward_sync_without_a_heartbeat_for_90_seconds_is_abandoned_back_to_configured(): void {
		$this->state( PluginState::SYNCING );
		$this->sync_meta( array( 'sync_session_id' => 'tab-a', 'status' => 'started', 'last_heartbeat' => time() - 91 ) );

		$this->passes( ValidationHelper::validate_sync_operation( 'tab-b', 'start_sync' ) );

		$this->assertSame( PluginState::CONFIGURED, ConfigManager::get_state() );
		$this->assertNull( ConfigManager::get_sync_progress(), 'the dead sync\'s metadata is gone' );
	}

	public function test_a_stale_reverse_sync_drops_its_metadata_but_keeps_offloading_on(): void {
		$this->state( PluginState::OFFLOADING_ACTIVE );
		$this->sync_meta( array( 'sync_session_id' => 'tab-a', 'status' => 'started', 'last_heartbeat' => time() - 600, 'is_reverse_sync' => true ) );

		$this->passes( ValidationHelper::validate_sync_operation( 'tab-b', 'disconnect' ) );

		$this->assertSame( PluginState::OFFLOADING_ACTIVE, ConfigManager::get_state() );
		$this->assertFalse( get_option( ConfigManager::SYNC_META_OPTION ), 'metadata deleted' );
	}

	public function test_a_heartbeat_of_exactly_90_seconds_is_still_alive(): void {
		$this->state( PluginState::SYNCING );
		$this->sync_meta( array( 'sync_session_id' => 'tab-a', 'status' => 'started', 'last_heartbeat' => time() - 90 ) );

		$result = ValidationHelper::validate_sync_operation( 'tab-b', 'retry_failed' );

		$this->assertSame( 'sync_active_in_another_tab', $result['reason'] );
		$this->assertSame( PluginState::SYNCING, ConfigManager::get_state() );
	}

	public function test_enable_offloading_skips_the_multi_tab_check(): void {
		// Not in the list of operations that drive the sync: a running sync
		// owned by another tab does not stop it; the state check does.
		$this->state( PluginState::SYNCED );
		$this->sync_meta( array( 'sync_session_id' => 'tab-a', 'status' => 'started', 'last_heartbeat' => time() ) );
		$this->stats_row = array( 'failed_files' => 0, 'pending_files' => 0 );

		$this->passes( ValidationHelper::validate_sync_operation( 'tab-b', 'enable_offloading' ) );
	}

	// ── Plugin state ────────────────────────────────────────

	/** @return array<string, array{string}> */
	public function startOperations(): array {
		return array(
			'start'        => array( 'start_sync' ),
			'retry failed' => array( 'retry_failed' ),
		);
	}

	/** @dataProvider startOperations */
	public function test_a_sync_cannot_start_while_the_state_is_syncing( string $operation ): void {
		$this->state( PluginState::SYNCING );

		$result = ValidationHelper::validate_sync_operation( 'tab-a', $operation );

		$this->assertFalse( $result['passed'] );
		$this->assertSame( 'sync_already_active', $result['reason'] );
		$this->assertSame( array( 'current_state' => PluginState::SYNCING ), $result['details'] );
	}

	public function test_offloading_can_only_be_enabled_from_synced(): void {
		$this->state( PluginState::CONFIGURED );

		$result = ValidationHelper::validate_sync_operation( 'tab-a', 'enable_offloading' );

		$this->assertSame( 'state_conflict', $result['reason'] );
		$this->assertSame(
			array( 'current_state' => PluginState::CONFIGURED, 'required_state' => PluginState::SYNCED ),
			$result['details']
		);
	}

	public function test_disconnect_needs_offloading_to_be_active(): void {
		$this->state( PluginState::SYNCED );

		$result = ValidationHelper::validate_sync_operation( 'tab-a', 'disconnect' );

		$this->assertSame( 'state_conflict', $result['reason'] );
		$this->assertSame( PluginState::OFFLOADING_ACTIVE, $result['details']['required_state'] );
	}

	public function test_disconnect_passes_while_offloading(): void {
		$this->state( PluginState::OFFLOADING_ACTIVE );
		$this->passes( ValidationHelper::validate_sync_operation( 'tab-a', 'disconnect' ) );
	}

	public function test_an_operation_without_a_state_rule_passes_in_any_state(): void {
		$this->state( PluginState::NOT_CONFIGURED );
		$this->passes( ValidationHelper::validate_sync_operation( 'tab-a', 'prepare_resync' ) );
	}

	// ── Files ───────────────────────────────────────────────

	public function test_offloading_is_refused_while_files_are_pending_or_failed(): void {
		$this->state( PluginState::SYNCED );
		$this->stats_row = array( 'total_files' => 10, 'synced_files' => 7, 'failed_files' => 2, 'pending_files' => 1 );

		$result = ValidationHelper::validate_sync_operation( 'tab-a', 'enable_offloading' );

		$this->assertFalse( $result['passed'] );
		$this->assertSame( 'files_not_synced', $result['reason'] );
		$this->assertSame( 2, $result['details']['failed_count'] );
		$this->assertSame( 1, $result['details']['pending_count'] );
		$this->assertSame( 70.0, $result['details']['stats']['percentage'] );
	}

	public function test_a_single_pending_file_is_enough_to_refuse(): void {
		$this->state( PluginState::SYNCED );
		$this->stats_row = array( 'failed_files' => 0, 'pending_files' => 1 );

		$this->assertSame( 'files_not_synced', ValidationHelper::validate_sync_operation( 'tab-a', 'enable_offloading' )['reason'] );
	}

	public function test_offloading_is_allowed_once_everything_is_synced(): void {
		$this->state( PluginState::SYNCED );
		$this->stats_row = array( 'total_files' => 3, 'synced_files' => 3, 'failed_files' => 0, 'pending_files' => 0 );

		$this->passes( ValidationHelper::validate_sync_operation( 'tab-a', 'enable_offloading' ) );
	}

	public function test_a_site_without_its_table_yet_counts_as_nothing_pending(): void {
		$this->state( PluginState::SYNCED );
		$this->stats_row = null;

		$this->passes( ValidationHelper::validate_sync_operation( 'tab-a', 'enable_offloading' ) );
	}
}
