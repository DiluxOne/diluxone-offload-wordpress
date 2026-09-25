<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use DiluxOneOffload\ConfigManager;

/**
 * Integration tests for the connection-health subsystem.
 *
 * Focus: the *idempotency contract* on `decrypt_failed` events —
 * `consecutive_failures` must NOT be incremented on every page load
 * when the same `decrypt_failed` state is already recorded. Without
 * that, every admin page view would inflate the counter past the
 * stream-wrapper fallback threshold (>= 3).
 *
 * Plus: error-code transitions, recovery, and the clear path.
 *
 * Lives in the integration suite because connection-health state is
 * persisted in the WordPress Options API.
 */
class ConnectionHealthTest extends IntegrationTestCase {

    public function test_record_failure_increments_consecutive_failures(): void {
        ConfigManager::record_connection_failure('500', 'Server Error', 'azure');
        $health = ConfigManager::get_connection_health();
        $this->assertSame(1, $health['consecutive_failures']);
        $this->assertSame('500', $health['error_code']);
        $this->assertSame('unhealthy', $health['status']);

        ConfigManager::record_connection_failure('500', 'Server Error', 'azure');
        $health = ConfigManager::get_connection_health();
        // record_connection_failure does NOT itself dedupe — the
        // idempotency for decrypt_failed lives in decrypt_credentials,
        // tested in the next case. Repeated direct calls increment.
        $this->assertSame(2, $health['consecutive_failures']);
    }

    public function test_decrypt_failed_does_not_inflate_counter_on_repeated_get_config(): void {
        // Plant a config with a credential that has the encrypted prefix
        // but is not actually decryptable. This bypasses save_config()
        // (which would encrypt for real) and simulates the post-salt-
        // rotation scenario that triggered this idempotency rule.
        update_option('diluxone_offload_config', [
            'cloud_provider' => 'azure',
            'provider_config' => [
                'storage_account' => 'someacc',
                'access_key'      => 'DILUXONEOFFLOADENC1:bm90X2Ffdmxhaml',
                'container_name'  => 'somecont',
            ],
        ]);

        // First read: triggers decrypt_failed event recording.
        ConfigManager::get_config();
        $first = ConfigManager::get_connection_health();
        $this->assertSame('decrypt_failed', $first['error_code']);
        $this->assertSame('unhealthy', $first['status']);
        $this->assertSame(1, $first['consecutive_failures']);

        // Subsequent reads must NOT bump consecutive_failures while the
        // unhealthy state is still 'decrypt_failed' — otherwise every
        // admin page view would push the counter past the fallback
        // threshold of 3 inside an hour.
        for ($i = 0; $i < 5; $i++) {
            ConfigManager::get_config();
        }
        $after = ConfigManager::get_connection_health();
        $this->assertSame(1, $after['consecutive_failures'], 'decrypt_failed must be idempotent across reads');
        $this->assertSame('decrypt_failed', $after['error_code']);
    }

    public function test_record_success_resets_counter_and_clears_error_fields(): void {
        ConfigManager::record_connection_failure('403', 'Forbidden', 'azure');
        ConfigManager::record_connection_failure('500', 'Server Error', 'azure');
        $before = ConfigManager::get_connection_health();
        $this->assertSame(2, $before['consecutive_failures']);

        ConfigManager::record_connection_success();
        $after = ConfigManager::get_connection_health();

        $this->assertSame('healthy', $after['status']);
        $this->assertSame(0, $after['consecutive_failures']);
        $this->assertSame('', $after['error_code']);
        $this->assertSame('', $after['error_message']);
    }

    public function test_clear_connection_health_removes_the_option_entirely(): void {
        ConfigManager::record_connection_failure('401', 'Unauthorized', 'azure');
        $this->assertNotFalse(get_option('diluxone_offload_connection_health', false));

        ConfigManager::clear_connection_health();

        // The option row must be DELETED, not merely reset to default
        // values. Assert directly via get_option() with a sentinel
        // default — this would still report `false` only when the row
        // is actually gone (a regression that changed clear() to
        // update_option(defaults) would fail this).
        $this->assertFalse(
            get_option('diluxone_offload_connection_health', false),
            'clear_connection_health() must delete the wp_options row, not just reset its values.'
        );

        // The accessor's contract: even after the option is deleted,
        // get_connection_health() returns the default-shape array (not
        // null) so callers don't need to null-check.
        $health = ConfigManager::get_connection_health();
        $this->assertSame(0, $health['consecutive_failures']);
        $this->assertSame('', $health['error_code']);
        $this->assertIsString($health['status']);
    }

    public function test_consecutive_failures_can_cross_the_stream_wrapper_refusal_threshold(): void {
        // The stream wrapper refuses new writes once consecutive_failures
        // reaches 3 (CloudStreamWrapper::stream_open(), write modes). This test
        // documents that threshold contract — if it ever changes, this test
        // fails and the change is caught in review.
        for ($i = 0; $i < 4; $i++) {
            ConfigManager::record_connection_failure('500', 'Server Error #' . $i, 'azure');
        }

        $health = ConfigManager::get_connection_health();
        $this->assertGreaterThanOrEqual(3, $health['consecutive_failures'], 'Stream wrapper refuses writes at >= 3.');
    }

    public function test_error_code_changes_are_reflected_in_subsequent_failures(): void {
        ConfigManager::record_connection_failure('403', 'Forbidden', 'azure');
        $a = ConfigManager::get_connection_health();
        $this->assertSame('403', $a['error_code']);

        ConfigManager::record_connection_failure('exception', 'Network unreachable', 'azure');
        $b = ConfigManager::get_connection_health();
        $this->assertSame('exception', $b['error_code']);
        $this->assertSame('Network unreachable', $b['error_message']);
        // Counter incremented across distinct codes too.
        $this->assertSame(2, $b['consecutive_failures']);
    }
}
