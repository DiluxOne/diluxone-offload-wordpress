<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\SiteHealth;
use DiluxOneOffload\Enums\PluginState;

/**
 * Tools › Site Health: the test reports the connection health the plugin
 * keeps (without contacting the storage) and the Info section names the
 * provider, never a key.
 */
class SiteHealthTest extends IntegrationTestCase {

    protected function setUp(): void {
        parent::setUp();
        // The plugin hooks it on admin requests only; a test is not one.
        if (!has_filter('site_status_tests', [SiteHealth::class, 'register_test'])) {
            SiteHealth::init();
        }
    }

    private function configure(): void {
        ConfigManager::save_config([
            'cloud_provider'  => 's3',
            'provider_config' => ['preset' => 'r2', 'region' => 'auto', 'endpoint' => 'https://acct.r2.cloudflarestorage.com', 'bucket' => 'b', 'access_key_id' => 'AKIDSITEHEALTH', 'secret_access_key' => 'never-shown-secret', 'public_url' => 'https://pub.r2.dev', 'path_style' => true],
        ]);
        ConfigManager::set_state(PluginState::OFFLOADING_ACTIVE);
    }

    public function test_the_test_is_registered_among_the_direct_ones(): void {
        $tests = apply_filters('site_status_tests', ['direct' => [], 'async' => []]);
        $this->assertArrayHasKey('diluxone_offload_connection', $tests['direct']);
    }

    public function test_without_a_provider_the_test_is_good_and_says_so(): void {
        $r = SiteHealth::connection_test();
        $this->assertSame('good', $r['status']);
        $this->assertStringContainsString('no storage configured', $r['label']);
    }

    public function test_healthy_names_the_service_and_unhealthy_is_critical_with_the_reason(): void {
        $this->configure();
        ConfigManager::record_connection_success();
        $r = SiteHealth::connection_test();
        $this->assertSame('good', $r['status']);
        $this->assertStringContainsString('Cloudflare R2', $r['description']);

        for ($i = 0; $i < 3; $i++) {
            ConfigManager::record_connection_failure('403', 'the keys cannot write to this bucket', 'upload');
        }
        $r = SiteHealth::connection_test();
        $this->assertSame('critical', $r['status']);
        $this->assertStringContainsString('paused', $r['label']);
        $this->assertStringContainsString('403 the keys cannot write to this bucket', $r['description']);
        $this->assertStringContainsString('page=diluxone-offload-status', $r['actions']);
        ConfigManager::clear_connection_health();
    }

    public function test_the_info_section_names_the_provider_and_never_a_key(): void {
        $this->configure();
        $info = apply_filters('debug_information', []);
        $this->assertArrayHasKey('diluxone-offload', $info);
        $fields = $info['diluxone-offload']['fields'];
        $this->assertSame('Cloudflare R2', $fields['service']['value']);
        $this->assertSame(DILUXONE_OFFLOAD_VERSION, $fields['version']['value']);
        $dump = wp_json_encode($info['diluxone-offload']);
        $this->assertStringNotContainsString('never-shown-secret', $dump);
        $this->assertStringNotContainsString('AKIDSITEHEALTH', $dump);
    }
}
