<?php
namespace Tests\Integration\RealAzure;

use Tests\Integration\IntegrationTestCase;
use DiluxOneOffload\Providers\AzureProvider;
use Tests\Integration\SendsParts;

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

    use SendsParts;


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

    /** The sync's path: blocks as handles, an upload taken up from the uncommitted blocks, the block list. */
    public function test_a_large_file_is_taken_up_where_it_was_left_and_assembled_byte_for_byte(): void {
        $local = $this->tempFile(9 * 1048576, 'mp4');
        $file  = ['local_path' => $local, 'remote_path' => 'uploads/2026/09/resumed.mp4'];
        $first = self::$provider->begin_chunked_upload($file);
        $this->assertTrue($first['success'], $first['error'] ?? '');
        $this->assertNull(self::sendPart(self::$provider, $first['upload'], 1));
        $this->assertFalse(self::$provider->file_exists($file['remote_path']), 'an uncommitted block is not a blob');

        // A later request: Azure lists block 1 as uncommitted.
        $upload = self::$provider->begin_chunked_upload($file, $first['upload']->uploadId())['upload'];
        $this->assertSame([2, 3], $upload->missingParts());
        foreach ($upload->missingParts() as $part) {
            $this->assertNull(self::sendPart(self::$provider, $upload, $part));
        }
        $this->assertNull(self::commitParts(self::$provider, $upload));

        $down = $this->tempFile(0);
        $this->assertTrue(self::$provider->download_file($file['remote_path'], $down)['success']);
        $this->assertSame(md5_file($local), md5_file($down));
    }

    /** Blocks an earlier upload left on the blob (the file's old content) are never taken up by another one. */
    public function test_blocks_of_another_upload_are_never_committed(): void {
        $file = ['local_path' => $this->tempFile(5 * 1048576, 'mp4'), 'remote_path' => 'uploads/2026/09/rewritten.mp4'];
        $old  = self::$provider->begin_chunked_upload($file)['upload'];
        $this->assertNull(self::sendPart(self::$provider, $old, 1));

        $new = self::$provider->begin_chunked_upload($file)['upload'];
        $this->assertNotSame($old->uploadId(), $new->uploadId());
        $this->assertSame([1, 2], self::$provider->begin_chunked_upload($file, $new->uploadId())['upload']->missingParts(), "the old block 1 is not the new upload's");
    }

    /**
     * A blob a 2.0.0 upload left uncommitted blocks on (ids of six ASCII
     * digits): Azure refuses a block whose id has another length, so this
     * version's ids keep that length, and its upload goes through.
     */
    public function test_a_blob_with_blocks_an_older_version_left_still_takes_an_upload(): void {
        $remote = 'uploads/2026/09/left-by-2-0-0.mp4';
        $date   = gmdate('D, d M Y H:i:s T');
        $block  = base64_encode('000000');
        $body   = 'an old block';
        $sts    = "PUT\n\n\n" . strlen($body) . "\n\napplication/octet-stream\n\n\n\n\n\n\nx-ms-date:{$date}\nx-ms-version:2020-04-08\n/" . self::$account . '/' . self::$container . "/{$remote}\nblockid:{$block}\ncomp:block";
        $r      = wp_remote_request('https://' . self::$account . '.blob.core.windows.net/' . self::$container . "/{$remote}?comp=block&blockid=" . rawurlencode($block), [
            'method'  => 'PUT',
            'timeout' => 60,
            'body'    => $body,
            'headers' => ['x-ms-date' => $date, 'x-ms-version' => '2020-04-08', 'Content-Type' => 'application/octet-stream', 'Authorization' => 'SharedKey ' . self::$account . ':' . base64_encode(hash_hmac('sha256', $sts, base64_decode(self::$key), true))],
        ]);
        $this->assertSame(201, wp_remote_retrieve_response_code($r), 'the old-format block is on the blob');

        $local  = $this->tempFile(5 * 1048576, 'mp4');
        $upload = self::$provider->begin_chunked_upload(['local_path' => $local, 'remote_path' => $remote])['upload'];
        foreach ($upload->missingParts() as $part) {
            $this->assertNull(self::sendPart(self::$provider, $upload, $part));
        }
        $this->assertNull(self::commitParts(self::$provider, $upload));
        $this->assertSame(5 * 1048576, (int) self::$provider->get_file_info($remote)['size']);
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

    public function test_a_page_of_the_listing_is_the_same_objects_and_ends_the_listing(): void {
        $local = $this->tempFile(1024, 'bin');
        foreach (['a', 'b', 'c'] as $name) {
            $this->assertTrue(self::$provider->upload_file($local, "uploads/paged/$name.bin")['success']);
        }
        $page = self::$provider->list_page('uploads/paged/');
        $this->assertSame('', $page['next'], 'three objects fit one page');
        $this->assertSame(array_column(self::$provider->list_files('uploads/paged/'), 'path'), array_column($page['files'], 'path'));
        $this->assertSame(1024, $page['files'][0]['size']);
    }

    /** @param array<string, string> $serving Settings › Serving as get_cloud_client() passes it: cache_control, storage_class. */
    private static function serving(array $serving): AzureProvider {
        return new AzureProvider($serving + [
            'storage_account' => self::$account,
            'container_name'  => self::$container,
            'access_key'      => self::$key,
        ]);
    }

    /**
     * What a SharedKey-signed HEAD of a blob says it was stored with.
     *
     * @return array{cache-control: string, x-ms-access-tier: string}
     */
    private static function blobHead(string $remote): array {
        $date = gmdate('D, d M Y H:i:s T');
        $path = implode('/', array_map('rawurlencode', explode('/', $remote)));
        $sts  = "HEAD\n\n\n\n\n\n\n\n\n\n\n\nx-ms-date:{$date}\nx-ms-version:2020-04-08\n/" . self::$account . '/' . self::$container . '/' . $path;
        $r    = wp_remote_head('https://' . self::$account . '.blob.core.windows.net/' . self::$container . '/' . $path, [
            'timeout' => 60,
            'headers' => ['x-ms-date' => $date, 'x-ms-version' => '2020-04-08', 'Authorization' => 'SharedKey ' . self::$account . ':' . base64_encode(hash_hmac('sha256', $sts, base64_decode(self::$key), true))],
        ]);
        if (is_wp_error($r) || 200 !== wp_remote_retrieve_response_code($r)) {
            throw new \RuntimeException("HEAD {$remote} failed: " . (is_wp_error($r) ? $r->get_error_message() : wp_remote_retrieve_response_code($r)));
        }
        return [
            'cache-control'    => (string) wp_remote_retrieve_header($r, 'cache-control'),
            'x-ms-access-tier' => (string) wp_remote_retrieve_header($r, 'x-ms-access-tier'),
        ];
    }

    /** A whole file the way the sync sends one under the chunk threshold: its curl handle, run. Null when it landed. */
    private static function sendWhole(AzureProvider $provider, string $local, string $remote): ?string {
        $h = $provider->prepare_batch_upload_handle(['local_path' => $local, 'remote_path' => $remote]);
        if (empty($h['success'])) {
            return (string) $h['error'];
        }
        $body   = (string) curl_exec($h['handle']);
        $status = (int) curl_getinfo($h['handle'], CURLINFO_HTTP_CODE);
        $error  = curl_error($h['handle']);
        if (is_resource($h['file_handle'] ?? null)) {
            fclose($h['file_handle']);
        }
        return '' !== $error ? $error : $provider->verify_upload_response($status, $body);
    }

    /**
     * Writes one blob by every way the plugin has: one Put Blob, blocks in
     * one call, the sync's whole-file handle, the sync's blocks and block
     * list, and a server-side copy (a rename). Returns the names.
     *
     * @return string[]
     */
    private function writeEveryWay(AzureProvider $provider, string $folder): array {
        $small = $this->tempFile(4096, 'png');
        $large = $this->tempFile(5 * 1048576, 'mp4');
        $keys  = [];
        foreach (['one-put.png' => $small, 'blocks.mp4' => $large] as $name => $local) {
            $up = $provider->upload_file($local, $keys[] = "uploads/{$folder}/{$name}");
            $this->assertTrue($up['success'], $name . ': ' . ($up['error'] ?? ''));
        }
        $this->assertNull(self::sendWhole($provider, $small, $keys[] = "uploads/{$folder}/sync-put.png"));
        $chunked = $provider->begin_chunked_upload(['local_path' => $large, 'remote_path' => $keys[] = "uploads/{$folder}/sync-blocks.mp4"]);
        $this->assertTrue($chunked['success'], $chunked['error'] ?? '');
        foreach ($chunked['upload']->missingParts() as $part) {
            $this->assertNull(self::sendPart($provider, $chunked['upload'], $part));
        }
        $this->assertNull(self::commitParts($provider, $chunked['upload']));
        $copy = $provider->copy_blob($keys[0], $keys[] = "uploads/{$folder}/renamed.png");
        $this->assertTrue($copy['success'], $copy['error'] ?? '');
        return $keys;
    }

    /** Settings › Serving's Cache-Control is stored with every new blob, whichever way it went up, and a copy keeps it. */
    public function test_every_way_up_stores_the_cache_control_and_a_copy_keeps_it(): void {
        foreach ($this->writeEveryWay(self::serving(['cache_control' => 'public, max-age=123']), 'cache') as $remote) {
            $this->assertSame('public, max-age=123', self::blobHead($remote)['cache-control'], $remote);
        }
        $this->assertTrue(self::$provider->upload_file($this->tempFile(1024, 'png'), 'uploads/cache/none.png')['success']);
        $this->assertSame('', self::blobHead('uploads/cache/none.png')['cache-control'], 'with the setting off nothing is stored');
    }

    /** The infrequent class is the Cool tier, asked for on every way up and on a copy, which does not carry the tier over by itself. */
    public function test_the_infrequent_class_stores_every_new_blob_in_the_cool_tier(): void {
        foreach ($this->writeEveryWay(self::serving(['storage_class' => 'infrequent']), 'class') as $remote) {
            $this->assertSame('Cool', self::blobHead($remote)['x-ms-access-tier'], $remote);
        }
        $this->assertTrue(self::$provider->upload_file($this->tempFile(1024, 'png'), 'uploads/class/default.png')['success']);
        $this->assertNotSame('Cool', self::blobHead('uploads/class/default.png')['x-ms-access-tier'], 'the default class is the account\'s');
    }
}
