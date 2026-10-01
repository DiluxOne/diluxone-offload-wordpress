<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\Enums\PluginState;

/**
 * The e-mails to the site's administrator when uploads pause (the third
 * failure in a row while offloading is on) and when they resume. One per
 * transition, never one per failure; off with Settings › Logging's switch;
 * never a key in the message.
 */
class HealthNotificationTest extends IntegrationTestCase {

    /** @var array<int, array{to:mixed,subject:string,message:string}> */
    private array $mails = [];

    /** @var bool|null What the mail transport answers (null: let wp_mail run). */
    private ?bool $transport = true;

    private string $secret = '';
    private string $admin_email = '';

    protected function setUp(): void {
        parent::setUp();
        $this->secret      = base64_encode(random_bytes(32));
        $this->admin_email = (string) get_option('admin_email');
        ConfigManager::save_config([
            'cloud_provider'  => 'azure',
            'provider_config' => ['storage_account' => 'mailacct', 'container_name' => 'media', 'access_key' => $this->secret],
        ]);
        ConfigManager::set_state(PluginState::OFFLOADING_ACTIVE);
        add_filter('pre_wp_mail', [$this, 'captureMail'], 10, 2);
    }

    protected function tearDown(): void {
        remove_filter('pre_wp_mail', [$this, 'captureMail'], 10);
        update_option('admin_email', $this->admin_email);
        parent::tearDown();
    }

    public function captureMail($pre, array $atts) {
        $this->mails[] = ['to' => $atts['to'], 'subject' => (string) $atts['subject'], 'message' => (string) $atts['message']];
        return $this->transport;
    }

    private function failTimes(int $n, string $code = '403', string $message = 'HTTP 403 AuthenticationFailed'): void {
        for ($i = 0; $i < $n; $i++) {
            ConfigManager::record_connection_failure($code, $message, 'upload');
        }
    }

    public function test_the_third_failure_while_offloading_sends_one_paused_mail(): void {
        $this->failTimes(2);
        $this->assertCount(0, $this->mails, 'two failures are not a pause');

        $this->failTimes(1);
        $this->assertCount(1, $this->mails);
        $mail = $this->mails[0];
        $this->assertSame($this->admin_email, $mail['to']);
        $this->assertStringContainsString('paused', $mail['subject']);
        $this->assertStringContainsString(wp_specialchars_decode(get_option('blogname'), ENT_QUOTES), $mail['subject']);
        $this->assertStringContainsString('403 HTTP 403 AuthenticationFailed', $mail['message']);
        $this->assertStringContainsString('page=diluxone-offload-status&tab=health', $mail['message']);
        $this->assertStringNotContainsString($this->secret, $mail['message'], 'never the key');
        $this->assertTrue(ConfigManager::get_connection_health()['paused_notified']);

        $this->failTimes(3);
        $this->assertCount(1, $this->mails, 'later failures of the same pause send nothing');
    }

    public function test_the_success_after_an_announced_pause_sends_one_resumed_mail(): void {
        $this->failTimes(3);
        ConfigManager::record_connection_success();
        ConfigManager::record_connection_success();

        $this->assertCount(2, $this->mails);
        $this->assertStringContainsString('resumed', $this->mails[1]['subject']);
        $this->assertStringContainsString('reaches your storage again', $this->mails[1]['message']);
        $this->assertFalse(ConfigManager::get_connection_health()['paused_notified']);
    }

    public function test_a_success_without_an_announced_pause_sends_nothing(): void {
        $this->failTimes(2);
        ConfigManager::record_connection_success();
        $this->assertCount(0, $this->mails);
    }

    public function test_no_mail_while_offloading_is_off_and_the_pause_is_announced_once_it_is_on(): void {
        ConfigManager::set_state(PluginState::SYNCED);
        $this->failTimes(4);
        $this->assertCount(0, $this->mails, 'uploads are not refused, so there is nothing to announce');
        $this->assertEmpty(ConfigManager::get_connection_health()['paused_notified']);

        ConfigManager::set_state(PluginState::OFFLOADING_ACTIVE);
        $this->failTimes(1);
        $this->assertCount(1, $this->mails);
        $this->assertStringContainsString('paused', $this->mails[0]['subject']);
    }

    public function test_the_switch_off_sends_nothing_and_leaves_the_pause_unannounced(): void {
        $this->assertTrue(ConfigManager::save_plugin_settings(array_merge(ConfigManager::get_plugin_settings(), ['notify_email' => false])));
        $this->assertFalse(ConfigManager::get_config()['notify_email']);

        $this->failTimes(3);

        $this->assertCount(0, $this->mails);
        $this->assertFalse(ConfigManager::get_connection_health()['paused_notified'], 'a pause kept quiet is announced later, once the switch is on');

        ConfigManager::save_plugin_settings(array_merge(ConfigManager::get_plugin_settings(), ['notify_email' => true]));
        $this->failTimes(1);
        $this->assertCount(1, $this->mails);
    }

    public function test_no_valid_admin_address_sends_nothing(): void {
        global $wpdb;
        // update_option() sanitizes admin_email; write the broken value as a
        // site upgraded from somewhere else could have it.
        $wpdb->update($wpdb->options, ['option_value' => 'not-an-address'], ['option_name' => 'admin_email']);
        wp_cache_delete('admin_email', 'options');
        wp_cache_delete('alloptions', 'options');

        $this->failTimes(3);

        $this->assertCount(0, $this->mails);
        $this->assertFalse(ConfigManager::get_connection_health()['paused_notified']);
    }

    public function test_a_mail_the_server_refuses_is_not_retried_on_every_failure(): void {
        $this->transport = false;
        $this->failTimes(3);
        $this->assertCount(1, $this->mails, 'attempted once');
        $this->assertTrue(ConfigManager::get_connection_health()['paused_notified'], 'counted as announced');
        $this->failTimes(2);
        $this->assertCount(1, $this->mails);
    }
}
