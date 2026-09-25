<?php
namespace Tests\Integration\RealAzure;

use Tests\Integration\IntegrationTestCase;
use DiluxOneOffload\Providers\AzureProvider;

/**
 * The Azure provider against a real storage account: every request the
 * plugin makes, with real signatures, real status codes and real bytes on
 * the other side. Sizes sit on both sides of the 4 MiB block limit so the
 * single PUT and the Put Block / Put Block List paths are both exercised.
 *
 * Credentials come from build/real-azure-credentials.json (written by
 * `make test-real` locally and by the real-storage workflow in CI). Without
 * that file the class is skipped, never silently passed. The class creates
 * its own container and deletes it at the end.
 */
class RealAzureProviderTest extends IntegrationTestCase {

    private static string $account = '';
    private static string $key = '';
    private static string $container = '';
    private static AzureProvider $provider;

    /** @var string[] Temp files to remove. */
    private array $temp = [];

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        $file = rtrim(DILUXONE_OFFLOAD_DIR, '/') . '/build/real-azure-credentials.json';
        if (!file_exists($file)) {
            self::markTestSkipped('No real Azure credentials (build/real-azure-credentials.json).');
        }
        $creds = json_decode((string) file_get_contents($file), true);
        self::$account   = (string) ($creds['account'] ?? '');
        self::$key       = (string) ($creds['key'] ?? '');
        // Named after the run, like the Playwright containers, so the workflow's
        // final sweep owns it. The id travels in the credentials file: the
        // tests container does not see the runner's environment.
        self::$container = 'e2e-' . strtolower((string) ($creds['run_id'] ?? 'local')) . '-php-' . strtolower(wp_generate_password(8, false));
        if (self::$account === '' || self::$key === '') {
            self::markTestSkipped('Incomplete real Azure credentials.');
        }
        self::containerRequest('PUT');
        self::$provider = new AzureProvider([
            'storage_account' => self::$account,
            'container_name'  => self::$container,
            'access_key'      => self::$key,
        ]);
    }

    public static function tearDownAfterClass(): void {
        if (self::$container !== '') {
            self::containerRequest('DELETE');
        }
        parent::tearDownAfterClass();
    }

    protected function tearDown(): void {
        foreach ($this->temp as $f) {
            @unlink($f);
        }
        $this->temp = [];
        parent::tearDown();
    }

    /**
     * Create or delete the run's container with a SharedKey-signed request of
     * our own. The container is public at blob level, the way a deployment
     * has to be for browsers to load media; the provider's connection test
     * refuses anything else.
     */
    private static function containerRequest(string $method): void {
        $date   = gmdate('D, d M Y H:i:s T');
        $access = 'PUT' === $method ? "x-ms-blob-public-access:blob\n" : '';
        $sts    = "{$method}\n\n\n\n\n\n\n\n\n\n\n\n{$access}x-ms-date:{$date}\nx-ms-version:2020-04-08\n/" . self::$account . '/' . self::$container . "\nrestype:container";
        $sig    = base64_encode(hash_hmac('sha256', $sts, base64_decode(self::$key), true));
        $headers = ['x-ms-date' => $date, 'x-ms-version' => '2020-04-08', 'Content-Length' => '0', 'Authorization' => 'SharedKey ' . self::$account . ':' . $sig];
        if ('PUT' === $method) {
            $headers['x-ms-blob-public-access'] = 'blob';
        }
        $r = wp_remote_request('https://' . self::$account . '.blob.core.windows.net/' . self::$container . '?restype=container', [
            'method'  => $method,
            'timeout' => 60,
            // A bodiless PUT needs an explicit zero Content-Length or Azure answers 411; a zero length is signed as empty.
            'headers' => $headers,
        ]);
        if (is_wp_error($r) || !in_array(wp_remote_retrieve_response_code($r), [201, 202, 409], true)) {
            throw new \RuntimeException("Container {$method} failed: " . (is_wp_error($r) ? $r->get_error_message() : wp_remote_retrieve_response_code($r)));
        }
    }

    private function tempFile(int $bytes, string $ext = 'bin'): string {
        $path = tempnam(sys_get_temp_dir(), 'dlx') . '.' . $ext;
        $fh   = fopen($path, 'wb');
        for ($written = 0; $written < $bytes; $written += 1048576) {
            fwrite($fh, random_bytes(min(1048576, $bytes - $written)));
        }
        fclose($fh);
        $this->temp[] = $path;
        return $path;
    }

    public function test_the_connection_is_accepted(): void {
        $r = self::$provider->test_connection();
        $this->assertTrue($r['success'], $r['message'] ?? '');
    }

    public function test_a_wrong_key_is_refused_without_leaking_the_signature(): void {
        $wrong = new AzureProvider(['storage_account' => self::$account, 'container_name' => self::$container, 'access_key' => base64_encode(random_bytes(64))]);
        $r = $wrong->test_connection();
        $this->assertFalse($r['success']);
        $this->assertStringContainsString('403', $r['message']);
        $this->assertStringNotContainsString('MAC signature', $r['message'], 'the body detail never reaches a message');
        $this->assertStringNotContainsString('SharedKey', $r['message']);
    }

    public function test_a_small_file_goes_up_in_one_put_and_comes_back_byte_for_byte(): void {
        $local  = $this->tempFile(300 * 1024, 'png');
        $remote = 'uploads/2026/09/small.png';
        $up = self::$provider->upload_file($local, $remote);
        $this->assertTrue($up['success'], $up['error'] ?? '');
        $this->assertTrue(self::$provider->file_exists($remote));
        $info = self::$provider->get_file_info($remote);
        $this->assertSame(300 * 1024, (int) $info['size']);

        $down = $this->tempFile(0);
        $r = self::$provider->download_file($remote, $down);
        $this->assertTrue($r['success'], $r['error'] ?? '');
        $this->assertSame(md5_file($local), md5_file($down));
    }

    public function test_a_file_over_one_block_goes_up_in_blocks_within_bounded_memory(): void {
        $local  = $this->tempFile(9 * 1048576, 'mp4');
        $remote = 'uploads/2026/09/clip.mp4';
        $before = memory_get_usage(true);
        $up = self::$provider->upload_file($local, $remote);
        $this->assertTrue($up['success'], $up['error'] ?? '');
        $this->assertLessThan(6 * 1048576, memory_get_usage(true) - $before, 'a 9 MiB upload costs at most one block');
        $info = self::$provider->get_file_info($remote);
        $this->assertSame(9 * 1048576, (int) $info['size']);

        $down = $this->tempFile(0);
        $before = memory_get_usage(true);
        $this->assertTrue(self::$provider->download_file($remote, $down)['success']);
        $this->assertLessThan(6 * 1048576, memory_get_usage(true) - $before, 'a 9 MiB download is streamed');
        $this->assertSame(md5_file($local), md5_file($down));
    }

    public function test_the_block_boundary_on_both_sides(): void {
        foreach ([4194303, 4194304, 4194305] as $bytes) {
            $local  = $this->tempFile($bytes, 'bin');
            $remote = "uploads/2026/09/edge-{$bytes}.bin";
            $this->assertTrue(self::$provider->upload_file($local, $remote)['success'], (string) $bytes);
            $this->assertSame($bytes, (int) self::$provider->get_file_info($remote)['size'], (string) $bytes);
            $down = $this->tempFile(0);
            $this->assertTrue(self::$provider->download_file($remote, $down)['success']);
            $this->assertSame(md5_file($local), md5_file($down), (string) $bytes);
        }
    }

    public function test_names_with_spaces_and_accents_round_trip(): void {
        $local  = $this->tempFile(2048, 'txt');
        $remote = 'uploads/2026/09/café photo (1).txt';
        $this->assertTrue(self::$provider->upload_file($local, $remote)['success']);
        $this->assertTrue(self::$provider->file_exists($remote));
        $keys = array_column(self::$provider->list_files('uploads/2026/09/caf'), 'path');
        $this->assertContains($remote, $keys);
        $down = $this->tempFile(0);
        $this->assertTrue(self::$provider->download_file($remote, $down)['success']);
        $this->assertSame(md5_file($local), md5_file($down));
    }

    public function test_copy_and_delete(): void {
        $local = $this->tempFile(4096, 'bin');
        $this->assertTrue(self::$provider->upload_file($local, 'uploads/a/src.bin')['success']);
        $copy = self::$provider->copy_blob('uploads/a/src.bin', 'uploads/a/dst.bin');
        $this->assertTrue($copy['success'], $copy['error'] ?? '');
        $this->assertTrue(self::$provider->file_exists('uploads/a/dst.bin'));
        $this->assertTrue(self::$provider->delete_file('uploads/a/src.bin')['success']);
        $this->assertFalse(self::$provider->file_exists('uploads/a/src.bin'));
        $this->assertTrue(self::$provider->file_exists('uploads/a/dst.bin'), 'the copy is independent of the source');
    }

    public function test_a_missing_object_is_a_clean_404(): void {
        $this->assertFalse(self::$provider->file_exists('uploads/never/there.jpg'));
        $this->assertFalse(self::$provider->get_file_info('uploads/never/there.jpg'));
        $down = $this->tempFile(0);
        $r = self::$provider->download_file('uploads/never/there.jpg', $down);
        $this->assertFalse($r['success']);
        $this->assertStringContainsString('404', $r['error']);
        $this->assertFileDoesNotExist($down, 'nothing is left where the file would have been');
    }

    public function test_listing_by_prefix_returns_only_that_prefix(): void {
        $local = $this->tempFile(1024, 'bin');
        $this->assertTrue(self::$provider->upload_file($local, 'uploads/sites/2/x.bin')['success']);
        $this->assertTrue(self::$provider->upload_file($local, 'uploads/x.bin')['success']);
        $under = array_column(self::$provider->list_files('uploads/sites/2/'), 'path');
        $this->assertSame(['uploads/sites/2/x.bin'], $under);
        $all = array_column(self::$provider->list_files('uploads/'), 'path');
        $this->assertContains('uploads/x.bin', $all);
        $this->assertContains('uploads/sites/2/x.bin', $all, 'a parent prefix lists the other site too: callers must filter with owns_key()');
    }
}
