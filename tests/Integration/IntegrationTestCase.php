<?php
namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Base class for integration tests that require a loaded WordPress.
 *
 * Resets the plugin's custom DB table and known options between tests
 * so each test runs in isolation. Provides small helpers for inserting
 * test rows quickly.
 */
class IntegrationTestCase extends TestCase {

    /** @var string Custom files table name (resolved once per class). */
    protected static string $table_name = '';

    /**
     * Plugin options that need to be cleaned between tests. If a new
     * top-level option is introduced, add it here so tests don't see
     * leftover state from previous tests.
     *
     * @var array<int, string>
     */
    protected static array $plugin_options = [
        'diluxone_offload_config',
        'diluxone_offload_plugin_state',
        'diluxone_offload_sync_meta',
        'diluxone_offload_sync_progress',
        'diluxone_offload_failed_files',
        'diluxone_offload_connection_health',
    ];

    /**
     * Transients the plugin caches across requests. A test run is one long
     * request, so a scan cached by one test would otherwise be the file list
     * the next test syncs.
     *
     * @var array<int, string>
     */
    protected static array $plugin_transients = [
        'diluxone_offload_full_file_list',
        'diluxone_offload_azure_stats',
        'diluxone_offload_stats',
    ];

    /**
     * @beforeClass
     */
    public static function setUpTableName(): void {
        self::$table_name = \DiluxOneOffload\DiluxOneOffloadDB::get_table_name();
    }

    protected function setUp(): void {
        parent::setUp();
        $this->cleanDatabase();
        $this->cleanOptions();
        self::resetWrapperClient();
        // A test that enabled offloading leaves the wrapper's upload_dir filter
        // behind; the next SyncManager would then scan diluxoneoffload://
        // instead of the fixtures on disk.
        \DiluxOneOffload\CloudStreamWrapper::tear_down();
    }

    /**
     * CloudStreamWrapper memoises its cloud client for the request. A test
     * run is one long request, so without this every test after the first
     * would keep talking to the first test's (fake) client.
     */
    public static function resetWrapperClient(): void {
        $prop = new \ReflectionProperty(\DiluxOneOffload\CloudStreamWrapper::class, 'cloud_client');
        if ( PHP_VERSION_ID < 80100 ) { // Required before 8.1, deprecated from 8.5.
        	$prop->setAccessible( true );
        }
        $prop->setValue(null, null);
    }

    protected function tearDown(): void {
        \DiluxOneOffload\CloudStreamWrapper::tear_down();
        $this->cleanDatabase();
        $this->cleanOptions();
        parent::tearDown();
    }

    /**
     * Truncate the custom files table.
     */
    protected function cleanDatabase(): void {
        global $wpdb;
        $table = self::$table_name;
        if (!empty($table)) {
            $wpdb->query("TRUNCATE TABLE `{$table}`");
        }
    }

    /**
     * Delete every plugin option from wp_options.
     */
    protected function cleanOptions(): void {
        foreach (self::$plugin_options as $option) {
            delete_option($option);
        }
        foreach (self::$plugin_transients as $transient) {
            delete_transient($transient);
        }
        delete_transient('diluxone_offload_notice_' . get_current_user_id());
    }

    /**
     * Helper: insert a test file into the custom table.
     *
     * @param string $path File path relative to /uploads.
     * @param int    $size File size in bytes.
     */
    protected function addTestFile(string $path, int $size = 1024): bool {
        return \DiluxOneOffload\DiluxOneOffloadDB::add_file($path, $size);
    }

    /**
     * Helper: insert N test files with auto-numbered paths.
     */
    protected function addTestFiles(int $count, int $size = 1024): void {
        for ($i = 1; $i <= $count; $i++) {
            $this->addTestFile("/2024/01/test-file-{$i}.jpg", $size);
        }
    }

    /**
     * Helper: get the raw row count from the custom table.
     */
    protected function getTableRowCount(): int {
        global $wpdb;
        $table = self::$table_name;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
    }
}
