<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use Tests\Integration\FakeCloudClient;
use DiluxOneOffload\CloudStreamWrapper;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\Enums\PluginState;
use DiluxOneOffload\Logger;

/**
 * What the debug logging toggle does, on a real upload.
 *
 * PHP's error_log is pointed at a file, a file goes through the stream
 * wrapper to the fake client, and the file is read back: with the toggle
 * off the upload leaves no informational line; with it on it does; and
 * the access key is on no line either way. The unit suite proves the
 * gates in isolation; this proves them on the path a site actually runs.
 */
class LoggingTest extends IntegrationTestCase {

    private ?FakeCloudClient $client = null;
    private string $sink = '';
    private string $previous_sink = '';
    private string $key = '';

    protected function setUp(): void {
        parent::setUp();
        $this->sink          = tempnam(sys_get_temp_dir(), 'dlx-log-');
        $this->previous_sink = (string) ini_get('error_log');
        ini_set('error_log', $this->sink);
        $this->client = new FakeCloudClient('http://127.0.0.1:1');
        add_filter('diluxone_offload_pre_cloud_client', [$this, 'injectClient']);
        $this->key = base64_encode(random_bytes(32));
        ConfigManager::set_state(PluginState::OFFLOADING_ACTIVE);
    }

    protected function tearDown(): void {
        remove_filter('diluxone_offload_pre_cloud_client', [$this, 'injectClient']);
        CloudStreamWrapper::unregister();
        ini_set('error_log', $this->previous_sink);
        @unlink($this->sink);
        Logger::refresh();
        parent::tearDown();
    }

    public function injectClient($pre) {
        return $this->client;
    }

    private function configure(bool $debug): void {
        ConfigManager::save_config([
            'cloud_provider'  => 'azure',
            'provider_config' => ['storage_account' => 'logacct', 'container_name' => 'media', 'access_key' => $this->key],
            'debug_enabled'   => $debug,
        ]);
        Logger::refresh();
    }

    private function upload(string $name): void {
        CloudStreamWrapper::register();
        $this->assertNotFalse(file_put_contents('diluxoneoffload://' . CloudStreamWrapper::key_prefix() . '/2026/09/' . $name, 'logged bytes ' . uniqid()));
        $this->assertArrayHasKey('uploads/2026/09/' . $name, $this->client->blobs);
    }

    private function written(): string {
        return (string) file_get_contents($this->sink);
    }

    public function test_with_the_toggle_off_an_upload_leaves_no_informational_line(): void {
        $this->configure(false);
        $this->upload('quiet.txt');
        $this->assertStringNotContainsString('[DiluxOne Offload CloudStreamWrapper]', $this->written());
    }

    public function test_with_the_toggle_on_the_upload_is_logged(): void {
        $this->configure(true);
        $this->upload('loud.txt');
        $this->assertStringContainsString('[DiluxOne Offload CloudStreamWrapper]', $this->written());
        $this->assertStringContainsString('loud.txt', $this->written());
    }

    public function test_the_access_key_is_never_written_whatever_the_toggle(): void {
        $this->configure(true);
        $this->upload('secret.txt');
        $this->client->upload_status = 500;
        $this->configure(true);
        $this->assertStringNotContainsString($this->key, $this->written());
        $this->assertStringNotContainsString(substr($this->key, 0, 12), $this->written());
    }
}
