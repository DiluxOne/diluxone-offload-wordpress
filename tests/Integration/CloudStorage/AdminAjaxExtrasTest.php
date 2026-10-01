<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use Tests\Integration\ScriptedHttp;
use Tests\Integration\FakeCloudClient;
use Tests\Integration\FaultyCloudClient;
use Tests\Integration\LocalBlobServer;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\Enums\PluginState;
use WPAjaxDieContinueException;

/**
 * Admin AJAX handlers, second helping: the nonce shapes "test connection"
 * accepts, the reset that "cancel sync" performs when nothing is running, and
 * the stats refresh.
 */
class AdminAjaxExtrasTest extends IntegrationTestCase {
    use ScriptedHttp;


    private static ?LocalBlobServer $server = null;
    private ?FakeCloudClient $fake = null;
    private int $admin_id = 0;

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        self::$server = new LocalBlobServer(8774);
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
        $this->admin_id = (int) wp_insert_user(['user_login' => 'adx_' . wp_generate_password(8, false), 'user_pass' => wp_generate_password(12), 'role' => 'administrator']);
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
        delete_transient('diluxone_offload_connection_test_passed_' . $this->admin_id);
        delete_transient('diluxone_offload_stats');
        delete_transient('diluxone_offload_sas_token');
        wp_delete_user($this->admin_id);
        wp_set_current_user(0);
        $_POST = [];
        $_REQUEST = [];
        parent::tearDown();
    }

    public function injectFake($pre) {
        return $this->fake;
    }

    private function call(string $action, array $post = []): array {
        $_POST = $post;
        $_POST['nonce'] = wp_create_nonce('diluxone_offload_admin');
        $_REQUEST = $_POST;
        ob_start();
        try {
            do_action('wp_ajax_' . $action);
        } catch (WPAjaxDieContinueException $e) {
        }
        $raw = (string) ob_get_clean();
        $json = json_decode($raw, true);
        return ['json' => is_array($json) ? $json : null, 'raw' => $raw];
    }

    private static function json(int $code, array $payload): array {
        return self::httpReply($code, (string) json_encode($payload));
    }

    public function test_connection_test_accepts_the_form_nonce_too(): void {
        $_POST = ['_wpnonce' => wp_create_nonce('diluxone_offload_admin'), 'provider' => 'azure'];
        $_REQUEST = $_POST;
        ob_start();
        try {
            do_action('wp_ajax_diluxone_offload_test_connection');
        } catch (WPAjaxDieContinueException $e) {
        }
        $j = json_decode((string) ob_get_clean(), true);
        $this->assertStringContainsString('Storage Account Name is required', $j['data']['message'], 'got past the nonce check');
    }

    public function test_connection_test_without_any_nonce_is_refused(): void {
        $_POST = ['provider' => 'azure'];
        $_REQUEST = $_POST;
        ob_start();
        try {
            do_action('wp_ajax_diluxone_offload_test_connection');
        } catch (WPAjaxDieContinueException $e) {
        }
        $j = json_decode((string) ob_get_clean(), true);
        $this->assertStringContainsString('Security', $j['data']['message']);
    }

    // ── save_updated_credentials ────────────────────────────

    public function test_saving_credentials_requires_a_prior_test(): void {
        $r = $this->call('diluxone_offload_save_updated_credentials', ['provider' => 'azure']);
        $this->assertStringContainsString('test the connection first', $r['json']['data']['message']);
    }

    public function test_saving_azure_credentials_that_differ_from_the_tested_ones_is_refused(): void {
        $this->passConnectionTest('azure', ['storage_account' => 'tested', 'access_key' => 'k', 'container_name' => 'media']);
        $r = $this->call('diluxone_offload_save_updated_credentials', ['provider' => 'azure', 'account_name' => 'other', 'account_key' => 'k', 'container_name' => 'media']);
        $this->assertStringContainsString('do not match', $r['json']['data']['message']);
    }

    public function test_saving_an_invalid_provider_config_is_an_error_not_a_fatal(): void {
        $this->passConnectionTest('azure', ['storage_account' => '', 'access_key' => '', 'container_name' => '']);
        $r = $this->call('diluxone_offload_save_updated_credentials', ['provider' => 'azure', 'account_name' => '', 'account_key' => '', 'container_name' => '']);
        $this->assertFalse($r['json']['success'], $r['raw']);
        // Rejected by ProviderConfig::validate_azure_config() before it ever reaches
        // save_provider_config(), so the message names the missing field rather
        // than the generic "not saved" — same not-a-fatal contract either way.
        $this->assertStringContainsString('Storage Account Name is required', $r['json']['data']['message']);
        $this->assertSame(PluginState::NOT_CONFIGURED, ConfigManager::get_state(), 'nothing was written');
    }

    public function test_saving_credentials_for_an_unknown_provider_is_an_error(): void {
        $this->passConnectionTest('azure', ['storage_account' => 'tested', 'access_key' => 'k', 'container_name' => 'media']);
        $r = $this->call('diluxone_offload_save_updated_credentials', ['provider' => 'dropbox', 'account_name' => 'tested', 'account_key' => 'k', 'container_name' => 'media']);
        $this->assertFalse($r['json']['success']);
        $this->assertStringContainsString('Unsupported cloud provider', $r['json']['data']['message']);
    }

    public function test_mark_sync_complete_without_a_sync_manager_says_so(): void {
        $plugin = \DiluxOneOffload\Plugin::get_instance();
        $prop   = new \ReflectionProperty($plugin, 'sync_manager');
        if ( PHP_VERSION_ID < 80100 ) { // Required before 8.1, deprecated from 8.5.
        	$prop->setAccessible( true );
        }
        $prop->setValue($plugin, null);
        try {
            $r = $this->call('diluxone_offload_mark_sync_complete');
        } finally {
            $prop->setValue($plugin, new \DiluxOneOffload\SyncManager());
        }
        $this->assertFalse($r['json']['success']);
        $this->assertStringContainsString('not available', $r['json']['data']);
    }


    public function test_cancel_with_no_active_sync_resets_to_configured(): void {
        ConfigManager::set_state(PluginState::SYNCED);
        $this->addTestFiles(2);
        update_option('diluxone_offload_failed_files', ['/x.jpg'], false);
        $r = $this->call('diluxone_offload_cancel_sync', ['session_id' => 'me']);
        $this->assertTrue($r['json']['success'], $r['raw']);
        $this->assertStringContainsString('reset to configured', $r['json']['data']['message']);
        $this->assertSame(PluginState::CONFIGURED, ConfigManager::get_state());
        $this->assertSame(0, $this->getTableRowCount());
        $this->assertFalse(get_option('diluxone_offload_failed_files'));
    }

    // ── refresh_stats through the interface ─────────────────

    public function test_refresh_stats_asks_whatever_provider_is_configured(): void {
        // Before, only an AzureProvider had stats; any client does now.
        $this->fake = new FakeCloudClient(self::$server->base_url);
        $this->fake->blobs = ['uploads/a.jpg' => 'abc', 'uploads/b.jpg' => 'de'];
        add_filter('diluxone_offload_pre_cloud_client', [$this, 'injectFake']);
        $r = $this->call('diluxone_offload_refresh_stats');
        $this->assertTrue($r['json']['success'], $r['raw']);
        $this->assertSame(2, $r['json']['data']['fileCount']);
        $this->assertSame(5, $r['json']['data']['storageUsedBytes']);
    }

    // ── Test Connection and the saved connection's health ───

    private function pauseHealth(): void {
        update_option('diluxone_offload_connection_health', ['status' => 'unhealthy', 'error_code' => '403', 'error_message' => 'HTTP 403', 'consecutive_failures' => 3, 'last_check' => time(), 'last_success' => 0, 'error_source' => 'upload']);
    }

    public function test_a_passing_test_of_the_saved_credentials_ends_a_pause(): void {
        $key = base64_encode(random_bytes(32));
        ConfigManager::save_config(['cloud_provider' => 'azure', 'provider_config' => ['storage_account' => 'savedacct', 'container_name' => 'media', 'access_key' => $key]]);
        ConfigManager::set_state(PluginState::OFFLOADING_ACTIVE);
        $this->pauseHealth();
        $this->scriptHttp(fn() => self::httpReply(200, '', ['x-ms-blob-public-access' => 'blob']));

        $r = $this->call('diluxone_offload_test_connection', ['provider' => 'azure', 'account_name' => 'savedacct', 'account_key' => $key, 'container_name' => 'media']);

        $this->assertTrue($r['json']['success'], $r['raw']);
        $health = ConfigManager::get_connection_health();
        $this->assertSame('healthy', $health['status'], 'the key that is in use works again: uploads reopen now');
        $this->assertSame(0, $health['consecutive_failures']);
    }

    public function test_a_failing_test_leaves_no_permission_to_save(): void {
        $this->scriptHttp(fn() => self::httpReply(403, '<?xml version="1.0"?><Error><Code>AuthenticationFailed</Code><Message>Server failed to authenticate</Message></Error>'));
        $r = $this->call('diluxone_offload_test_connection', ['provider' => 'azure', 'account_name' => 'badacct', 'account_key' => base64_encode(random_bytes(32)), 'container_name' => 'media']);
        $this->assertFalse($r['json']['success'], $r['raw']);
        $this->assertStringContainsString('403', $r['json']['data']['message']);
        $this->assertFalse(get_transient('diluxone_offload_connection_test_passed_' . $this->admin_id));
    }

    public function test_a_provider_name_in_another_spelling_is_refused_before_any_request(): void {
        $this->scriptHttp(fn() => self::httpReply(200));
        $r = $this->call('diluxone_offload_test_connection', ['provider' => 'AZURE', 'account_name' => 'acct', 'account_key' => 'k', 'container_name' => 'media']);
        $this->assertFalse($r['json']['success'], $r['raw']);
        $this->assertSame('Unsupported cloud provider', $r['json']['data']['message']);
        $this->assertSame([], $this->httpRequests(), 'nothing was sent');
    }

    public function test_a_field_posted_as_an_array_is_treated_as_empty(): void {
        $this->scriptHttp(fn() => self::httpReply(200));
        $r = $this->call('diluxone_offload_test_connection', ['provider' => 'azure', 'account_name' => ['evil' => 'x'], 'account_key' => base64_encode(random_bytes(32)), 'container_name' => 'media']);
        $this->assertFalse($r['json']['success'], $r['raw']);
        $this->assertStringContainsString('Storage Account Name is required', $r['json']['data']['message']);
        $this->assertSame([], $this->httpRequests());
    }

    public function test_a_subscriber_cannot_test_a_connection(): void {
        $sub = (int) wp_insert_user(['user_login' => 'adxsub_' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(12), 'role' => 'subscriber']);
        wp_set_current_user($sub);
        $this->scriptHttp(fn() => self::httpReply(200, '', ['x-ms-blob-public-access' => 'blob']));
        try {
            $r = $this->call('diluxone_offload_test_connection', ['provider' => 'azure', 'account_name' => 'acct', 'account_key' => base64_encode(random_bytes(32)), 'container_name' => 'media']);
        } finally {
            wp_set_current_user($this->admin_id);
            wp_delete_user($sub);
        }
        $this->assertSame('Insufficient permissions', $r['json']['data']['message']);
        $this->assertSame([], $this->httpRequests());
    }

    // ── Check now and stats, when the provider fails ────────

    public function test_check_now_without_a_provider_says_there_is_nothing_to_check(): void {
        $r = $this->call('diluxone_offload_check_health');
        $this->assertFalse($r['json']['success'], $r['raw']);
        $this->assertStringContainsString('No provider is connected', $r['json']['data']['message']);
    }

    private function useFaulty(): FaultyCloudClient {
        $client = new FaultyCloudClient(self::$server->base_url);
        $this->fake = $client;
        add_filter('diluxone_offload_pre_cloud_client', [$this, 'injectFake']);
        return $client;
    }

    public function test_stats_the_provider_cannot_compute_are_an_error_and_never_mark_the_connection_healthy(): void {
        $client = $this->useFaulty();
        $client->throws['get_storage_stats'] = 'HTTP 403 listing refused';
        $this->pauseHealth();
        $r = $this->call('diluxone_offload_refresh_stats');
        $this->assertFalse($r['json']['success'], $r['raw']);
        $this->assertSame('HTTP 403 listing refused', $r['json']['data']['message']);
        $this->assertSame('unhealthy', ConfigManager::get_connection_health()['status']);
    }

    public function test_stats_answered_as_a_failure_are_passed_on(): void {
        $client = new class(self::$server->base_url) extends FakeCloudClient {
            public function get_storage_stats(bool $force_refresh = false): array {
                return ['success' => false, 'message' => 'Listing failed: HTTP 500'];
            }
        };
        $this->fake = $client;
        add_filter('diluxone_offload_pre_cloud_client', [$this, 'injectFake']);
        $r = $this->call('diluxone_offload_refresh_stats');
        $this->assertFalse($r['json']['success'], $r['raw']);
        $this->assertSame('Listing failed: HTTP 500', $r['json']['data']['message']);
    }
}
