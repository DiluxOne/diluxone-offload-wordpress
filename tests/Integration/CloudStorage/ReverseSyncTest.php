<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use Tests\Integration\FakeCloudClient;
use Tests\Integration\LocalBlobServer;
use DiluxOneOffload\SyncManager;
use DiluxOneOffload\Plugin;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\CloudStreamWrapper;
use DiluxOneOffload\DiluxOneOffloadDB as DB;
use DiluxOneOffload\Enums\PluginState;
use WPAjaxDieContinueException;

/**
 * Integration tests for the way back: reverse sync (download everything from
 * the cloud), local-file deletion after a sync, cloud comparison, and the
 * AJAX endpoints the Disconnect flow drives. Runs the real curl_multi
 * download loop against the local stand-in server.
 */
class ReverseSyncTest extends IntegrationTestCase {

    private static ?LocalBlobServer $server = null;
    private FakeCloudClient $client;
    private int $admin_id = 0;
    /** @var string[] */
    private array $fixtures = [];
    private string $base = '';

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        self::$server = new LocalBlobServer(8769);
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
        $this->base = wp_upload_dir()['basedir'];
        $this->client = new FakeCloudClient(self::$server->base_url);
        add_filter('diluxone_offload_pre_cloud_client', [$this, 'injectClient']);
        $plugin = Plugin::get_instance();
        $prop = new \ReflectionProperty($plugin, 'sync_manager');
        if ( PHP_VERSION_ID < 80100 ) { // Required before 8.1, deprecated from 8.5.
        	$prop->setAccessible( true );
        }
        $prop->setValue($plugin, new SyncManager());

        $id = wp_insert_user(['user_login' => 'rev_' . wp_generate_password(8, false), 'user_pass' => wp_generate_password(12), 'role' => 'administrator']);
        $this->admin_id = (int) $id;
        wp_set_current_user($this->admin_id);

