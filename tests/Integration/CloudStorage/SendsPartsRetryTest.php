<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use Tests\Integration\LocalBlobServer;
use Tests\Integration\SendsParts;
use DiluxOneOffload\Providers\AzureProvider;

/**
 * What the real Backblaze B2 suite met: one 500 from the service on a
 * request of the suite's own helpers failed the test, where the plugin's
 * sync would have asked again. The helpers send a whole file and a part
 * again as the sync does, against a local server that answers 500 once:
 * the second try lands, a 4xx is not asked again, and three 500s in a row
 * are the end of it.
 */
class SendsPartsRetryTest extends IntegrationTestCase {
    use SendsParts;

    private static ?LocalBlobServer $server = null;
    private string $local = '';

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        self::$server = new LocalBlobServer(8778);
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
        $this->local = (string) tempnam(sys_get_temp_dir(), 'dlx-retry');
        file_put_contents($this->local, random_bytes(4096));
    }

    protected function tearDown(): void {
        @unlink($this->local);
        parent::tearDown();
    }

    /** The real Azure provider, every request of it sent under `$path` of the local server. */
    private static function providerAt(string $path): AzureProvider {
        $p    = new AzureProvider(['storage_account' => 'retryacct', 'container_name' => 'media', 'access_key' => base64_encode(random_bytes(32))]);
        $prop = new \ReflectionProperty($p, 'endpoint');
        if ( PHP_VERSION_ID < 80100 ) { // Required before 8.1, deprecated from 8.5.
        	$prop->setAccessible( true );
        }
        $prop->setValue($p, self::$server->base_url . $path);
        return $p;
    }

    private function sendWhole(AzureProvider $provider): ?string {
        return self::sendRetrying(
            fn (): array => $provider->prepare_batch_upload_handle(['local_path' => $this->local, 'remote_path' => 'uploads/2026/10/retry.png']),
            static fn (int $status, string $body): ?string => $provider->verify_upload_response($status, $body)
        );
    }

    private function firstPart(AzureProvider $provider): ?string {
        $begun = $provider->begin_chunked_upload(['local_path' => $this->local, 'remote_path' => 'uploads/2026/10/retry.mp4']);
        $this->assertTrue($begun['success']);
        return self::sendPart($provider, $begun['upload'], 1);
    }

    public function test_a_whole_file_that_meets_one_500_lands_on_the_second_try(): void {
        $token = LocalBlobServer::token();
        $this->assertNull($this->sendWhole(self::providerAt("/fail-once-500/{$token}")));
        $this->assertSame(2, self::$server->hits($token));
    }

    public function test_a_part_that_meets_one_500_lands_on_the_second_try(): void {
        $token = LocalBlobServer::token();
        $this->assertNull($this->firstPart(self::providerAt("/fail-once-500/{$token}")));
        $this->assertSame(2, self::$server->hits($token));
    }

    public function test_a_4xx_is_not_asked_again(): void {
        $token = LocalBlobServer::token();
        $error = $this->firstPart(self::providerAt("/count/{$token}/status-403"));
        $this->assertStringContainsString('HTTP 403', (string) $error);
        $this->assertSame(1, self::$server->hits($token));
    }

    public function test_three_500s_in_a_row_are_the_end_of_it(): void {
        $token = LocalBlobServer::token();
        $error = $this->sendWhole(self::providerAt("/count/{$token}/status-500"));
        $this->assertStringContainsString('HTTP 500', (string) $error);
        $this->assertSame(3, self::$server->hits($token));

        $token = LocalBlobServer::token();
        $error = $this->firstPart(self::providerAt("/count/{$token}/status-500"));
        $this->assertStringContainsString('HTTP 500', (string) $error);
        $this->assertSame(3, self::$server->hits($token));
    }
}
