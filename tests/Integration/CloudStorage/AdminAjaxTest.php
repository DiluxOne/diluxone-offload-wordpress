<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use Tests\Integration\ScriptedHttp;
use Tests\Integration\FakeCloudClient;
use Tests\Integration\LocalBlobServer;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\DiluxOneOffloadDB;
use DiluxOneOffload\Enums\PluginState;
use WPAjaxDieContinueException;

/**
 * Integration tests for the Admin class's AJAX endpoints — the ones behind
 * the Cloud Provider, Settings and Tools tabs.
 *
 * Nonce and capability enforcement for every action is covered by the sweep
 * in PluginAjaxTest; these are the positive paths. Where a handler builds a
 * real provider from the form (Test Connection, Refresh stats), Azure is
 * scripted through pre_http_request so the signing and parsing code runs for
 * real; where a handler only needs "a provider", the stand-in is injected.
 */
class AdminAjaxTest extends IntegrationTestCase {

    use ScriptedHttp;

    private static ?LocalBlobServer $server = null;
    private ?FakeCloudClient $fake = null;
    private int $admin_id = 0;
    /** @var string[] */
    private array $fixtures = [];

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        self::$server = new LocalBlobServer(8767);
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
        $id = wp_insert_user([
            'user_login' => 'adm_' . wp_generate_password(8, false),
            'user_pass'  => wp_generate_password(12),
            'role'       => 'administrator',
        ]);
        $this->assertNotInstanceOf(\WP_Error::class, $id);
        $this->admin_id = (int) $id;
        wp_set_current_user($this->admin_id);
        $_POST = [];
        $_REQUEST = [];
    }

    protected function tearDown(): void {
        $this->unhookHttp();
        if ($this->fake) {
            remove_filter('diluxone_offload_pre_cloud_client', [$this, 'injectFake']);
            $this->fake = null;
        }
        foreach ($this->fixtures as $f) {
            @unlink($f);
        }
        $this->fixtures = [];
        delete_transient('diluxone_offload_connection_test_passed_' . $this->admin_id);
        delete_transient('diluxone_offload_azure_stats');
        if ($this->admin_id) {
            wp_delete_user($this->admin_id);
        }
        wp_set_current_user(0);
        $_POST = [];
        $_REQUEST = [];
        parent::tearDown();
    }

    // ── Helpers ─────────────────────────────────────────────

    public function injectFake($pre) {
        return $this->fake;
    }

    private function useFakeClient(): FakeCloudClient {
        $this->fake = new FakeCloudClient(self::$server->base_url);
        add_filter('diluxone_offload_pre_cloud_client', [$this, 'injectFake']);
        return $this->fake;
    }

    private function configureAzure(): void {
        ConfigManager::save_config([
            'cloud_provider'  => 'azure',
            'provider_config' => [
                'storage_account' => 'admacct',
                'container_name'  => 'media',
                'access_key'      => base64_encode(random_bytes(32)),
            ],
        ]);
        ConfigManager::set_state(PluginState::CONFIGURED);
    }

    private function fixture(string $relative, string $content = 'x'): string {
        $path = wp_upload_dir()['basedir'] . '/' . ltrim($relative, '/');
        wp_mkdir_p(dirname($path));
        file_put_contents($path, $content);
        $this->fixtures[] = $path;
        return $path;
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
            // wp_send_json_* ends in wp_die.
        }
        $raw = (string) ob_get_clean();
        $json = json_decode($raw, true);
        return ['json' => is_array($json) ? $json : null, 'raw' => $raw];
    }

    private static function blobsXml(array $blobs): string {
        $items = '';
        foreach ($blobs as $name => $size) {
            $items .= "<Blob><Name>{$name}</Name><Properties><Content-Length>{$size}</Content-Length></Properties></Blob>";
        }
        return '<?xml version="1.0" encoding="utf-8"?><EnumerationResults><Blobs>' . $items . '</Blobs><NextMarker></NextMarker></EnumerationResults>';
    }

    // ── Test Connection (real provider, scripted Azure) ─────

    public function test_test_connection_succeeds_and_remembers_it_for_save(): void {
        $this->scriptHttp(fn() => self::httpReply(200, '', ['x-ms-blob-public-access' => 'blob']));

        $r = $this->call('diluxone_offload_test_connection', [
            'provider'       => 'azure',
            'account_name'   => 'admacct',
            'account_key'    => base64_encode(random_bytes(32)),
            'container_name' => 'media',
        ]);

        $this->assertNotNull($r['json'], $r['raw']);
        $this->assertTrue($r['json']['success'], $r['raw']);
        $this->assertNotFalse(
            get_transient('diluxone_offload_connection_test_passed_' . $this->admin_id),
            'a passed test is what later authorises saving these credentials'
        );
        $req = $this->httpRequests('GET')[0];
        $this->assertStringContainsString('admacct.blob.core.windows.net/media?restype=container', $req['url']);
        $this->assertStringStartsWith('SharedKey admacct:', $req['args']['headers']['Authorization']);
    }

    public function test_test_connection_reports_azures_refusal(): void {
        $this->scriptHttp(fn() => self::httpReply(403, '<Error><Message>Server failed to authenticate the request</Message></Error>'));

        $r = $this->call('diluxone_offload_test_connection', [
            'provider'       => 'azure',
            'account_name'   => 'admacct',
            'account_key'    => base64_encode(random_bytes(32)),
            'container_name' => 'media',
        ]);

        $this->assertNotNull($r['json'], $r['raw']);
        $this->assertFalse($r['json']['success']);
        $this->assertStringContainsString('authenticate', json_encode($r['json']));
        $this->assertFalse(get_transient('diluxone_offload_connection_test_passed_' . $this->admin_id));
    }

    public function test_test_connection_with_missing_fields_is_an_error_not_a_fatal(): void {
        $r = $this->call('diluxone_offload_test_connection', ['provider' => 'azure']);
        $this->assertNotNull($r['json'], $r['raw']);
        $this->assertFalse($r['json']['success']);
    }

    // ── Refresh stats (real provider, scripted list) ────────

    public function test_refresh_stats_lists_the_container_and_caches(): void {
        $this->configureAzure();
        $this->scriptHttp(fn() => self::httpReply(200, self::blobsXml(['uploads/a.jpg' => 100, 'uploads/b.mp4' => 300, 'uploads/c.pdf' => 5])));

        $r = $this->call('diluxone_offload_refresh_stats');

        $this->assertNotNull($r['json'], $r['raw']);
        $this->assertTrue($r['json']['success'], $r['raw']);
        $this->assertSame(3, $r['json']['data']['fileCount']);
        $this->assertSame(405, $r['json']['data']['storageUsedBytes']);
        $this->assertSame(1, $r['json']['data']['filesByType']['videos']);
        $this->assertNotFalse(get_transient('diluxone_offload_azure_stats'), 'the 12-second listing is cached for the Overview');
    }

    public function test_refresh_stats_without_a_provider_is_a_clean_error(): void {
        $r = $this->call('diluxone_offload_refresh_stats');
        $this->assertNotNull($r['json'], $r['raw']);
        $this->assertFalse($r['json']['success']);
    }

    // ── Remove provider ─────────────────────────────────────

    public function test_remove_provider_wipes_config_state_and_table(): void {
        $this->configureAzure();
        DiluxOneOffloadDB::add_file('/2026/09/tracked.jpg', 1);
        update_option('diluxone_offload_sync_meta', ['status' => 'started']);

        $r = $this->call('diluxone_offload_ajax_remove_provider');

        $this->assertTrue($r['json']['success'] ?? false, $r['raw']);
        $this->assertFalse(ConfigManager::is_configured());
        $this->assertSame(PluginState::NOT_CONFIGURED, ConfigManager::get_state());
        $this->assertFalse(get_option('diluxone_offload_sync_meta'));
        $this->assertSame(0, $this->getTableRowCount());
    }

    // ── Sync tools ──────────────────────────────────────────

    public function test_mark_sync_complete_moves_to_synced_when_a_sync_exists(): void {
        $this->configureAzure();
        $this->useFakeClient();
        ConfigManager::set_state(PluginState::SYNCING);
        update_option('diluxone_offload_sync_meta', ['status' => 'started', 'sync_session_id' => 's1', 'start_time' => time(), 'last_heartbeat' => time()]);

        $r = $this->call('diluxone_offload_mark_sync_complete');

        $this->assertTrue($r['json']['success'] ?? false, $r['raw']);
        $this->assertSame(PluginState::SYNCED, ConfigManager::get_state());
    }

    public function test_mark_sync_complete_with_nothing_synced_and_no_metadata_is_an_error(): void {
        $this->configureAzure();
        $this->useFakeClient();
        ConfigManager::set_state(PluginState::CONFIGURED);
        delete_option('diluxone_offload_sync_meta');
        $r = $this->call('diluxone_offload_mark_sync_complete');
        $this->assertFalse($r['json']['success'] ?? true, $r['raw']);
        $this->assertSame(PluginState::CONFIGURED, ConfigManager::get_state());
    }

    public function test_mark_sync_complete_without_metadata_but_with_synced_files_moves_to_synced(): void {
        // After a Disconnect the sync metadata is gone but every file is still
        // tracked as synced; "Complete Sync" finds nothing to upload and skips
        // straight to the summary, and its request must still land the state.
        $this->configureAzure();
        $this->useFakeClient();
        ConfigManager::set_state(PluginState::CONFIGURED);
        delete_option('diluxone_offload_sync_meta');
        $this->addTestFiles(2);
        \DiluxOneOffload\DiluxOneOffloadDB::mark_synced('/2024/01/test-file-1.jpg');
        \DiluxOneOffload\DiluxOneOffloadDB::mark_synced('/2024/01/test-file-2.jpg');

        $r = $this->call('diluxone_offload_mark_sync_complete');

        $this->assertTrue($r['json']['success'] ?? false, $r['raw']);
        $this->assertSame(PluginState::SYNCED, ConfigManager::get_state());
        $this->assertSame([], get_option('diluxone_offload_sync_meta', []), 'no metadata is invented');
    }

    public function test_mark_sync_complete_without_metadata_and_a_file_still_pending_is_refused(): void {
        $this->configureAzure();
        $this->useFakeClient();
        ConfigManager::set_state(PluginState::CONFIGURED);
        delete_option('diluxone_offload_sync_meta');
        $this->addTestFiles(2);
        \DiluxOneOffload\DiluxOneOffloadDB::mark_synced('/2024/01/test-file-1.jpg');

        $r = $this->call('diluxone_offload_mark_sync_complete');

        $this->assertFalse($r['json']['success'] ?? true, $r['raw']);
        $this->assertSame(PluginState::CONFIGURED, ConfigManager::get_state());
    }

    public function test_mark_sync_complete_after_offloading_was_enabled_does_not_pull_it_back(): void {
        // Enable Offloading and this request can cross on the wire; whichever
        // arrives second must not downgrade OFFLOADING_ACTIVE to SYNCED.
        $this->configureAzure();
        $this->useFakeClient();
        ConfigManager::set_state(PluginState::OFFLOADING_ACTIVE);
        delete_option('diluxone_offload_sync_meta');
        $this->addTestFiles(1);

        $r = $this->call('diluxone_offload_mark_sync_complete');

        $this->assertTrue($r['json']['success'] ?? false, $r['raw']);
        $this->assertSame(PluginState::OFFLOADING_ACTIVE, ConfigManager::get_state());
    }

    public function test_cancel_sync_from_the_owning_tab_succeeds(): void {
        $this->configureAzure();
        $this->useFakeClient();
        ConfigManager::set_state(PluginState::SYNCING);
        update_option('diluxone_offload_sync_meta', ['status' => 'started', 'sync_session_id' => 'tab-A', 'start_time' => time(), 'last_heartbeat' => time()]);

        $r = $this->call('diluxone_offload_cancel_sync', ['session_id' => 'tab-A']);

        $this->assertTrue($r['json']['success'] ?? false, $r['raw']);
        $this->assertNotSame(PluginState::SYNCING, ConfigManager::get_state());
    }

    public function test_cancel_sync_from_another_tab_is_refused(): void {
        $this->configureAzure();
        $this->useFakeClient();
        ConfigManager::set_state(PluginState::SYNCING);
        update_option('diluxone_offload_sync_meta', ['status' => 'started', 'sync_session_id' => 'tab-A', 'start_time' => time(), 'last_heartbeat' => time()]);

        $r = $this->call('diluxone_offload_cancel_sync', ['session_id' => 'tab-B']);

        $this->assertFalse($r['json']['success'] ?? true, $r['raw']);
        $this->assertTrue($r['json']['data']['validation_failed'] ?? false);
        $this->assertSame('tab-A', get_option('diluxone_offload_sync_meta')['sync_session_id'], 'the owning tab keeps the sync');
    }

    public function test_clear_failed_empties_the_failed_list(): void {
        ConfigManager::add_failed_files(['/2026/09/x.jpg']);
        $r = $this->call('diluxone_offload_clear_failed');
        $this->assertTrue($r['json']['success'] ?? false, $r['raw']);
        $this->assertSame([], ConfigManager::get_failed_files());
    }

}
