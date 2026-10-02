<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\Enums\PluginState;
use Tests\Integration\FaultyCloudClient;

/**
 * Integration tests for ConfigManager.
 *
 * Exercises configuration persistence and state-machine transitions
 * against the real WordPress Options API and the plugin's connection-
 * health tracking.
 */
class ConfigManagerTest extends IntegrationTestCase {

    public function test_save_config_and_get_config_roundtrip(): void {
        $config = [
            'cloud_provider'  => 'azure',
            'provider_config' => [
                'storage_account' => 'testaccount',
                'access_key'      => 'testkey123',
                'container_name'  => 'testcontainer',
            ],
            'debug_enabled'    => true,
            'keep_local_files' => false,
            'timeout'          => 120,
        ];

        $saved = ConfigManager::save_config($config);
        $this->assertTrue($saved);

        $loaded = ConfigManager::get_config();
        $this->assertSame('azure', $loaded['cloud_provider']);
        $this->assertSame('testaccount', $loaded['provider_config']['storage_account']);
        $this->assertTrue($loaded['debug_enabled']);
        $this->assertFalse($loaded['keep_local_files']);
        $this->assertSame(120, $loaded['timeout']);
    }

    public function test_save_provider_config_and_get_provider_config(): void {
        $provider = [
            'cloud_provider'  => 'azure',
            'provider_config' => [
                'storage_account' => 'myaccount',
                'access_key'      => 'mykey',
                'container_name'  => 'mycontainer',
            ],
        ];

        ConfigManager::save_provider_config($provider);

        $loaded = ConfigManager::get_provider_config();
        $this->assertSame('azure', $loaded['cloud_provider']);
        $this->assertSame('myaccount', $loaded['provider_config']['storage_account']);
    }

    public function test_save_plugin_settings_and_get_plugin_settings(): void {
        $settings = [
            'debug_enabled'    => true,
            'keep_local_files' => false,
            'timeout'          => 90,
            'max_file_size'    => 104857600, // 100 MB
        ];

        ConfigManager::save_plugin_settings($settings);

        $loaded = ConfigManager::get_plugin_settings();
        $this->assertTrue($loaded['debug_enabled']);
        $this->assertFalse($loaded['keep_local_files']);
        $this->assertSame(90, $loaded['timeout']);
    }

    public function test_state_transition_configured_to_syncing(): void {
        ConfigManager::set_state(PluginState::CONFIGURED);
        $this->assertSame(PluginState::CONFIGURED, ConfigManager::get_state());

        ConfigManager::set_state(PluginState::SYNCING);
        $this->assertSame(PluginState::SYNCING, ConfigManager::get_state());
    }

    public function test_state_transition_syncing_to_synced(): void {
        ConfigManager::set_state(PluginState::SYNCING);

        ConfigManager::set_state(PluginState::SYNCED);
        $this->assertSame(PluginState::SYNCED, ConfigManager::get_state());
    }

    public function test_state_transition_synced_to_offloading_active(): void {
        ConfigManager::set_state(PluginState::SYNCED);

        ConfigManager::set_state(PluginState::OFFLOADING_ACTIVE);
        $this->assertSame(PluginState::OFFLOADING_ACTIVE, ConfigManager::get_state());
    }

    public function test_enable_and_disable_offloading(): void {
        ConfigManager::save_config([
            'cloud_provider'  => 'azure',
            'provider_config' => [
                'storage_account' => 'acc',
                'access_key'      => 'key',
                'container_name'  => 'cont',
            ],
        ]);
        ConfigManager::set_state(PluginState::SYNCED);

        $enabled = ConfigManager::enable_offloading();
        $this->assertTrue($enabled);
        $this->assertTrue(ConfigManager::is_offloading_enabled());

        $disabled = ConfigManager::disable_offloading();
        $this->assertTrue($disabled);
        $this->assertFalse(ConfigManager::is_offloading_enabled());
    }

    public function test_reset_clears_all_config(): void {
        ConfigManager::save_config([
            'cloud_provider'  => 'azure',
            'provider_config' => ['storage_account' => 'acc'],
        ]);
        ConfigManager::set_state(PluginState::CONFIGURED);

        $result = ConfigManager::reset();
        $this->assertTrue($result);

        $state = ConfigManager::get_state();
        $this->assertSame(PluginState::NOT_CONFIGURED, $state);

        $config = ConfigManager::get_config();
        $this->assertSame('', $config['cloud_provider']);
    }

