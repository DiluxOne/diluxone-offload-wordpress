<?php
namespace Tests\Integration;

use DiluxOneOffload\DTOs\ChunkedUpload;
use DiluxOneOffload\Interfaces\CloudStorageClientInterface;

/**
 * Runs a chunked upload's handles one by one, the way the sync's pool runs
 * them, so a real-storage test can drive the provider's part, resume and
 * commit calls against the real service.
 */
trait SendsParts {

    /** Send one part and read its answer: null when it landed, else the error line. */
    private static function sendPart(CloudStorageClientInterface $provider, ChunkedUpload $upload, int $part): ?string {
        $h = $provider->prepare_part_handle($upload, $part);
        if (empty($h['success'])) {
            return (string) $h['error'];
        }
        $body   = (string) curl_exec($h['handle']);
        $status = (int) curl_getinfo($h['handle'], CURLINFO_HTTP_CODE);
        $error  = curl_error($h['handle']);
        fclose($h['file_handle']);
        return '' !== $error ? $error : $provider->finish_part($upload, $part, $status, $body);
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
