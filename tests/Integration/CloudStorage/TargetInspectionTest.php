<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use Tests\Integration\FakeCloudClient;
use DiluxOneOffload\Plugin;
use DiluxOneOffload\SyncManager;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\DiluxOneOffloadDB as DB;
use DiluxOneOffload\Enums\PluginState;
use WPAjaxDieContinueException;

/**
 * Before the first sync: what already sits under this site's prefix in the
 * container or bucket, and emptying it. Only the prefix is ever counted or
 * deleted, only while nothing was synced yet, and only when the owner typed
 * the container's name.
 */
class TargetInspectionTest extends IntegrationTestCase {

    private FakeCloudClient $client;
    private int $admin_id = 0;

    protected function setUp(): void {
        parent::setUp();
        $this->client = new FakeCloudClient('http://127.0.0.1:1');
        add_filter('diluxone_offload_pre_cloud_client', [$this, 'injectClient']);
        $this->setSyncManager(new SyncManager());
        $this->admin_id = (int) wp_insert_user(['user_login' => 'tgt_' . wp_generate_password(8, false), 'user_pass' => wp_generate_password(12), 'role' => 'administrator']);
        wp_set_current_user($this->admin_id);
        ConfigManager::save_config([
            'cloud_provider'  => 'azure',
            'provider_config' => ['storage_account' => 'tgtacct', 'container_name' => 'media', 'access_key' => base64_encode(random_bytes(32))],
        ]);
        ConfigManager::set_state(PluginState::CONFIGURED);
        $this->client->blobs = [
            'uploads/2025/01/old.jpg'      => str_repeat('a', 300),
            'uploads/2025/01/old-150.jpg'  => str_repeat('b', 100),
            'backups/site.zip'             => str_repeat('c', 5000),
            'uploadsX/not-ours.txt'        => 'd',
        ];
        $_POST = [];
        $_REQUEST = [];
    }

