<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use DiluxOneOffload\DiluxOneOffloadDB;

/**
 * Integration tests for DiluxOneOffloadDB.
 *
 * Exercises CRUD against the wp_diluxone_offload_files custom table on a real
 * MySQL database (provided by wp-env's tests environment).
 */
class DiluxOneOffloadDBTest extends IntegrationTestCase {

    public function test_create_files_table_creates_table_with_expected_structure(): void {
        $this->assertTrue(DiluxOneOffloadDB::table_exists());

        global $wpdb;
        $table = DiluxOneOffloadDB::get_table_name();
        $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`");

        $expected_columns = [
            'file', 'size', 'transferred', 'synced', 'deleted',
            'errors', 'error_message', 'upload_id', 'created_at', 'updated_at',
        ];
        foreach ($expected_columns as $col) {
            $this->assertContains($col, $columns, "Column '{$col}' missing from table");
        }
    }

    public function test_the_primary_key_covers_the_whole_file_column(): void {
        global $wpdb;
        $table = DiluxOneOffloadDB::get_table_name();

        $key = $wpdb->get_row("SHOW INDEX FROM `{$table}` WHERE Key_name = 'PRIMARY'");
        $this->assertNotNull($key, 'the table has a primary key');
        $this->assertSame('file', $key->Column_name);
        $this->assertNull(
            $key->Sub_part,
            'the primary key indexes the whole column, not a prefix of it: a prefix key '
            . 'lets two paths sharing their first N characters collide, and reviewers read '
            . 'PRIMARY KEY (`file`(191)) as invalid on sight'
        );

        $column = $wpdb->get_row("SHOW COLUMNS FROM `{$table}` LIKE 'file'");
        $this->assertSame('varbinary(767)', strtolower((string) $column->Type), 'bytes, the way the filesystem sees a path; 767 is the InnoDB index limit everywhere');
    }

    public function test_a_path_longer_than_191_characters_is_tracked_whole(): void {
        // A long title becomes a long file name, and WordPress adds -150x150
        // and -scaled on top. The previous column truncated (MariaDB) or
        // refused (MySQL strict) anything past 191 characters.
        $path = '/2026/09/' . str_repeat('x', 230) . '-150x150.png';
        $this->assertTrue(DiluxOneOffloadDB::add_file($path, 10));
        $files = array_column(DiluxOneOffloadDB::get_pending_files(10, PHP_INT_MAX), 'file');
        $this->assertContains($path, $files);
        $this->assertSame(1, DiluxOneOffloadDB::mark_synced($path));
    }

    public function test_paths_that_differ_only_in_case_are_two_files(): void {
        // On Linux they are two files; a collated key made them one row.
        $this->assertTrue(DiluxOneOffloadDB::add_file('/2026/09/PHOTO.PNG', 1));
        $this->assertTrue(DiluxOneOffloadDB::add_file('/2026/09/photo.png', 2));
        $this->assertSame(2, $this->getTableRowCount());
    }

    public function test_a_path_that_does_not_fit_the_key_is_refused_not_truncated(): void {
        $path = '/2026/09/' . str_repeat('y', 800) . '.png';
        $this->assertFalse(DiluxOneOffloadDB::add_file($path, 1));
        $this->assertTrue(DiluxOneOffloadDB::add_files_batch([['path' => $path, 'size' => 1], ['path' => '/2026/09/ok.png', 'size' => 1]]));
        $this->assertSame(1, $this->getTableRowCount(), 'the batch went on without the path that does not fit');
    }

    public function test_a_cloud_key_that_does_not_fit_the_key_is_left_out_of_the_cloud_only_batch(): void {
        $long = '/2026/09/' . str_repeat('z', 800) . '.png';
        $this->assertFalse(DiluxOneOffloadDB::add_cloud_only_file($long, 1));
        $this->assertTrue(DiluxOneOffloadDB::add_cloud_only_files_batch([['path' => $long, 'size' => 1], ['path' => '/2026/09/from-cloud.png', 'size' => 1]]));
        $this->assertSame(1, $this->getTableRowCount(), 'the batch went on without the key that does not fit');
    }

    public function test_a_table_from_1_3_moves_onto_the_binary_key_and_keeps_its_rows(): void {
        global $wpdb;
        $table = DiluxOneOffloadDB::get_table_name();
        $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        $wpdb->query("CREATE TABLE `{$table}` (
            `file` VARCHAR(191) NOT NULL, `size` BIGINT UNSIGNED NOT NULL DEFAULT 0, `transferred` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `synced` TINYINT(1) NOT NULL DEFAULT 0, `deleted` TINYINT(1) NOT NULL DEFAULT 0, `errors` INT UNSIGNED NOT NULL DEFAULT 0,
            `error_message` TEXT NULL, `upload_id` VARCHAR(255) NULL, `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (`file`)
        ) " . $wpdb->get_charset_collate());
        $wpdb->query("INSERT INTO `{$table}` (file, size, synced) VALUES ('/2026/09/a.png', 5, 1), ('/2026/09/b.png', 6, 0)");
        delete_option(DiluxOneOffloadDB::TABLE_VERSION_OPTION);

