<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\Enums\PluginState;
use WPAjaxDieContinueException;

/**
 * Integration tests for AJAX handlers.
 *
 * Tests nonce verification, capability checks, and basic handler flow.
 * AJAX handlers are tested by simulating $_POST and dispatching the WP
 * action directly (not via HTTP).
 *
 * wp_die() is intercepted globally in bootstrap-integration.php to throw
 * WPAjaxDieContinueException instead of calling die().
 */
class AjaxHandlersTest extends IntegrationTestCase {

    /** @var int Admin user ID created per-test. */
    private int $admin_user_id = 0;

    protected function setUp(): void {
        parent::setUp();

        // Create an admin user so capability checks succeed. wp_insert_user
        // returns WP_Error on failure (e.g. username collision); guard the
        // typed int property assignment so we get a readable failure message
        // rather than a TypeError further down the test.
        $created = wp_insert_user([
            'user_login' => 'test_admin_' . wp_generate_password(6, false),
            'user_pass'  => wp_generate_password(12),
            'role'       => 'administrator',
        ]);
        if (is_wp_error($created)) {
            $this->fail('wp_insert_user (admin) failed: ' . $created->get_error_message());
        }
        $this->admin_user_id = (int) $created;

        // Reset request globals between tests.
        $_POST = [];
        $_REQUEST = [];
    }

    protected function tearDown(): void {
        if ($this->admin_user_id) {
            wp_delete_user($this->admin_user_id);
        }

        $_POST = [];
        $_REQUEST = [];
        wp_set_current_user(0);

        parent::tearDown();
    }

    public function test_ajax_handler_rejects_without_nonce(): void {
        wp_set_current_user($this->admin_user_id);

        $_POST = [];

        $this->expectException(WPAjaxDieContinueException::class);
        do_action('wp_ajax_diluxone_offload_get_sync_state');
    }

    public function test_ajax_handler_rejects_without_capability(): void {
        $created = wp_insert_user([
            'user_login' => 'test_subscriber_' . wp_generate_password(6, false),
            'user_pass'  => wp_generate_password(12),
            'role'       => 'subscriber',
        ]);
        if (is_wp_error($created)) {
            $this->fail('wp_insert_user (subscriber) failed: ' . $created->get_error_message());
        }
        $subscriber_id = (int) $created;
        wp_set_current_user($subscriber_id);

        // Subscriber CAN create the same nonce — capabilities are
        // verified separately, after the nonce check passes.
        $nonce = wp_create_nonce('diluxone_offload_admin');
        $_POST['nonce'] = $nonce;
        $_REQUEST['nonce'] = $nonce;

        $this->expectException(WPAjaxDieContinueException::class);

        try {
            do_action('wp_ajax_diluxone_offload_get_sync_state');
        } finally {
            wp_delete_user($subscriber_id);
        }
    }

    public function test_get_sync_state_executes_with_valid_auth(): void {
        wp_set_current_user($this->admin_user_id);
        ConfigManager::set_state(PluginState::CONFIGURED);

        $nonce = wp_create_nonce('diluxone_offload_admin');
        $_POST['nonce'] = $nonce;
        $_REQUEST['nonce'] = $nonce;
        $_POST['session_id'] = 'test-session-123';

        ob_start();
        $exception_message = '';
        try {
            do_action('wp_ajax_diluxone_offload_get_sync_state');
        } catch (WPAjaxDieContinueException $e) {
            $exception_message = $e->getMessage();
        }
        $output = ob_get_clean();

        $this->assertNotSame('-1', $exception_message, 'Handler should pass nonce check');
        $this->assertNotEmpty($output, 'Handler should produce output');
    }

    public function test_save_updated_credentials_persists_config(): void {
        wp_set_current_user($this->admin_user_id);

        // The save_updated_credentials handler refuses to persist
        // credentials unless the matching connection-test transient
        // is present (proves the user just clicked Test Connection
        // with these credentials and they validated).
        set_transient('diluxone_offload_connection_test_passed_' . $this->admin_user_id, [
            'account_name'   => 'teststorage',
            'container_name' => 'testcontainer',
            'provider'       => 'azure',
        ], 300);

        $nonce = wp_create_nonce('diluxone_offload_admin');
        $_POST['nonce'] = $nonce;
        $_REQUEST['nonce'] = $nonce;
        $_POST['provider'] = 'azure';
        $_POST['account_name'] = 'teststorage';
        $_POST['account_key'] = 'testkey123';
        $_POST['container_name'] = 'testcontainer';

        ob_start();
        try {
            do_action('wp_ajax_diluxone_offload_save_updated_credentials');
        } catch (WPAjaxDieContinueException $e) {
            // Expected — wp_send_json calls wp_die.
        }
        ob_get_clean();

        $config = ConfigManager::get_config();
        $this->assertSame('azure', $config['cloud_provider']);

        $state = ConfigManager::get_state();
        $this->assertSame(PluginState::CONFIGURED, $state);
    }

    public function test_start_sync_executes_with_valid_auth(): void {
        wp_set_current_user($this->admin_user_id);

        ConfigManager::set_state(PluginState::CONFIGURED);

        $nonce = wp_create_nonce('diluxone_offload_admin');
        $_POST['nonce'] = $nonce;
        $_REQUEST['nonce'] = $nonce;
        $_POST['session_id'] = 'test-session-456';

        ob_start();
        $exception_message = '';
        try {
            do_action('wp_ajax_diluxone_offload_start_sync');
        } catch (WPAjaxDieContinueException $e) {
            $exception_message = $e->getMessage();
        }
        $output = ob_get_clean();

        $this->assertNotSame('-1', $exception_message, 'Handler should pass nonce check');
        $this->assertNotEmpty($output, 'Handler should produce output');
    }

    public function test_nonce_verification_uses_diluxone_offload_admin(): void {
        wp_set_current_user($this->admin_user_id);

        $wrong_nonce = wp_create_nonce('wrong_action');
        $_POST['nonce'] = $wrong_nonce;
        $_REQUEST['nonce'] = $wrong_nonce;

        $this->expectException(WPAjaxDieContinueException::class);
        do_action('wp_ajax_diluxone_offload_get_sync_state');
    }
}
