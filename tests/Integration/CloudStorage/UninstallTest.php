<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use DiluxOneOffload\DiluxOneOffloadDB as DB;

/**
 * uninstall.php removes everything the plugin wrote to the database and
 * nothing else. It runs in-process here, so the table is recreated
 * afterwards for the tests that follow.
 */
class UninstallTest extends IntegrationTestCase {

    protected function tearDown(): void {
        DB::create_files_table();
        parent::tearDown();
    }

    public function test_uninstall_drops_the_table_and_every_option_and_transient(): void {
        global $wpdb;

        update_option('diluxone_offload_config', ['cloud_provider' => 'azure']);
        update_option('diluxone_offload_plugin_state', 'configured');
        update_option('diluxone_offload_sync_meta', ['status' => 'completed']);
        update_option('diluxone_offload_db_version', '1.2');
        set_transient('diluxone_offload_azure_stats', ['blobs' => 3], 300);
        set_transient('diluxone_offload_connection_test_passed_42', ['provider' => 'azure'], 300);
        set_transient('diluxone_offload_notice_42', ['type' => 'success', 'message' => 'hi'], 60);
        update_option('unrelated_option_stays', 'yes');
        $this->addTestFile('/2026/09/a.jpg');
        $this->assertTrue(DB::table_exists());

        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', 'diluxone-offload-wordpress/diluxone-offload.php');
        }
        require DILUXONE_OFFLOAD_DIR . 'uninstall.php';

        $this->assertFalse(get_option('diluxone_offload_config'));
        $this->assertFalse(get_option('diluxone_offload_plugin_state'));
        $this->assertFalse(get_option('diluxone_offload_sync_meta'));
        $this->assertFalse(get_option('diluxone_offload_db_version'));
        $this->assertFalse(get_transient('diluxone_offload_azure_stats'));
        $this->assertFalse(get_transient('diluxone_offload_connection_test_passed_42'));
        $this->assertFalse(get_transient('diluxone_offload_notice_42'));
        $this->assertSame('yes', get_option('unrelated_option_stays'), 'only the plugin\'s own rows go');
        delete_option('unrelated_option_stays');

        $leftovers = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", '%' . $wpdb->esc_like('diluxone_offload_') . '%')
        );
        $this->assertSame(0, $leftovers, 'no diluxone_offload_* option or transient survives');
        $this->assertFalse(DB::table_exists(), 'the tracking table is dropped');
    }
}
