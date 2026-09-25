<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use DiluxOneOffload\DiluxOneOffloadDB as DB;

/**
 * Integration tests for the file-tracking table across a file's whole life.
 *
 * A row goes pending → (progress, errors) → synced → maybe cloud-only
 * (local copy deleted) → maybe downloaded back. Every transition here is one
 * the sync engine, the offloading toggle or the reverse sync performs, and
 * each is asserted against the real table, because the flags on these rows
 * are what decide whether a file is served from disk or from the cloud.
 */
class DbLifecycleTest extends IntegrationTestCase {

    private function row(string $file): array {
        global $wpdb;
        return (array) $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM `' . self::$table_name . '` WHERE file = %s', $file),
            ARRAY_A
        );
    }

    // ── Pending → synced ────────────────────────────────────

    public function test_a_new_file_is_pending_and_counted(): void {
        DB::add_file('/2026/01/a.jpg', 100);

        $this->assertTrue(DB::has_pending_files());
        $this->assertSame(1, DB::get_total_count());
        $this->assertSame(0, (int) $this->row('/2026/01/a.jpg')['synced']);
    }

    public function test_marking_synced_moves_it_out_of_pending(): void {
        DB::add_file('/2026/01/a.jpg', 100);
        DB::mark_synced('/2026/01/a.jpg');

        $this->assertFalse(DB::has_pending_files());
        $synced = array_column(DB::get_synced_files(), 'file');
        $this->assertContains('/2026/01/a.jpg', $synced);
    }

    public function test_progress_accumulates_transferred_bytes_and_clears_errors(): void {
        DB::add_file('/2026/01/big.bin', 1000);
        DB::increment_error('/2026/01/big.bin', 'first try');
        DB::update_progress('/2026/01/big.bin', 400);
        DB::update_progress('/2026/01/big.bin', 250);

        $row = $this->row('/2026/01/big.bin');
        $this->assertSame(650, (int) $row['transferred']);
        $this->assertSame(0, (int) $row['errors'], 'progress means the transfer is alive again');
    }

    public function test_upload_id_is_stored_for_resumable_uploads(): void {
        DB::add_file('/2026/01/chunked.bin', 50000000);
        DB::set_upload_id('/2026/01/chunked.bin', 'block-list-abc');
        $this->assertSame('block-list-abc', $this->row('/2026/01/chunked.bin')['upload_id']);
    }

    // ── Errors and retry ────────────────────────────────────

    public function test_three_errors_take_a_file_out_of_the_pending_set(): void {
        DB::add_file('/2026/01/flaky.jpg', 10);
        DB::increment_error('/2026/01/flaky.jpg', 'e1');
        DB::increment_error('/2026/01/flaky.jpg', 'e2');
        $this->assertTrue(DB::has_pending_files(), 'two errors: still retried');

        DB::increment_error('/2026/01/flaky.jpg', 'e3');
        $this->assertFalse(DB::has_pending_files(), 'three errors: parked as failed');
        $this->assertSame('e3', $this->row('/2026/01/flaky.jpg')['error_message']);
    }

    public function test_reset_errors_puts_failed_files_back_in_play(): void {
        DB::add_file('/2026/01/flaky.jpg', 10);
        foreach (['e1', 'e2', 'e3'] as $e) {
            DB::increment_error('/2026/01/flaky.jpg', $e);
        }
        DB::add_file('/2026/01/fine.jpg', 10);

        $count = DB::reset_errors();

        $this->assertSame(1, (int) $count, 'only the failed one is touched');
        $row = $this->row('/2026/01/flaky.jpg');
        $this->assertSame(0, (int) $row['errors']);
        $this->assertNull($row['error_message']);
        $this->assertTrue(DB::has_pending_files());
    }

    public function test_reset_all_files_to_pending_forgets_sync_state(): void {
        DB::add_file('/2026/01/a.jpg', 10);
        DB::add_file('/2026/01/b.jpg', 10);
        DB::mark_synced('/2026/01/a.jpg');
        DB::mark_synced('/2026/01/b.jpg');
        $this->assertFalse(DB::has_pending_files());

        $this->assertTrue(DB::reset_all_files_to_pending());

        $this->assertTrue(DB::has_pending_files());
        $this->assertSame([], DB::get_synced_files());
        $this->assertSame(0, (int) $this->row('/2026/01/a.jpg')['transferred']);
    }

    // ── Cloud-only (offloaded) and back ─────────────────────

    public function test_a_cloud_only_file_is_synced_and_deleted_locally(): void {
        DB::add_cloud_only_file('/2026/01/cloud.jpg', 77);

        $row = $this->row('/2026/01/cloud.jpg');
        $this->assertSame(1, (int) $row['synced']);
        $this->assertSame(1, (int) $row['deleted']);
        $this->assertSame(77, (int) $row['transferred']);
        $this->assertTrue(DB::has_deleted_files());
        $this->assertSame(1, DB::count_deleted_files());
    }

    public function test_cloud_only_batch_insert(): void {
        $this->assertTrue(DB::add_cloud_only_files_batch([
            ['path' => '/2026/01/c1.jpg', 'size' => 1],
            ['path' => '/2026/01/c2.jpg', 'size' => 2],
            ['path' => '/2026/01/c3.jpg', 'size' => 3],
        ]));
        $this->assertSame(3, DB::count_deleted_files());
        $this->assertSame(3, DB::get_total_count());
    }

    public function test_deleted_files_listing_and_stats(): void {
        DB::add_cloud_only_file('/2026/01/x.jpg', 10);
        DB::add_cloud_only_file('/2026/01/y.jpg', 20);
        DB::add_file('/2026/01/local.jpg', 5); // pending, not deleted

        $deleted = array_column(DB::get_deleted_files(), 'file');
        sort($deleted);
        $this->assertSame(['/2026/01/x.jpg', '/2026/01/y.jpg'], $deleted);

        $stats = (array) DB::get_deleted_stats();
        $this->assertNotEmpty($stats);
    }

    public function test_get_deleted_files_honours_the_limit(): void {
        for ($i = 1; $i <= 5; $i++) {
            DB::add_cloud_only_file("/2026/01/d{$i}.jpg", $i);
        }
        $this->assertCount(2, DB::get_deleted_files(2));
    }

    public function test_marking_downloaded_brings_a_cloud_only_file_back_local(): void {
        DB::add_cloud_only_file('/2026/01/back.jpg', 99);
        DB::set_upload_id('/2026/01/back.jpg', 'leftover');

        DB::mark_downloaded('/2026/01/back.jpg');

        $row = $this->row('/2026/01/back.jpg');
        $this->assertSame(1, (int) $row['synced'], 'still in the cloud');
        $this->assertSame(0, (int) $row['deleted'], 'and now on disk again');
        $this->assertSame(99, (int) $row['transferred']);
        $this->assertSame(0, (int) $row['errors']);
        $this->assertNull($row['upload_id']);
        $this->assertFalse(DB::has_deleted_files());
    }

    // ── Forgetting ──────────────────────────────────────────

    public function test_delete_synced_files_removes_only_synced_rows(): void {
        DB::add_file('/2026/01/s1.jpg', 1);
        DB::add_file('/2026/01/s2.jpg', 1);
        DB::add_file('/2026/01/pending.jpg', 1);
        DB::mark_synced('/2026/01/s1.jpg');
        DB::mark_synced('/2026/01/s2.jpg');

        $removed = DB::delete_synced_files();

        $this->assertSame(2, (int) $removed);
        $this->assertSame(1, DB::get_total_count());
        $this->assertNotEmpty($this->row('/2026/01/pending.jpg'));
    }
    // ── The table is missing ────────────────────────────────

    public function test_every_writer_fails_cleanly_and_every_reader_is_empty_when_the_table_is_gone(): void {
        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS `' . self::$table_name . '`');
        $suppressed = $wpdb->suppress_errors(true);
        try {
            $this->assertFalse(DB::table_exists());
            $this->assertFalse(DB::add_file('/x.jpg', 1));
            $this->assertFalse(DB::add_files_batch([['path' => '/x.jpg', 'size' => 1]]));
            $this->assertFalse(DB::add_cloud_only_file('/x.jpg', 1));
            $this->assertFalse(DB::add_cloud_only_files_batch([['path' => '/x.jpg', 'size' => 1]]));
            $this->assertFalse(DB::mark_synced('/x.jpg'));
            $this->assertFalse(DB::mark_downloaded('/x.jpg'));
            $this->assertFalse(DB::update_progress('/x.jpg', 10));
            $this->assertFalse(DB::increment_error('/x.jpg', 'boom'));
            $this->assertFalse(DB::set_upload_id('/x.jpg', 'u'));
            $this->assertSame([], DB::get_pending_files());
            $this->assertSame([], DB::get_failed_files());
            $this->assertSame([], DB::get_synced_files());
            $this->assertSame([], DB::get_deleted_files());
            $this->assertSame(0, DB::get_total_count());
            $this->assertSame(0, DB::count_deleted_files());
            $this->assertFalse(DB::has_pending_files());
            $this->assertFalse(DB::has_deleted_files());
            $this->assertTrue(DB::add_files_batch([]), 'an empty batch is a no-op');
            $this->assertTrue(DB::add_cloud_only_files_batch([]), 'an empty batch is a no-op');
        } finally {
            $wpdb->suppress_errors($suppressed);
            DB::create_files_table();
        }
        $this->assertTrue(DB::table_exists(), 'recreated for the tests that follow');
    }
}
