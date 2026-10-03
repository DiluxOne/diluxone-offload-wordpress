<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use Tests\Integration\FakeCloudClient;
use Tests\Integration\LocalBlobServer;
use DiluxOneOffload\Admin;
use DiluxOneOffload\CloudStreamWrapper;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\Enums\PluginState;
use DiluxOneOffload\DiluxOneOffloadDB as DB;
use DiluxOneOffload\SyncManager;

/**
 * The data the remodelled screens rest on: the timestamps, the tracking
 * table that survives Delete Local Files, the live uploads it records, the
 * last upload, the skipped list, the forced health check and the paged
 * failed list. Each one is proved where it is written, then where it is
 * shown.
 */
class AdminDataTest extends IntegrationTestCase {

    private static ?LocalBlobServer $server = null;
    private ?FakeCloudClient $fake = null;
    private int $admin_id = 0;

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
        $this->admin_id = (int) wp_insert_user(['user_login' => 'data_' . wp_generate_password(8, false), 'user_pass' => wp_generate_password(12), 'role' => 'administrator']);
        wp_set_current_user($this->admin_id);
        if (!class_exists('WP_Screen')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
        }
        require_once ABSPATH . 'wp-admin/includes/screen.php';
        set_current_screen('toplevel_page_diluxone-offload');
        foreach ([ConfigManager::TIMESTAMPS_OPTION, ConfigManager::LAST_UPLOAD_OPTION, ConfigManager::SKIPPED_OPTION] as $o) {
            delete_option($o);
        }
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
    }

    protected function tearDown(): void {
        CloudStreamWrapper::deactivate_offloading();
        CloudStreamWrapper::unregister();
        if ($this->fake) {
            remove_filter('diluxone_offload_pre_cloud_client', [$this, 'injectFake']);
            $this->fake = null;
        }
        foreach ([ConfigManager::TIMESTAMPS_OPTION, ConfigManager::LAST_UPLOAD_OPTION, ConfigManager::SKIPPED_OPTION] as $o) {
            delete_option($o);
        }
        wp_delete_user($this->admin_id);
        wp_set_current_user(0);
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        parent::tearDown();
    }

    public function injectFake($pre) {
        return $this->fake;
    }

    private function useFakeClient(): FakeCloudClient {
        $this->fake = new FakeCloudClient(self::$server->base_url);
        add_filter('diluxone_offload_pre_cloud_client', [$this, 'injectFake']);
        return $this->fake;
    }

    private function configure(string $state = PluginState::CONFIGURED, string $account = 'dataacct', string $container = 'media'): void {
        ConfigManager::save_provider_config([
            'cloud_provider'  => 'azure',
            'provider_config' => ['storage_account' => $account, 'container_name' => $container, 'access_key' => base64_encode(random_bytes(32))],
        ]);
        ConfigManager::set_state($state);
    }

    private function render(string $page, string $tab): string {
        $_GET['page'] = $page;
        $_GET['tab'] = $tab;
        $GLOBALS['wp_scripts'] = null;
        $GLOBALS['wp_styles'] = null;
        ob_start();
        Admin::enqueue_admin_assets('toplevel_page_diluxone-offload');
        Admin::render_admin_page();
        return (string) ob_get_clean();
    }

    // ── A1: timestamps ──────────────────────────────────────

    public function test_connected_at_is_written_for_a_new_connection_and_kept_through_a_key_rotation(): void {
        $this->assertNull(ConfigManager::get_timestamps()['connected_at']);
        $this->configure(PluginState::CONFIGURED, 'first', 'media');
        $first = ConfigManager::get_timestamps()['connected_at'];
        $this->assertIsInt($first);

        // A new key for the same account and container: not a new connection.
        update_option(ConfigManager::TIMESTAMPS_OPTION, ['connected_at' => $first - 1000]);
        ConfigManager::save_provider_config(['cloud_provider' => 'azure', 'provider_config' => ['storage_account' => 'first', 'container_name' => 'media', 'access_key' => base64_encode(random_bytes(32))]]);
        $this->assertSame($first - 1000, ConfigManager::get_timestamps()['connected_at'], 'a rotated key keeps the date');

        // Another container is a new connection.
        ConfigManager::save_provider_config(['cloud_provider' => 'azure', 'provider_config' => ['storage_account' => 'first', 'container_name' => 'other', 'access_key' => base64_encode(random_bytes(32))]]);
        $this->assertGreaterThan($first - 1000, ConfigManager::get_timestamps()['connected_at']);

        $this->useFakeClient();
        $html = $this->render('diluxone-offload-provider', 'connection');
        $this->assertStringContainsString('Connected since', $html);
    }

    public function test_state_changes_are_timed_and_offloading_has_its_own_clock(): void {
        $this->configure(PluginState::CONFIGURED);
        $stamps = ConfigManager::get_timestamps();
        $this->assertIsInt($stamps['state_changed_at']);
        $this->assertNull($stamps['offloading_since']);

        ConfigManager::set_state(PluginState::OFFLOADING_ACTIVE);
        $since = ConfigManager::get_timestamps()['offloading_since'];
        $this->assertIsInt($since);

        // Setting the same state again does not move the clocks.
        update_option(ConfigManager::TIMESTAMPS_OPTION, ['state_changed_at' => 5, 'offloading_since' => 7]);
        ConfigManager::set_state(PluginState::OFFLOADING_ACTIVE);
        $this->assertSame(['connected_at' => null, 'state_changed_at' => 5, 'offloading_since' => 7], ConfigManager::get_timestamps());

        ConfigManager::set_state(PluginState::SYNCED);
        $this->assertNull(ConfigManager::get_timestamps()['offloading_since'], 'leaving offloading stops its clock');
        $this->assertNotSame(5, ConfigManager::get_timestamps()['state_changed_at']);
    }

    // ── A2 + A3 + A4: the table survives, records live uploads, remembers the last ──

    public function test_delete_local_files_keeps_the_rows_as_cloud_only(): void {
        $this->configure(PluginState::OFFLOADING_ACTIVE);
        $this->useFakeClient();
        $this->addTestFiles(2);
        DB::mark_synced('/2024/01/test-file-1.jpg');
        DB::mark_synced('/2024/01/test-file-2.jpg');
        $_POST = ['nonce' => wp_create_nonce('diluxone_offload_admin')];
        $_REQUEST = $_POST;
        $plugin = \DiluxOneOffload\Plugin::get_instance();
        try {
            $plugin->ajax_cs_process_delete_batch();
        } catch (\WPAjaxDieContinueException $e) {
            // wp_send_json.
        }
        $counts = Admin::tracking_counts(true);
        $this->assertSame(2, $counts['total'], 'the rows stay');
        $this->assertSame(2, $counts['cloud_only'], 'as cloud-only');
        $this->assertSame(0, $counts['local']);
    }

    public function test_an_upload_through_the_wrapper_is_recorded_and_remembered(): void {
        $this->configure(PluginState::OFFLOADING_ACTIVE);
        $this->useFakeClient();
        CloudStreamWrapper::register();
        CloudStreamWrapper::activate_offloading();
        $this->assertNull(ConfigManager::get_last_upload());

        $key = CloudStreamWrapper::key_prefix() . '/2026/09/live.jpg';
        $this->assertNotFalse(file_put_contents('diluxoneoffload://' . $key, 'twelve bytes'));

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . DB::get_table_name() . ' WHERE file = %s', '/2026/09/live.jpg'), ARRAY_A);
        $this->assertIsArray($row, 'the upload has a row');
        $this->assertSame('1', (string) $row['synced']);
        $this->assertSame('1', (string) $row['deleted'], 'no local copy');
        $this->assertSame(12, (int) $row['size']);
        $created = $row['created_at'];

        // The same key again (a regenerated thumbnail): the row is updated, its created_at kept.
        $this->assertNotFalse(file_put_contents('diluxoneoffload://' . $key, 'now fifteen bytes'));
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . DB::get_table_name() . ' WHERE file = %s', '/2026/09/live.jpg'), ARRAY_A);
        $this->assertSame(17, (int) $row['size']);
        $this->assertSame($created, $row['created_at']);
        $this->assertSame(1, Admin::tracking_counts(true)['cloud_only']);

        $last = ConfigManager::get_last_upload();
        $this->assertSame('/2026/09/live.jpg', $last['path']);
        $this->assertSame(17, $last['size']);
        $this->assertEqualsWithDelta(time(), $last['time'], 5);

        CloudStreamWrapper::deactivate_offloading();
        CloudStreamWrapper::unregister();
        $html = $this->render('diluxone-offload-sync', 'sync');
        $this->assertStringContainsString('Last upload', $html);
        $this->assertStringContainsString('live.jpg', $html);
        $this->assertStringContainsString('Not on this server', $html);
    }

    public function test_unlink_and_rename_through_the_wrapper_keep_the_table_true(): void {
        $this->configure(PluginState::OFFLOADING_ACTIVE);
        $this->useFakeClient();
        CloudStreamWrapper::register();
        CloudStreamWrapper::activate_offloading();
        $prefix = 'diluxoneoffload://' . CloudStreamWrapper::key_prefix();
        $this->assertNotFalse(file_put_contents($prefix . '/2026/09/a.jpg', 'aaa'));
        $this->assertNotFalse(file_put_contents($prefix . '/2026/09/b.jpg', 'bbbb'));
        $this->assertSame(2, Admin::tracking_counts(true)['cloud_only']);

        $this->assertTrue(rename($prefix . '/2026/09/a.jpg', $prefix . '/2026/09/renamed.jpg'));
        $files = array_column(\DiluxOneOffload\DiluxOneOffloadDB::get_deleted_files(), 'file');
        $this->assertContains('/2026/09/renamed.jpg', $files, 'the row followed the file');
        $this->assertNotContains('/2026/09/a.jpg', $files);

        $this->assertTrue(unlink($prefix . '/2026/09/b.jpg'));
        $files = array_column(\DiluxOneOffload\DiluxOneOffloadDB::get_deleted_files(), 'file');
        $this->assertNotContains('/2026/09/b.jpg', $files, 'a deleted file has no row');
        $this->assertSame(1, Admin::tracking_counts(true)['cloud_only']);

        // A rename whose source the table does not know leaves the destination's row alone.
        $this->assertNotFalse(file_put_contents($prefix . '/2026/09/c.jpg', 'c'));
        $this->assertNotFalse(file_put_contents($prefix . '/2026/09/untracked.jpg', 'u'));
        \DiluxOneOffload\DiluxOneOffloadDB::forget_file('/2026/09/untracked.jpg');
        $this->assertTrue(rename($prefix . '/2026/09/untracked.jpg', $prefix . '/2026/09/c.jpg'));
        $files = array_column(\DiluxOneOffload\DiluxOneOffloadDB::get_deleted_files(), 'file');
        $this->assertContains('/2026/09/c.jpg', $files, 'the overwritten destination keeps its row');
    }

    public function test_skip_reasons_have_labels(): void {
        $this->assertSame('Empty files', Admin::skip_reason_label('empty_file'));
        $this->assertSame('Over the size limit', Admin::skip_reason_label('File size exceeds limit (30 MB > 20 MB)'));
        $this->assertSame('File type not allowed', Admin::skip_reason_label('File extension not allowed: .exe'));
        $this->assertSame('something new', Admin::skip_reason_label('something new'), 'an unknown reason is shown as it is');
    }

    // ── A5: the skipped list ────────────────────────────────

    public function test_the_scan_writes_what_it_left_out_and_the_sync_screen_shows_it(): void {
        $this->configure(PluginState::CONFIGURED);
        $this->useFakeClient();
        $dir = wp_upload_dir()['basedir'] . '/2026/09';
        wp_mkdir_p($dir);
        file_put_contents($dir . '/empty.txt', '');
        file_put_contents($dir . '/kept.txt', 'kept');
        try {
            $files = (new SyncManager())->scan_files_to_sync(true);
        } finally {
            @unlink($dir . '/empty.txt');
            @unlink($dir . '/kept.txt');
        }
        $this->assertNotEmpty($files);
        $skipped = ConfigManager::get_skipped();
        $this->assertIsArray($skipped);
        $this->assertGreaterThanOrEqual(1, $skipped['total']);
        $this->assertArrayHasKey('empty_file', $skipped['reasons']);
        $this->assertContains('/2026/09/empty.txt', $skipped['reasons']['empty_file']['paths']);
        $this->assertEqualsWithDelta(time(), $skipped['time'], 5);

        DB::add_file('/2026/09/kept.txt', 4);
        DB::mark_synced('/2026/09/kept.txt');
        ConfigManager::set_state(PluginState::SYNCED);
        $html = $this->render('diluxone-offload-sync', 'sync');
        $this->assertStringContainsString('Skipped by the last scan', $html);
        $this->assertStringContainsString('empty.txt', $html);
    }

    // ── A6: Check now, and the posted-credentials test ─────

    public function test_check_now_ignores_the_cache_and_answers_the_health(): void {
        $this->configure(PluginState::SYNCED);
        $this->useFakeClient();
        update_option('diluxone_offload_connection_health', ['status' => 'unhealthy', 'error_code' => '403', 'error_message' => 'x', 'consecutive_failures' => 3, 'last_check' => time(), 'last_success' => 0, 'error_source' => 'azure']);
        $this->assertSame('unhealthy', ConfigManager::check_connection_health()['status'], 'within five minutes the cache answers');
        $this->assertSame('healthy', ConfigManager::check_connection_health(true)['status'], 'forced: the provider is asked');

        update_option('diluxone_offload_connection_health', ['status' => 'unhealthy', 'error_code' => '403', 'error_message' => 'x', 'consecutive_failures' => 3, 'last_check' => time(), 'last_success' => 0, 'error_source' => 'azure']);
        $_POST = ['nonce' => wp_create_nonce('diluxone_offload_admin')];
        $_REQUEST = $_POST;
        $out = '';
        try {
            ob_start();
            Admin::ajax_check_health();
        } catch (\WPAjaxDieContinueException $e) {
            $out = (string) ob_get_clean();
        }
        $json = json_decode($out, true);
        $this->assertTrue($json['success'] ?? false, $out);
        $this->assertSame('healthy', $json['data']['status']);
        $this->assertSame(0, $json['data']['consecutive_failures']);

        $html = $this->render('diluxone-offload-status', 'health');
        $this->assertStringContainsString('check-health-now', $html);
        $this->assertTrue(wp_script_is('diluxone-offload-admin-status', 'enqueued'), 'the Health tab has its script');
    }

    public function test_a_passing_test_of_other_credentials_does_not_mark_the_saved_connection_healthy(): void {
        $this->configure(PluginState::SYNCED, 'saved', 'media');
        $this->useFakeClient();
        update_option('diluxone_offload_connection_health', ['status' => 'unhealthy', 'error_code' => '403', 'error_message' => 'x', 'consecutive_failures' => 3, 'last_check' => time(), 'last_success' => 0, 'error_source' => 'azure']);
        $_POST = ['nonce' => wp_create_nonce('diluxone_offload_admin'), 'provider' => 'azure', 'account_name' => 'saved', 'account_key' => base64_encode(random_bytes(32)), 'container_name' => 'media'];
        $_REQUEST = $_POST;
        // Azure answers every request of Test Connection with success, so the
        // test really passes, without leaving this machine.
        $answer = static function ($pre, array $args) {
            $method = strtoupper($args['method'] ?? 'GET');
            $code   = 'PUT' === $method ? 201 : ('DELETE' === $method ? 202 : 200);
            return ['headers' => ['x-ms-blob-public-access' => 'blob'], 'body' => '<?xml version="1.0" encoding="utf-8"?><EnumerationResults><Blobs/></EnumerationResults>', 'response' => ['code' => $code, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
        };
        add_filter('pre_http_request', $answer, 10, 2);
        $out = '';
        try {
            ob_start();
            Admin::ajax_test_connection();
        } catch (\WPAjaxDieContinueException $e) {
            $out = (string) ob_get_clean();
        } finally {
            remove_filter('pre_http_request', $answer, 10);
        }
        $json = json_decode($out, true);
        $this->assertTrue($json['success'] ?? false, 'the test of the new key passed: ' . $out);
        $this->assertSame('unhealthy', ConfigManager::get_connection_health()['status'], 'a new key that passes says nothing about the saved one');
    }

    /** A synced library offers to look for files added since the sync, before offloading is enabled. */
    public function test_the_synced_screen_offers_scan_and_complete_sync(): void {
        $this->configure(PluginState::SYNCED);
        $this->useFakeClient();
        DB::add_file('/2026/10/a.jpg', 10);
        DB::mark_synced('/2026/10/a.jpg');

        $html = $this->render('diluxone-offload-sync', 'sync');

        $this->assertMatchesRegularExpression('#<button id="start-sync-btn"[^>]*>\s*<span[^>]*></span>\s*Scan and Complete Sync#', $html);
        $this->assertStringContainsString('Look for files added since the last sync and upload them', $html);
        $this->assertStringContainsString('Resync All Files', $html, 'starting over is still offered');
    }

    // ── A7: the failed list is paged ────────────────────────

    public function test_the_failed_list_is_paged_and_counted(): void {
        $this->configure(PluginState::SYNCED);
        $this->useFakeClient();
        for ($i = 1; $i <= 60; $i++) {
            DB::add_file(sprintf('/2026/09/f%03d.jpg', $i), 10);
            DB::increment_error(sprintf('/2026/09/f%03d.jpg', $i), 'boom');
        }
        $this->assertCount(60, DB::get_failed_files());
        $this->assertCount(50, DB::get_failed_files(50));
        $this->assertSame(60, DB::count_failed_files());

        $html = $this->render('diluxone-offload-sync', 'sync');
        $this->assertStringContainsString('The first 50 of 60', $html);
        $this->assertStringContainsString('Failed Files (60)', $html, 'the heading counts all of them');
    }

    public function test_the_sync_bar_shows_failed_files_as_their_own_part(): void {
        $this->configure(PluginState::SYNCED);
        $this->useFakeClient();
        for ($i = 1; $i <= 4; $i++) {
            DB::add_file(sprintf('/2026/09/b%d.jpg', $i), 10);
        }
        DB::mark_synced('/2026/09/b1.jpg');
        DB::mark_synced('/2026/09/b2.jpg');
        DB::increment_error('/2026/09/b3.jpg', 'boom');

        $html = $this->render('diluxone-offload-sync', 'sync');
        $this->assertStringContainsString('2 of 4 · 50%', $html);
        $this->assertMatchesRegularExpression('/meter__part--done" style="width: 50%"/', $html);
        $this->assertMatchesRegularExpression('/meter__part--failed" style="width: 25%"/', $html);
        $this->assertStringContainsString('Still to upload · 1', $html);
        $this->assertStringContainsString('Failed · 1', $html);
    }

    public function test_disconnect_and_offloading_tabs_show_the_figures(): void {
        $this->configure(PluginState::OFFLOADING_ACTIVE);
        $this->useFakeClient();
        $this->addTestFiles(3);
        DB::mark_synced('/2024/01/test-file-1.jpg');
        DB::add_cloud_only_file('/2024/01/cloud-only.jpg', 2048);
        $html = $this->render('diluxone-offload-sync', 'offloading');
        $this->assertStringContainsString('Offloading since', $html);
        $this->assertStringContainsString('Where your 2 files are', $html);
        $this->assertStringContainsString('diluxone-offload-meter__part--cloud', $html);
        $this->assertStringContainsString('diluxone-offload-meter__part--local', $html);
        $html = $this->render('diluxone-offload-sync', 'disconnect');
        $this->assertStringContainsString('Files to bring back', $html);
        $this->assertStringContainsString('Free disk here', $html);
    }
}
