<?php
namespace Tests\Integration;

use DiluxOneOffload\DTOs\ChunkedUpload;
use DiluxOneOffload\Interfaces\CloudStorageClientInterface;
use DiluxOneOffload\Providers\PartUpload;

/**
 * A cloud provider stand-in for integration tests.
 *
 * Everything the sync engine asks of a provider is answered from memory —
 * except the parallel upload path, which works on real cURL handles driven
 * through curl_multi_*. For that it hands back real handles pointing at a
 * throwaway local HTTP server (see LocalBlobServer) that answers with the
 * status the test chose, so the actual multi loop runs, not a copy of it.
 */
class FakeCloudClient implements CloudStorageClientInterface {

    use PartUpload;

    /** @var array<string, string> remote path => content */
    public array $blobs = [];

    /** @var string[] remote paths deleted */
    public array $deleted = [];

    /** @var int HTTP status the local server answers uploads with. */
    public int $upload_status = 201;

    /** @var int HTTP status the local server answers downloads with. */
    public int $download_status = 200;

    /** @var bool Make test_connection() fail. */
    public bool $connection_ok = true;

    /** @var string|null When set, verify_upload_response() refuses every upload with this line. */
    public ?string $upload_verdict = null;

    /** @var string|null When set, list_files() throws with this message. */
    public ?string $list_error = null;

    /** @var string[] Remote paths delete_file() refuses (HTTP 403). */
    public array $undeletable = [];

    /** @var int Download handles handed out (one per attempted download). */
    public int $downloads = 0;

    /**
     * @param string $upload_base_url Where the curl handles point (a LocalBlobServer).
     * @param string $public_base_url The URL the client hands out for media; http:// lets a
     *                                test prove what Force HTTPS does to it.
     */
    public function __construct(private string $upload_base_url, private string $public_base_url = 'https://fake.cloud') {}

    public function test_connection(): array {
        return $this->connection_ok
            ? ['success' => true, 'message' => 'fake ok']
            : ['success' => false, 'message' => 'HTTP 403 fake refusal'];
    }

    public function upload_file(string $local_path, string $remote_path, array $options = []): array {
        if (!file_exists($local_path)) {
            return ['success' => false, 'url' => '', 'error' => 'missing'];
        }
        $this->blobs[ltrim($remote_path, '/')] = (string) file_get_contents($local_path);
        return ['success' => true, 'url' => $this->get_file_url($remote_path), 'error' => ''];
    }

    public function download_file(string $remote_path, string $local_path): array {
        $key = ltrim($remote_path, '/');
        if (!isset($this->blobs[$key])) {
            return ['success' => false, 'error' => 'HTTP 404'];
        }
        wp_mkdir_p(dirname($local_path));
        file_put_contents($local_path, $this->blobs[$key]);
        return ['success' => true, 'error' => ''];
    }

    public function file_exists(string $remote_path): bool {
        return isset($this->blobs[ltrim($remote_path, '/')]);
    }

    public function get_file_checksum(string $remote_path) {
        $key = ltrim($remote_path, '/');
        return isset($this->blobs[$key]) ? base64_encode(md5($this->blobs[$key], true)) : false;
    }

    public function get_file_info(string $remote_path) {
        $key = ltrim($remote_path, '/');
        if (!isset($this->blobs[$key])) {
            return false;
        }
        return ['path' => $key, 'size' => strlen($this->blobs[$key]), 'md5' => $this->get_file_checksum($key), 'last_modified' => gmdate('D, d M Y H:i:s') . ' GMT'];
    }

    /** @var callable|null Runs before each delete, with the key: something else happening meanwhile. */
    public $on_delete = null;

    public function delete_file(string $remote_path): array {
        $key = ltrim($remote_path, '/');
        if (null !== $this->on_delete) {
            ($this->on_delete)($key);
        }
        if (in_array($key, $this->undeletable, true)) {
            return ['success' => false, 'error' => 'HTTP 403 fake refusal'];
        }
        unset($this->blobs[$key]);
        $this->deleted[] = $key;
        return ['success' => true, 'error' => ''];
    }

