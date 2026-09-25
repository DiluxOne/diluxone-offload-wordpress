<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\Enums\PluginState;

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
}
