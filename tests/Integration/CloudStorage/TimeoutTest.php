<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use Tests\Integration\LocalBlobServer;
use DiluxOneOffload\CloudStreamWrapper;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\DiluxOneOffloadDB as DB;
use DiluxOneOffload\Enums\PluginState;
use DiluxOneOffload\Providers\AzureProvider;
use DiluxOneOffload\SyncManager;

/**
 * What the Transfer Timeout setting does.
 *
 * The real Azure provider is pointed at a local server that answers only
 * after the setting's minimum (30 s) has passed, so every transfer path
 * that carries file bytes must give up cleanly at the setting: a live
 * upload through the stream wrapper, a download through it, and the sync
 * engine's curl batch. Each case waits the full timeout, which is why the
 * class is in the `slow` group: `--exclude-group slow` leaves it out.
 *
 * @group slow
 */
class TimeoutTest extends IntegrationTestCase {

    private const TIMEOUT = 30;
    private const DELAY   = 35;

    private static ?LocalBlobServer $server = null;
    private ?AzureProvider $provider = null;
    private array $fixtures = [];

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        self::$server = new LocalBlobServer(8777);
    }

    public static function tearDownAfterClass(): void {
        if (self::$server) {
            self::$server->stop();
            self::$server = null;
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void {
        parent::setUp();
        ConfigManager::save_config([
            'cloud_provider'  => 'azure',
            'provider_config' => ['storage_account' => 'slowacct', 'container_name' => 'media', 'access_key' => base64_encode(random_bytes(32))],
            'timeout'         => self::TIMEOUT,
        ]);
        ConfigManager::set_state(PluginState::OFFLOADING_ACTIVE);
        $this->provider = $this->slowProvider();
        add_filter('diluxone_offload_pre_cloud_client', [$this, 'injectProvider']);
    }

    protected function tearDown(): void {
        remove_filter('diluxone_offload_pre_cloud_client', [$this, 'injectProvider']);
        CloudStreamWrapper::unregister();
        foreach ($this->fixtures as $f) {
            @unlink($f);
        }
        $this->fixtures = [];
        parent::tearDown();
    }

    public function injectProvider($pre) {
        return $this->provider;
    }

    /** The real provider, with the setting, aimed at a server that answers after DELAY seconds. */
    private function slowProvider(): AzureProvider {
        $config = ConfigManager::get_config();
        $p      = new AzureProvider($config['provider_config'] + ['upload_timeout' => (int) $config['timeout']]);
        $prop   = new \ReflectionProperty($p, 'endpoint');
        if ( PHP_VERSION_ID < 80100 ) { // Required before 8.1, deprecated from 8.5.
        	$prop->setAccessible( true );
        }
        $prop->setValue($p, self::$server->base_url . '/delay-' . self::DELAY);
        return $p;
    }

    private function assertGaveUpAtTheSetting(float $started): void {
        $elapsed = microtime(true) - $started;
        $this->assertGreaterThanOrEqual(self::TIMEOUT - 1, $elapsed, 'gave up before the setting allowed');
        $this->assertLessThan(self::DELAY, $elapsed, 'waited for the server instead of giving up at the setting');
    }

    public function test_a_live_upload_gives_up_at_the_setting_and_the_health_says_why(): void {
        CloudStreamWrapper::register();
        $started = microtime(true);
        $key = CloudStreamWrapper::key_prefix() . '/2026/09/slow.txt';
        @file_put_contents('diluxoneoffload://' . $key, 'bytes that never arrive');
        $this->assertGaveUpAtTheSetting($started);
        // PHP ignores what stream_close() returns, so the failure travels the
        // way WordPress reads it: the wrapper notes it and the upload handler
        // turns it into an error (fail_upload_if_write_failed).
        $reason = CloudStreamWrapper::take_write_failure('/' . $key);
        $this->assertNotNull($reason, 'the failed write is on record for the upload handler');
        $this->assertStringContainsStringIgnoringCase('timed out', $reason);
        $this->assertFileDoesNotExist(wp_upload_dir()['basedir'] . '/2026/09/slow.txt', 'and nothing is written to the server instead');

        $health = ConfigManager::get_connection_health();
        $this->assertSame('unhealthy', $health['status']);
        $this->assertSame('timeout', $health['error_code']);
        $this->assertSame('upload', $health['error_source']);
    }

    public function test_a_download_gives_up_at_the_setting(): void {
        CloudStreamWrapper::register();
        $started = microtime(true);
        $read    = @file_get_contents('diluxoneoffload://' . CloudStreamWrapper::key_prefix() . '/2026/09/slow-read.txt');
        $this->assertGaveUpAtTheSetting($started);
        $this->assertFalse($read);
    }

    public function test_the_sync_batch_gives_up_at_the_setting_and_records_the_error(): void {
        ConfigManager::set_state(PluginState::CONFIGURED);
        $base = wp_upload_dir()['basedir'];
        wp_mkdir_p($base . '/slowsync');
        $file = $base . '/slowsync/one.bin';
        file_put_contents($file, 'sync me');
        $this->fixtures[] = $file;
        DB::add_file('/slowsync/one.bin', 7);
        $sm = new SyncManager();
        $this->assertTrue($sm->start_sync()['success'], 'a pending row makes start_sync a continuation');

        $started = microtime(true);
        $r       = $sm->process_batch(0.0);
        $this->assertGaveUpAtTheSetting($started);

        $rows = DB::get_failed_files();
        $this->assertCount(1, $rows);
        // The engine counts the attempt before it starts and the failure after it.
        $this->assertGreaterThanOrEqual(1, (int) $rows[0]['errors']);
        // curl's own words for CURLE_OPERATION_TIMEDOUT: "Timeout was reached".
        $this->assertMatchesRegularExpression('/timeout|timed out/i', (string) $rows[0]['error_message']);
        $this->assertSame('timeout', ConfigManager::error_code_from_message((string) $rows[0]['error_message']), 'and the banner would call it a timeout');
        $this->assertSame(0, $r['uploaded_this_batch'] ?? 0);
    }
}