    public function copy_blob(string $source_path, string $dest_path): array {
        $src = ltrim($source_path, '/');
        if (!isset($this->blobs[$src])) {
            return ['success' => false, 'error' => 'HTTP 404'];
        }
        $this->blobs[ltrim($dest_path, '/')] = $this->blobs[$src];
        return ['success' => true, 'error' => ''];
    }

    public function list_files(string $remote_path = ''): array {
        if (null !== $this->list_error) {
            throw new \Exception($this->list_error);
        }
        $out = [];
        foreach ($this->blobs as $path => $content) {
            if ($remote_path === '' || strpos($path, ltrim($remote_path, '/')) === 0) {
                $out[] = ['path' => $path, 'size' => strlen($content), 'md5' => '', 'last_modified' => ''];
            }
        }
        return $out;
    }

    /** Objects per page of list_page(), like a provider's page limit. */
    public int $page_size = 1000;

    /** Pages list_page() has served. */
    public int $pages_listed = 0;

    /** Pages in key order; the marker is the last key of the page before, like S3's position-based token. */
    public function list_page(string $prefix, string $marker = ''): array {
        if (null !== $this->list_error) {
            throw new \Exception($this->list_error);
        }
        ++$this->pages_listed;
        $keys = array_keys($this->blobs);
        sort($keys, SORT_STRING);
        $keys = array_values(array_filter($keys, static fn($k) => strpos($k, ltrim($prefix, '/')) === 0 && ($marker === '' || strcmp($k, $marker) > 0)));
        $page = array_slice($keys, 0, $this->page_size);
        return [
            'files' => array_map(fn($k) => ['path' => $k, 'size' => strlen($this->blobs[$k]), 'md5' => '', 'last_modified' => ''], $page),
            'next'  => count($keys) > $this->page_size ? (string) end($page) : '',
        ];
    }

    public function get_file_url(string $remote_path): string {
        return rtrim($this->public_base_url, '/') . '/' . ltrim($remote_path, '/');
    }

    public function get_provider_name(): string {
        return 'fake';
    }

    public function get_storage_stats(bool $force_refresh = false): array {
        return ['success' => true, 'data' => ['fileCount' => count($this->blobs), 'storageUsedBytes' => array_sum(array_map('strlen', $this->blobs))]];
    }

    public function describe_error_body(string $body): string {
        return '' === $body ? '' : ' - Fake ' . $body;
    }

    public function verify_upload_response(int $status, string $body): ?string {
        if (null !== $this->upload_verdict) {
            return $this->upload_verdict;
        }
        return $status >= 200 && $status < 300 ? null : 'HTTP ' . $status . $this->describe_error_body($body);
    }

