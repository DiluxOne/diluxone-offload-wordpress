<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use Tests\Integration\FakeCloudClient;
use Tests\Integration\ScriptedHttp;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\DiluxOneOffloadDB;
use DiluxOneOffload\Enums\PluginState;
use WPAjaxDieContinueException;

/**
 * The AJAX handlers when what is under them fails: the database refuses a
 * statement or throws, an option cannot be written, the HTTP transport
 * throws.
 *
 * The contract every handler keeps is the same: one JSON error that names
 * the failure, never a fatal and never a success, and nothing reported as
 * done that was not. The faults are injected the way WordPress lets any
 * plugin interfere: the `query` filter (every $wpdb statement passes
 * through it), the option hooks, and `pre_http_request`.
 */
class HandlerFaultsTest extends IntegrationTestCase {
    use ScriptedHttp;

    private int $admin_id = 0;
    private ?FakeCloudClient $fake = null;

    /** @var array<int, array{0: string, 1: callable, 2: int}> Filters to remove in tearDown. */
    private array $hooks = [];

    protected function setUp(): void {
        parent::setUp();
        $this->admin_id = (int) wp_insert_user(['user_login' => 'hf_' . wp_generate_password(8, false), 'user_pass' => wp_generate_password(12), 'role' => 'administrator']);
        wp_set_current_user($this->admin_id);
        $_POST    = [];
        $_REQUEST = [];
    }

    protected function tearDown(): void {
        foreach ($this->hooks as [$hook, $callback, $priority]) {
            remove_filter($hook, $callback, $priority);
        }
        $this->hooks = [];
        $this->unhookHttp();
        if ($this->fake) {
            remove_filter('diluxone_offload_pre_cloud_client', [$this, 'injectFake']);
            $this->fake = null;
        }
        global $wpdb;
        $wpdb->suppress_errors(false);
        delete_transient('diluxone_offload_connection_test_passed_' . $this->admin_id);
        wp_delete_user($this->admin_id);
        wp_set_current_user(0);
        $_POST    = [];
        $_REQUEST = [];
        parent::tearDown();
    }

    public function injectFake($pre) {
        return $this->fake;
    }

    private function hook(string $hook, callable $callback, int $priority = 10, int $args = 1): void {
        add_filter($hook, $callback, $priority, $args);
        $this->hooks[] = [$hook, $callback, $priority];
    }

    /**
     * Every statement on the tracking table that contains $verb throws, as a
     * database driver in exception mode does when the server goes away.
     */
    private function tableThrows(string $verb = ''): void {
        $table = DiluxOneOffloadDB::get_table_name();
        $this->hook('query', static function ($query) use ($table, $verb) {
            if (stripos((string) $query, $table) !== false && ($verb === '' || stripos((string) $query, $verb) !== false)) {
                throw new \RuntimeException('database went away');
            }
            return $query;
        });
    }

    /** Statements on the tracking table that contain $verb fail: $wpdb answers false. */
    private function tableRefuses(string $verb): void {
        global $wpdb;
        $wpdb->suppress_errors(true);
        $table = DiluxOneOffloadDB::get_table_name();
        $this->hook('query', static function ($query) use ($table, $verb) {
            if (stripos((string) $query, $table) !== false && stripos((string) $query, $verb) !== false) {
                return 'INSERT INTO `dlx_no_such_table` VALUES (1)';
            }
            return $query;
        });
    }

    /** @return array{json: array|null, raw: string} */
    private function call(string $action, array $post = []): array {
        $_POST          = $post;
        $_POST['nonce'] = wp_create_nonce('diluxone_offload_admin');
        $_REQUEST       = $_POST;
        ob_start();
        try {
            do_action('wp_ajax_' . $action);
        } catch (WPAjaxDieContinueException $e) {
        }
        $raw  = (string) ob_get_clean();
        $json = json_decode($raw, true);
        return ['json' => is_array($json) ? $json : null, 'raw' => $raw];
    }

    /** The handler answered exactly one JSON error whose data contains $expect. */
    private function assertJsonError(array $r, string $expect): void {
        $this->assertIsArray($r['json'], 'one JSON document, nothing after it: ' . $r['raw']);
        $this->assertFalse($r['json']['success'], $r['raw']);
        $data = is_array($r['json']['data']) ? (string) ($r['json']['data']['message'] ?? '') : (string) $r['json']['data'];
        $this->assertStringContainsString($expect, $data);
    }

    private function configure(string $state): void {
        ConfigManager::save_config([
            'cloud_provider'  => 'azure',
            'provider_config' => ['storage_account' => 'faultacct', 'container_name' => 'media', 'access_key' => base64_encode(random_bytes(32))],
        ]);
        ConfigManager::set_state($state);
    }

    // ── Admin handlers ──────────────────────────────────────

