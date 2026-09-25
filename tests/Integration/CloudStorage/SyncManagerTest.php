<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use Tests\Integration\FakeCloudClient;
use Tests\Integration\LocalBlobServer;
use DiluxOneOffload\SyncManager;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\DiluxOneOffloadDB;
use DiluxOneOffload\Enums\PluginState;

/**
 * Integration tests for the sync state machine, end to end.
 *
 * Real WordPress, real database, real files in the uploads directory, and a
 * provider stand-in injected through the diluxone_offload_pre_cloud_client
 * filter. The parallel upload path uses genuine cURL handles against a local
 * throwaway HTTP server, so the curl_multi loop that ships in the plugin is
 * the one that runs here.
 *
 * Everything the SyncManager persists between requests (sync_meta, the
 * tracking table) is asserted from the DB, because a browser drives this over
 * many requests and only the DB survives them.
 */
class SyncManagerTest extends IntegrationTestCase {

    private static ?LocalBlobServer $server = null;

    private FakeCloudClient $client;

    /** @var string[] Fixture files created in the uploads dir. */
    private array $fixtures = [];

    private string $uploads_base = '';

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        self::$server = new LocalBlobServer();
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
        $this->uploads_base = wp_upload_dir()['basedir'];

        $this->client = new FakeCloudClient(self::$server->base_url);
        add_filter('diluxone_offload_pre_cloud_client', [$this, 'injectClient']);

