<?php
namespace Tests\Integration;

use DiluxOneOffload\Interfaces\CloudStorageClientInterface;

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

    /** @var int Download handles handed out (one per attempted download). */
    public int $downloads = 0;

    public function __construct(private string $upload_base_url) {}

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

    public function delete_file(string $remote_path): array {
        $key = ltrim($remote_path, '/');
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
        $out = [];
        foreach ($this->blobs as $path => $content) {
            if ($remote_path === '' || strpos($path, ltrim($remote_path, '/')) === 0) {
                $out[] = ['path' => $path, 'size' => strlen($content), 'md5' => '', 'last_modified' => ''];
            }
        }
        return $out;
    }

    public function get_file_url(string $remote_path): string {
        return 'https://fake.cloud/' . ltrim($remote_path, '/');
    }

    public function get_provider_name(): string {
        return 'fake';
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

    public function prepare_chunked_upload_handle(array $file_info): array {
        return $this->prepare_batch_upload_handle($file_info);
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