    /** @return array<string, array{array<string, string>, string}> */
    public function throwingTransports(): array {
        return [
            // Azure's probe catches it and answers a failed test.
            'azure' => [['provider' => 'azure', 'account_name' => 'acct', 'account_key' => 'a2V5', 'container_name' => 'media'], 'transport exploded'],
            // The S3 probe lets it through: the handler's own catch answers.
            's3'    => [[
                'provider'             => 's3',
                's3_preset'            => 'aws',
                's3_region'            => 'eu-west-1',
                's3_endpoint'          => 'https://s3.eu-west-1.amazonaws.com/',
                's3_bucket'            => 'fault-media',
                's3_access_key_id'     => 'AKIAFAULTTEST',
                's3_secret_access_key' => 'fault/secret+key',
                's3_public_url'        => 'https://fault-media.s3.eu-west-1.amazonaws.com/',
            ], 'Connection error: transport exploded'],
        ];
    }

    /**
     * @dataProvider throwingTransports
     * @param array<string, string> $post
     */
    public function test_a_connection_test_whose_transport_throws_is_a_failed_test(array $post, string $expect): void {
        $this->hook('pre_http_request', static function () {
            throw new \RuntimeException('transport exploded');
        }, 1);

        $r = $this->call('diluxone_offload_test_connection', $post);

        $this->assertJsonError($r, $expect);
        $this->assertFalse(get_transient('diluxone_offload_connection_test_passed_' . $this->admin_id), 'no permission to save after a failed test');
    }

    public function test_credentials_the_database_does_not_save_are_reported_as_not_saved(): void {
        $key = base64_encode(random_bytes(32));
        $this->passConnectionTest('azure', ['storage_account' => 'tested', 'access_key' => $key, 'container_name' => 'media']);
        // The write is refused: update_option() sees no change and answers false.
        $this->hook('pre_update_option_' . ConfigManager::CONFIG_OPTION, static fn($value, $old) => $old, 10, 2);

        $r = $this->call('diluxone_offload_save_updated_credentials', ['provider' => 'azure', 'account_name' => 'tested', 'account_key' => $key, 'container_name' => 'media']);

        $this->assertJsonError($r, 'Credentials were not saved');
        $this->assertSame(PluginState::NOT_CONFIGURED, ConfigManager::get_state(), 'nothing moved to configured');
    }

    public function test_a_reset_the_database_throws_on_is_an_error_and_keeps_the_state(): void {
        $this->configure(PluginState::SYNCED);
        $this->addTestFiles(2);
        $this->tableThrows('TRUNCATE');

        $r = $this->call('diluxone_offload_cancel_sync', ['session_id' => 'me']);

        $this->assertJsonError($r, 'Error: database went away');
        $this->assertSame(PluginState::SYNCED, ConfigManager::get_state(), 'not reported as reset');
    }

    public function test_completing_a_sync_the_database_throws_on_is_an_error_and_keeps_the_state(): void {
        $this->configure(PluginState::SYNCING);
        update_option('diluxone_offload_sync_meta', ['status' => 'processing', 'sync_session_id' => 's1', 'last_heartbeat' => time()], false);
        $this->tableThrows();

        $r = $this->call('diluxone_offload_mark_sync_complete');

        $this->assertJsonError($r, 'Error completing sync: database went away');
        $this->assertSame(PluginState::SYNCING, ConfigManager::get_state());
    }

    public function test_clearing_failed_files_the_database_refuses_is_an_error_and_keeps_the_list(): void {
        $this->configure(PluginState::SYNCING);
        $this->addTestFile('/2026/01/failed.jpg');
        update_option('diluxone_offload_failed_files', ['/2026/01/failed.jpg'], false);
        $this->tableRefuses('DELETE');

        $r = $this->call('diluxone_offload_clear_failed');

        $this->assertJsonError($r, 'Error clearing failed files: the tracking table could not be updated');
        $this->assertSame(1, $this->getTableRowCount(), 'the row is still there');
        $this->assertSame(['/2026/01/failed.jpg'], get_option('diluxone_offload_failed_files'), 'and so is the list');
    }

    public function test_removing_a_provider_whose_options_cannot_be_deleted_is_an_error(): void {
        $this->configure(PluginState::CONFIGURED);
        $this->hook('delete_option', static function ($option) {
            if ($option === 'diluxone_offload_config') {
                throw new \RuntimeException('options table is read-only');
            }
        });

        $r = $this->call('diluxone_offload_ajax_remove_provider');

        $this->assertJsonError($r, 'Error deleting configuration: options table is read-only');
        $this->assertTrue(ConfigManager::is_configured(), 'not reported as deleted');
    }

    // ── Plugin handlers ─────────────────────────────────────

    public function test_a_remote_scan_whose_rows_cannot_be_recorded_is_an_error(): void {
        $this->configure(PluginState::OFFLOADING_ACTIVE);
        $this->fake        = new FakeCloudClient('http://127.0.0.1:9');
        $this->fake->blobs = ['uploads/2026/01/cloud-only.jpg' => 'abc'];
        add_filter('diluxone_offload_pre_cloud_client', [$this, 'injectFake']);
        $this->tableRefuses('INSERT');

        $r = $this->call('diluxone_offload_scan_remote');

        $this->assertJsonError($r, 'Could not record the files found in the cloud');
        $this->assertSame(0, $this->getTableRowCount());
    }