        // The state machine still needs a saved provider to consider itself configured.
        ConfigManager::save_config([
            'cloud_provider'  => 'azure',
            'provider_config' => [
                'storage_account' => 'syncacct',
                'container_name'  => 'media',
                'access_key'      => base64_encode(random_bytes(32)),
            ],
        ]);
        ConfigManager::set_state(PluginState::CONFIGURED);
        delete_option('diluxone_offload_sync_meta');
    }

    protected function tearDown(): void {
        remove_filter('diluxone_offload_pre_cloud_client', [$this, 'injectClient']);
        foreach ($this->fixtures as $f) {
            @unlink($f);
        }
        $this->fixtures = [];
        delete_option('diluxone_offload_sync_meta');
        unset($_POST['session_id']);
        parent::tearDown();
    }

    /** Filter callback: hand the engine our stand-in. */
    public function injectClient($pre) {
        return $this->client;
    }

    private function fixture(string $relative, string $content = 'x'): string {
        $path = $this->uploads_base . '/' . ltrim($relative, '/');
        wp_mkdir_p(dirname($path));
        file_put_contents($path, $content);
        $this->fixtures[] = $path;
        return $path;
    }

    private function scanAndStart(): SyncManager {
        $sm = new SyncManager();
        $sm->scan_local_files();
        $r = $sm->start_sync();
        $this->assertTrue($r['success'], 'start_sync: ' . print_r($r, true));
        return $sm;
    }

    // ── scan ────────────────────────────────────────────────

    public function test_a_path_the_tracking_table_cannot_hold_is_skipped_and_the_rest_scanned(): void {
        // A file name is at most 255 bytes, so only nesting gets a relative
        // path past the 767-byte key column. It is skipped, and counted, and
        // the file next to it is tracked as usual.
        $deep = '2026/09/' . str_repeat('d', 250) . '/' . str_repeat('e', 250) . '/' . str_repeat('f', 250) . '/too-deep.png';
        $this->fixture($deep, 'deep');
        $this->fixture('2026/09/beside.png', 'ok');

        $result = (new SyncManager())->scan_local_files();

        $this->assertTrue($result['success']);
        global $wpdb;
        $files = $wpdb->get_col("SELECT file FROM `" . self::$table_name . "`");
        $this->assertContains('/2026/09/beside.png', $files);
        $this->assertSame([], array_values(array_filter($files, fn ($f) => strpos($f, 'too-deep') !== false)), 'the over-long path never reaches the table, whole or truncated');
    }

    public function test_scan_registers_local_files_in_the_tracking_table(): void {
        $this->fixture('2026/09/sync-a.jpg', 'aaa');
        $this->fixture('2026/09/sync-b.png', 'bbbbb');

        $result = (new SyncManager())->scan_local_files();

        $this->assertTrue($result['success']);
        $this->assertGreaterThanOrEqual(2, $result['total_files']);

        global $wpdb;
        $rows = $wpdb->get_results("SELECT file, size, synced FROM `" . self::$table_name . "` WHERE file LIKE '%sync-%'", ARRAY_A);
        $by = array_column($rows, null, 'file');
        $this->assertArrayHasKey('/2026/09/sync-a.jpg', $by);
        $this->assertSame(3, (int) $by['/2026/09/sync-a.jpg']['size']);
        $this->assertSame(0, (int) $by['/2026/09/sync-a.jpg']['synced'], 'freshly scanned files are pending');
    }

    public function test_scan_without_a_provider_fails_cleanly(): void {
        remove_filter('diluxone_offload_pre_cloud_client', [$this, 'injectClient']);
        ConfigManager::reset();
        $result = (new SyncManager())->scan_local_files();
        $this->assertFalse($result['success']);
    }

    // ── start ───────────────────────────────────────────────

    public function test_start_sync_records_the_session_and_moves_to_syncing(): void {
        $this->fixture('2026/09/start.jpg');
        $this->scanAndStart();

        $meta = get_option('diluxone_offload_sync_meta');
        $this->assertSame('started', $meta['status']);
        $this->assertNotEmpty($meta['sync_session_id']);
        $this->assertSame(PluginState::SYNCING, ConfigManager::get_state());
    }

    public function test_start_sync_refuses_from_not_configured(): void {
        ConfigManager::set_state(PluginState::NOT_CONFIGURED);
        $result = (new SyncManager())->start_sync();
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not_configured', $result['message']);
    }

    // ── batches: the real curl_multi loop ───────────────────

    public function test_a_batch_uploads_pending_files_and_completes(): void {
        $this->fixture('2026/09/batch-1.jpg', 'one');
        $this->fixture('2026/09/batch-2.jpg', 'two');
        $sm = $this->scanAndStart();

        $result = $sm->process_batch(30.0);

        $this->assertSame('completed', $result['status'], print_r($result, true));
        $stats = DiluxOneOffloadDB::get_stats();
        $this->assertSame(0, (int) $stats['pending_files']);
        $this->assertGreaterThanOrEqual(2, (int) $stats['synced_files']);
        $this->assertArrayHasKey('uploads/2026/09/batch-1.jpg', $this->client->blobs, 'uploaded under uploads/');
        $this->assertSame('one', $this->client->blobs['uploads/2026/09/batch-1.jpg']);
    }

    public function test_a_rejected_upload_is_counted_and_not_marked_synced(): void {
        $this->fixture('2026/09/reject.jpg', 'nope');
        $this->client->upload_status = 403;
        $sm = $this->scanAndStart();

        $sm->process_batch(30.0);

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT synced, errors FROM `" . self::$table_name . "` WHERE file = %s", '/2026/09/reject.jpg'), ARRAY_A);
        $this->assertSame(0, (int) $row['synced'], 'a 403 must not count as synced');
        $this->assertGreaterThanOrEqual(1, (int) $row['errors'], 'the failure is counted for retry');
    }

    public function test_a_file_deleted_between_scan_and_upload_is_recorded_as_an_error(): void {
        $path = $this->fixture('2026/09/vanish.jpg', 'gone soon');
        $sm = $this->scanAndStart();
        unlink($path);

        $sm->process_batch(30.0);

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT synced, errors FROM `" . self::$table_name . "` WHERE file = %s", '/2026/09/vanish.jpg'), ARRAY_A);
        $this->assertSame(0, (int) $row['synced']);
        $this->assertGreaterThanOrEqual(1, (int) $row['errors']);
        $this->assertArrayNotHasKey('uploads/2026/09/vanish.jpg', $this->client->blobs);
    }

    public function test_a_missing_file_among_several_is_not_credited_with_a_neighbours_upload(): void {
        // Results come back in completion order: a file that never opened is
        // reported first even when the batch (largest first) has it last, so
        // a merge by position would pin its failure on the first good file
        // and its neighbour's success on it.
        $this->fixture('2026/09/keep-1.jpg', 'one-one-one');
        $this->fixture('2026/09/keep-2.jpg', 'two-two');
        $this->fixture('2026/09/keep-3.jpg', 'three');
        $gone = $this->fixture('2026/09/vanish-2.jpg', 'g');
        $sm = $this->scanAndStart();
        unlink($gone);

        $sm->process_batch(30.0);

        global $wpdb;
        $rows = $wpdb->get_results("SELECT file, synced FROM `" . self::$table_name . "` ORDER BY file", ARRAY_A);
        $synced = array_column($rows, 'synced', 'file');
        $this->assertSame('0', (string) $synced['/2026/09/vanish-2.jpg'], 'the missing file is not synced');
        foreach (['/2026/09/keep-1.jpg', '/2026/09/keep-2.jpg', '/2026/09/keep-3.jpg'] as $file) {
            $this->assertSame('1', (string) $synced[$file], "$file was uploaded");
        }
        $this->assertArrayNotHasKey('uploads/2026/09/vanish-2.jpg', $this->client->blobs);
    }

    public function test_process_batch_without_an_active_sync_says_so(): void {
        $result = (new SyncManager())->process_batch();
        $this->assertSame('error', $result['status']);
    }

    /** Two tabs: the one that did not start the sync gets told to back off. */
    public function test_a_foreign_session_is_refused(): void {
        $this->fixture('2026/09/session.jpg');
        $sm = $this->scanAndStart();

        $_POST['session_id'] = 'someone-else';
        $result = $sm->process_batch();

        $this->assertSame('session_lost', $result['status']);
        $this->assertNotEmpty($result['active_session_id']);
    }

    // ── progress / pause / cancel ───────────────────────────

    public function test_progress_reflects_the_table_with_numeric_fields(): void {
        $this->fixture('2026/09/p1.jpg');
        $this->fixture('2026/09/p2.jpg');
        $sm = $this->scanAndStart();

        $p = $sm->get_progress();

        $this->assertSame('started', $p['status']);
        $this->assertIsInt($p['total_files'], 'the browser does arithmetic on this — it must be a number, not "2"');
        $this->assertGreaterThanOrEqual(2, $p['total_files']);
        $this->assertSame($p['total_files'], $p['pending_files'] + $p['processed_files']);
    }

    public function test_progress_is_null_with_no_sync(): void {
        $this->assertNull((new SyncManager())->get_progress());
    }

    public function test_pause_and_resume_flip_the_meta_status(): void {
        $this->fixture('2026/09/pause.jpg');
        $sm = $this->scanAndStart();

        $sm->pause_sync();
        $this->assertSame('paused', get_option('diluxone_offload_sync_meta')['status']);

        $sm->resume_sync();
        $this->assertSame('started', get_option('diluxone_offload_sync_meta')['status']);
    }

    public function test_cancel_clears_the_session(): void {
        $this->fixture('2026/09/cancel.jpg');
        $sm = $this->scanAndStart();

        $sm->cancel_sync();

        $meta = get_option('diluxone_offload_sync_meta', []);
        $this->assertTrue(empty($meta) || ($meta['status'] ?? '') !== 'started', 'no sync must remain active');
        $this->assertNotSame(PluginState::SYNCING, ConfigManager::get_state());
    }
}
