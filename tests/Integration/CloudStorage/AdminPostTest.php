<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use Tests\Integration\FakeCloudClient;
use Tests\Integration\LocalBlobServer;
use DiluxOneOffload\Admin;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\DiluxOneOffloadDB as DB;
use DiluxOneOffload\Enums\PluginState;
use WPAjaxDieContinueException;

/**
 * The admin_post form handlers: saving settings, saving provider
 * credentials, and the "remove configuration" reset. Each ends in wp_safe_redirect() + exit, so the test
 * hooks the `wp_redirect` filter and throws to capture the destination
 * before exit() can run.
 */
class AdminPostTest extends IntegrationTestCase {

    private static ?LocalBlobServer $server = null;
    private ?FakeCloudClient $fake = null;
    private int $admin_id = 0;
    private int $subscriber_id = 0;

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        self::$server = new LocalBlobServer(8771);
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
        $this->admin_id = (int) wp_insert_user(['user_login' => 'post_' . wp_generate_password(8, false), 'user_pass' => wp_generate_password(12), 'role' => 'administrator']);
        $this->subscriber_id = (int) wp_insert_user(['user_login' => 'sub_' . wp_generate_password(8, false), 'user_pass' => wp_generate_password(12), 'role' => 'subscriber']);
        wp_set_current_user($this->admin_id);
        add_filter('wp_redirect', [$this, 'captureRedirect']);
        $_POST = [];
        $_REQUEST = [];
    }

    protected function tearDown(): void {
        remove_filter('wp_redirect', [$this, 'captureRedirect']);
        if ($this->fake) {
            remove_filter('diluxone_offload_pre_cloud_client', [$this, 'injectFake']);
            $this->fake = null;
        }
        wp_delete_user($this->admin_id);
        wp_delete_user($this->subscriber_id);
        wp_set_current_user(0);
        $_POST = [];
        $_REQUEST = [];
        parent::tearDown();
    }

    public function captureRedirect(string $location) {
        throw new RedirectCaptured($location);
    }

    public function injectFake($pre) {
        return $this->fake;
    }

    private function useFakeClient(): FakeCloudClient {
        $this->fake = new FakeCloudClient(self::$server->base_url);
        add_filter('diluxone_offload_pre_cloud_client', [$this, 'injectFake']);
        return $this->fake;
    }

    /**
     * Runs a handler with a valid nonce and returns the parsed redirect query,
     * plus the notice the handler queued for this user under 'success' or
     * 'error' — the URL itself carries neither any more.
     */
    private function submit(callable $handler, string $nonce_action, array $post): array {
        $_POST = $post + ['_wpnonce' => wp_create_nonce($nonce_action)];
        $_REQUEST = $_POST;
        try {
            $handler();
        } catch (RedirectCaptured $e) {
            $query = [];
            parse_str((string) parse_url($e->location, PHP_URL_QUERY), $query);
            $this->assertStringStartsWith(admin_url('admin.php'), $e->location);
            $this->assertArrayNotHasKey('success', $query, 'the message does not travel in the URL');
            $this->assertArrayNotHasKey('error', $query, 'the message does not travel in the URL');
            $notice = get_transient('diluxone_offload_notice_' . $this->admin_id);
            if (is_array($notice)) {
                $query[$notice['type']] = $notice['message'];
                delete_transient('diluxone_offload_notice_' . $this->admin_id);
            }
            return $query;
        }
        $this->fail('handler did not redirect');
    }

    // ── guards ──────────────────────────────────────────────

    /** posted_fields() verifies the request itself: a nonce and the capability, before it reads a field. */
    public function test_posted_fields_reads_nothing_without_a_nonce_or_the_capability(): void {
        $read = static function (): array {
            $m = new \ReflectionMethod(Admin::class, 'posted_fields');
            if ( PHP_VERSION_ID < 80100 ) { // Required before 8.1, deprecated from 8.5.
                $m->setAccessible( true );
            }
            return $m->invoke(null, ['timeout']);
        };

        $_POST = ['timeout' => '90'];
        $this->assertSame([], $read(), 'no nonce, no field');

        $_POST = ['timeout' => '90', '_wpnonce' => 'forged'];
        $this->assertSame([], $read(), 'a nonce that does not verify, no field');

        $_POST = ['timeout' => '90', '_wpnonce' => wp_create_nonce('some_other_action')];
        $this->assertSame([], $read(), 'a nonce for another action, no field');

        wp_set_current_user($this->subscriber_id);
        $_POST = ['timeout' => '90', '_wpnonce' => wp_create_nonce('diluxone_offload_save_config')];
        $this->assertSame([], $read(), 'a subscriber with a valid nonce, no field');

        wp_set_current_user($this->admin_id);
        foreach (['_wpnonce' => 'diluxone_offload_save_config', 'nonce' => 'diluxone_offload_admin'] as $field => $action) {
            $_POST = ['timeout' => '90', $field => wp_create_nonce($action)];
            $this->assertSame(['timeout' => '90'], $read(), "an administrator with the $action nonce in $field");
        }
    }

    public function test_the_handler_refuses_a_bad_nonce(): void {
        foreach (['save_config'] as $h) {
            $_POST = ['_wpnonce' => 'nope'];
            try {
                Admin::$h();
                $this->fail("$h ran without a nonce");
            } catch (WPAjaxDieContinueException $e) {
                $this->assertStringContainsString('Security', $e->getMessage(), $h);
            }
        }
    }

    public function test_the_handler_refuses_a_non_admin(): void {
        wp_set_current_user($this->subscriber_id);
        foreach (['save_config' => 'diluxone_offload_save_config'] as $h => $action) {
            $_POST = ['_wpnonce' => wp_create_nonce($action)];
            try {
                Admin::$h();
                $this->fail("$h ran for a subscriber");
            } catch (WPAjaxDieContinueException $e) {
                $this->assertStringContainsString('permissions', $e->getMessage(), $h);
            }
        }
    }

    // ── save_config: the Settings screen, one form per tab ──

    public function test_transfers_tab_saves_its_two_numbers_and_nothing_else(): void {
        ConfigManager::save_plugin_settings(['force_https_on_cloud' => false, 'debug_enabled' => true, 'timeout' => 60, 'max_file_size' => 20 * MB_IN_BYTES]);
        $q = $this->submit([Admin::class, 'save_config'], 'diluxone_offload_save_config', [
            'screen'        => 'settings',
            'tab'           => 'transfers',
            'max_file_size' => '256',
            'timeout'       => '45',
        ]);
        $this->assertSame('diluxone-offload-settings', $q['page']);
        $this->assertSame('transfers', $q['tab']);
        $this->assertArrayHasKey('success', $q);
        $cfg = ConfigManager::get_config();
        $this->assertSame(256 * MB_IN_BYTES, (int) $cfg['max_file_size'], 'stored in bytes');
        $this->assertSame(45, (int) $cfg['timeout']);
        $this->assertFalse((bool) $cfg['force_https_on_cloud'], 'a checkbox of another tab is not reset by this form');
        $this->assertTrue((bool) $cfg['debug_enabled'], 'nor is this one');
    }

    public function test_serving_and_logging_tabs_save_their_checkbox_both_ways(): void {
        $q = $this->submit([Admin::class, 'save_config'], 'diluxone_offload_save_config', ['screen' => 'settings', 'tab' => 'serving', 'force_https_on_cloud' => '1']);
        $this->assertSame('serving', $q['tab']);
        $this->assertTrue((bool) ConfigManager::get_config()['force_https_on_cloud']);
        $q = $this->submit([Admin::class, 'save_config'], 'diluxone_offload_save_config', ['screen' => 'settings', 'tab' => 'serving']);
        $this->assertFalse((bool) ConfigManager::get_config()['force_https_on_cloud'], 'absent from its own form means unchecked');

        $q = $this->submit([Admin::class, 'save_config'], 'diluxone_offload_save_config', ['screen' => 'settings', 'tab' => 'logging', 'enable_debug_logging' => '1']);
        $this->assertSame('logging', $q['tab']);
        $this->assertTrue((bool) ConfigManager::get_config()['debug_enabled']);
        $q = $this->submit([Admin::class, 'save_config'], 'diluxone_offload_save_config', ['screen' => 'settings', 'tab' => 'logging']);
        $this->assertFalse((bool) ConfigManager::get_config()['debug_enabled']);
    }

    public function test_serving_saves_browser_caching_and_the_storage_class(): void {
        $this->submit([Admin::class, 'save_config'], 'diluxone_offload_save_config', ['screen' => 'settings', 'tab' => 'serving', 'force_https_on_cloud' => '1', 'cache_control_enabled' => '1', 'cache_control' => "public, max-age=86400\r\n", 'storage_class' => 'infrequent']);
        $cfg = ConfigManager::get_config();
        $this->assertTrue((bool) $cfg['cache_control_enabled']);
        $this->assertSame('public, max-age=86400', $cfg['cache_control'], 'only what a header may carry');
        $this->assertSame('infrequent', $cfg['storage_class']);

        $this->submit([Admin::class, 'save_config'], 'diluxone_offload_save_config', ['screen' => 'settings', 'tab' => 'serving', 'cache_control' => 'public, max-age=86400', 'storage_class' => 'GLACIER']);
        $cfg = ConfigManager::get_config();
        $this->assertFalse((bool) $cfg['cache_control_enabled'], 'unchecked');
        $this->assertSame('standard', $cfg['storage_class'], 'never an archive class');
    }

    public function test_an_unknown_settings_tab_falls_back_to_transfers(): void {
        $q = $this->submit([Admin::class, 'save_config'], 'diluxone_offload_save_config', ['screen' => 'settings', 'tab' => 'nope', 'timeout' => '90']);
        $this->assertSame('transfers', $q['tab']);
        $this->assertSame(90, (int) ConfigManager::get_config()['timeout']);
    }

    public function keepOldOption($value, $old) {
        return $old;
    }

    public function test_settings_tab_reports_a_refused_write(): void {
        add_filter('pre_update_option_diluxone_offload_config', [$this, 'keepOldOption'], 10, 2);
        try {
            $q = $this->submit([Admin::class, 'save_config'], 'diluxone_offload_save_config', ['screen' => 'settings', 'tab' => 'transfers', 'timeout' => '77']);
        } finally {
            remove_filter('pre_update_option_diluxone_offload_config', [$this, 'keepOldOption'], 10);
        }
        $this->assertSame('transfers', $q['tab']);
        $this->assertStringContainsString('Failed to save settings', $q['error']);
    }

    public function test_provider_tab_reports_a_refused_write(): void {
        $this->useFakeClient();
        $key = base64_encode(random_bytes(32));
        $this->passConnectionTest('azure', ['storage_account' => 'refusedacct', 'access_key' => $key, 'container_name' => 'media']);
        add_filter('pre_update_option_diluxone_offload_config', [$this, 'keepOldOption'], 10, 2);
        try {
            $q = $this->submit([Admin::class, 'save_config'], 'diluxone_offload_save_config', [
                'screen'         => 'cloud-provider',
                'cloud_provider' => 'azure',
                'account_name'   => 'refusedacct',
                'account_key'    => $key,
                'container_name' => 'media',
            ]);
        } finally {
            remove_filter('pre_update_option_diluxone_offload_config', [$this, 'keepOldOption'], 10);
        }
        $this->assertStringContainsString('The configuration could not be saved', $q['error']);
    }

    // ── save_config: the Cloud Provider screen ──────────────

    public function test_provider_tab_saves_azure_credentials_and_moves_to_configured(): void {
        $this->useFakeClient();
        $key = base64_encode(random_bytes(32));
        $this->passConnectionTest('azure', ['storage_account' => 'posttestacct', 'access_key' => $key, 'container_name' => 'media']);
        $q = $this->submit([Admin::class, 'save_config'], 'diluxone_offload_save_config', [
            'screen'         => 'cloud-provider',
            'cloud_provider' => 'azure',
            'account_name'   => 'posttestacct',
            'account_key'    => $key,
            'container_name' => 'media',
        ]);
        $this->assertSame('diluxone-offload-provider', $q['page']);
        $this->assertSame('connection', $q['tab']);
        $this->assertArrayHasKey('success', $q, print_r($q, true));
        $cfg = ConfigManager::get_config();
        $this->assertSame('azure', $cfg['cloud_provider']);
        $this->assertSame('posttestacct', $cfg['provider_config']['storage_account']);
        $this->assertSame($key, ConfigManager::get_current_provider_config()['access_key'], 'key round-trips through encryption');
        $this->assertNotSame($key, get_option('diluxone_offload_config')['provider_config']['access_key'], 'key is not stored in clear');
        $this->assertSame(PluginState::CONFIGURED, ConfigManager::get_state());
        $this->assertFalse(get_transient('diluxone_offload_connection_test_passed_' . $this->admin_id), 'a test is spent by the save it allowed');
    }

    public function test_provider_tab_refuses_a_configuration_that_was_not_tested(): void {
        $this->useFakeClient();
        $q = $this->submit([Admin::class, 'save_config'], 'diluxone_offload_save_config', [
            'screen'         => 'cloud-provider',
            'cloud_provider' => 'azure',
            'account_name'   => 'untested',
            'account_key'    => base64_encode(random_bytes(32)),
            'container_name' => 'media',
        ]);
        $this->assertStringContainsString('Test the connection before saving', $q['error']);
        $this->assertSame(PluginState::NOT_CONFIGURED, ConfigManager::get_state());
    }

    public function test_provider_tab_refuses_a_key_other_than_the_tested_one(): void {
        // The gap the fingerprint closes: before, the tested account and
        // container were compared, but any key could be saved with them.
        $this->useFakeClient();
        $this->passConnectionTest('azure', ['storage_account' => 'tested', 'access_key' => base64_encode(random_bytes(32)), 'container_name' => 'media']);
        $q = $this->submit([Admin::class, 'save_config'], 'diluxone_offload_save_config', [
            'screen'         => 'cloud-provider',
            'cloud_provider' => 'azure',
            'account_name'   => 'tested',
            'account_key'    => base64_encode(random_bytes(32)),
            'container_name' => 'media',
        ]);
        $this->assertStringContainsString('Test the connection before saving', $q['error']);
        $this->assertSame(PluginState::NOT_CONFIGURED, ConfigManager::get_state());
    }

    public function test_provider_tab_saves_an_s3_provider_with_the_secret_encrypted(): void {
        $this->useFakeClient();
        $post = [
            's3_preset'            => 'aws',
            's3_region'            => 'eu-west-1',
            's3_endpoint'          => 'https://s3.eu-west-1.amazonaws.com/',
            's3_bucket'            => 'post-media',
            's3_access_key_id'     => 'AKIAPOSTTEST',
            's3_secret_access_key' => 'post/secret+key',
            's3_public_url'        => 'https://post-media.s3.eu-west-1.amazonaws.com/',
        ];
        $this->passConnectionTest('s3', \DiluxOneOffload\DTOs\ProviderConfig::fromPost(['cloud_provider' => 's3'] + $post)->getProviderConfig());

        $q = $this->submit([Admin::class, 'save_config'], 'diluxone_offload_save_config', ['screen' => 'cloud-provider', 'cloud_provider' => 's3'] + $post);

        $this->assertArrayHasKey('success', $q, print_r($q, true));
        $saved = ConfigManager::get_current_provider_config();
        $this->assertSame('https://s3.eu-west-1.amazonaws.com', $saved['endpoint'], 'stored normalised');
        $this->assertSame('https://post-media.s3.eu-west-1.amazonaws.com', $saved['public_url']);
        $this->assertFalse($saved['path_style'], 'Amazon S3 addresses the bucket in the host');
        $this->assertSame('post/secret+key', $saved['secret_access_key'], 'the secret round-trips through encryption');
        $raw = get_option('diluxone_offload_config')['provider_config'];
        $this->assertStringStartsWith('DILUXONEOFFLOADENC1:', $raw['secret_access_key'], 'the secret is not stored in clear');
        $this->assertSame('AKIAPOSTTEST', $raw['access_key_id'], 'the key id is not a secret');
        $this->assertSame(PluginState::CONFIGURED, ConfigManager::get_state());
        $this->assertInstanceOf(\DiluxOneOffload\Providers\S3CompatibleProvider::class, \DiluxOneOffload\Factories\CloudStorageFactory::create('s3', $saved));
    }

    public function test_provider_tab_refuses_an_s3_form_with_a_plain_http_endpoint_outside_custom(): void {
        $q = $this->submit([Admin::class, 'save_config'], 'diluxone_offload_save_config', [
            'screen'               => 'cloud-provider',
            'cloud_provider'       => 's3',
            's3_preset'            => 'aws',
            's3_region'            => 'us-east-1',
            's3_endpoint'          => 'http://s3.us-east-1.amazonaws.com',
            's3_bucket'            => 'b-media',
            's3_access_key_id'     => 'AKIA',
            's3_secret_access_key' => 's',
            's3_public_url'        => 'https://b-media.s3.us-east-1.amazonaws.com',
        ]);
        $this->assertStringContainsString('Endpoint must be an https:// URL', $q['error']);
        $this->assertSame(PluginState::NOT_CONFIGURED, ConfigManager::get_state());
    }

    public function test_provider_tab_rejects_an_invalid_storage_account_name(): void {
        $q = $this->submit([Admin::class, 'save_config'], 'diluxone_offload_save_config', [
            'screen'         => 'cloud-provider',
            'cloud_provider' => 'azure',
            'account_name'   => 'Has Spaces',
            'account_key'    => 'k',
            'container_name' => 'media',
        ]);
        $this->assertStringContainsString('3-24 lowercase', $q['error']);
        $this->assertSame(PluginState::NOT_CONFIGURED, ConfigManager::get_state());
    }

    public function test_provider_tab_rejects_missing_fields(): void {
        $q = $this->submit([Admin::class, 'save_config'], 'diluxone_offload_save_config', [
            'screen'         => 'cloud-provider',
            'cloud_provider' => 'azure',
            'account_name'   => 'acct',
            'account_key'    => '',
            'container_name' => 'media',
        ]);
        $this->assertStringContainsString('Access Key is required', $q['error']);
    }

    public function test_provider_tab_saves_even_when_the_account_is_unreachable_and_health_records_it(): void {
        // It passed its test a minute ago; by the time it is saved the
        // account no longer answers.
        $this->useFakeClient()->connection_ok = false;
        $key = base64_encode(random_bytes(32));
        $this->passConnectionTest('azure', ['storage_account' => 'unreachable', 'access_key' => $key, 'container_name' => 'media']);
        $q = $this->submit([Admin::class, 'save_config'], 'diluxone_offload_save_config', [
            'screen'         => 'cloud-provider',
            'cloud_provider' => 'azure',
            'account_name'   => 'unreachable',
            'account_key'    => $key,
            'container_name' => 'media',
        ]);
        $this->assertArrayHasKey('success', $q, 'credentials are stored; reachability is the health check\'s job');
        delete_option('diluxone_offload_connection_health');
        $this->assertSame('unhealthy', ConfigManager::check_connection_health()['status']);
    }

    public function test_provider_tab_without_credentials_just_returns_to_the_connection(): void {
        $q = $this->submit([Admin::class, 'save_config'], 'diluxone_offload_save_config', ['screen' => 'cloud-provider']);
        $this->assertSame('diluxone-offload-provider', $q['page']);
        $this->assertSame('connection', $q['tab']);
        $this->assertArrayNotHasKey('error', $q);
        $this->assertArrayNotHasKey('success', $q);
    }

    public function test_unknown_screen_is_an_invalid_save_request(): void {
        $q = $this->submit([Admin::class, 'save_config'], 'diluxone_offload_save_config', ['screen' => 'tools']);
        $this->assertSame('diluxone-offload', $q['page']);
        $this->assertArrayNotHasKey('tab', $q);
        $this->assertStringContainsString('The save request was not valid', $q['error']);
    }
}

/** Thrown by the wp_redirect filter so the handler's exit() is never reached. */
class RedirectCaptured extends \Exception {
    public string $location;
    public function __construct(string $location) {
        parent::__construct('redirect to ' . $location);
        $this->location = $location;
    }
}