        ConfigManager::save_config([
            'cloud_provider'  => 'azure',
            'provider_config' => ['storage_account' => 'revacct', 'container_name' => 'media', 'access_key' => base64_encode(random_bytes(32))],
        ]);
        ConfigManager::set_state(PluginState::OFFLOADING_ACTIVE);
        delete_option('diluxone_offload_sync_meta');
        $_POST = [];
        $_REQUEST = [];
    }

    protected function tearDown(): void {
        remove_filter('diluxone_offload_pre_cloud_client', [$this, 'injectClient']);
        CloudStreamWrapper::deactivate_offloading();
        foreach ($this->fixtures as $f) {
            @unlink($f);
        }
        $this->fixtures = [];
        delete_option('diluxone_offload_sync_meta');
        if ($this->admin_id) {
            wp_delete_user($this->admin_id);
        }
        wp_set_current_user(0);
        $_POST = [];
        $_REQUEST = [];
        parent::tearDown();
    }

    public function injectClient($pre) {
        return $this->client;
    }

    private function local(string $relative): string {
        $p = $this->base . '/' . ltrim($relative, '/');
        $this->fixtures[] = $p;
        return $p;
    }

    /** @return array{json: array|null, raw: string} */
    private function call(string $action, array $post = []): array {
        $_POST = $post;
        $_POST['nonce'] = wp_create_nonce('diluxone_offload_admin');
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

    // ── start_reverse_sync ──────────────────────────────────

    public function test_reverse_sync_needs_offloading_active(): void {
        ConfigManager::set_state(PluginState::SYNCED);
        $r = (new SyncManager())->start_reverse_sync();
        $this->assertFalse($r['success']);
    }

    public function test_reverse_sync_refuses_when_the_cloud_is_unreachable(): void {
        $this->client->connection_ok = false;
        $r = (new SyncManager())->start_reverse_sync();
        $this->assertFalse($r['success']);
        $this->assertStringContainsString('failed', strtolower($r['message']));
    }

    public function test_scratch_mode_catalogues_every_cloud_file_as_pending_download(): void {
        $this->client->blobs = ['uploads/2026/09/a.jpg' => 'AAA', 'uploads/2026/09/b.jpg' => 'BB'];
        $r = (new SyncManager())->start_reverse_sync('scratch');
        $this->assertTrue($r['success'], print_r($r, true));
        $this->assertSame(2, DB::count_deleted_files());
        $meta = get_option('diluxone_offload_sync_meta');
        $this->assertTrue($meta['is_reverse_sync']);
        $this->assertSame('started', $meta['status']);
    }

    public function test_scratch_mode_with_an_empty_cloud_fails_cleanly(): void {
        $this->client->blobs = [];
        $r = (new SyncManager())->start_reverse_sync('scratch');
        $this->assertFalse($r['success']);
    }

    public function test_continue_mode_resumes_an_existing_catalogue(): void {
        DB::add_cloud_only_file('/2026/09/x.jpg', 3);
        $r = (new SyncManager())->start_reverse_sync('continue');
        $this->assertTrue($r['success'], print_r($r, true));
        $this->assertSame(1, DB::count_deleted_files(), 'nothing re-catalogued');
    }

    public function test_continue_mode_catalogues_only_files_missing_from_the_db(): void {
        $this->client->blobs = ['uploads/2026/09/known.jpg' => 'k', 'uploads/2026/09/new.jpg' => 'n'];
        DB::add_file('/2026/09/known.jpg', 1);
        DB::mark_synced('/2026/09/known.jpg');
        $r = (new SyncManager())->start_reverse_sync('continue');
        $this->assertTrue($r['success'], print_r($r, true));
        $deleted = array_column(DB::get_deleted_files(), 'file');
        $this->assertContains('/2026/09/new.jpg', $deleted);
    }

    // ── process_reverse_batch (real curl_multi) ─────────────

    public function test_reverse_batch_downloads_and_marks_files_local(): void {
        $this->client->blobs = ['uploads/2026/09/d1.jpg' => 'one-1', 'uploads/2026/09/d2.jpg' => 'two-22'];
        $this->local('2026/09/d1.jpg');
        $this->local('2026/09/d2.jpg');
        $sm = new SyncManager();
        $this->assertTrue($sm->start_reverse_sync('scratch')['success']);

        $r = $sm->process_reverse_batch(30.0);

        $this->assertSame('completed', $r['status'], print_r($r, true));
        $this->assertSame(0, DB::count_deleted_files(), 'every file is back on disk');
        $this->assertSame('one-1', file_get_contents($this->base . '/2026/09/d1.jpg'));
        $this->assertSame('two-22', file_get_contents($this->base . '/2026/09/d2.jpg'));
    }

    public function test_reverse_batch_skips_files_already_present_with_the_right_size(): void {
        $this->client->blobs = ['uploads/2026/09/have.jpg' => 'exact'];
        file_put_contents($this->local('2026/09/have.jpg'), 'exact');
        DB::add_cloud_only_file('/2026/09/have.jpg', 5);
        $sm = new SyncManager();
        $this->assertTrue($sm->start_reverse_sync('continue')['success']);

        $r = $sm->process_reverse_batch(30.0);

        $this->assertSame('completed', $r['status']);
        $this->assertSame(0, DB::count_deleted_files());
        $this->assertSame(0, $this->client->downloads, 'nothing was downloaded again');
    }

    public function test_a_blob_that_vanished_from_the_cloud_is_retried_then_given_up(): void {
        DB::add_cloud_only_file('/2026/09/vanished.jpg', 3); // catalogued, but no blob behind it
        update_option('diluxone_offload_sync_meta', ['status' => 'started', 'is_reverse_sync' => true, 'reverse_mode' => 'scratch', 'total_files' => 1, 'already_downloaded' => 0, 'last_heartbeat' => time()], false);
        $r = (new SyncManager())->process_reverse_batch(30.0);
        $this->assertSame('completed', $r['status'], 'the batch does not spin forever on it');
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT deleted, errors FROM `' . self::$table_name . '` WHERE file = %s', '/2026/09/vanished.jpg'), ARRAY_A);
        $this->assertSame(1, (int) $row['deleted'], 'still not local');
        $this->assertGreaterThanOrEqual(3, (int) $row['errors'], 'three strikes');
        $this->assertSame(0, DB::count_deleted_files(), 'parked, not pending');
        $this->assertGreaterThanOrEqual(2, $this->client->downloads, 'it was retried before being parked');
    }

    /**
     * The download lands in a sibling part file and replaces the attachment
     * only once it checks out. A copy that was already there survives a
     * server error, and no part file is left behind.
     */
    public function test_a_failed_download_leaves_the_existing_local_copy_untouched(): void {
        $this->client->blobs = ['uploads/2026/09/keep.jpg' => 'newer bytes from the cloud'];
        $this->client->download_status = 500;
        $path = $this->local('2026/09/keep.jpg');
        wp_mkdir_p(dirname($path));
        file_put_contents($path, 'good local copy');
        $sm = new SyncManager();
        $sm->start_reverse_sync('scratch');

        $sm->process_reverse_batch(30.0);

        $this->assertSame('good local copy', file_get_contents($path), 'the attachment is untouched');
        $this->assertFileDoesNotExist($path . '.dlxpart', 'no part file left behind');
    }

    public function test_a_completed_download_replaces_the_local_copy_and_leaves_no_part_file(): void {
        $this->client->blobs = ['uploads/2026/09/replace.jpg' => 'from the cloud'];
        $path = $this->local('2026/09/replace.jpg');
        wp_mkdir_p(dirname($path));
        file_put_contents($path, 'stale');
        $sm = new SyncManager();
        $sm->start_reverse_sync('scratch');

        $sm->process_reverse_batch(30.0);

        $this->assertSame('from the cloud', file_get_contents($path));
        $this->assertFileDoesNotExist($path . '.dlxpart');
    }

    public function test_reverse_batch_records_a_failed_download(): void {
        $this->client->blobs = ['uploads/2026/09/bad.jpg' => 'x'];
        $this->client->download_status = 500;
        $this->local('2026/09/bad.jpg');
        $sm = new SyncManager();
        $sm->start_reverse_sync('scratch');

        $sm->process_reverse_batch(30.0);

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT deleted, errors FROM `' . self::$table_name . '` WHERE file = %s', '/2026/09/bad.jpg'), ARRAY_A);
        $this->assertSame(1, (int) $row['deleted'], 'still cloud-only');
        $this->assertGreaterThanOrEqual(1, (int) $row['errors']);
    }

    public function test_reverse_batch_without_an_active_reverse_sync_errors(): void {
        $this->assertSame('error', (new SyncManager())->process_reverse_batch()['status']);
    }

    // ── AJAX: the Disconnect flow ───────────────────────────

    public function test_ajax_start_and_process_reverse_sync(): void {
        $this->client->blobs = ['uploads/2026/09/ajax-r.jpg' => 'payload'];
        $this->local('2026/09/ajax-r.jpg');

        $start = $this->call('diluxone_offload_start_reverse_sync', ['mode' => 'scratch', 'concurrency' => '5']);
        $this->assertTrue($start['json']['success'] ?? false, $start['raw']);

        $batch = $this->call('diluxone_offload_process_reverse_batch');
        $this->assertTrue($batch['json']['success'] ?? false, $batch['raw']);
        $this->assertSame('completed', $batch['json']['data']['status'] ?? null);
        $this->assertSame('payload', file_get_contents($this->base . '/2026/09/ajax-r.jpg'));
    }

    public function test_ajax_calculate_download(): void {
        $this->client->blobs = ['uploads/2026/09/c1.jpg' => 'abc'];
        DB::add_cloud_only_file('/2026/09/c1.jpg', 3);
        $calc = $this->call('diluxone_offload_calculate_download');
        $this->assertTrue($calc['json']['success'] ?? false, $calc['raw']);
    }

    public function test_ajax_scan_remote_registers_cloud_only_files(): void {
        $this->client->blobs = ['uploads/2026/09/s1.jpg' => 'abc', 'uploads/2026/09/s2.jpg' => 'de'];
        $scan = $this->call('diluxone_offload_scan_remote');
        $this->assertTrue($scan['json']['success'] ?? false, $scan['raw']);
        $this->assertSame(2, $scan['json']['data']['new_files'] ?? null);
        $stats = (array) DB::get_deleted_stats();
        $this->assertSame(2, (int) ($stats['files'] ?? 0), 'both are recorded as pending download');
    }

    public function test_ajax_deletable_stats_and_delete_batch_remove_local_copies_of_synced_files(): void {
        ConfigManager::set_state(PluginState::SYNCED);
        $p = $this->local('2026/09/del.jpg');
        file_put_contents($p, 'to be deleted locally');
        DB::add_file('/2026/09/del.jpg', 21);
        DB::mark_synced('/2026/09/del.jpg');
        $this->client->blobs['uploads/2026/09/del.jpg'] = 'to be deleted locally';

        $stats = $this->call('diluxone_offload_get_deletable_stats');
        $this->assertTrue($stats['json']['success'] ?? false, $stats['raw']);

        $del = $this->call('diluxone_offload_process_delete_batch');
        $this->assertTrue($del['json']['success'] ?? false, $del['raw']);
        $this->assertFileDoesNotExist($p);
        $this->assertSame('completed', $del['json']['data']['status']);
        $this->assertSame(0, DB::get_total_count(), 'the catalogue is cleared once nothing is left to delete');
    }

    // ── Plugin plumbing ─────────────────────────────────────

    public function test_filter_image_editors_puts_ours_first_and_last(): void {
        $editors = Plugin::get_instance()->filter_image_editors(['WP_Image_Editor_Imagick', 'WP_Image_Editor_GD']);
        $this->assertSame('DiluxOneOffload\\DiluxOneOffload_Image_Editor_Imagick', $editors[0]);
        $this->assertSame('DiluxOneOffload\\DiluxOneOffload_Image_Editor_GD', end($editors));
        $this->assertNotContains('WP_Image_Editor_Imagick', $editors);
    }

    // The DEV MODE endpoints exist only behind DILUXONE_OFFLOAD_DEV_MODE,
    // which the integration bootstrap defines.

    public function test_dev_enable_without_sync_needs_the_configured_state(): void {
        ConfigManager::set_state(PluginState::SYNCED);
        $r = $this->call('diluxone_offload_dev_enable_without_sync');
        $this->assertFalse($r['json']['success'], $r['raw']);
        $this->assertStringContainsString('Invalid state', $r['json']['data']);
    }

    public function test_dev_enable_without_sync_turns_offloading_on_directly(): void {
        ConfigManager::set_state(PluginState::CONFIGURED);
        $r = $this->call('diluxone_offload_dev_enable_without_sync');
        $this->assertTrue($r['json']['success'], $r['raw']);
        $this->assertSame(PluginState::OFFLOADING_ACTIVE, ConfigManager::get_state());
    }

    public function test_dev_disconnect_without_sync_needs_offloading_to_be_active(): void {
        ConfigManager::set_state(PluginState::SYNCED);
        $r = $this->call('diluxone_offload_dev_disconnect_without_sync');
        $this->assertFalse($r['json']['success'], $r['raw']);
        $this->assertStringContainsString('Invalid state', $r['json']['data']);
    }

    public function test_dev_disconnect_without_sync_returns_to_configured_and_empties_the_table(): void {
        DB::add_file('/2026/09/x.jpg', 1);
        $r = $this->call('diluxone_offload_dev_disconnect_without_sync');
        $this->assertTrue($r['json']['success'], $r['raw']);
        $this->assertSame(PluginState::CONFIGURED, ConfigManager::get_state());
        $this->assertSame(0, DB::get_total_count());
    }

    public function test_init_is_idempotent_and_activation_hooks_the_image_editors(): void {
        $plugin = Plugin::get_instance();
        $plugin->init();
        $plugin->init();
        $this->assertNotFalse(has_filter('wp_image_editors', [$plugin, 'filter_image_editors']));
        Plugin::deactivate();
        $this->assertSame(PluginState::SYNCED, ConfigManager::get_state(), 'deactivation turns offloading off');
    }
}
