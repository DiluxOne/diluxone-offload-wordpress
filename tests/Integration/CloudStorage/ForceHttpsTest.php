<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use Tests\Integration\FakeCloudClient;
use DiluxOneOffload\CloudStreamWrapper;
use DiluxOneOffload\ConfigManager;

/**
 * What "Force HTTPS for cloud storage URLs" does.
 *
 * The client hands out http:// URLs, the way WordPress downgrades them on
 * a site served over plain http; the setting must re-issue exactly those,
 * on the cloud host and nowhere else, through the four filters the plugin
 * registers. The host is whatever the client's URL says, which is what
 * makes the setting work for any provider.
 */
class ForceHttpsTest extends IntegrationTestCase {

    private ?FakeCloudClient $client = null;

    protected function setUp(): void {
        parent::setUp();
        $this->client = new FakeCloudClient('http://127.0.0.1:1', 'http://fake.cloud');
        add_filter('diluxone_offload_pre_cloud_client', [$this, 'injectClient']);
        $this->configure(true);
    }

    protected function tearDown(): void {
        remove_filter('diluxone_offload_pre_cloud_client', [$this, 'injectClient']);
        CloudStreamWrapper::unregister_force_https_filters();
        parent::tearDown();
    }

    public function injectClient($pre) {
        return $this->client;
    }

    private function configure(bool $force): void {
        ConfigManager::save_config([
            'cloud_provider'       => 'azure',
            'provider_config'      => ['storage_account' => 'httpsacct', 'container_name' => 'media', 'access_key' => base64_encode(random_bytes(32))],
            'force_https_on_cloud' => $force,
        ]);
        self::resetWrapperClient();
        CloudStreamWrapper::register_force_https_filters();
    }

    public function test_the_cloud_host_is_the_host_of_the_url_the_client_hands_out(): void {
        $this->assertSame('fake.cloud', CloudStreamWrapper::get_cloud_host());
    }

    public function test_on_it_reissues_attachment_urls_on_the_cloud_host_as_https(): void {
        $this->assertSame('https://fake.cloud/uploads/2026/09/a.jpg', apply_filters('wp_get_attachment_url', 'http://fake.cloud/uploads/2026/09/a.jpg', 0));
    }

    public function test_on_it_leaves_other_hosts_alone(): void {
        $this->assertSame('http://example.org/wp-content/themes/x/a.css', apply_filters('style_loader_src', 'http://example.org/wp-content/themes/x/a.css', 'x'));
    }

    public function test_on_it_rewrites_every_entry_of_a_srcset_and_the_enqueued_assets(): void {
        $sources = apply_filters('wp_calculate_image_srcset', [
            300 => ['url' => 'http://fake.cloud/uploads/a-300.jpg', 'descriptor' => 'w', 'value' => 300],
            600 => ['url' => 'http://fake.cloud/uploads/a-600.jpg', 'descriptor' => 'w', 'value' => 600],
        ]);
        $this->assertSame('https://fake.cloud/uploads/a-300.jpg', $sources[300]['url']);
        $this->assertSame('https://fake.cloud/uploads/a-600.jpg', $sources[600]['url']);
        $this->assertSame('https://fake.cloud/uploads/site.css', apply_filters('style_loader_src', 'http://fake.cloud/uploads/site.css', 'site'));
        $this->assertSame('https://fake.cloud/uploads/site.js', apply_filters('script_loader_src', 'http://fake.cloud/uploads/site.js', 'site'));
    }

    public function test_an_https_url_is_untouched(): void {
        $this->assertSame('https://fake.cloud/uploads/a.jpg', apply_filters('wp_get_attachment_url', 'https://fake.cloud/uploads/a.jpg', 0));
    }

    public function test_off_it_leaves_the_http_url_as_the_client_gave_it(): void {
        $this->configure(false);
        $this->assertSame('http://fake.cloud/uploads/2026/09/a.jpg', apply_filters('wp_get_attachment_url', 'http://fake.cloud/uploads/2026/09/a.jpg', 0));
    }

    public function test_without_a_client_there_is_no_cloud_host_and_nothing_is_rewritten(): void {
        remove_filter('diluxone_offload_pre_cloud_client', [$this, 'injectClient']);
        delete_option('diluxone_offload_config');
        self::resetWrapperClient();
        $this->assertSame('', CloudStreamWrapper::get_cloud_host());
        $this->assertSame('http://fake.cloud/uploads/a.jpg', apply_filters('wp_get_attachment_url', 'http://fake.cloud/uploads/a.jpg', 0));
    }
}
