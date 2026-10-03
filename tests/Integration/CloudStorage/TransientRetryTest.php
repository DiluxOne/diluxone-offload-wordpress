<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use Tests\Integration\ScriptedHttp;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\CloudStreamWrapper;
use DiluxOneOffload\Enums\PluginState;

/**
 * What the real Backblaze B2 suite found: a thumbnail WordPress writes
 * through the stream wrapper met one 500 "internal incident" and never
 * reached the bucket. With the providers retrying transient answers, the
 * write goes through on the second PUT, and the site sees nothing of it.
 */
class TransientRetryTest extends IntegrationTestCase {
    use ScriptedHttp;

    protected function setUp(): void {
        parent::setUp();
        ConfigManager::save_config([
            'cloud_provider'  => 's3',
            'provider_config' => [
                'preset'            => 'custom',
                'endpoint'          => 'https://s3.retry.test',
                'region'            => 'us-east-1',
                'bucket'            => 'media',
                'access_key_id'     => 'AKIDRETRY',
                'secret_access_key' => 'retry-secret',
                'public_url'        => 'https://cdn.retry.test',
                'path_style'        => true,
            ],
        ]);
        ConfigManager::set_state(PluginState::SYNCED);
        CloudStreamWrapper::clear_stat_cache();
        CloudStreamWrapper::clear_file_cache();
        $this->assertTrue(CloudStreamWrapper::activate_offloading());
    }

    protected function tearDown(): void {
        $this->unhookHttp();
        CloudStreamWrapper::deactivate_offloading();
        CloudStreamWrapper::clear_stat_cache();
        CloudStreamWrapper::clear_file_cache();
        parent::tearDown();
    }

    /** @param int[] $put_answers What the PUTs get, in turn. */
    private function scriptPuts(array $put_answers): void {
        $this->scriptHttp(function (string $method) use (&$put_answers) {
            if ($method === 'PUT') {
                return self::httpReply(array_shift($put_answers) ?? 200, 'internal incident');
            }
            return self::httpReply(404);
        });
    }

    public function test_a_write_through_the_wrapper_survives_one_internal_error(): void {
        $this->scriptPuts([500, 200]);
        $path = wp_upload_dir()['basedir'] . '/2026/09/photo-150x150.png';
        $this->assertStringStartsWith('diluxoneoffload://', $path);
        $this->assertSame(4, file_put_contents($path, 'fake'));
        $puts = $this->httpRequests('PUT');
        $this->assertCount(2, $puts, 'the 500 was asked again');
        $this->assertStringEndsWith('/media/uploads/2026/09/photo-150x150.png', $puts[1]['url']);
        $this->assertNotSame('unhealthy', ConfigManager::get_connection_health()['status'] ?? '', 'a retried write is not a connection failure');
    }

    /**
     * What the real Google suite found: a delete right after WordPress wrote
     * the file (an edited image, a thumbnail) met a 429, as Google allows one
     * change a second to the same object, and the object stayed stored.
     */
    public function test_a_delete_through_the_wrapper_the_service_throttles_goes_through_after_a_pause(): void {
        $deletes = [429, 204];
        $this->scriptHttp(function (string $method) use (&$deletes) {
            if ($method === 'DELETE') {
                return self::httpReply(array_shift($deletes) ?? 204, '<Error><Code>SlowDown</Code><Message>Please reduce your request rate.</Message></Error>');
            }
            return self::httpReply(404);
        });
        $started = hrtime(true);
        $this->assertTrue(unlink(wp_upload_dir()['basedir'] . '/2026/10/photo-e1791012026924.png'));
        $this->assertCount(2, $this->httpRequests('DELETE'), 'the 429 was asked again');
        $this->assertGreaterThanOrEqual(1.0, (hrtime(true) - $started) / 1e9, 'after at least a second');
    }

    public function test_three_internal_errors_in_a_row_are_recorded_as_a_failed_upload(): void {
        $this->scriptPuts([500, 503, 500]);
        $path = wp_upload_dir()['basedir'] . '/2026/09/photo-300x300.png';
        // PHP ignores what a wrapper's close returns, so file_put_contents()
        // cannot say it; the wrapper records the failure for the screens.
        @file_put_contents($path, 'fake');
        $this->assertCount(3, $this->httpRequests('PUT'), 'three attempts, no more');
        $health = ConfigManager::get_connection_health();
        $this->assertSame('unhealthy', $health['status']);
        $this->assertSame('upload', $health['error_source']);
        $this->assertStringContainsString('500', $health['error_message'], 'the last answer is the one reported');
    }
}
