<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use Tests\Integration\ScriptedHttp;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\Enums\PluginState;

/**
 * Settings › Serving reaches the requests: the client the plugin builds from
 * its saved configuration writes new uploads with the Cache-Control header
 * and the storage class the settings hold, and without them when they are off.
 */
class NewUploadHeadersTest extends IntegrationTestCase {

    use ScriptedHttp;

    /** @var string[] */
    private array $files = [];

    protected function tearDown(): void {
        $this->unhookHttp();
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function upload(string $provider, array $provider_config): array {
        ConfigManager::save_config(['cloud_provider' => $provider, 'provider_config' => $provider_config]);
        ConfigManager::set_state(PluginState::CONFIGURED);
        $this->scriptHttp(fn() => self::httpReply('azure' === $provider ? 201 : 200));
        $file = wp_tempnam('headers');
        file_put_contents($file, 'twelve bytes');
        $this->files[] = $file;
        $client = ConfigManager::get_cloud_client();
        $this->assertNotNull($client);
        $this->assertTrue($client->upload_file($file, 'uploads/2026/09/pic.jpg', ['mime_type_from_path' => 'pic.jpg'])['success']);
        return $this->httpRequests('PUT')[0]['args']['headers'];
    }

    private function s3(string $preset): array {
        return ['preset' => $preset, 'region' => 'us-east-1', 'endpoint' => 'https://s3.us-east-1.amazonaws.com', 'bucket' => 'b', 'access_key_id' => 'AKID', 'secret_access_key' => 'secret', 'public_url' => 'https://b.s3.amazonaws.com', 'path_style' => true];
    }

    public function test_by_default_a_new_upload_is_cached_for_a_week_in_the_standard_class(): void {
        $headers = $this->upload('s3', $this->s3('aws'));
        $this->assertSame('public, max-age=604800', $headers['Cache-Control']);
        $this->assertArrayNotHasKey('x-amz-storage-class', $headers);
    }

    public function test_the_saved_settings_reach_an_s3_upload(): void {
        ConfigManager::save_plugin_settings(['cache_control_enabled' => true, 'cache_control' => 'public, max-age=60', 'storage_class' => 'infrequent']);
        $headers = $this->upload('s3', $this->s3('aws'));
        $this->assertSame('public, max-age=60', $headers['Cache-Control']);
        $this->assertSame('STANDARD_IA', $headers['x-amz-storage-class']);
    }

    public function test_caching_off_sends_no_cache_control(): void {
        ConfigManager::save_plugin_settings(['cache_control_enabled' => false]);
        $headers = $this->upload('s3', $this->s3('b2'));
        $this->assertArrayNotHasKey('Cache-Control', $headers);
    }

    public function test_the_saved_settings_reach_an_azure_upload(): void {
        ConfigManager::save_plugin_settings(['storage_class' => 'infrequent']);
        $headers = $this->upload('azure', ['storage_account' => 'hdracct', 'container_name' => 'media', 'access_key' => base64_encode(random_bytes(32))]);
        $this->assertSame('public, max-age=604800', $headers['x-ms-blob-cache-control']);
        $this->assertSame('Cool', $headers['x-ms-access-tier']);
    }
}
