<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use DiluxOneOffload\ValidationHelper;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\DiluxOneOffloadDB as DB;
use DiluxOneOffload\Enums\PluginState;

/**
 * ValidationHelper decides whether a sync-related operation may run: is
 * another browser tab driving a sync, is the plugin in a state that allows
 * it, are all files synced before offloading is switched on. It reads real
 * options and the real tracking table, so it is tested here against both.
 */
class ValidationHelperTest extends IntegrationTestCase {

    private function meta(array $overrides = []): void {
        update_option('diluxone_offload_sync_meta', $overrides + [
            'status'          => 'started',
            'sync_session_id' => 'tab-A',
            'last_heartbeat'  => time(),
            'is_reverse_sync' => false,
        ], false);
    }

    // ── multi-tab ───────────────────────────────────────────

    public function test_passes_when_no_sync_exists(): void {
        delete_option('diluxone_offload_sync_meta');
        $r = ValidationHelper::validate_sync_operation('tab-B', 'start_sync');
        $this->assertTrue($r['passed']);
        $this->assertSame('', $r['reason']);
    }

    public function test_a_finished_sync_does_not_block_anyone(): void {
        foreach (['completed', 'completed_with_errors', 'failed'] as $status) {
            $this->meta(['status' => $status]);
            $this->assertTrue(ValidationHelper::validate_sync_operation('tab-B', 'start_sync')['passed'], $status);
        }
    }

    public function test_the_owning_tab_passes_and_another_tab_is_refused(): void {
        $this->meta();
        $this->assertTrue(ValidationHelper::validate_sync_operation('tab-A', 'start_sync')['passed']);
        $r = ValidationHelper::validate_sync_operation('tab-B', 'start_sync');
        $this->assertFalse($r['passed']);
        $this->assertSame('sync_active_in_another_tab', $r['reason']);
        $this->assertSame('tab-A', $r['details']['sync_meta']['sync_session_id']);
    }

    public function test_a_stale_forward_sync_is_cleaned_up_and_the_operation_passes(): void {
        ConfigManager::set_state(PluginState::SYNCING);
        $this->meta(['last_heartbeat' => time() - 120]);
        $r = ValidationHelper::validate_sync_operation('tab-B', 'start_sync');
        $this->assertTrue($r['passed']);
        $this->assertSame(PluginState::CONFIGURED, ConfigManager::get_state(), 'state reset');
        $this->assertFalse(get_option('diluxone_offload_sync_meta'), 'metadata cleared');
    }

    public function test_a_stale_reverse_sync_only_drops_its_metadata(): void {
        ConfigManager::set_state(PluginState::OFFLOADING_ACTIVE);
        $this->meta(['last_heartbeat' => time() - 120, 'is_reverse_sync' => true]);
        $r = ValidationHelper::validate_sync_operation('tab-B', 'disconnect');
        $this->assertTrue($r['passed']);
        $this->assertSame(PluginState::OFFLOADING_ACTIVE, ConfigManager::get_state(), 'offloading stays on');
        $this->assertFalse(get_option('diluxone_offload_sync_meta'));
    }

    public function test_operations_outside_the_multi_tab_list_skip_that_check(): void {
        $this->meta(); // tab-A owns a live sync
        ConfigManager::set_state(PluginState::SYNCED);
        $this->assertTrue(ValidationHelper::validate_sync_operation('tab-B', 'enable_offloading')['passed']);
    }

    // ── plugin state ────────────────────────────────────────

    public function test_start_sync_is_refused_while_already_syncing(): void {
        ConfigManager::set_state(PluginState::SYNCING);
        foreach (['start_sync', 'retry_failed'] as $op) {
            $r = ValidationHelper::validate_sync_operation('tab-A', $op);
            $this->assertFalse($r['passed'], $op);
            $this->assertSame('sync_already_active', $r['reason']);
            $this->assertSame(PluginState::SYNCING, $r['details']['current_state']);
        }
    }

    public function test_enable_offloading_needs_the_synced_state(): void {
        ConfigManager::set_state(PluginState::CONFIGURED);
        $r = ValidationHelper::validate_sync_operation('tab-A', 'enable_offloading');
        $this->assertFalse($r['passed']);
        $this->assertSame('state_conflict', $r['reason']);
        $this->assertSame(PluginState::SYNCED, $r['details']['required_state']);
    }

    public function test_disconnect_needs_offloading_to_be_active(): void {
        ConfigManager::set_state(PluginState::SYNCED);
        $r = ValidationHelper::validate_sync_operation('tab-A', 'disconnect');
        $this->assertFalse($r['passed']);
        $this->assertSame('state_conflict', $r['reason']);
        $this->assertSame(PluginState::OFFLOADING_ACTIVE, $r['details']['required_state']);

        ConfigManager::set_state(PluginState::OFFLOADING_ACTIVE);
        $this->assertTrue(ValidationHelper::validate_sync_operation('tab-A', 'disconnect')['passed']);
    }

    // ── files state ─────────────────────────────────────────

    public function test_enable_offloading_is_refused_while_files_are_pending_or_failed(): void {
        ConfigManager::set_state(PluginState::SYNCED);
        DB::add_file('/2026/09/pending.jpg', 10);
        $r = ValidationHelper::validate_sync_operation('tab-A', 'enable_offloading');
        $this->assertFalse($r['passed']);
        $this->assertSame('files_not_synced', $r['reason']);
        $this->assertSame(1, $r['details']['pending_count']);
    }

    public function test_enable_offloading_passes_once_everything_is_synced(): void {
        ConfigManager::set_state(PluginState::SYNCED);
        DB::add_file('/2026/09/done.jpg', 10);
        DB::mark_synced('/2026/09/done.jpg');
        $this->assertTrue(ValidationHelper::validate_sync_operation('tab-A', 'enable_offloading')['passed']);
    }
}
