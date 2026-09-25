<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use Tests\Integration\ScriptedHttp;
use Tests\Integration\FakeCloudClient;
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
        $this->assertStringContainsString('Missing required fields', $j['data']['message'], 'got past the nonce check');
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
        set_transient('diluxone_offload_connection_test_passed_' . $this->admin_id, ['provider' => 'azure', 'account_name' => 'tested', 'container_name' => 'media', 'timestamp' => time()], 300);
        $r = $this->call('diluxone_offload_save_updated_credentials', ['provider' => 'azure', 'account_name' => 'other', 'account_key' => 'k', 'container_name' => 'media']);
        $this->assertStringContainsString('do not match', $r['json']['data']['message']);
    }

    public function test_saving_an_invalid_provider_config_is_an_error_not_a_fatal(): void {
        set_transient('diluxone_offload_connection_test_passed_' . $this->admin_id, ['provider' => 'azure', 'account_name' => '', 'container_name' => '', 'timestamp' => time()], 300);
        $r = $this->call('diluxone_offload_save_updated_credentials', ['provider' => 'azure', 'account_name' => '', 'account_key' => '', 'container_name' => '']);
        $this->assertFalse($r['json']['success'], $r['raw']);
        // Rejected by ProviderConfig::validate_azure_config() before it ever reaches
        // save_provider_config(), so the message names the missing field rather
        // than the generic "not saved" — same not-a-fatal contract either way.
        $this->assertStringContainsString('Storage Account Name is required', $r['json']['data']['message']);
        $this->assertSame(PluginState::NOT_CONFIGURED, ConfigManager::get_state(), 'nothing was written');
    }

    public function test_saving_credentials_for_an_unknown_provider_is_an_error(): void {
        set_transient('diluxone_offload_connection_test_passed_' . $this->admin_id, ['provider' => 'azure', 'account_name' => 'tested', 'container_name' => 'media', 'timestamp' => time()], 300);
        $r = $this->call('diluxone_offload_save_updated_credentials', ['provider' => 'dropbox', 'account_name' => 'tested', 'account_key' => 'k', 'container_name' => 'media']);
        $this->assertFalse($r['json']['success']);
        $this->assertStringContainsString('not saved', $r['json']['data']['message']);
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

    // ── refresh_stats per provider ──────────────────────────

    public function test_refresh_stats_with_an_unknown_provider_type_is_an_error(): void {
        $this->fake = new FakeCloudClient(self::$server->base_url);
        add_filter('diluxone_offload_pre_cloud_client', [$this, 'injectFake']);
        $r = $this->call('diluxone_offload_refresh_stats');
        $this->assertFalse($r['json']['success']);
        $this->assertStringContainsString('Unknown provider', $r['json']['data']['message']);
    }
}