    protected function tearDown(): void {
        remove_filter('diluxone_offload_pre_cloud_client', [$this, 'injectClient']);
        $this->setSyncManager(new SyncManager());
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

    private function call(string $action, array $post = []): array {
        $_POST = $post;
        $_POST['nonce'] = wp_create_nonce('diluxone_offload_admin');
        $_REQUEST = $_POST;
        ob_start();
        try {
            do_action('wp_ajax_' . $action);
        } catch (WPAjaxDieContinueException $e) {
        } catch (\WPDieException $e) {
            // wp_die() with a message (a refused capability) stops here.
        }
        $raw = (string) ob_get_clean();
        $j = json_decode($raw, true);
        return ['json' => is_array($j) ? $j : null, 'raw' => $raw];
    }

    public function test_counts_only_this_sites_prefix(): void {
        $r = $this->call('diluxone_offload_inspect_target');
        $this->assertTrue($r['json']['success'], $r['raw']);
        $this->assertSame(2, $r['json']['data']['files'], 'backups/ and uploadsX/ are not under uploads/');
        $this->assertSame(400, $r['json']['data']['bytes']);
        $this->assertSame('media', $r['json']['data']['target']);
        $this->assertSame('uploads/', $r['json']['data']['prefix']);
    }

    public function test_an_empty_prefix_reports_nothing(): void {
        $this->client->blobs = ['backups/site.zip' => 'x'];
        $r = $this->call('diluxone_offload_inspect_target');
        $this->assertTrue($r['json']['success'], $r['raw']);
        $this->assertSame(0, $r['json']['data']['files']);
    }

    public function test_every_visit_lists_again(): void {
        // A cancelled and reset sync leaves objects a minute after the prefix
        // was empty; an answer kept from before would say it still is.
        $this->client->blobs = ['backups/site.zip' => 'x'];
        $this->assertSame(0, $this->call('diluxone_offload_inspect_target')['json']['data']['files']);
        $this->client->blobs['uploads/left-by-a-reset.jpg'] = 'r';
        $this->assertSame(1, $this->call('diluxone_offload_inspect_target')['json']['data']['files']);
    }

    public function test_a_listing_failure_is_reported_not_guessed(): void {
        $this->client->list_error = 'HTTP 403 fake refusal';
        $r = $this->call('diluxone_offload_inspect_target');
        $this->assertFalse($r['json']['success']);
        $this->assertStringContainsString('could not be listed', $r['json']['data']);
        $this->assertStringContainsString('403', $r['json']['data']);
    }

    public function test_nothing_is_inspected_once_a_sync_ran(): void {
        DB::add_file('/2025/01/mine.jpg', 10);
        $r = $this->call('diluxone_offload_inspect_target');
        $this->assertFalse($r['json']['success']);
        $this->assertStringContainsString('only before the first sync', $r['json']['data']);
    }

    public function test_empty_deletes_only_the_prefix_with_the_right_name(): void {
        $r = $this->call('diluxone_offload_empty_target', ['confirm' => 'media']);
        $this->assertTrue($r['json']['success'], $r['raw']);
        $this->assertSame(2, $r['json']['data']['deleted']);
        $this->assertSame(0, $r['json']['data']['remaining']);
        $this->assertSame(['backups/site.zip', 'uploadsX/not-ours.txt'], array_keys($this->client->blobs), 'nothing outside uploads/ was touched');
    }

    /** Case and every character count; surrounding spaces are trimmed, as in any text field. */
    public function test_a_wrong_name_deletes_nothing(): void {
        foreach (['', 'Media', 'medi', 'other'] as $typed) {
            $r = $this->call('diluxone_offload_empty_target', ['confirm' => $typed]);
            $this->assertFalse($r['json']['success'], "'$typed' was accepted");
        }
        $this->assertCount(4, $this->client->blobs);
        $this->assertSame([], $this->client->deleted);
    }

    public function test_empty_is_refused_once_a_sync_ran(): void {
        DB::add_file('/2025/01/mine.jpg', 10);
        $r = $this->call('diluxone_offload_empty_target', ['confirm' => 'media']);
        $this->assertFalse($r['json']['success']);
        $this->assertSame([], $this->client->deleted);
        ConfigManager::set_state(PluginState::SYNCED);
        DB::clear_table();
        $r = $this->call('diluxone_offload_empty_target', ['confirm' => 'media']);
        $this->assertFalse($r['json']['success'], 'not in the configured state either');
        $this->assertSame([], $this->client->deleted);
    }

    public function test_a_file_that_cannot_be_deleted_is_counted_and_reported(): void {
        $this->client->undeletable = ['uploads/2025/01/old.jpg'];
        $r = $this->call('diluxone_offload_empty_target', ['confirm' => 'media']);
        $this->assertTrue($r['json']['success'], $r['raw']);
        $d = $r['json']['data'];
        $this->assertSame(1, $d['deleted']);
        $this->assertSame(1, $d['failed']);
        $this->assertSame(1, $d['remaining']);
        $this->assertStringContainsString('403', $d['errors'][0]);
    }

    public function test_an_expired_time_budget_leaves_the_rest_for_the_next_round(): void {
        $sm = new SyncManager();
        $first = $sm->empty_target(0.0);
        $this->assertSame(1, $first['deleted'], 'one delete, then the budget is spent');
        $this->assertSame(1, $first['remaining']);
        $second = $sm->empty_target(8.0);
        $this->assertSame(1, $second['deleted']);
        $this->assertSame(0, $second['remaining']);
    }

    public function test_unauthorised_users_are_turned_away(): void {
        $editor = (int) wp_insert_user(['user_login' => 'ed_' . wp_generate_password(8, false), 'user_pass' => wp_generate_password(12), 'role' => 'editor']);
        wp_set_current_user($editor);
        foreach (['diluxone_offload_inspect_target', 'diluxone_offload_empty_target'] as $action) {
            $this->call($action, ['confirm' => 'media']);
        }
        $this->assertSame([], $this->client->deleted);
        wp_delete_user($editor);
    }
}
