<?php
namespace Tests\Integration\RealS3;

use Tests\Integration\IntegrationTestCase;
use DiluxOneOffload\Providers\AwsSignatureV4;
use DiluxOneOffload\Providers\S3CompatibleProvider;
use DiluxOneOffload\Providers\S3Presets;
use Tests\Integration\SendsParts;

/**
 * The S3-compatible provider against a real S3 server: real SigV4
 * signatures, real status codes, real bytes. Sizes sit on both sides of the
 * 5 MiB part size so the single PUT and the multipart path are both
 * exercised, and the probe of Test Connection is read back anonymously.
 *
 * Settings come from build/real-s3-credentials.json (written by
 * `make test-integration-real REAL_PROVIDER=s3`). Without it the class is
 * skipped, never silently passed. Against the local server the class creates
 * its own public bucket and deletes it; with a fixed bucket (a real service,
 * where the CI keys cannot create public buckets) it works under a prefix of
 * its own and deletes what it wrote.
 */
class RealS3ProviderTest extends IntegrationTestCase {

    use SendsParts;


    /** @var array<string, string> */
    private static array $settings = [];
    private static string $bucket = '';
    private static bool $own_bucket = false;
    private static string $prefix = '';
    private static S3CompatibleProvider $provider;

    /** @var string[] Temp files to remove. */
    private array $temp = [];

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        $file = rtrim(DILUXONE_OFFLOAD_DIR, '/') . '/build/real-s3-credentials.json';
        if (!file_exists($file)) {
            self::markTestSkipped('No real S3 settings (build/real-s3-credentials.json).');
        }
        self::$settings = (array) json_decode((string) file_get_contents($file), true);
        $run = strtolower((string) (self::$settings['run_id'] ?? 'local'));
        if ('' !== (string) (self::$settings['bucket'] ?? '')) {
            self::$bucket = (string) self::$settings['bucket'];
            self::$prefix = 'e2e-php-' . $run . '-' . strtolower(wp_generate_password(6, false)) . '/';
        } else {
            self::$bucket     = 'e2e-' . $run . '-php-' . strtolower(wp_generate_password(8, false));
            self::$own_bucket = true;
            self::bucketRequest('PUT', '');
            self::bucketRequest('PUT', 'policy', (string) wp_json_encode([
                'Version'   => '2012-10-17',
                'Statement' => [['Effect' => 'Allow', 'Principal' => ['AWS' => ['*']], 'Action' => ['s3:GetObject'], 'Resource' => ['arn:aws:s3:::' . self::$bucket . '/*']]],
            ]));
        }
        self::$provider = self::provider(self::$settings['secret_access_key']);
    }

    public static function tearDownAfterClass(): void {
        if (self::$bucket !== '' && isset(self::$provider)) {
            foreach (self::$provider->list_files(self::$prefix) as $object) {
                self::$provider->delete_file((string) $object['path']);
            }
            if (self::$own_bucket) {
                self::bucketRequest('DELETE', '');
            }
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

    /** @param array<string, string> $serving Settings › Serving as get_cloud_client() passes it: cache_control, storage_class. */
    private static function provider(string $secret, array $serving = []): S3CompatibleProvider {
        return new S3CompatibleProvider($serving + [
            'preset'            => self::$settings['preset'],
            'endpoint'          => self::$settings['endpoint'],
            'region'            => self::$settings['region'],
            'bucket'            => self::$bucket,
            'access_key_id'     => self::$settings['access_key_id'],
            'secret_access_key' => $secret,
            'public_url'        => str_replace('{bucket}', self::$bucket, (string) self::$settings['public_url']),
            'path_style'        => 'aws' !== self::$settings['preset'],
        ]);
    }

    /** Create the bucket, set its policy or delete it, signed with the plugin's own signer. */
    private static function bucketRequest(string $method, string $query, string $body = ''): void {
        $url     = rtrim((string) self::$settings['endpoint'], '/') . '/' . self::$bucket . ('' === $query ? '' : '?' . $query . '=');
        $signer  = new AwsSignatureV4((string) self::$settings['access_key_id'], (string) self::$settings['secret_access_key'], (string) self::$settings['region']);
        $headers = $signer->sign($method, $url, [], '' === $body ? AwsSignatureV4::EMPTY_PAYLOAD : hash('sha256', $body));
        unset($headers['Host']);
        $r = wp_remote_request($url, ['method' => $method, 'headers' => $headers, 'body' => $body, 'timeout' => 60]);
        if (is_wp_error($r) || wp_remote_retrieve_response_code($r) >= 300) {
            throw new \RuntimeException("Bucket {$method} {$query} failed: " . (is_wp_error($r) ? $r->get_error_message() : wp_remote_retrieve_response_code($r) . ' ' . wp_remote_retrieve_body($r)));
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

    private function key(string $name): string {
        return self::$prefix . 'uploads/2026/09/' . $name;
    }

    public function test_the_connection_is_accepted_with_the_probe_read_back_anonymously(): void {
        $r = self::$provider->test_connection();
        $this->assertTrue($r['success'], $r['message'] ?? '');
        $this->assertSame([], array_filter(array_column(self::$provider->list_files('uploads/'), 'path'), fn($k) => false !== strpos($k, 'diluxone-offload-probe')), 'the probe is deleted');
    }

    public function test_a_wrong_secret_is_refused_without_leaking_the_signature(): void {
        $r = self::provider('not-the-secret-' . wp_generate_password(20, false))->test_connection();
        $this->assertFalse($r['success']);
        $this->assertStringStartsWith('HTTP 403', $r['message']);
        foreach (['StringToSign', 'CanonicalRequest', 'SignatureProvided', self::$settings['access_key_id']] as $never) {
            $this->assertStringNotContainsString($never, $r['message']);
        }
    }

    public function test_a_small_file_goes_up_in_one_put_and_comes_back_byte_for_byte(): void {
        $local = $this->tempFile(300 * 1024, 'png');
        $key   = $this->key('small.png');
        $up    = self::$provider->upload_file($local, $key);
        $this->assertTrue($up['success'], $up['error'] ?? '');
        $this->assertTrue(self::$provider->file_exists($key));
        $info = self::$provider->get_file_info($key);
        $this->assertSame(300 * 1024, (int) $info['size']);
        $this->assertSame(base64_encode(md5_file($local, true)), $info['md5'], 'a single PUT keeps an MD5 ETag');

        $down = $this->tempFile(0);
        $this->assertTrue(self::$provider->download_file($key, $down)['success']);
        $this->assertSame(md5_file($local), md5_file($down));
        $public = wp_remote_get($up['url']);
        $this->assertSame(200, wp_remote_retrieve_response_code($public), 'readable at the public URL');
        $this->assertSame('image/png', wp_remote_retrieve_header($public, 'content-type'));
    }

    public function test_a_file_over_one_part_goes_up_in_parts_within_bounded_memory(): void {
        $local  = $this->tempFile(12 * 1048576, 'mp4');
        $key    = $this->key('clip.mp4');
        $before = memory_get_usage(true);
        $up     = self::$provider->upload_file($local, $key);
        $this->assertTrue($up['success'], $up['error'] ?? '');
        $this->assertLessThan(8 * 1048576, memory_get_usage(true) - $before, 'a 12 MiB upload costs about one part');
        $this->assertSame(12 * 1048576, (int) self::$provider->get_file_info($key)['size']);
        $this->assertFalse(self::$provider->get_file_checksum($key), 'a multipart ETag is not an MD5');

        $down = $this->tempFile(0);
        $this->assertTrue(self::$provider->download_file($key, $down)['success']);
        $this->assertSame(md5_file($local), md5_file($down));
    }

    /** The sync's path: parts as handles, an upload taken up with ListParts where it was left, the commit. */
    public function test_a_large_file_is_taken_up_where_it_was_left_and_assembled_byte_for_byte(): void {
        $local = $this->tempFile(11 * 1048576, 'mp4');
        $file  = ['local_path' => $local, 'remote_path' => $this->key('resumed.mp4')];
        $first = self::$provider->begin_chunked_upload($file);
        $this->assertTrue($first['success'], $first['error'] ?? '');
        $this->assertNull(self::sendPart(self::$provider, $first['upload'], 1));

        // A later request, through what the sync keeps (an upload name longer
        // than the row allows, as R2's are, is kept in an option by its
        // SHA-1): part 1 is there.
        \DiluxOneOffload\DiluxOneOffloadDB::remember_upload('/not-a-row', $first['upload'], 1700000000);
        $token  = $first['upload']->resumeToken(1700000000);
        $again  = self::$provider->begin_chunked_upload($file, \DiluxOneOffload\DiluxOneOffloadDB::resumable_upload_name($token, 11 * 1048576, 1700000000));
        $upload = $again['upload'];
        $this->assertSame($first['upload']->uploadId(), $upload->uploadId());
        $this->assertSame([2, 3], $upload->missingParts());
        foreach ($upload->missingParts() as $part) {
            $this->assertNull(self::sendPart(self::$provider, $upload, $part));
        }
        $this->assertNull(self::commitParts(self::$provider, $upload));

        $down = $this->tempFile(0);
        $this->assertTrue(self::$provider->download_file($file['remote_path'], $down)['success']);
        $this->assertSame(md5_file($local), md5_file($down));
    }

    /**
     * An upload named only by its SHA-1 is found by ListMultipartUploads: the
     * fallback when the long name the sync keeps in an option is gone.
     * Cloudflare R2 did not return the upload this way in CI (30 September
     * 2026), so the sync does not count on it there.
     */
    public function test_an_upload_named_by_its_sha1_is_found_among_the_unfinished_uploads(): void {
        if ('r2' === (self::$settings['preset'] ?? '')) {
            $this->markTestSkipped('R2 did not list the upload by its key; the sync keeps long names in an option instead.');
        }
        $file  = ['local_path' => $this->tempFile(6 * 1048576, 'mp4'), 'remote_path' => $this->key('by-sha1.mp4')];
        $first = self::$provider->begin_chunked_upload($file)['upload'];
        $this->assertNull(self::sendPart(self::$provider, $first, 1));

        $again = self::$provider->begin_chunked_upload($file, '#' . sha1($first->uploadId()))['upload'];
        $this->assertSame($first->uploadId(), $again->uploadId());
        $this->assertSame([2], $again->missingParts());
        self::$provider->abort_chunked_upload(new \DiluxOneOffload\DTOs\ChunkedUpload($file['local_path'], $file['remote_path'], 1, 1, '#' . sha1($first->uploadId())));
        $fresh = self::$provider->begin_chunked_upload($file, $first->uploadId())['upload'];
        $this->assertNotSame($first->uploadId(), $fresh->uploadId(), 'the abort found it too');
        self::$provider->abort_chunked_upload($fresh);
    }

    /** An aborted upload is one the service no longer knows: taking it up starts a new one. */
    public function test_an_aborted_upload_cannot_be_taken_up_and_starts_over(): void {
        $local = $this->tempFile(6 * 1048576, 'mp4');
        $file  = ['local_path' => $local, 'remote_path' => $this->key('aborted.mp4')];
        $first = self::$provider->begin_chunked_upload($file)['upload'];
        $this->assertNull(self::sendPart(self::$provider, $first, 1));
        self::$provider->abort_chunked_upload($first);

        $again = self::$provider->begin_chunked_upload($file, $first->uploadId())['upload'];
        $this->assertNotSame($first->uploadId(), $again->uploadId());
        $this->assertSame([1, 2], $again->missingParts());
        self::$provider->abort_chunked_upload($again);
        $this->assertFalse(self::$provider->file_exists($file['remote_path']));
    }

    public function test_the_part_boundary_on_both_sides(): void {
        foreach ([5242879, 5242880, 5242881] as $bytes) {
            $local = $this->tempFile($bytes, 'bin');
            $key   = $this->key("edge-{$bytes}.bin");
            $up = self::$provider->upload_file($local, $key);
            $this->assertTrue($up['success'], $bytes . ': ' . ($up['error'] ?? ''));
            $this->assertSame($bytes, (int) self::$provider->get_file_info($key)['size'], (string) $bytes);
            $down = $this->tempFile(0);
            $got  = self::$provider->download_file($key, $down);
            $this->assertTrue($got['success'], $bytes . ': ' . ($got['error'] ?? ''));
            $this->assertSame(md5_file($local), md5_file($down), (string) $bytes);
        }
    }

    public function test_names_with_spaces_plus_signs_and_accents_round_trip(): void {
        $local = $this->tempFile(2048, 'txt');
        $key   = $this->key('café photo (1)+final.txt');
        $this->assertTrue(self::$provider->upload_file($local, $key)['success']);
        $this->assertTrue(self::$provider->file_exists($key));
        $this->assertContains($key, array_column(self::$provider->list_files(self::$prefix . 'uploads/2026/09/caf'), 'path'));
        $down = $this->tempFile(0);
        $this->assertTrue(self::$provider->download_file($key, $down)['success']);
        $this->assertSame(md5_file($local), md5_file($down));
        $this->assertSame(200, wp_remote_retrieve_response_code(wp_remote_get(self::$provider->get_file_url($key))), 'the encoded public URL works');
    }

    public function test_copy_and_delete_and_a_delete_of_nothing_succeeds(): void {
        $local = $this->tempFile(4096, 'bin');
        $this->assertTrue(self::$provider->upload_file($local, $this->key('src.bin'))['success']);
        $copy = self::$provider->copy_blob($this->key('src.bin'), $this->key('dst.bin'));
        $this->assertTrue($copy['success'], $copy['error'] ?? '');
        $this->assertTrue(self::$provider->delete_file($this->key('src.bin'))['success']);
        $this->assertFalse(self::$provider->file_exists($this->key('src.bin')));
        $this->assertTrue(self::$provider->file_exists($this->key('dst.bin')), 'the copy is independent of the source');
        $this->assertTrue(self::$provider->delete_file($this->key('never-there.bin'))['success'], 'gone is gone');
    }

    public function test_a_missing_object_is_a_clean_404(): void {
        $this->assertFalse(self::$provider->file_exists($this->key('never.jpg')));
        $this->assertFalse(self::$provider->get_file_info($this->key('never.jpg')));
        $down = $this->tempFile(0);
        $r = self::$provider->download_file($this->key('never.jpg'), $down);
        $this->assertFalse($r['success']);
        $this->assertStringContainsString('404', $r['error']);
        $this->assertFileDoesNotExist($down);
    }

    public function test_a_listing_by_prefix_returns_only_that_prefix(): void {
        $local = $this->tempFile(1024, 'bin');
        $this->assertTrue(self::$provider->upload_file($local, self::$prefix . 'uploads/sites/2/x.bin')['success']);
        $this->assertTrue(self::$provider->upload_file($local, self::$prefix . 'uploads/x.bin')['success']);
        $this->assertSame([self::$prefix . 'uploads/sites/2/x.bin'], array_column(self::$provider->list_files(self::$prefix . 'uploads/sites/2/'), 'path'));
        $this->assertContains(self::$prefix . 'uploads/x.bin', array_column(self::$provider->list_files(self::$prefix . 'uploads/'), 'path'));
    }

    public function test_a_page_of_the_listing_is_the_same_objects_and_ends_the_listing(): void {
        $local = $this->tempFile(1024, 'bin');
        foreach (['a', 'b', 'c'] as $name) {
            $this->assertTrue(self::$provider->upload_file($local, self::$prefix . "uploads/paged/$name.bin")['success']);
        }
        $page = self::$provider->list_page(self::$prefix . 'uploads/paged/');
        $this->assertSame('', $page['next'], 'three objects fit one page');
        $this->assertSame(array_column(self::$provider->list_files(self::$prefix . 'uploads/paged/'), 'path'), array_column($page['files'], 'path'));
        $this->assertSame(1024, $page['files'][0]['size']);
    }

    /**
     * What a signed HEAD of an object says it was stored with.
     *
     * @return array{cache-control: string, x-amz-storage-class: string}
     */
    private static function objectHead(string $key): array {
        $path    = implode('/', array_map('rawurlencode', explode('/', $key)));
        $url     = rtrim((string) self::$settings['endpoint'], '/') . '/' . self::$bucket . '/' . $path;
        $signer  = new AwsSignatureV4((string) self::$settings['access_key_id'], (string) self::$settings['secret_access_key'], (string) self::$settings['region']);
        $headers = $signer->sign('HEAD', $url, [], AwsSignatureV4::EMPTY_PAYLOAD);
        unset($headers['Host']);
        $r = wp_remote_head($url, ['headers' => $headers, 'timeout' => 60]);
        if (is_wp_error($r) || 200 !== wp_remote_retrieve_response_code($r)) {
            throw new \RuntimeException("HEAD {$key} failed: " . (is_wp_error($r) ? $r->get_error_message() : wp_remote_retrieve_response_code($r)));
        }
        return [
            'cache-control'       => (string) wp_remote_retrieve_header($r, 'cache-control'),
            // S3 leaves the default class unsaid.
            'x-amz-storage-class' => (string) wp_remote_retrieve_header($r, 'x-amz-storage-class') ?: 'STANDARD',
        ];
    }

    /** A whole file the way the sync sends one under the chunk threshold: its curl handle, run. Null when it landed. */
    private static function sendWhole(S3CompatibleProvider $provider, string $local, string $key): ?string {
        $h = $provider->prepare_batch_upload_handle(['local_path' => $local, 'remote_path' => $key]);
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
     * Writes one object by every way the plugin has: one PUT, parts in one
     * call, the sync's whole-file handle, the sync's parts and commit, and a
     * server-side copy (a rename). Returns the keys.
     *
     * @return string[]
     */
    private function writeEveryWay(S3CompatibleProvider $provider, string $folder): array {
        $small = $this->tempFile(4096, 'png');
        $large = $this->tempFile(6 * 1048576, 'mp4');
        $keys  = [];
        foreach (['one-put.png' => $small, 'parts.mp4' => $large] as $name => $local) {
            $up = $provider->upload_file($local, $keys[] = $this->key("{$folder}/{$name}"));
            $this->assertTrue($up['success'], $name . ': ' . ($up['error'] ?? ''));
        }
        $this->assertNull(self::sendWhole($provider, $small, $keys[] = $this->key("{$folder}/sync-put.png")));
        $chunked = $provider->begin_chunked_upload(['local_path' => $large, 'remote_path' => $keys[] = $this->key("{$folder}/sync-parts.mp4")]);
        $this->assertTrue($chunked['success'], $chunked['error'] ?? '');
        foreach ($chunked['upload']->missingParts() as $part) {
            $this->assertNull(self::sendPart($provider, $chunked['upload'], $part));
        }
        $this->assertNull(self::commitParts($provider, $chunked['upload']));
        $copy = $provider->copy_blob($keys[0], $keys[] = $this->key("{$folder}/renamed.png"));
        $this->assertTrue($copy['success'], $copy['error'] ?? '');
        return $keys;
    }

    /** Settings › Serving's Cache-Control is stored with every new object, whichever way it went up, and a copy keeps it. */
    public function test_every_way_up_stores_the_cache_control_and_a_copy_keeps_it(): void {
        $provider = self::provider(self::$settings['secret_access_key'], ['cache_control' => 'public, max-age=123']);
        foreach ($this->writeEveryWay($provider, 'cache') as $key) {
            $this->assertSame('public, max-age=123', self::objectHead($key)['cache-control'], $key);
        }
        $plain = $this->key('cache/none.png');
        $this->assertTrue(self::$provider->upload_file($this->tempFile(1024, 'png'), $plain)['success']);
        $this->assertNotSame('public, max-age=123', self::objectHead($plain)['cache-control'], 'with the setting off nothing of ours is stored');
    }

    /**
     * The infrequent class is asked for on every way up and on a copy (which
     * does not carry it over by itself), and only on a service that offers it
     * (Amazon S3, R2); any other service stores the object in its default class.
     */
    public function test_the_infrequent_class_is_asked_for_only_where_the_service_offers_it(): void {
        $offers   = S3Presets::offers_infrequent((string) self::$settings['preset']);
        $provider = self::provider(self::$settings['secret_access_key'], ['storage_class' => 'infrequent']);
        foreach ($this->writeEveryWay($provider, 'class') as $key) {
            $class = self::objectHead($key)['x-amz-storage-class'];
            if ($offers) {
                $this->assertSame('STANDARD_IA', $class, $key);
            } else {
                $this->assertNotSame('STANDARD_IA', $class, $key);
            }
        }
    }
}