    public function test_record_connection_failure_and_success(): void {
        ConfigManager::record_connection_failure('403', 'Forbidden', 'azure');

        $health = ConfigManager::get_connection_health();
        $this->assertSame('unhealthy', $health['status']);
        $this->assertSame(1, $health['consecutive_failures']);
        $this->assertSame('403', $health['error_code']);

        ConfigManager::record_connection_failure('500', 'Server Error', 'azure');
        $health = ConfigManager::get_connection_health();
        $this->assertSame(2, $health['consecutive_failures']);

        ConfigManager::record_connection_success();
        $health = ConfigManager::get_connection_health();
        $this->assertSame('healthy', $health['status']);
        $this->assertSame(0, $health['consecutive_failures']);
    }

    public function test_get_config_returns_defaults_when_not_configured(): void {
        $config = ConfigManager::get_config();

        $this->assertIsArray($config);
        $this->assertSame('', $config['cloud_provider']);
        $this->assertSame([], $config['provider_config']);
        $this->assertFalse($config['debug_enabled']);
        $this->assertTrue($config['keep_local_files']);
        $this->assertSame(60, $config['timeout']);
        // 20 MB default — see ConfigManager::DEFAULT_CONFIG. The mug-website-v2
        // version of this plugin used 500 MB; the current diluxone-offload
        // tightened it to a safer cap that fits most shared-hosting limits.
        $this->assertSame(20971520, $config['max_file_size']);
    }

    /**
     * update_option() returns false when the stored value is identical, and
     * that used to surface as "Failed to save settings to database" the
     * moment someone pressed Save without changing anything. Found by the
     * E2E suite on its first run.
     */
    public function test_saving_an_unchanged_config_is_still_a_success(): void {
        $config = [
            'cloud_provider'  => 'azure',
            'provider_config' => [
                'storage_account' => 'acct',
                'container_name'  => 'cont',
                'access_key'      => base64_encode(random_bytes(32)),
            ],
        ];

        $this->assertTrue(ConfigManager::save_config($config), 'first save writes');
        $this->assertTrue(ConfigManager::save_config($config), 'identical second save must not read as a failure');

        $settings = ConfigManager::get_plugin_settings();
        $this->assertTrue(ConfigManager::save_plugin_settings($settings), 'settings save with no changes');
        $this->assertTrue(ConfigManager::save_plugin_settings($settings), 'and again');
    }

    // ── Refusals: what is never stored ─────────────────────

    private function validAzure(): array {
        return [
            'cloud_provider'  => 'azure',
            'provider_config' => ['storage_account' => 'acct', 'container_name' => 'cont', 'access_key' => base64_encode(random_bytes(32))],
        ];
    }

    public function test_an_unknown_state_is_refused_and_the_state_is_unchanged(): void {
        ConfigManager::set_state(PluginState::SYNCED);
        $this->assertFalse(ConfigManager::set_state('error'));
        $this->assertSame(PluginState::SYNCED, ConfigManager::get_state());
    }

    public function test_a_real_state_change_stamps_offloading_since_and_clears_it_on_leaving(): void {
        ConfigManager::set_state(PluginState::SYNCED);
        ConfigManager::set_state(PluginState::OFFLOADING_ACTIVE);
        $this->assertGreaterThanOrEqual(time() - 5, ConfigManager::get_timestamps()['offloading_since']);
        ConfigManager::set_state(PluginState::SYNCED);
        $this->assertNull(ConfigManager::get_timestamps()['offloading_since']);
    }

    public function test_an_unsupported_provider_is_never_saved(): void {
        $this->assertFalse(ConfigManager::save_config(['cloud_provider' => 'dropbox', 'provider_config' => ['x' => 'y']]));
        $this->assertFalse(ConfigManager::save_provider_config(['cloud_provider' => 'dropbox', 'provider_config' => ['x' => 'y']]));
        $this->assertFalse(get_option('diluxone_offload_config'), 'nothing was written');
    }

    public function test_a_provider_config_missing_a_required_field_is_never_saved(): void {
        $incomplete = ['cloud_provider' => 'azure', 'provider_config' => ['storage_account' => 'acct', 'container_name' => 'cont']];
        $this->assertFalse(ConfigManager::save_config($incomplete));
        $this->assertFalse(ConfigManager::save_provider_config($incomplete));
        $this->assertSame('', ConfigManager::get_config()['cloud_provider']);
        $this->assertSame(PluginState::NOT_CONFIGURED, ConfigManager::get_state());
    }

