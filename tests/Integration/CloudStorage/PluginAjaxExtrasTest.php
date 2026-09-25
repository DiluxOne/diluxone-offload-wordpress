<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use Tests\Integration\FakeCloudClient;
use Tests\Integration\LocalBlobServer;
use DiluxOneOffload\Plugin;
use DiluxOneOffload\SyncManager;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\DiluxOneOffloadDB as DB;
use DiluxOneOffload\Enums\PluginState;
use WPAjaxDieContinueException;

/**
 * The plugin's AJAX handlers beyond the happy path: the two-step start
 * (pre-check, then confirmed run) with its options, the multi-tab state
 * machine, remote scanning against a catalogue, and what every handler says
 * when there is no sync manager to hand the work to.
 */
class PluginAjaxExtrasTest extends IntegrationTestCase {

    private static ?LocalBlobServer $server = null;
    private FakeCloudClient $client;
    private int $admin_id = 0;
    /** @var string[] */
    private array $fixtures = [];
    private string $base = '';

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        self::$server = new LocalBlobServer(8775);
    }

    public static function tearDownAfterClass(): void {
        if (self::$server) {
            self::$server->stop();
            self::$server = null;
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void {
        parent::setUp();
        $this->base   = wp_upload_dir()['basedir'];
        $this->client = new FakeCloudClient(self::$server->base_url);
        add_filter('diluxone_offload_pre_cloud_client', [$this, 'injectClient']);
        $this->setSyncManager(new SyncManager());
        $this->admin_id = (int) wp_insert_user(['user_login' => 'pax_' . wp_generate_password(8, false), 'user_pass' => wp_generate_password(12), 'role' => 'administrator']);
        wp_set_current_user($this->admin_id);
        ConfigManager::save_config([
            'cloud_provider'     => 'azure',
            'provider_config'    => ['storage_account' => 'paxacct', 'container_name' => 'media', 'access_key' => base64_encode(random_bytes(32))],
            'allowed_file_types' => 'pax',
        ]);
        ConfigManager::set_state(PluginState::CONFIGURED);
        delete_option('diluxone_offload_sync_meta');
        $_POST = [];
        $_REQUEST = [];
    }

    protected function tearDown(): void {
        remove_filter('diluxone_offload_pre_cloud_client', [$this, 'injectClient']);
        $this->setSyncManager(new SyncManager());
        foreach (array_reverse($this->fixtures) as $f) {
            is_dir($f) ? @rmdir($f) : @unlink($f);
        }
        $this->fixtures = [];
        delete_option('diluxone_offload_sync_meta');
        wp_delete_user($this->admin_id);
        wp_set_current_user(0);
        $_POST = [];
        $_REQUEST = [];
        parent::tearDown();
    }

    public function injectClient($pre) {
        return $this->client;
    }

    private function setSyncManager(?SyncManager $sm): void {
        $prop = new \ReflectionProperty(Plugin::get_instance(), 'sync_manager');
        if ( PHP_VERSION_ID < 80100 ) { // Required before 8.1, deprecated from 8.5.
        	$prop->setAccessible( true );
        }
        $prop->setValue(Plugin::get_instance(), $sm);
    }

    private function fixture(string $relative, string $content = 'x'): string {
        $path = $this->base . '/' . ltrim($relative, '/');
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            $this->fixtures[] = $dir;
        }
        file_put_contents($path, $content);
        $this->fixtures[] = $path;
        return $path;
    }

    private function call(string $action, array $post = [], string $nonce_action = 'diluxone_offload_admin'): array {
        $_POST = $post;
        $_POST['nonce'] = wp_create_nonce($nonce_action);
        $_REQUEST = $_POST;
        ob_start();
        try {
            do_action('wp_ajax_' . $action);
        } catch (WPAjaxDieContinueException $e) {
        }
        $raw = (string) ob_get_clean();
        $j = json_decode($raw, true);
        return ['json' => is_array($j) ? $j : null, 'raw' => $raw];
    }

    // ── start_sync: pre-check and options ───────────────────

    public function test_unconfirmed_start_reports_what_a_sync_would_do(): void {
        $this->fixture('pax/new.pax', 'abcd');
        $r = $this->call('diluxone_offload_start_sync', ['session_id' => 'tab-1']);
        $this->assertTrue($r['json']['success'], $r['raw']);
        $d = $r['json']['data'];
        $this->assertTrue($d['requires_confirmation']);
        $this->assertSame(1, $d['data']['new_files']);
        $this->assertSame(4, $d['data']['new_files_size']);
        $this->assertSame(1, DB::get_total_count(), 'the new file was catalogued for the confirmed run');
        $this->assertSame(PluginState::CONFIGURED, ConfigManager::get_state(), 'nothing started yet');
    }

    public function test_start_from_another_tab_is_a_validation_failure(): void {
        update_option('diluxone_offload_sync_meta', ['status' => 'started', 'sync_session_id' => 'tab-A', 'last_heartbeat' => time()], false);
        $r = $this->call('diluxone_offload_start_sync', ['session_id' => 'tab-B', 'confirmed' => '1']);
        $this->assertTrue($r['json']['success']);
        $this->assertTrue($r['json']['data']['validation_failed']);
        $this->assertSame('sync_active_in_another_tab', $r['json']['data']['reason']);
    }

    public function test_from_scratch_resets_synced_rows_and_clamps_low_concurrency(): void {
        $this->fixture('pax/a.pax', 'a');
        DB::add_file('/pax/a.pax', 1);
        DB::mark_synced('/pax/a.pax');
        $r = $this->call('diluxone_offload_start_sync', ['session_id' => 't', 'confirmed' => '1', 'from_scratch' => '1', 'concurrency' => '1']);
        $this->assertTrue($r['json']['success'], $r['raw']);
        $this->assertTrue($r['json']['data']['action_executed']);
        $this->assertSame(1, (int) DB::get_stats()['pending_files'], 'the synced row is pending again');
        $this->assertSame(3, get_option('diluxone_offload_sync_meta')['concurrency'], 'clamped to the minimum');
    }

    public function test_retry_failed_resets_only_failed_rows_and_clamps_high_concurrency(): void {
        ConfigManager::set_state(PluginState::SYNCED);
        DB::add_file('/pax/bad.pax', 1);
        for ($i = 0; $i < 3; $i++) {
            DB::increment_error('/pax/bad.pax', 'boom');
        }
        $this->assertSame([], DB::get_pending_files(), 'three errors park the file');
        $r = $this->call('diluxone_offload_start_sync', ['session_id' => 't', 'confirmed' => '1', 'retry_failed' => '1', 'concurrency' => '99']);
        $this->assertTrue($r['json']['success'], $r['raw']);
        $this->assertCount(1, DB::get_pending_files(), 'its error count was reset');
        $this->assertSame(40, get_option('diluxone_offload_sync_meta')['concurrency'], 'clamped to the maximum');
        $this->assertSame(PluginState::SYNCING, ConfigManager::get_state());
    }

    public function test_a_start_the_manager_refuses_is_an_error(): void {
        ConfigManager::set_state(PluginState::NOT_CONFIGURED);
        $r = $this->call('diluxone_offload_start_sync', ['session_id' => 't', 'confirmed' => '1']);
        $this->assertFalse($r['json']['success'], $r['raw']);
        $this->assertStringContainsString('Cannot start sync', $r['json']['data']);
    }

    // ── get_sync_state ──────────────────────────────────────

    public function test_state_without_metadata(): void {
        $r = $this->call('diluxone_offload_get_sync_state', ['session_id' => 't']);
        $this->assertSame('no_sync', $r['json']['data']['state']);
        $this->assertSame('No sync in progress', $r['json']['data']['message']);
    }

    public function test_state_without_metadata_but_marked_syncing_is_flagged_inconsistent(): void {
        ConfigManager::set_state(PluginState::SYNCING);
        $r = $this->call('diluxone_offload_get_sync_state', ['session_id' => 't']);
        $this->assertSame('no_sync', $r['json']['data']['state']);
        $this->assertStringContainsString('inconsistent', $r['json']['data']['message']);
    }

    public function test_state_of_an_abandoned_sync_is_expired_and_cleaned_up(): void {
        ConfigManager::set_state(PluginState::SYNCING);
        update_option('diluxone_offload_sync_meta', ['status' => 'started', 'sync_session_id' => 'old', 'last_heartbeat' => time() - 500], false);
        $r = $this->call('diluxone_offload_get_sync_state', ['session_id' => 'new']);
        $this->assertSame('expired', $r['json']['data']['state']);
        $this->assertGreaterThanOrEqual(500, $r['json']['data']['last_heartbeat_age']);
        $this->assertSame(PluginState::CONFIGURED, ConfigManager::get_state());
        $this->assertFalse(get_option('diluxone_offload_sync_meta'));
    }

    /**
     * A reverse sync carries no session or heartbeat. Before the guard, the
     * expiry branch read that as an abandoned sync and dropped the plugin to
     * CONFIGURED in the middle of a Disconnect download.
     */
    public function test_state_during_a_reverse_sync_leaves_the_plugin_alone(): void {
        ConfigManager::set_state(PluginState::OFFLOADING_ACTIVE);
        update_option('diluxone_offload_sync_meta', ['status' => 'started', 'is_reverse_sync' => true, 'reverse_mode' => 'continue', 'total_files' => 3], false);
        $r = $this->call('diluxone_offload_get_sync_state', ['session_id' => 'new']);
        $this->assertSame('no_sync', $r['json']['data']['state']);
        $this->assertSame(PluginState::OFFLOADING_ACTIVE, ConfigManager::get_state(), 'the state is not touched');
        $this->assertTrue((bool) get_option('diluxone_offload_sync_meta')['is_reverse_sync'], 'the download keeps its metadata');
    }

    public function test_state_of_a_finished_sync_carries_the_final_numbers(): void {
        DB::add_file('/pax/done.pax', 1);
        DB::mark_synced('/pax/done.pax');
        update_option('diluxone_offload_sync_meta', ['status' => 'completed', 'sync_session_id' => 'x', 'last_heartbeat' => time(), 'total_files' => 1], false);
        $r = $this->call('diluxone_offload_get_sync_state', ['session_id' => 'x']);
        $this->assertSame('terminated', $r['json']['data']['state']);
        $this->assertSame('completed', $r['json']['data']['status']);
        $this->assertSame(1, (int) $r['json']['data']['sync_meta']['successful_uploads']);
    }

    public function test_state_seen_from_another_tab_is_inactive_with_the_owner_named(): void {
        update_option('diluxone_offload_sync_meta', ['status' => 'started', 'sync_session_id' => 'owner', 'last_heartbeat' => time(), 'total_files' => 0], false);
        $r = $this->call('diluxone_offload_get_sync_state', ['session_id' => 'visitor']);
        $this->assertSame('inactive', $r['json']['data']['state']);
        $this->assertSame('owner', $r['json']['data']['active_session_id']);
    }

    // ── take_control guards ─────────────────────────────────

    public function test_take_control_needs_a_session_and_a_sync(): void {
        $r = $this->call('diluxone_offload_take_control', []);
        $this->assertStringContainsString('Session ID is required', $r['json']['data']);
        $r = $this->call('diluxone_offload_take_control', ['session_id' => 'me']);
        $this->assertStringContainsString('No active sync', $r['json']['data']);
    }

    // ── scan_remote ─────────────────────────────────────────

    public function test_scan_remote_with_an_empty_cloud(): void {
        $this->client->blobs = [];
        $r = $this->call('diluxone_offload_scan_remote');
        $this->assertTrue($r['json']['success'], $r['raw']);
        $this->assertSame(0, $r['json']['data']['scanned']);
    }

    public function test_scan_remote_marks_a_catalogued_unsynced_row_of_the_same_size_as_synced(): void {
        $this->client->blobs = ['uploads/pax/same.pax' => 'abc', 'uploads/pax/new.pax' => 'd'];
        DB::add_file('/pax/same.pax', 3);
        $r = $this->call('diluxone_offload_scan_remote');
        $this->assertTrue($r['json']['success'], $r['raw']);
        $this->assertSame(2, $r['json']['data']['scanned']);
        $this->assertSame(1, $r['json']['data']['already_in_db']);
        $this->assertSame(1, $r['json']['data']['new_files']);
        $this->assertSame(2, (int) DB::get_stats()['synced_files']);
    }

    public function test_scan_remote_without_a_provider_is_a_clean_error(): void {
        remove_filter('diluxone_offload_pre_cloud_client', [$this, 'injectClient']);
        delete_option('diluxone_offload_config');
        $r = $this->call('diluxone_offload_scan_remote');
        $this->assertFalse($r['json']['success']);
        $this->assertStringContainsString('not configured', $r['json']['data']);
    }

    // ── process_delete_batch details ────────────────────────

    public function test_delete_batch_counts_a_missing_local_file_as_done_and_maps_the_wrapper_basedir(): void {
        ConfigManager::set_state(PluginState::SYNCED);
        DB::add_file('/pax/gone-already.pax', 1);
        DB::mark_synced('/pax/gone-already.pax');
        \DiluxOneOffload\CloudStreamWrapper::activate_offloading(); // wp_upload_dir() now answers with the protocol
        try {
            $this->assertStringStartsWith('diluxoneoffload://', wp_upload_dir()['basedir']);
            $r = $this->call('diluxone_offload_process_delete_batch');
        } finally {
            \DiluxOneOffload\CloudStreamWrapper::deactivate_offloading();
        }
        $this->assertTrue($r['json']['success'], $r['raw']);
        $this->assertSame('completed', $r['json']['data']['status']);
        $this->assertSame(1, $r['json']['data']['deleted_this_batch']);
    }

    // ── no sync manager ─────────────────────────────────────

    public function test_every_sync_handler_says_so_when_there_is_no_sync_manager(): void {
        $this->setSyncManager(null);
        foreach (['start_sync', 'process_batch', 'start_reverse_sync', 'process_reverse_batch', 'scan_remote', 'calculate_download', 'calculate_sync', 'activate_offloading', 'deactivate_offloading', 'reset_state_to_configured'] as $a) {
            $nonce = in_array($a, ['activate_offloading', 'deactivate_offloading'], true) ? 'diluxone_offload_admin_nonce' : 'diluxone_offload_admin';
            $r = $this->call('diluxone_offload_' . $a, ['session_id' => 't', 'confirmed' => '1'], $nonce);
            $this->assertFalse($r['json']['success'] ?? true, "$a: " . $r['raw']);
            $this->assertStringContainsString('not available', (string) $r['json']['data'], $a);
        }
    }

    public function test_reverse_sync_refused_by_the_manager_is_an_error(): void {
        ConfigManager::set_state(PluginState::SYNCED);
        $r = $this->call('diluxone_offload_start_reverse_sync', ['mode' => 'scratch']);
        $this->assertFalse($r['json']['success'], $r['raw']);
        $this->assertStringContainsString('only available', $r['json']['data']);
    }


    public function test_the_database_is_recreated_when_its_version_is_behind(): void {
        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS `' . self::$table_name . '`');
        update_option(DB::TABLE_VERSION_OPTION, '0');
        $m = new \ReflectionMethod(Plugin::class, 'check_and_update_database');
        if ( PHP_VERSION_ID < 80100 ) { // Required before 8.1, deprecated from 8.5.
        	$m->setAccessible( true );
        }
        $m->invoke(Plugin::get_instance());
        $this->assertTrue(DB::table_exists());
        $this->assertSame(DB::TABLE_VERSION, get_option(DB::TABLE_VERSION_OPTION));
    }

    public function test_a_new_site_is_ignored_unless_the_plugin_is_network_active(): void {
        if (!is_multisite()) {
            $this->markTestSkipped('multisite only');
        }
        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $basename    = plugin_basename(DILUXONE_OFFLOAD_FILE);
        $was_network = is_plugin_active_for_network($basename);
        if ($was_network) {
            deactivate_plugins($basename, true, true);
        }
        try {
            $this->assertFalse(is_plugin_active_for_network($basename));
            Plugin::on_new_site((object) ['blog_id' => get_current_blog_id()]);
            $this->assertTrue(DB::table_exists(), 'nothing broke; the existing table is untouched');
        } finally {
            if ($was_network) {
                activate_plugin($basename, '', true, true);
            }
        }
    }
}
