<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use Tests\Integration\ScriptedHttp;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\CloudStreamWrapper;
use DiluxOneOffload\DiluxOneOffloadDB;
use DiluxOneOffload\Enums\PluginState;

/**
 * The two real providers, Azure and S3, built from the saved configuration
 * the way a request builds them, behind the stream wrapper and the WordPress
 * HTTP API. The storage service is a small in-memory object store answering
 * pre_http_request, so every request the provider signs and sends is seen,
 * and what an upload, a read, a stat, a delete and a rename through
 * diluxoneoffload:// leave behind is checked in the store, the tracking
 * table, the connection health and the stats transient.
 */
class ProviderThroughWordPressTest extends IntegrationTestCase {
    use ScriptedHttp;

    /** @var array<string, string> Object key => bytes. */
    private array $store = [];

    /** @var int|null When set, every PUT of bytes answers this status. */
    private ?int $put_status = null;

    /** @var int|null When set, every listing answers this status. */
    private ?int $list_status = null;

    private string $provider = '';

    protected function tearDown(): void {
        $this->unhookHttp();
        CloudStreamWrapper::deactivate_offloading();
        CloudStreamWrapper::clear_stat_cache();
        CloudStreamWrapper::clear_file_cache();
        foreach (ConfigManager::STATS_TRANSIENTS as $t) {
            delete_transient($t);
        }
        delete_option(ConfigManager::LAST_UPLOAD_OPTION);
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public function providers(): array {
        return ['azure' => ['azure'], 's3' => ['s3']];
    }

    private function connect(string $provider): void {
        $this->provider = $provider;
        $config = 'azure' === $provider
            ? ['storage_account' => 'wpintacct', 'container_name' => 'media', 'access_key' => base64_encode(random_bytes(32))]
            : ['preset' => 'custom', 'endpoint' => 'https://s3.wpint.test', 'region' => 'us-east-1', 'bucket' => 'media', 'access_key_id' => 'AKIDWPINT', 'secret_access_key' => 'wpint-secret', 'public_url' => 'https://cdn.wpint.test', 'path_style' => true];
        $this->assertTrue(ConfigManager::save_config(['cloud_provider' => $provider, 'provider_config' => $config]));
        ConfigManager::set_state(PluginState::SYNCED);
        self::resetWrapperClient();
        CloudStreamWrapper::clear_stat_cache();
        CloudStreamWrapper::clear_file_cache();
        $this->scriptHttp([$this, 'serve']);
        $this->assertTrue(CloudStreamWrapper::activate_offloading());
    }

    /** The object key a request names: the path after the container or the bucket. */
    private static function keyOf(string $url): string {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $key  = preg_replace('#^/media/?#', '', $path);
        return rawurldecode((string) $key);
    }

    /** @param array<string, mixed> $args */
    private static function header(array $args, string $name): string {
        foreach ((array) ($args['headers'] ?? []) as $k => $v) {
            if (strtolower((string) $k) === strtolower($name)) {
                return (string) $v;
            }
        }
        return '';
    }

    /**
     * The object store. Public: ScriptedHttp calls it for every request.
     *
     * @param array<string, mixed> $args
     */
    public function serve(string $method, string $url, array $args): array {
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        parse_str($query, $q);
        $key = self::keyOf($url);

        if ('GET' === $method && (isset($q['comp']) && 'list' === $q['comp'] || isset($q['list-type']))) {
            if (null !== $this->list_status) {
                return self::httpReply($this->list_status, '<?xml version="1.0"?><Error><Code>AuthorizationFailure</Code><Message>listing refused</Message></Error>');
            }
            return self::httpReply(200, $this->listing((string) ($q['prefix'] ?? '')));
        }

        if ('PUT' === $method) {
            $source = self::header($args, 'x-ms-copy-source') ?: self::header($args, 'x-amz-copy-source');
            if ('' !== $source) {
                $from = rawurldecode((string) preg_replace('#^.*?/media/#', '', (string) (wp_parse_url($source, PHP_URL_PATH) ?: $source)));
                if (!isset($this->store[$from])) {
                    return self::httpReply(404, '<?xml version="1.0"?><Error><Code>NoSuchKey</Code><Message>no source</Message></Error>');
                }
                $this->store[$key] = $this->store[$from];
                return 'azure' === $this->provider
                    ? self::httpReply(202)
                    : self::httpReply(200, '<?xml version="1.0"?><CopyObjectResult><ETag>"x"</ETag></CopyObjectResult>');
            }
            if (null !== $this->put_status) {
                return self::httpReply($this->put_status, '<?xml version="1.0"?><Error><Code>AuthorizationFailure</Code><Message>write refused</Message></Error>');
            }
            $this->store[$key] = (string) ($args['body'] ?? '');
            return self::httpReply('azure' === $this->provider ? 201 : 200, '', ['etag' => '"' . md5($this->store[$key]) . '"']);
        }

        if ('HEAD' === $method) {
            return isset($this->store[$key])
                ? self::httpReply(200, '', ['content-length' => (string) strlen($this->store[$key])])
                : self::httpReply(404);
        }

        if ('GET' === $method) {
            return isset($this->store[$key])
                ? self::httpReply(200, $this->store[$key], ['content-length' => (string) strlen($this->store[$key])])
                : self::httpReply(404, '<?xml version="1.0"?><Error><Code>BlobNotFound</Code><Message>missing</Message></Error>');
        }

        if ('DELETE' === $method) {
            unset($this->store[$key]);
            return self::httpReply('azure' === $this->provider ? 202 : 204);
        }

        return self::httpReply(400);
    }

    private function listing(string $prefix): string {
        $keys = array_values(array_filter(array_keys($this->store), static fn($k) => 0 === strpos($k, $prefix)));
        sort($keys);
        $items = '';
        foreach ($keys as $k) {
            $size   = strlen($this->store[$k]);
            $items .= 'azure' === $this->provider
                ? "<Blob><Name>{$k}</Name><Properties><Content-Length>{$size}</Content-Length><Last-Modified>Mon, 01 Jan 2026 00:00:00 GMT</Last-Modified></Properties></Blob>"
                : "<Contents><Key>{$k}</Key><Size>{$size}</Size><ETag>\"e\"</ETag><LastModified>2026-01-01T00:00:00Z</LastModified></Contents>";
        }
        return 'azure' === $this->provider
            ? '<?xml version="1.0" encoding="utf-8"?><EnumerationResults><Blobs>' . $items . '</Blobs><NextMarker></NextMarker></EnumerationResults>'
            : '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult><IsTruncated>false</IsTruncated>' . $items . '</ListBucketResult>';
    }

    private function cloud(string $relative): string {
        return wp_upload_dir()['basedir'] . '/' . ltrim($relative, '/');
    }

    // ── The wrapper on a real provider ─────────────────────

    /** @dataProvider providers */
    public function test_a_write_and_a_read_go_through_the_provider(string $provider): void {
        $this->connect($provider);
        $path = $this->cloud('2026/10/hello.txt');
        $this->assertStringStartsWith('diluxoneoffload://uploads/', $path);

        $this->assertSame(5, file_put_contents($path, 'hello'));
        $this->assertSame('hello', $this->store['uploads/2026/10/hello.txt'] ?? null);
        $put = $this->httpRequests('PUT')[0];
        $this->assertSame('azure' === $provider ? 'https://wpintacct.blob.core.windows.net/media/uploads/2026/10/hello.txt' : 'https://s3.wpint.test/media/uploads/2026/10/hello.txt', $put['url']);
        $this->assertNotSame('', self::header($put['args'], 'Authorization'), 'signed');
        $this->assertGreaterThan(0, (int) ($put['args']['timeout'] ?? 0), 'an explicit timeout');
        $this->assertNotNull($this->rowOf('uploads/2026/10/hello.txt'));

        CloudStreamWrapper::clear_file_cache();
        $this->assertSame('hello', file_get_contents($path), 'read back from the service');
        $this->assertNotEmpty($this->httpRequests('GET'));
    }

    /** @dataProvider providers */
    public function test_existence_is_asked_with_a_head_request(string $provider): void {
        $this->connect($provider);
        $this->store['uploads/2026/10/here.jpg'] = 'jpg';
        $this->assertTrue(file_exists($this->cloud('2026/10/here.jpg')));
        $this->assertFalse(file_exists($this->cloud('2026/10/absent.jpg')));
        $this->assertCount(2, $this->httpRequests('HEAD'));
    }

    /** @dataProvider providers */
    public function test_a_missing_object_cannot_be_opened_for_reading(string $provider): void {
        $this->connect($provider);
        $this->assertFalse(@fopen($this->cloud('2026/10/nothing.txt'), 'r'));
    }

    /** @dataProvider providers */
    public function test_unlink_and_rename_reach_the_service_and_the_table(string $provider): void {
        $this->connect($provider);
        file_put_contents($this->cloud('2026/10/tmp.css'), 'css');

        $this->assertTrue(rename($this->cloud('2026/10/tmp.css'), $this->cloud('2026/10/final.css')));
        $this->assertSame(['uploads/2026/10/final.css'], array_keys($this->store));
        $this->assertNull($this->rowOf('uploads/2026/10/tmp.css'));
        $this->assertNotNull($this->rowOf('uploads/2026/10/final.css'));

        $this->assertTrue(unlink($this->cloud('2026/10/final.css')));
        $this->assertSame([], $this->store);
        $this->assertNull($this->rowOf('uploads/2026/10/final.css'));
        $this->assertNotEmpty($this->httpRequests('DELETE'));
    }

    /** @dataProvider providers */
    public function test_a_refused_upload_fails_the_wordpress_upload_and_records_the_status(string $provider): void {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $this->connect($provider);
        $this->put_status = 403;
        $tmp = wp_tempnam('refused.png');
        $im  = imagecreatetruecolor(4, 4);
        imagepng($im, $tmp);
        imagedestroy($im);

        $file   = ['name' => 'refused.png', 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => 0, 'size' => filesize($tmp)];
        $result = wp_handle_sideload($file, ['test_form' => false]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('403', $result['error']);
        $this->assertSame([], $this->store);
        $health = ConfigManager::get_connection_health();
        $this->assertSame('403', $health['error_code']);
        $this->assertSame('upload', $health['error_source']);
        $this->assertCount(1, $this->httpRequests('PUT'), 'a 4xx is never sent again');
    }

    // ── Stats, through the transient ───────────────────────

    /** @dataProvider providers */
    public function test_stats_are_computed_from_the_sites_own_listing_and_cached(string $provider): void {
        $this->connect($provider);
        $this->store = [
            'uploads/2026/10/a.jpg'         => str_repeat('a', 10),
            'uploads/2026/10/b.mp4'         => str_repeat('b', 20),
            'uploads/2026/10/c.pdf'         => str_repeat('c', 30),
            'uploads/sites/2/2026/10/x.jpg' => str_repeat('x', 1000),
        ];
        $client = ConfigManager::get_cloud_client();

        $stats = $client->get_storage_stats();
        $this->assertTrue($stats['success']);
        if (is_multisite()) {
            $this->assertSame(3, $stats['data']['fileCount'], 'another site\'s objects are not the main site\'s');
            $this->assertSame(60, $stats['data']['storageUsedBytes']);
        }
        $this->assertSame(1, $stats['data']['filesByType']['videos']);
        $this->assertSame($stats['data'], get_transient(ConfigManager::STATS_TRANSIENTS[$provider]));
        $this->assertSame(['success' => true, 'data' => $stats['data']], ConfigManager::get_cached_cloud_stats());

        $listings = count($this->httpRequests('GET'));
        $client->get_storage_stats();
        $this->assertCount($listings, $this->httpRequests('GET'), 'the second call is the transient');
        $client->get_storage_stats(true);
        $this->assertGreaterThan($listings, count($this->httpRequests('GET')), 'a forced refresh lists again');
    }

    /** @dataProvider providers */
    public function test_a_refused_listing_drops_the_cached_stats_and_records_the_failure(string $provider): void {
        $this->connect($provider);
        set_transient(ConfigManager::STATS_TRANSIENTS[$provider], ['fileCount' => 99], 300);
        $this->list_status = 403;

        $stats = ConfigManager::get_cloud_client()->get_storage_stats(true);

        $this->assertFalse($stats['success']);
        $this->assertStringContainsString('403', $stats['message']);
        $this->assertFalse(get_transient(ConfigManager::STATS_TRANSIENTS[$provider]));
        $health = ConfigManager::get_connection_health();
        $this->assertSame('unhealthy', $health['status']);
        $this->assertSame('403', $health['error_code']);
        $this->assertCount(1, $this->httpRequests('GET'), 'a 403 is not asked again');
    }

    private function rowOf(string $key): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . self::$table_name . '` WHERE file = %s', DiluxOneOffloadDB::path_from_key($key)), ARRAY_A);
        return $row ?: null;
    }
}