    public function test_a_provider_config_that_is_not_an_array_is_refused(): void {
        $this->assertFalse(ConfigManager::save_provider_config(['cloud_provider' => 'azure', 'provider_config' => 'acct:key']));
        $this->assertFalse(ConfigManager::save_config(['cloud_provider' => 'azure', 'provider_config' => 'acct:key']));
    }

    public function test_settings_out_of_range_are_refused_and_the_saved_ones_kept(): void {
        $this->assertTrue(ConfigManager::save_plugin_settings(array_merge(ConfigManager::get_plugin_settings(), ['timeout' => 90])));
        $this->assertFalse(ConfigManager::save_plugin_settings(array_merge(ConfigManager::get_plugin_settings(), ['timeout' => -1])));
        $this->assertSame(90, ConfigManager::get_config()['timeout']);
    }

    public function test_a_corrupted_option_reads_as_the_defaults_instead_of_failing(): void {
        update_option('diluxone_offload_config', ['cloud_provider' => '', 'provider_config' => [], 'timeout' => -30]);
        $config = ConfigManager::get_config();
        $this->assertSame(60, $config['timeout'], 'defaults, not a fatal on every request');
        $this->assertSame('', $config['cloud_provider']);
    }

    public function test_the_credential_is_stored_encrypted_and_read_back_in_clear(): void {
        $config = $this->validAzure();
        ConfigManager::save_config($config);
        $raw = get_option('diluxone_offload_config');
        $this->assertStringStartsWith('DILUXONEOFFLOADENC1:', $raw['provider_config']['access_key']);
        $this->assertSame($config['provider_config']['access_key'], ConfigManager::get_config()['provider_config']['access_key']);
    }

    public function test_losing_the_configuration_drops_the_state_to_not_configured(): void {
        ConfigManager::save_config($this->validAzure());
        $this->assertSame(PluginState::CONFIGURED, ConfigManager::get_state());
        ConfigManager::save_config(['cloud_provider' => '', 'provider_config' => []]);
        $this->assertSame(PluginState::NOT_CONFIGURED, ConfigManager::get_state());
    }

    // ── Offloading switch ──────────────────────────────────

    public function test_offloading_cannot_be_enabled_before_a_sync(): void {
        ConfigManager::save_config($this->validAzure());
        ConfigManager::set_state(PluginState::CONFIGURED);
        $this->assertFalse(ConfigManager::enable_offloading());
        $this->assertFalse(ConfigManager::is_offloading_enabled());
        $this->assertSame(PluginState::CONFIGURED, ConfigManager::get_state());
    }

    public function test_disabling_offloading_that_is_not_on_reports_failure(): void {
        ConfigManager::set_state(PluginState::SYNCED);
        $this->assertFalse(ConfigManager::disable_offloading());
        $this->assertSame(PluginState::SYNCED, ConfigManager::get_state());
    }

    // ── Client, stats, connection test ─────────────────────

    public function test_no_client_and_no_cached_stats_without_a_provider(): void {
        $this->assertNull(ConfigManager::get_cloud_client());
        $this->assertNull(ConfigManager::get_cached_cloud_stats());
        $this->assertSame(['success' => false, 'message' => 'Cloud client not configured'], ConfigManager::test_connection());
    }

    public function test_a_saved_provider_builds_its_own_client_with_the_settings(): void {
        ConfigManager::save_config($this->validAzure());
        $client = ConfigManager::get_cloud_client();
        $this->assertInstanceOf(\DiluxOneOffload\Providers\AzureProvider::class, $client);
        ConfigManager::save_plugin_settings(array_merge(ConfigManager::get_plugin_settings(), ['timeout' => 75]));
        $this->assertSame(75, ConfigManager::client_settings()['upload_timeout'], 'the Transfer Timeout travels with the client');
    }

    public function test_cached_stats_come_from_the_providers_transient_only(): void {
        ConfigManager::save_config($this->validAzure());
        $this->assertNull(ConfigManager::get_cached_cloud_stats());
        set_transient(ConfigManager::STATS_TRANSIENTS['azure'], ['fileCount' => 7], 60);
        $this->assertSame(['success' => true, 'data' => ['fileCount' => 7]], ConfigManager::get_cached_cloud_stats());
        ConfigManager::record_connection_failure('500', 'HTTP 500', 'list_files');
        $this->assertNull(ConfigManager::get_cached_cloud_stats(), 'a failure makes the cached stats stale');
    }