        $this->assertTrue(DiluxOneOffloadDB::create_files_table());

        $column = $wpdb->get_row("SHOW COLUMNS FROM `{$table}` LIKE 'file'");
        $this->assertSame('varbinary(767)', strtolower((string) $column->Type));
        $key = $wpdb->get_row("SHOW INDEX FROM `{$table}` WHERE Key_name = 'PRIMARY'");
        $this->assertSame('file', $key->Column_name);
        $this->assertNull($key->Sub_part);
        $this->assertSame(2, $this->getTableRowCount(), 'the rows survive the migration');
        $this->assertSame(DiluxOneOffloadDB::TABLE_VERSION, get_option(DiluxOneOffloadDB::TABLE_VERSION_OPTION));
    }

    public function test_a_failed_create_does_not_record_the_schema_version(): void {
        global $wpdb;
        $table = DiluxOneOffloadDB::get_table_name();

        $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        delete_option(DiluxOneOffloadDB::TABLE_VERSION_OPTION);

        // Make the CREATE impossible, the way a missing grant or a full disk
        // would. dbDelta reports nothing either way, which is the whole point.
        $suppress = $wpdb->suppress_errors(true);
        $filter   = static function ($query) use ($table) {
            return str_replace("CREATE TABLE {$table} ", "CREATE TABLE `no such db`.`{$table}` ", (string) $query);
        };
        add_filter('query', $filter);

        $created = DiluxOneOffloadDB::create_files_table();

        remove_filter('query', $filter);
        $wpdb->suppress_errors($suppress);

        $this->assertFalse($created, 'the failure is reported to the caller');
        $this->assertFalse(DiluxOneOffloadDB::table_exists(), 'nothing was created');
        $this->assertFalse(
            get_option(DiluxOneOffloadDB::TABLE_VERSION_OPTION),
            'the version option stays unwritten, so the next page load tries again '
            . 'instead of believing the schema is current'
        );

        $this->assertTrue(DiluxOneOffloadDB::create_files_table(), 'and the retry works');
        $this->assertSame(DiluxOneOffloadDB::TABLE_VERSION, get_option(DiluxOneOffloadDB::TABLE_VERSION_OPTION));
    }

    public function test_add_file_inserts_with_pending_status(): void {
        $result = DiluxOneOffloadDB::add_file('/2024/01/photo.jpg', 2048576);
        $this->assertTrue($result);

        $stats = DiluxOneOffloadDB::get_stats();
        $this->assertSame(1, (int) $stats['total_files']);
        $this->assertSame(0, (int) $stats['synced_files']);

        $pending = DiluxOneOffloadDB::get_pending_files();
        $this->assertCount(1, $pending);
        $this->assertSame('/2024/01/photo.jpg', $pending[0]['file']);
    }

    public function test_add_files_batch_inserts_multiple_files(): void {
        $files = [
            ['path' => '/2024/01/img1.jpg', 'size' => 1000],
            ['path' => '/2024/01/img2.jpg', 'size' => 2000],
            ['path' => '/2024/01/img3.jpg', 'size' => 3000],
        ];

        $result = DiluxOneOffloadDB::add_files_batch($files);
        $this->assertTrue($result);

        $stats = DiluxOneOffloadDB::get_stats();
        $this->assertSame(3, (int) $stats['total_files']);
        $this->assertSame(6000, (int) $stats['total_size']);
    }

    public function test_mark_synced_updates_status(): void {
        DiluxOneOffloadDB::add_file('/2024/01/photo.jpg', 1024);

        $result = DiluxOneOffloadDB::mark_synced('/2024/01/photo.jpg');
        $this->assertNotFalse($result);

        $stats = DiluxOneOffloadDB::get_stats();
        $this->assertSame(1, (int) $stats['synced_files']);
        $this->assertSame(0, (int) $stats['pending_files']);
    }

    public function test_get_stats_counts_by_status(): void {
        $this->addTestFiles(5, 1000);

        DiluxOneOffloadDB::mark_synced('/2024/01/test-file-1.jpg');
        DiluxOneOffloadDB::mark_synced('/2024/01/test-file-2.jpg');

        DiluxOneOffloadDB::increment_error('/2024/01/test-file-3.jpg', 'Timeout');
        DiluxOneOffloadDB::increment_error('/2024/01/test-file-3.jpg', 'Timeout');
        DiluxOneOffloadDB::increment_error('/2024/01/test-file-3.jpg', 'Timeout');

        $stats = DiluxOneOffloadDB::get_stats();
        $this->assertSame(5, (int) $stats['total_files']);
        $this->assertSame(2, (int) $stats['synced_files']);
        $this->assertSame(1, (int) $stats['failed_files']);
        $this->assertSame(3, (int) $stats['pending_files']);
    }

    public function test_get_pending_files_returns_only_pending(): void {
        $this->addTestFiles(3, 1024);
        DiluxOneOffloadDB::mark_synced('/2024/01/test-file-1.jpg');

        $pending = DiluxOneOffloadDB::get_pending_files();
        $this->assertCount(2, $pending);

        $paths = array_map(fn($f) => $f['file'], $pending);
        $this->assertNotContains('/2024/01/test-file-1.jpg', $paths);
    }

    public function test_get_pending_files_respects_limit(): void {
        $this->addTestFiles(10, 1024);

        $pending = DiluxOneOffloadDB::get_pending_files(3);
        $this->assertCount(3, $pending);
    }

    public function test_get_failed_files_returns_only_failed(): void {
        $this->addTestFiles(3, 1024);

        DiluxOneOffloadDB::mark_synced('/2024/01/test-file-1.jpg');
        DiluxOneOffloadDB::increment_error('/2024/01/test-file-2.jpg', 'Error 1');

        $failed = DiluxOneOffloadDB::get_failed_files();
        $this->assertCount(2, $failed);
    }

    public function test_reset_failed_files_to_pending(): void {
        $this->addTestFiles(2, 1024);

        DiluxOneOffloadDB::increment_error('/2024/01/test-file-1.jpg', 'Timeout');
        DiluxOneOffloadDB::increment_error('/2024/01/test-file-1.jpg', 'Timeout');
        DiluxOneOffloadDB::increment_error('/2024/01/test-file-1.jpg', 'Timeout');

        $result = DiluxOneOffloadDB::reset_failed_files_to_pending();
        $this->assertTrue($result);

        $pending = DiluxOneOffloadDB::get_pending_files();
        $this->assertCount(2, $pending);

        global $wpdb;
        $table = DiluxOneOffloadDB::get_table_name();
        $max_errors = (int) $wpdb->get_var("SELECT MAX(errors) FROM `{$table}`");
        $this->assertSame(0, $max_errors);
    }

    public function test_clear_table_removes_all_records(): void {
        $this->addTestFiles(5, 1024);
        $this->assertSame(5, $this->getTableRowCount());

        $result = DiluxOneOffloadDB::clear_table();
        $this->assertTrue($result);

        $this->assertSame(0, $this->getTableRowCount());
    }

    public function test_increment_error_tracks_error_count_and_message(): void {
        DiluxOneOffloadDB::add_file('/2024/01/photo.jpg', 1024);

        DiluxOneOffloadDB::increment_error('/2024/01/photo.jpg', 'Connection timeout');
        DiluxOneOffloadDB::increment_error('/2024/01/photo.jpg', 'Server error 500');

        global $wpdb;
        $table = DiluxOneOffloadDB::get_table_name();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT errors, error_message FROM `{$table}` WHERE file = %s", '/2024/01/photo.jpg')
        );

        $this->assertSame(2, (int) $row->errors);
        $this->assertSame('Server error 500', $row->error_message);
    }

    public function test_add_cloud_only_file_inserts_with_synced_and_deleted(): void {
        $result = DiluxOneOffloadDB::add_cloud_only_file('/2024/01/cloud-only.jpg', 5000);
        $this->assertTrue($result);

        $deleted_stats = DiluxOneOffloadDB::get_deleted_stats();
        $this->assertSame(1, (int) $deleted_stats['files']);
        $this->assertSame(5000, (int) $deleted_stats['size']);

        $pending = DiluxOneOffloadDB::get_pending_files();
        $this->assertCount(0, $pending);
    }

    /** Only the leading uploads/ is the object prefix; a deeper folder by that name is part of the path. */
    public function test_path_from_key_strips_only_the_leading_prefix(): void {
        $this->assertSame('/2026/09/a.jpg', DiluxOneOffloadDB::path_from_key('uploads/2026/09/a.jpg'));
        $this->assertSame('/2026/uploads/b.jpg', DiluxOneOffloadDB::path_from_key('uploads/2026/uploads/b.jpg'));
        $this->assertSame('/c.jpg', DiluxOneOffloadDB::path_from_key('c.jpg'));
    }
}