    /** Real cURL handle so SyncManager's curl_multi loop runs for real. */
    public function prepare_batch_upload_handle(array $file_info): array {
        $fh = fopen($file_info['local_path'], 'rb');
        if (!$fh) {
            return ['success' => false, 'error' => 'open failed', 'file_handle' => null];
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->upload_base_url . '/' . ltrim($file_info['remote_path'], '/') . '?status=' . $this->upload_status,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_UPLOAD         => true,
            CURLOPT_INFILE         => $fh,
            CURLOPT_INFILESIZE     => filesize($file_info['local_path']),
            CURLOPT_TIMEOUT        => 10,
        ]);
        // Record what would land in the cloud when the server accepts it.
        if ($this->upload_status < 300) {
            $this->blobs[ltrim($file_info['remote_path'], '/')] = (string) file_get_contents($file_info['local_path']);
        }
        return ['success' => true, 'handle' => $ch, 'file_handle' => $fh];
    }

    /** @var string[] Remote paths whose chunked upload the engine dropped (a failed part or commit). */
    public array $abandoned = [];

    /** @var int Bytes per part of a chunked upload. */
    public int $part_size = 1048576;

    /** @var int HTTP status the local server answers a part with, unless part_statuses says otherwise. */
    public int $part_status = 201;

    /** @var array<int, int[]> Part number => statuses for its attempts, one each, in order; then part_status. */
    public array $part_statuses = [];

    /** @var int Parts sent, retries included. */
    public int $part_requests = 0;

    /** @var array<string, array<int, string>> Bytes of every part that landed, per key and part number. */
    private array $parts = [];

    public function begin_chunked_upload(array $file_info): array {
        $size = is_file($file_info['local_path']) ? (int) filesize($file_info['local_path']) : 0;
        if ($size <= 0) {
            return ['success' => false, 'error' => 'File not found: ' . $file_info['local_path']];
        }
        return ['success' => true, 'upload' => new ChunkedUpload($file_info['local_path'], ltrim($file_info['remote_path'], '/'), $size, $this->part_size, 'fake-upload')];
    }

    /** A real PUT of the part's bytes, streamed from the file the way the providers do it. */
    public function prepare_part_handle(ChunkedUpload $upload, int $part): array {
        ++$this->part_requests;
        $status = !empty($this->part_statuses[$part]) ? array_shift($this->part_statuses[$part]) : $this->part_status;
        $ch     = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->upload_base_url . '/' . $upload->remotePath() . '?part=' . $part . '&status=' . $status,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_TIMEOUT        => 10,
        ]);
        $fh = self::stream_part($ch, $upload, $part);
        if ($status < 300) {
            $this->parts[$upload->remotePath()][$part] = (string) file_get_contents($upload->localPath(), false, null, $upload->offset($part), $upload->length($part));
        }
        return ['success' => true, 'handle' => $ch, 'file_handle' => $fh];
    }

    public function finish_part(ChunkedUpload $upload, int $part, int $status, string $body): ?string {
        if ($status < 200 || $status >= 300) {
            return 'Failed part ' . $part . ': HTTP ' . $status . $this->describe_error_body($body);
        }
        $upload->recordTag($part, 'tag-' . $part);
        return null;
    }

    public function prepare_commit_handle(ChunkedUpload $upload): array {
        $tags = $upload->tags();
        if (null === $tags) {
            return ['success' => false, 'error' => 'untagged part', 'handle' => null, 'file_handle' => null];
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->upload_base_url . '/' . $upload->remotePath() . '?commit=1&status=' . $this->upload_status,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_TIMEOUT        => 10,
        ]);
        if ($this->upload_status < 300) {
            $landed = $this->parts[$upload->remotePath()] ?? [];
            ksort($landed);
            $this->blobs[$upload->remotePath()] = implode('', array_intersect_key($landed, $tags));
        }
        return [
            'success'     => true,
            'handle'      => $ch,
            'file_handle' => null,
            'on_failure'  => function () use ($upload): void {
                $this->abort_chunked_upload($upload);
            },
        ];
    }

    public function abort_chunked_upload(ChunkedUpload $upload): void {
        $this->abandoned[] = $upload->remotePath();
        unset($this->parts[$upload->remotePath()]);
    }

    /**
     * Real cURL handle for the reverse-sync download loop. The blob's content
     * is written to the local file up front; the GET to the local server then
     * answers 200 with an empty body, so CURLOPT_FILE appends nothing and the
     * file ends up holding exactly what the "cloud" had.
     */
    public function prepare_download_handle(array $file_info): array {
        ++$this->downloads;
        $key = ltrim($file_info['remote_path'], '/');
        if (!isset($this->blobs[$key])) {
            return ['success' => false, 'error' => 'HTTP 404', 'file_handle' => null];
        }
        wp_mkdir_p(dirname($file_info['local_path']));
        // Like the real provider: the bytes land in a sibling part file that
        // SyncManager renames over the attachment once the download checks out.
        $part = $file_info['local_path'] . '.dlxpart';
        file_put_contents($part, $this->blobs[$key]);
        $fh = fopen($part, 'ab');
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->upload_base_url . '/' . $key . '?status=' . $this->download_status,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FILE           => $fh,
            CURLOPT_TIMEOUT        => 10,
        ]);
        return ['success' => true, 'handle' => $ch, 'file_handle' => $fh, 'part_path' => $part];
    }
}
