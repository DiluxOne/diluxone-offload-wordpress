<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use Tests\Integration\FakeCloudClient;
use Tests\Integration\LocalBlobServer;
use DiluxOneOffload\SyncManager;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\DiluxOneOffloadDB as DB;
use DiluxOneOffload\Enums\PluginState;

/**
 * The forward sync's less-travelled paths: retrying failed files, resuming
 * with nothing left, scanning an uploads folder with files that must be
 * skipped, time-boxed batches, cancelling, comparing against the cloud, and
 * every "no provider" refusal.
 */
class ForwardSyncTest extends IntegrationTestCase {

    private static ?LocalBlobServer $server = null;
    private ?FakeCloudClient $client = null;
    /** @var string[] */
    private array $fixtures = [];
    private string $base = '';

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        self::$server = new LocalBlobServer(8773);
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
        $this->configure();
        ConfigManager::set_state(PluginState::CONFIGURED);
        delete_option('diluxone_offload_sync_meta');
        $_POST = [];
    }

    protected function tearDown(): void {
        remove_filter('diluxone_offload_pre_cloud_client', [$this, 'injectClient']);
        foreach (array_reverse($this->fixtures) as $f) {
            is_dir($f) ? @rmdir($f) : @unlink($f);
        }
        $this->fixtures = [];
        delete_option('diluxone_offload_sync_meta');
        $_POST = [];
        parent::tearDown();
    }

    public function injectClient($pre) {
        return $this->client;
    }

    private function configure(array $extra = []): void {
        ConfigManager::save_config($extra + [
            'cloud_provider'  => 'azure',
            'provider_config' => ['storage_account' => 'fwdacct', 'container_name' => 'media', 'access_key' => base64_encode(random_bytes(32))],
        ]);
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

    // ── start_sync ──────────────────────────────────────────

    public function test_retry_with_nothing_to_retry_moves_straight_to_synced(): void {
        DB::add_file('/2026/09/done.jpg', 1);
        DB::mark_synced('/2026/09/done.jpg');
        $r = (new SyncManager())->start_sync(true);
        $this->assertFalse($r['success']);
        $this->assertStringContainsString('No failed files', $r['message']);
        $this->assertSame(PluginState::SYNCED, ConfigManager::get_state());
    }

    public function test_retry_from_synced_picks_up_the_failed_files(): void {
        ConfigManager::set_state(PluginState::SYNCED);
        DB::add_file('/2026/09/failed.jpg', 1);
        $r = (new SyncManager())->start_sync(true);
        $this->assertTrue($r['success'], print_r($r, true));
        $this->assertSame(1, $r['total_files']);
        $this->assertSame(PluginState::SYNCING, ConfigManager::get_state());
    }

    public function test_resuming_with_everything_synced_completes_immediately(): void {
        DB::add_file('/2026/09/a.jpg', 1);
        DB::mark_synced('/2026/09/a.jpg');
        $r = (new SyncManager())->start_sync();
        $this->assertTrue($r['success']);
        $this->assertSame(0, $r['total_files']);
        $this->assertStringContainsString('already synced', $r['message']);
        $this->assertSame(PluginState::SYNCED, ConfigManager::get_state());
    }

    public function test_a_fresh_sync_with_nothing_eligible_is_synced_at_once(): void {
        $this->configure(['allowed_file_types' => 'nothingmatchesthis']);
        $r = (new SyncManager())->start_sync();
        $this->assertTrue($r['success']);
        $this->assertStringContainsString('No files to sync', $r['message']);
        $this->assertSame(PluginState::SYNCED, ConfigManager::get_state());
    }

    public function test_a_fresh_sync_catalogues_in_batches_of_five_hundred(): void {
        $this->configure(['allowed_file_types' => 'bulk']);
        for ($i = 0; $i < 501; $i++) {
            $this->fixture("bulk/f{$i}.bulk", 'b');
        }
        $r = (new SyncManager())->start_sync();
        $this->assertTrue($r['success'], print_r($r, true));
        $this->assertSame(501, $r['total_files']);
        $this->assertSame(501, DB::get_total_count());
        $this->assertSame(PluginState::SYNCING, ConfigManager::get_state());
    }

    public function test_every_entry_point_refuses_without_a_provider(): void {
        remove_filter('diluxone_offload_pre_cloud_client', [$this, 'injectClient']);
        delete_option('diluxone_offload_config');
        ConfigManager::set_state(PluginState::CONFIGURED);
        $sm = new SyncManager();
        $this->assertStringContainsString('not configured', $sm->start_sync()['message']);
        ConfigManager::set_state(PluginState::OFFLOADING_ACTIVE);
        $this->assertStringContainsString('not configured', $sm->start_reverse_sync()['message']);
    }

    // ── process_batch ───────────────────────────────────────

    public function test_a_zero_time_budget_still_uploads_one_round_and_reports_processing(): void {
        $this->configure(['allowed_file_types' => 'tb']);
        $this->fixture('tb/one.tb', 'one');
        $this->fixture('tb/two.tb', 'two');
        $sm = new SyncManager();
        $this->assertTrue($sm->start_sync()['success']);
        $r = $sm->process_batch(0.0);
        $this->assertSame('processing', $r['status']);
        $this->assertSame(2, $r['uploaded_this_batch']);
        $this->assertSame('completed', $sm->process_batch(5.0)['status']);
    }

    // ── Maximum File Size ───────────────────────────────────

    /** The setting's whole effect: a file above it never enters the sync, a file below it is uploaded. */
    public function test_a_file_above_the_maximum_file_size_is_left_out_of_the_initial_sync(): void {
        $this->configure(['allowed_file_types' => 'sz', 'max_file_size' => 2 * 1048576]);
        $this->fixture('sz/small.sz', str_repeat('s', 1048576));
        $this->fixture('sz/large.sz', str_repeat('l', 3 * 1048576));

        $sm = new SyncManager();
        $r  = $sm->start_sync();
        $this->assertTrue($r['success'], print_r($r, true));
        $this->assertSame(1, $r['total_files'], 'only the file under the limit is catalogued');
        $this->assertSame(1, DB::get_total_count());

        $this->assertSame('completed', $sm->process_batch(5.0)['status']);
        $this->assertArrayHasKey('uploads/sz/small.sz', $this->client->blobs);
        $this->assertArrayNotHasKey('uploads/sz/large.sz', $this->client->blobs, 'the large file never reaches the cloud');
        $this->assertFileExists($this->base . '/sz/large.sz', 'and stays where it was');
    }

    /** A file exactly at the limit is under it. */
    public function test_a_file_exactly_at_the_maximum_file_size_is_synced(): void {
        $this->configure(['allowed_file_types' => 'szx', 'max_file_size' => 1048576]);
        $this->fixture('szx/exact.szx', str_repeat('e', 1048576));
        $r = (new SyncManager())->start_sync();
        $this->assertTrue($r['success'], print_r($r, true));
        $this->assertSame(1, $r['total_files']);
    }

    // ── scan_local_files / pause / resume ───────────────────

    public function test_scan_local_files_with_nothing_eligible(): void {
        $this->configure(['allowed_file_types' => 'nothingmatchesthis']);
        $r = (new SyncManager())->scan_local_files();
        $this->assertTrue($r['success']);
        $this->assertSame(0, $r['total_files']);
    }

    public function test_scan_local_files_stores_in_batches_of_five_hundred(): void {
        $this->configure(['allowed_file_types' => 'blk']);
        for ($i = 0; $i < 501; $i++) {
            $this->fixture("blk/f{$i}.blk", 'b');
        }
        $r = (new SyncManager())->scan_local_files();
        $this->assertTrue($r['success'], print_r($r, true));
        $this->assertSame(501, DB::get_total_count());
    }

    public function test_scan_sees_nothing_while_uploads_live_on_the_wrapper(): void {
        ConfigManager::set_state(PluginState::SYNCED);
        \DiluxOneOffload\CloudStreamWrapper::activate_offloading();
        try {
            $files = (new SyncManager())->scan_files_to_sync(true);
        } finally {
            \DiluxOneOffload\CloudStreamWrapper::deactivate_offloading();
        }
        $this->assertSame([], $files, 'the protocol path is not a local directory');
    }

    public function test_pause_and_resume_need_a_sync(): void {
        $sm = new SyncManager();
        $this->assertFalse($sm->pause_sync());
        $this->assertFalse($sm->resume_sync());
    }

    public function test_batches_are_capped_by_the_batch_size(): void {
        $this->configure(['allowed_file_types' => 'cap']);
        $sm  = new SyncManager();
        $capProp = new \ReflectionProperty($sm, 'batch_size');
        if ( PHP_VERSION_ID < 80100 ) { // Required before 8.1, deprecated from 8.5.
        	$capProp->setAccessible( true );
        }
        $cap = $capProp->getValue($sm);
        for ($i = 0; $i <= $cap; $i++) {
            $this->fixture("cap/f{$i}.cap", 'c');
        }
        $this->assertTrue($sm->start_sync()['success']);
        $r = $sm->process_batch(0.0);
        $this->assertSame('processing', $r['status']);
        $this->assertSame($cap, $r['uploaded_this_batch'], 'one round uploads exactly batch_size files');
    }

    public function missingBasedir(array $dirs): array {
        $dirs['basedir'] = '/nonexistent/diluxone-offload-uploads';
        $dirs['path']    = $dirs['basedir'] . '/2026/09';
        return $dirs;
    }

    public function test_scan_of_a_missing_uploads_directory_is_empty(): void {
        add_filter('upload_dir', [$this, 'missingBasedir']);
        try {
            $files = (new SyncManager())->scan_files_to_sync(true);
        } finally {
            remove_filter('upload_dir', [$this, 'missingBasedir']);
        }
        $this->assertSame([], $files);
    }

    public function test_files_over_the_chunk_threshold_take_the_chunked_upload_path(): void {
        $this->configure(['allowed_file_types' => 'big']);
        $sm        = new SyncManager();
        $thresholdProp = new \ReflectionProperty($sm, 'chunked_threshold');
        if ( PHP_VERSION_ID < 80100 ) { // Required before 8.1, deprecated from 8.5.
        	$thresholdProp->setAccessible( true );
        }
        $threshold = $thresholdProp->getValue($sm);
        $path      = $this->fixture('big/huge.big', '');
        $fh        = fopen($path, 'w');
        ftruncate($fh, $threshold + 1);
        fclose($fh);
        $this->assertTrue($sm->start_sync()['success']);
        $r = $sm->process_batch(30.0);
        $this->assertSame('completed', $r['status']);
        $this->assertArrayHasKey('uploads/big/huge.big', $this->client->blobs);
        $this->assertSame($threshold + 1, strlen($this->client->blobs['uploads/big/huge.big']));
    }


    public function test_cancel_clears_a_running_sync(): void {
        update_option('diluxone_offload_sync_meta', ['status' => 'started', 'sync_session_id' => 'a', 'last_heartbeat' => time()], false);
        ConfigManager::set_state(PluginState::SYNCING);
        DB::add_file('/2026/09/x.jpg', 1);
        $this->assertTrue((new SyncManager())->cancel_sync());
        $this->assertSame(PluginState::CONFIGURED, ConfigManager::get_state());
        $this->assertSame(0, DB::get_total_count());
        $this->assertFalse(get_option('diluxone_offload_sync_meta'));
    }

    public function test_cancel_clears_a_paused_sync(): void {
        update_option('diluxone_offload_sync_meta', ['status' => 'paused', 'sync_session_id' => 'a'], false);
        ConfigManager::set_state(PluginState::SYNCING);
        $this->assertTrue((new SyncManager())->cancel_sync());
        $this->assertSame(PluginState::CONFIGURED, ConfigManager::get_state());
    }

    public function test_cancel_with_nothing_running_says_so(): void {
        $this->assertFalse((new SyncManager())->cancel_sync());
    }

    // ── scan_files_to_sync ──────────────────────────────────

    public function test_scan_skips_empty_cache_hidden_and_disallowed_files(): void {
        $this->configure(['allowed_file_types' => 'sc']);
        $this->fixture('scan/keep.sc', 'ok');
        $this->fixture('scan/empty.sc', '');
        $this->fixture('scan/cache/cached.sc', 'c');
        $this->fixture('scan/.hidden.sc', 'h');
        $this->fixture('scan/wrong.txt', 'w');
        $files = (new SyncManager())->scan_files_to_sync(true);
        $paths = array_map(fn($f) => $f['remote_path'], $files);
        $this->assertContains('uploads/scan/keep.sc', $paths);
        $this->assertNotContains('uploads/scan/empty.sc', $paths, 'empty files are skipped');
        $this->assertNotContains('uploads/scan/cache/cached.sc', $paths, 'cache dirs are skipped');
        $this->assertNotContains('uploads/scan/.hidden.sc', $paths, 'hidden files are skipped');
        $this->assertNotContains('uploads/scan/wrong.txt', $paths, 'extension filter applies');
        $this->assertNull($files[array_search('uploads/scan/keep.sc', $paths, true)]['checksum'], 'initial sync skips MD5');
    }

    public function test_scan_computes_checksums_when_not_initial(): void {
        $this->configure(['allowed_file_types' => 'ck']);
        $this->fixture('ck/a.ck', 'abc');
        $files = (new SyncManager())->scan_files_to_sync(false);
        $this->assertSame(md5('abc'), $files[0]['checksum']);
    }

    // ── reverse sync extras ─────────────────────────────────

    public function test_reverse_continue_with_an_empty_cloud_and_no_catalogue_fails(): void {
        ConfigManager::set_state(PluginState::OFFLOADING_ACTIVE);
        $this->client->blobs = [];
        $r = (new SyncManager())->start_reverse_sync('continue');
        $this->assertFalse($r['success']);
        $this->assertStringContainsString('No files found', $r['message']);
    }

    public function test_reverse_scratch_catalogues_a_large_cloud_in_batches(): void {
        ConfigManager::set_state(PluginState::OFFLOADING_ACTIVE);
        for ($i = 0; $i < 501; $i++) {
            $this->client->blobs["uploads/many/f{$i}.bin"] = 'x';
        }
        $r = (new SyncManager())->start_reverse_sync('scratch');
        $this->assertTrue($r['success'], print_r($r, true));
        $this->assertSame(501, (int) $r['total_files']);
        $this->assertSame(501, DB::count_deleted_files());
    }

    public function test_reverse_continue_catalogues_only_what_the_db_lacks_in_batches(): void {
        ConfigManager::set_state(PluginState::OFFLOADING_ACTIVE);
        for ($i = 0; $i < 501; $i++) {
            $this->client->blobs["uploads/many/f{$i}.bin"] = 'x';
        }
        DB::add_file('/many/f0.bin', 1);
        DB::mark_synced('/many/f0.bin'); // local and synced: not missing
        $r = (new SyncManager())->start_reverse_sync('continue');
        $this->assertTrue($r['success'], print_r($r, true));
        $this->assertSame(500, DB::count_deleted_files());
    }

    public function test_a_reverse_round_is_capped_by_the_batch_size(): void {
        ConfigManager::set_state(PluginState::OFFLOADING_ACTIVE);
        $sm  = new SyncManager();
        $capProp = new \ReflectionProperty($sm, 'batch_size');
        if ( PHP_VERSION_ID < 80100 ) { // Required before 8.1, deprecated from 8.5.
        	$capProp->setAccessible( true );
        }
        $cap = $capProp->getValue($sm);
        for ($i = 0; $i <= $cap; $i++) {
            $this->client->blobs["uploads/cap/f{$i}.bin"] = 'x';
            $this->fixtures[] = $this->base . "/cap/f{$i}.bin";
        }
        $this->fixtures[] = $this->base . '/cap';
        $this->assertTrue($sm->start_reverse_sync('scratch')['success']);
        $r = $sm->process_reverse_batch(0.0);
        $this->assertSame('processing', $r['status']);
        $this->assertSame($cap, $r['downloaded_this_batch']);
        $this->assertSame(1, $r['remaining_files']);
    }

    public function test_reverse_batch_with_a_zero_time_budget_reports_processing(): void {
        ConfigManager::set_state(PluginState::OFFLOADING_ACTIVE);
        $this->client->blobs = ['uploads/rv/a.bin' => 'aaa', 'uploads/rv/b.bin' => 'bbb'];
        $this->fixtures[] = $this->base . '/rv/a.bin';
        $this->fixtures[] = $this->base . '/rv/b.bin';
        $this->fixtures[] = $this->base . '/rv';
        $sm = new SyncManager();
        $this->assertTrue($sm->start_reverse_sync('scratch')['success']);
        $r = $sm->process_reverse_batch(0.0);
        $this->assertSame('processing', $r['status']);
        $this->assertSame(2, $r['downloaded_this_batch']);
        $this->assertSame(0, $r['remaining_files']);
    }
}
