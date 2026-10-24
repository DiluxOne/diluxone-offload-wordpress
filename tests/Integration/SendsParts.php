<?php
namespace Tests\Integration;

use DiluxOneOffload\DTOs\ChunkedUpload;
use DiluxOneOffload\Interfaces\CloudStorageClientInterface;

/**
 * Runs a chunked upload's handles one by one, the way the sync's pool runs
 * them, so a real-storage test can drive the provider's part, resume and
 * commit calls against the real service. A whole file or a part is sent
 * again as the sync sends it again (SyncManager::part_result): up to three
 * tries, on a 500 to 504 or a dropped connection, never on a timeout or a
 * 4xx. A commit is never sent again, as in the plugin.
 */
trait SendsParts {

    /** Send one part and read its answer: null when it landed, else the error line. */
    private static function sendPart(CloudStorageClientInterface $provider, ChunkedUpload $upload, int $part): ?string {
        return self::sendRetrying(
            static fn (): array => $provider->prepare_part_handle($upload, $part),
            static fn (int $status, string $body): ?string => $provider->finish_part($upload, $part, $status, $body)
        );
    }

    /**
     * Run a transfer, with a fresh handle (and file handle) on each try, and
     * read its answer: null when it landed, else the last error line.
     *
     * @param callable(): array<string, mixed>   $prepare The provider's handle for it.
     * @param callable(int, string): ?string     $verdict The provider's reading of a status and body.
     */
    private static function sendRetrying(callable $prepare, callable $verdict): ?string {
        for ($try = 1; ; $try++) {
            $h = $prepare();
            if (empty($h['success'])) {
                return (string) $h['error'];
            }
            $body   = (string) curl_exec($h['handle']);
            $status = (int) curl_getinfo($h['handle'], CURLINFO_HTTP_CODE);
            $errno  = curl_errno($h['handle']);
            $error  = curl_error($h['handle']);
            if (is_resource($h['file_handle'] ?? null)) {
                fclose($h['file_handle']);
            }
            $result = '' !== $error ? $error : $verdict($status, $body);
            if (null === $result) {
                return null;
            }
            // A timeout already spent its time and a 4xx will not change; a 5xx
            // or a dropped connection is the service asking to be asked again.
            $transient = '' !== $error ? CURLE_OPERATION_TIMEDOUT !== $errno : $status >= 500 && $status <= 504;
            if (!$transient || $try >= 3) {
                return $result;
            }
        }
    }

    /** Commit the parts: null when the object was assembled, else the error line. */
    private static function commitParts(CloudStorageClientInterface $provider, ChunkedUpload $upload): ?string {
        $h = $provider->prepare_commit_handle($upload);
        if (empty($h['success'])) {
            return (string) $h['error'];
        }
        $body = (string) curl_exec($h['handle']);
        return $provider->verify_upload_response((int) curl_getinfo($h['handle'], CURLINFO_HTTP_CODE), $body);
    }
}