    public function test_test_connection_asks_the_configured_client(): void {
        $client = new FaultyCloudClient('http://127.0.0.1:9');
        $client->connection_ok = false;
        $inject = static fn() => $client;
        add_filter('diluxone_offload_pre_cloud_client', $inject);
        try {
            $this->assertSame(['success' => false, 'message' => 'HTTP 403 fake refusal'], ConfigManager::test_connection());
        } finally {
            remove_filter('diluxone_offload_pre_cloud_client', $inject);
        }
    }

    // ── check_connection_health() ──────────────────────────

    private function withClient(FaultyCloudClient $client, callable $fn) {
        $inject = static fn() => $client;
        add_filter('diluxone_offload_pre_cloud_client', $inject);
        try {
            return $fn();
        } finally {
            remove_filter('diluxone_offload_pre_cloud_client', $inject);
        }
    }

    public function test_a_recent_check_is_not_repeated_unless_forced(): void {
        ConfigManager::save_config($this->validAzure());
        $client = new FaultyCloudClient('http://127.0.0.1:9');
        ConfigManager::record_connection_failure('403', 'HTTP 403', 'upload');
        $this->withClient($client, function () {
            $this->assertSame('unhealthy', ConfigManager::check_connection_health()['status'], 'checked a moment ago: the cached answer');
            $this->assertSame('healthy', ConfigManager::check_connection_health(true)['status'], 'Check now asks the provider');
        });
    }

    public function test_a_stale_check_records_the_providers_failure_with_its_code(): void {
        ConfigManager::save_config($this->validAzure());
        $client = new FaultyCloudClient('http://127.0.0.1:9');
        $client->connection_ok = false;
        $health = $this->withClient($client, fn() => ConfigManager::check_connection_health());
        $this->assertSame('unhealthy', $health['status']);
        $this->assertSame('403', $health['error_code']);
        $this->assertSame('health_check', $health['error_source']);
    }

    public function test_a_check_whose_provider_throws_is_recorded_as_an_exception(): void {
        ConfigManager::save_config($this->validAzure());
        $client = new FaultyCloudClient('http://127.0.0.1:9');
        $client->throws['test_connection'] = 'cURL error 6: Could not resolve host';
        $health = $this->withClient($client, fn() => ConfigManager::check_connection_health(true));
        $this->assertSame('exception', $health['error_code']);
        $this->assertSame('cURL error 6: Could not resolve host', $health['error_message']);
    }

    public function test_nothing_is_checked_without_a_configuration(): void {
        $client = new FaultyCloudClient('http://127.0.0.1:9');
        $client->connection_ok = false;
        $health = $this->withClient($client, fn() => ConfigManager::check_connection_health(true));
        $this->assertSame('unknown', $health['status']);
        $this->assertSame(0, $health['consecutive_failures']);
    }

    public function test_a_message_with_no_status_and_no_timeout_has_no_code(): void {
        $this->assertSame('', ConfigManager::error_code_from_message('Could not resolve host'));
        $this->assertSame('timeout', ConfigManager::error_code_from_message('Operation timed out after 60000 milliseconds'));
        $this->assertSame('503', ConfigManager::error_code_from_message('HTTP 503 on timeout-banner.jpg'));
    }

    // ── Failed files list ──────────────────────────────────

    public function test_failed_files_are_merged_by_path_and_entries_without_one_dropped(): void {
        ConfigManager::save_failed_files([['file' => '/a.jpg', 'error' => 'old']]);
        ConfigManager::add_failed_files([['file' => '/a.jpg', 'error' => 'new'], ['local_path' => '/b.jpg', 'error' => 'x'], ['error' => 'no path']]);
        $failed = ConfigManager::get_failed_files();
        $this->assertCount(2, $failed);
        $this->assertSame('new', $failed[0]['error'], 'the newer entry for a path wins');
        $this->assertSame('/b.jpg', $failed[1]['local_path']);
        ConfigManager::clear_failed_files();
        $this->assertSame([], ConfigManager::get_failed_files());
    }

    public function test_sync_progress_round_trips_and_clears(): void {
        $this->assertNull(ConfigManager::get_sync_progress());
        $this->assertTrue(ConfigManager::save_sync_progress(['session_id' => 's1']));
        $this->assertSame(['session_id' => 's1'], ConfigManager::get_sync_progress());
        $this->assertTrue(ConfigManager::clear_sync_progress());
        $this->assertNull(ConfigManager::get_sync_progress());
    }
}
