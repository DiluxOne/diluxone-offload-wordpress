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
        // PHPUnit keeps every test object until the suite ends: what the fake
        // cloud holds (a 10 MB file among them) goes now.
        $this->client->blobs = [];
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

    /** With more slots than batch_size, a request past its budget starts batch_size files: the round never held more. */
    public function test_batches_are_capped_by_the_batch_size(): void {
        $this->configure(['allowed_file_types' => 'cap']);
        for ($i = 0; $i < 6; $i++) {
            $this->fixture("cap/f{$i}.cap", 'c');
        }
        $sm = new SyncManager();
        $this->assertTrue($sm->start_sync()['success']);
        $sm->set_parallel_uploads(3);
        $capProp = new \ReflectionProperty($sm, 'batch_size');
        if ( PHP_VERSION_ID < 80100 ) { // Required before 8.1, deprecated from 8.5.
        	$capProp->setAccessible( true );
        }
        $capProp->setValue($sm, 2);

        $r = $sm->process_batch(0.0);
        $this->assertSame('processing', $r['status']);
        $this->assertSame(2, $r['uploaded_this_batch'], 'one round uploads exactly batch_size files');
    }

    /**
     * A round of up to 200 MB must not make a request outlive its budget:
     * past it the pool starts no new file, and only the files that started
     * are charged an attempt, so a request killed mid-transfer does not
     * retire the whole round after three tries.
     */
    public function test_a_request_past_its_budget_starts_no_new_file_and_charges_only_those_that_started(): void {
        global $wpdb;
        $this->configure(['allowed_file_types' => 'bud']);
        for ($i = 0; $i < 12; $i++) {
            $this->fixture("bud/f{$i}.bud", 'b');
        }
        $sm = new SyncManager();
        $this->assertTrue($sm->start_sync()['success']);
        $sm->set_parallel_uploads(3);

        $r = $sm->process_batch(0.0);
        $this->assertSame('processing', $r['status']);
        $this->assertSame(3, $r['uploaded_this_batch'], 'the first slots fill, so the request uploads something, and nothing else starts');
        $untouched = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . DB::get_table_name() . ' WHERE synced = 0 AND errors = 0');
        $this->assertSame(9, $untouched, 'the files that never started are pending with no attempt charged');
    }

    /** How much one round may take grows with the parallelism, never under the 12 MB it always had. */
    public function test_a_round_takes_five_megabytes_per_parallel_slot(): void {
        $sm = new SyncManager();
        foreach ([3 => 15, 5 => 25, 20 => 100, 40 => 200] as $level => $mb) {
            $sm->set_parallel_uploads($level);
            $this->assertSame($mb * 1024 * 1024, $sm->round_bytes(), "parallelism $level");
        }
    }

    /**
     * What made a sync start slow: largest first, 12 MB a round, so a library
     * that starts with videos uploaded one of them per round while the other
     * slots waited. Now the first round holds a large file and small ones,
     * one per slot.
     */
    public function test_a_library_that_starts_with_large_files_fills_every_slot_in_the_first_round(): void {
        global $wpdb;
        $this->configure(['allowed_file_types' => 'mix']);
        for ($i = 0; $i < 3; $i++) {
            $this->fixture("mix/video{$i}.mix", 'v');
        }
        for ($i = 0; $i < 8; $i++) {
            $this->fixture("mix/thumb{$i}.mix", 't');
        }
        $sm = new SyncManager();
        $this->assertTrue($sm->start_sync()['success']);
        // The tracking table says what a file weighs; make the videos 20 MB.
        $wpdb->query($wpdb->prepare('UPDATE ' . DB::get_table_name() . ' SET size = %d WHERE file LIKE %s', 20 * 1024 * 1024, '%/video%'));

        $r = $sm->process_batch(0.0);
        $this->assertSame(5, $r['uploaded_this_batch'], 'one video and four small files: every one of the 5 slots busy');
        $synced = $wpdb->get_col('SELECT file FROM ' . DB::get_table_name() . ' WHERE synced = 1');
        $this->assertCount(1, array_filter($synced, fn($f) => strpos($f, '/video') !== false), 'a 25 MB round holds one 20 MB video');
    }

    /** A round larger than the pool: every file goes through the slots, a missing one fails alone. */
    public function test_a_round_larger_than_the_pool_uploads_every_file_and_fails_only_the_missing_one(): void {
        global $wpdb;
        $this->configure(['allowed_file_types' => 'pool']);
        for ($i = 0; $i < 12; $i++) {
            $this->fixture("pool/f{$i}.pool", str_repeat('p', 10 + $i));
        }
        $sm = new SyncManager();
        $sm->set_parallel_uploads(3);
        $this->assertTrue($sm->start_sync()['success']);
        $sm->set_parallel_uploads(3);
        unlink($this->base . '/pool/f5.pool');

        $this->assertSame('completed', $sm->process_batch(30.0)['status']);
        $this->assertSame(11, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . DB::get_table_name() . ' WHERE synced = 1'));
        $failed = $wpdb->get_results('SELECT file, error_message FROM ' . DB::get_table_name() . ' WHERE synced = 0', ARRAY_A);
        $this->assertCount(1, $failed);
        $this->assertStringEndsWith('/pool/f5.pool', $failed[0]['file']);
        $this->assertStringContainsString('File not found', (string) $failed[0]['error_message']);
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

    public function test_a_chunked_upload_whose_commit_fails_is_handed_back_to_the_provider(): void {
        // The parts landed; only the engine knows the commit failed, and it
        // says so through the handle's on_failure, so the provider can drop
        // the parts instead of leaving them stored and billed.
        $this->configure(['allowed_file_types' => 'big']);
        $sm            = new SyncManager();
        $thresholdProp = new \ReflectionProperty($sm, 'chunked_threshold');
        if ( PHP_VERSION_ID < 80100 ) { // Required before 8.1, deprecated from 8.5.
        	$thresholdProp->setAccessible( true );
        }
        $path = $this->fixture('big/refused.big', '');
        $fh   = fopen($path, 'w');
        ftruncate($fh, $thresholdProp->getValue($sm) + 1);
        fclose($fh);
        $this->client->upload_status = 500;
        $this->assertTrue($sm->start_sync()['success']);
        $sm->process_batch(30.0);
        // Every attempt the engine made (it retries within the batch) is handed back.
        $this->assertNotEmpty($this->client->abandoned);
        $this->assertSame(['uploads/big/refused.big'], array_values(array_unique($this->client->abandoned)));
    }

    /** A threshold and part size small enough for a test file to make several parts. */
    private function chunkedAt(SyncManager $sm, int $threshold, int $partSize): void {
        $prop = new \ReflectionProperty($sm, 'chunked_threshold');
        if ( PHP_VERSION_ID < 80100 ) { // Required before 8.1, deprecated from 8.5.
        	$prop->setAccessible( true );
        }
        $prop->setValue($sm, $threshold);
        $this->client->part_size = $partSize;
    }

    /** The parts go through the pool, each streamed from its offset, and assemble into the file byte for byte. */
    public function test_a_large_files_parts_go_through_the_pool_and_assemble_byte_for_byte(): void {
        $this->configure(['allowed_file_types' => 'prt']);
        $bytes = random_bytes(3500);
        $this->fixture('prt/video.prt', $bytes);
        $this->fixture('prt/thumb.prt', 'small');
        $sm = new SyncManager();
        $this->assertTrue($sm->start_sync()['success']);
        $this->chunkedAt($sm, 1000, 1000);

        $this->assertSame('completed', $sm->process_batch(30.0)['status']);
        $this->assertSame(4, $this->client->part_requests, 'four parts of 1000 bytes, the last one 500');
        $this->assertSame($bytes, $this->client->blobs['uploads/prt/video.prt']);
        $this->assertSame('small', $this->client->blobs['uploads/prt/thumb.prt']);
        $this->assertSame([], $this->client->abandoned);
    }

    /** A part that meets a 503 goes again; the file is not failed for it. */
    public function test_a_part_that_meets_a_temporary_error_is_sent_again(): void {
        $this->configure(['allowed_file_types' => 'prt']);
        $bytes = random_bytes(3000);
        $this->fixture('prt/retry.prt', $bytes);
        $sm = new SyncManager();
        $this->assertTrue($sm->start_sync()['success']);
        $this->chunkedAt($sm, 1000, 1000);
        $this->client->part_statuses = [2 => [503, 500]];

        $this->assertSame('completed', $sm->process_batch(30.0)['status']);
        $this->assertSame(5, $this->client->part_requests, 'part 2 three times');
        $this->assertSame($bytes, $this->client->blobs['uploads/prt/retry.prt']);
    }

    /** A part refused for good fails its file alone, drops its upload and forgets it. */
    public function test_a_part_refused_for_good_fails_its_file_and_drops_the_upload(): void {
        global $wpdb;
        $this->configure(['allowed_file_types' => 'prt']);
        $this->fixture('prt/denied.prt', random_bytes(3000));
        $this->fixture('prt/fine.prt', 'fine');
        $sm = new SyncManager();
        $this->assertTrue($sm->start_sync()['success']);
        $this->chunkedAt($sm, 1000, 1000);
        $this->client->part_statuses = [2 => [403]];

        $sm->process_batch(0.0);
        $this->assertSame('fine', $this->client->blobs['uploads/prt/fine.prt'] ?? null, 'the other files of the round go through');
        $this->assertArrayNotHasKey('uploads/prt/denied.prt', $this->client->blobs);
        $this->assertSame(['uploads/prt/denied.prt'], $this->client->abandoned);
        $row = $wpdb->get_row($wpdb->prepare('SELECT error_message, upload_id FROM ' . DB::get_table_name() . ' WHERE file = %s', '/prt/denied.prt'), ARRAY_A);
        $this->assertStringContainsString('HTTP 403', (string) $row['error_message']);
        $this->assertNull($row['upload_id'], 'nothing is left to take up');
    }

    /** A part still failing on its third try fails the file; the next round starts it over with a new upload. */
    public function test_a_part_that_keeps_failing_fails_the_file_and_the_retry_starts_over(): void {
        $this->configure(['allowed_file_types' => 'prt']);
        $bytes = random_bytes(3000);
        $this->fixture('prt/flaky.prt', $bytes);
        $sm = new SyncManager();
        $this->assertTrue($sm->start_sync()['success']);
        $this->chunkedAt($sm, 1000, 1000);
        $this->client->part_statuses = [2 => [500, 500, 500]];

        $this->assertSame('completed', $sm->process_batch(30.0)['status']);
        $this->assertSame(['uploads/prt/flaky.prt'], $this->client->abandoned, 'the first upload was dropped');
        $this->assertSame([null, null], $this->client->resume_requests, 'and the second one did not try to take it up');
        $this->assertSame(8, $this->client->part_requests, 'three parts and two retries, then three parts');
        $this->assertSame($bytes, $this->client->blobs['uploads/prt/flaky.prt']);
    }

    /**
     * Past the budget no new part starts either: a large file left half sent
     * keeps its token, is not charged the attempt, and the next request takes
     * it up, sending only the parts the service does not hold.
     */
    public function test_a_large_file_left_half_sent_is_taken_up_by_the_next_request(): void {
        global $wpdb;
        $this->configure(['allowed_file_types' => 'prt']);
        $bytes = random_bytes(6000);
        $this->fixture('prt/long.prt', $bytes);
        $this->fixture('prt/later.prt', 'l');
        $sm = new SyncManager();
        $this->assertTrue($sm->start_sync()['success']);
        $sm->set_parallel_uploads(3);
        $this->chunkedAt($sm, 1000, 1000);

        $r = $sm->process_batch(0.0);
        $this->assertSame(0, $r['uploaded_this_batch'], 'the first fill is three parts of the large file');
        $this->assertSame(3, $this->client->part_requests);
        $row = $wpdb->get_row($wpdb->prepare('SELECT synced, errors, upload_id FROM ' . DB::get_table_name() . ' WHERE file = %s', '/prt/long.prt'), ARRAY_A);
        $this->assertSame('0', $row['synced']);
        $this->assertSame('0', $row['errors'], 'a file left half sent on purpose is not a failed attempt');
        $this->assertStringEndsWith('|fake-upload', (string) $row['upload_id']);

        $this->assertSame('completed', $sm->process_batch(30.0)['status']);
        $this->assertSame(['fake-upload'], array_values(array_filter($this->client->resume_requests)), 'the second request took the upload up');
        $this->assertSame(6, $this->client->part_requests, 'and sent only the three parts the service did not hold');
        $this->assertSame($bytes, $this->client->blobs['uploads/prt/long.prt']);
        $this->assertNull($wpdb->get_var($wpdb->prepare('SELECT upload_id FROM ' . DB::get_table_name() . ' WHERE file = %s', '/prt/long.prt')), 'a synced file keeps no token');
    }

    /** An upload name too long for the row (R2's) is kept as its SHA-1, and the next request still takes it up. */
    public function test_an_upload_with_a_long_name_is_still_taken_up(): void {
        global $wpdb;
        $this->configure(['allowed_file_types' => 'prt']);
        $bytes = random_bytes(6000);
        $this->fixture('prt/r2.prt', $bytes);
        $this->client->upload_name = str_repeat('R2', 150);
        $sm = new SyncManager();
        $this->assertTrue($sm->start_sync()['success']);
        $sm->set_parallel_uploads(3);
        $this->chunkedAt($sm, 1000, 1000);

        $sm->process_batch(0.0);
        $token = (string) $wpdb->get_var($wpdb->prepare('SELECT upload_id FROM ' . DB::get_table_name() . ' WHERE file = %s', '/prt/r2.prt'));
        $this->assertStringEndsWith('|#' . sha1($this->client->upload_name), $token, 'the row keeps the SHA-1, which fits');
        $this->assertSame([sha1($this->client->upload_name) => $this->client->upload_name], get_option(DB::LONG_UPLOAD_NAMES_OPTION), 'and the name itself is kept aside');

        $this->assertSame('completed', $sm->process_batch(30.0)['status']);
        $this->assertSame($this->client->upload_name, $this->client->resume_requests[1], 'the provider is handed the full name');
        $this->assertSame(6, $this->client->part_requests, 'the second request sent only the missing parts');
        $this->assertSame($bytes, $this->client->blobs['uploads/prt/r2.prt']);
        $this->assertSame([], get_option(DB::LONG_UPLOAD_NAMES_OPTION), 'a synced file leaves no name behind');
    }

    /** A file that changed since its upload was left half sent is sent again whole, as a new upload. */
    public function test_a_file_that_changed_since_is_started_over(): void {
        $this->configure(['allowed_file_types' => 'prt']);
        $path = $this->fixture('prt/edited.prt', random_bytes(6000));
        $sm   = new SyncManager();
        $this->assertTrue($sm->start_sync()['success']);
        $sm->set_parallel_uploads(3);
        $this->chunkedAt($sm, 1000, 1000);
        $sm->process_batch(0.0);

        $edited = random_bytes(6000);
        file_put_contents($path, $edited);
        touch($path, time() + 60);
        clearstatcache();

        $this->assertSame('completed', $sm->process_batch(30.0)['status']);
        $this->assertSame([null, null], $this->client->resume_requests, 'the token no longer describes the file');
        $this->assertSame(['uploads/prt/edited.prt'], $this->client->abandoned, 'the old upload goes before the new one starts');
        $this->assertSame(9, $this->client->part_requests, 'three parts, then all six again');
        $this->assertSame($edited, $this->client->blobs['uploads/prt/edited.prt']);
    }

    /** Discard failed files drops the uploads its rows left half sent, and keeps the synced rows. */
    public function test_discarding_the_failed_files_drops_the_uploads_they_named(): void {
        $this->configure(['allowed_file_types' => 'prt']);
        $this->fixture('prt/given-up.prt', random_bytes(6000));
        $this->fixture('prt/done.prt', 'done');
        $sm = new SyncManager();
        $this->assertTrue($sm->start_sync()['success']);
        $sm->set_parallel_uploads(3);
        $this->chunkedAt($sm, 1000, 1000);
        $sm->process_batch(0.0);
        DB::mark_synced('/prt/done.prt'); // Its slot went to the large file's parts.
        $this->assertSame([], $this->client->abandoned);

        $this->assertSame(1, DB::discard_unsynced_files());
        $this->assertSame(['uploads/prt/given-up.prt'], $this->client->abandoned);
        $this->assertSame(1, DB::get_total_count(), 'the synced file stays');
    }

    /** A table emptied while an upload was half sent drops that upload: nobody could take it up any more. */
    public function test_emptying_the_table_drops_the_uploads_it_named(): void {
        $this->configure(['allowed_file_types' => 'prt']);
        $this->fixture('prt/orphan.prt', random_bytes(6000));
        $sm = new SyncManager();
        $this->assertTrue($sm->start_sync()['success']);
        $sm->set_parallel_uploads(3);
        $this->chunkedAt($sm, 1000, 1000);
        $sm->process_batch(0.0);
        $this->assertSame([], $this->client->abandoned);

        DB::clear_table();
        $this->assertSame(['uploads/prt/orphan.prt'], $this->client->abandoned);
        $this->assertFalse(get_option(DB::LONG_UPLOAD_NAMES_OPTION), 'and the names kept aside go with the rows');
    }

    /**
     * The rounds of one request share its connections: against a server that
     * keeps them open, three rounds of two files open no more connections
     * than one round, where a multi handle per round opened one per file.
     */
    public function test_the_rounds_of_a_request_reuse_its_connections(): void {
        $server = new \Tests\Integration\KeepAliveServer(8779);
        try {
            $this->client = new \Tests\Integration\FakeCloudClient($server->base_url);
            $this->configure(['allowed_file_types' => 'ka']);
            for ($i = 0; $i < 6; $i++) {
                $this->fixture("ka/f{$i}.ka", str_repeat('k', 100 + $i));
            }
            $sm = new SyncManager();
            $this->assertTrue($sm->start_sync()['success']);
            $sm->set_parallel_uploads(3);
            $cap = new \ReflectionProperty($sm, 'batch_size');
            if ( PHP_VERSION_ID < 80100 ) { // Required before 8.1, deprecated from 8.5.
            	$cap->setAccessible( true );
            }
            $cap->setValue($sm, 2); // Three rounds of two.

            $this->assertSame('completed', $sm->process_batch(30.0)['status']);
            $this->assertCount(6, $this->client->blobs);
            $this->assertLessThanOrEqual(2, $server->connections(), 'the second and third rounds ran on the first round\'s connections');
        } finally {
            $server->stop();
        }
    }

    public function test_a_small_upload_that_fails_needs_nothing_handed_back(): void {
        $this->configure(['allowed_file_types' => 'sml']);
        $this->fixture('sml/refused.sml', 'small');
        $this->client->upload_status = 500;
        $sm = new SyncManager();
        $this->assertTrue($sm->start_sync()['success']);
        $sm->process_batch(30.0);
        $this->assertSame([], $this->client->abandoned);
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