    public function test_a_download_estimate_the_database_throws_on_is_an_error(): void {
        $this->configure(PluginState::OFFLOADING_ACTIVE);
        $this->tableThrows();

        $this->assertJsonError($this->call('diluxone_offload_calculate_download'), 'Error calculating download: database went away');
    }

    public function test_a_sync_estimate_the_database_throws_on_is_an_error(): void {
        $this->configure(PluginState::CONFIGURED);
        $this->tableThrows();

        $this->assertJsonError($this->call('diluxone_offload_calculate_sync'), 'Error calculating sync: database went away');
    }

    public function test_dev_enable_whose_state_cannot_be_saved_rolls_back_and_says_so(): void {
        $this->configure(PluginState::CONFIGURED);
        $this->hook('pre_update_option_' . ConfigManager::STATE_OPTION, static fn($value, $old) => $old, 10, 2);

        $r = $this->call('diluxone_offload_dev_enable_without_sync');

        $this->assertJsonError($r, 'Failed to activate offloading');
        $this->assertSame(PluginState::CONFIGURED, ConfigManager::get_state());
    }

    public function test_preparing_a_resync_the_database_throws_on_is_an_error(): void {
        $this->configure(PluginState::SYNCED);
        $this->addTestFiles(2);
        $this->tableThrows('TRUNCATE');

        $r = $this->call('diluxone_offload_prepare_resync');

        $this->assertJsonError($r, 'Failed to prepare resync: database went away');
        $this->assertSame(PluginState::SYNCED, ConfigManager::get_state(), 'not set back to configured');
    }

    public function test_discarding_failed_files_the_database_refuses_is_an_error_and_keeps_the_state(): void {
        $this->configure(PluginState::SYNCING);
        $this->addTestFile('/2026/01/failed.jpg');
        $this->tableRefuses('DELETE');

        $r = $this->call('diluxone_offload_discard_failed_files');

        $this->assertJsonError($r, 'Failed to discard failed files');
        $this->assertSame(PluginState::SYNCING, ConfigManager::get_state(), 'never promoted to synced');
        $this->assertSame(1, $this->getTableRowCount());
    }

    public function test_discarding_failed_files_the_database_throws_on_is_an_error(): void {
        $this->configure(PluginState::SYNCING);
        $this->tableThrows();

        $this->assertJsonError($this->call('diluxone_offload_discard_failed_files'), 'Error discarding failed files: database went away');
        $this->assertSame(PluginState::SYNCING, ConfigManager::get_state());
    }

    public function test_taking_control_whose_metadata_cannot_be_written_is_an_error(): void {
        $this->configure(PluginState::SYNCING);
        update_option('diluxone_offload_sync_meta', ['status' => 'processing', 'sync_session_id' => 'old-tab', 'last_heartbeat' => time()], false);
        $this->hook('pre_update_option_diluxone_offload_sync_meta', static function () {
            throw new \RuntimeException('options table is read-only');
        });

        $r = $this->call('diluxone_offload_take_control', ['session_id' => 'new-tab']);

        $this->assertJsonError($r, 'Error taking control: options table is read-only');
        $this->assertSame('old-tab', get_option('diluxone_offload_sync_meta')['sync_session_id']);
    }

    public function test_the_state_of_a_finished_sync_the_database_throws_on_is_an_error(): void {
        $this->configure(PluginState::SYNCED);
        update_option('diluxone_offload_sync_meta', ['status' => 'completed', 'sync_session_id' => 's1', 'last_heartbeat' => time(), 'total_files' => 1], false);
        $this->tableThrows();

        $this->assertJsonError($this->call('diluxone_offload_get_sync_state', ['session_id' => 's1']), 'Error getting sync state: database went away');
    }

    public function test_the_failed_count_the_database_throws_on_is_an_error(): void {
        $this->configure(PluginState::SYNCING);
        $this->tableThrows();

        $this->assertJsonError($this->call('diluxone_offload_get_failed_files_count'), 'Error getting failed files count: database went away');
    }

    /**
     * @group slow
     */
    public function test_a_delete_pass_that_runs_out_of_time_reports_processing_with_what_is_left(): void {
        $this->configure(PluginState::OFFLOADING_ACTIVE);
        global $wpdb;
        $table = DiluxOneOffloadDB::get_table_name();
        DiluxOneOffloadDB::add_file('/2026/01/stuck.jpg', 10);
        $wpdb->update($table, ['synced' => 1], ['file' => '/2026/01/stuck.jpg']);
        // The row is never marked deleted, so the pass keeps finding it until
        // its 20-second budget is spent.
        $this->hook('query', static function ($query) use ($table) {
            if (stripos((string) $query, 'UPDATE `' . $table . '`') === 0 && stripos((string) $query, '`deleted`') !== false) {
                return 'SELECT 1';
            }
            return $query;
        });

        $r = $this->call('diluxone_offload_process_delete_batch');

        $this->assertTrue($r['json']['success'] ?? false, $r['raw']);
        $this->assertSame('processing', $r['json']['data']['status'], 'not completed while a file is left');
        $this->assertSame(1, (int) $r['json']['data']['pending_files']);
        $this->assertGreaterThan(0, $r['json']['data']['deleted_this_batch']);
    }
}
