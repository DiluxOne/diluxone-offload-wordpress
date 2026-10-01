<?php
namespace Tests\Integration;

/**
 * The provider stand-in, able to fail the way a real one does: a call that
 * throws (a transport layer that raises), an upload or a download the
 * service refuses with a message. Everything else behaves like
 * FakeCloudClient.
 */
class FaultyCloudClient extends FakeCloudClient {

    /** @var array<string, string> Method name => message of the exception it throws. */
    public array $throws = [];

    /** @var string|null When set, upload_file() answers this failure. */
    public ?string $upload_error = null;

    /** @var string|null When set, download_file() answers this failure. */
    public ?string $download_error = null;

    /** @var int upload_file() calls. */
    public int $uploads = 0;

    private function maybe_throw(string $method): void {
        if (isset($this->throws[$method])) {
            throw new \Exception($this->throws[$method]);
        }
    }

    public function test_connection(): array {
        $this->maybe_throw(__FUNCTION__);
        return parent::test_connection();
    }

    public function upload_file(string $local_path, string $remote_path, array $options = []): array {
        ++$this->uploads;
        $this->maybe_throw(__FUNCTION__);
        if (null !== $this->upload_error) {
            return ['success' => false, 'url' => '', 'error' => $this->upload_error];
        }
        return parent::upload_file($local_path, $remote_path, $options);
    }

    public function download_file(string $remote_path, string $local_path): array {
        $this->maybe_throw(__FUNCTION__);
        if (null !== $this->download_error) {
            return ['success' => false, 'error' => $this->download_error];
        }
        return parent::download_file($remote_path, $local_path);
    }

    public function file_exists(string $remote_path): bool {
        $this->maybe_throw(__FUNCTION__);
        return parent::file_exists($remote_path);
    }

    public function delete_file(string $remote_path): array {
        $this->maybe_throw(__FUNCTION__);
        return parent::delete_file($remote_path);
    }

    public function copy_blob(string $source_path, string $dest_path): array {
        $this->maybe_throw(__FUNCTION__);
        return parent::copy_blob($source_path, $dest_path);
    }

    public function get_file_url(string $remote_path): string {
        $this->maybe_throw(__FUNCTION__);
        return parent::get_file_url($remote_path);
    }

    public function get_storage_stats(bool $force_refresh = false): array {
        $this->maybe_throw(__FUNCTION__);
        return parent::get_storage_stats($force_refresh);
    }
}
