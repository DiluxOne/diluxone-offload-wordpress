<?php
/**
 * Contract that every cloud-storage provider implementation must satisfy.
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload\Interfaces;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cloud Storage Client Interface
 *
 * Generic interface for all cloud storage providers
 *
 * @package DiluxOneOffload
 * @since 1.0.0
 */
interface CloudStorageClientInterface {

	/**
	 * Test connection to cloud storage
	 *
	 * @return array<string, mixed> ['success' => bool, 'message' => string]
	 */
	public function test_connection(): array;

	/**
	 * Upload a file to cloud storage
	 *
	 * @param string               $local_path Local file path
	 * @param string               $remote_path Remote path in cloud
	 * @param array<string, mixed> $options Additional options
	 * @return array<string, mixed> ['success' => bool, 'url' => string, 'error' => string]
	 */
	public function upload_file( string $local_path, string $remote_path, array $options = array() ): array;

	/**
	 * Download a file from cloud storage
	 *
	 * @param string $remote_path Remote path in cloud
	 * @param string $local_path Local destination path
	 * @return array<string, mixed> ['success' => bool, 'error' => string]
	 */
	public function download_file( string $remote_path, string $local_path ): array;

	/**
	 * Check if file exists in cloud storage
	 *
	 * @param string $remote_path Remote path
	 * @return bool
	 */
	public function file_exists( string $remote_path ): bool;

	/**
	 * Get file checksum from cloud storage
	 *
	 * @param string $remote_path Remote path
	 * @return string|false MD5 hash or false if error
	 */
	public function get_file_checksum( string $remote_path );

	/**
	 * Get file information from cloud storage
	 *
	 * @param string $remote_path Remote path
	 * @return array<string, mixed>|false File info ['size' => int, 'md5' => string, 'last_modified' => string] or false
	 */
	public function get_file_info( string $remote_path );

	/**
	 * Delete file from cloud storage
	 *
	 * A success means the file is gone, including when it was not there to
	 * begin with; a failure means it may still be there (a network error, a
	 * permission error, a server error). Callers that keep a record of what
	 * the cloud holds rely on that distinction.
	 *
	 * @param string $remote_path Remote path
	 * @return array<string, mixed> ['success' => bool, 'error' => string]
	 */
	public function delete_file( string $remote_path ): array;

	/**
	 * Copy file from one location to another within cloud storage
	 * Used by rename() operation (copy + delete)
	 *
	 * @param string $source_path Source remote path
	 * @param string $dest_path Destination remote path
	 * @return array<string, mixed> ['success' => bool, 'error' => string]
	 */
	public function copy_blob( string $source_path, string $dest_path ): array;

	/**
	 * List files in cloud storage directory
	 *
	 * @param string $remote_path Remote directory path
	 * @return array<int, array<string, mixed>> One ['path', 'size', 'md5', 'last_modified'] per object
	 */
	public function list_files( string $remote_path = '' ): array;

	/**
	 * One page of the objects under a prefix, in key order, and where the
	 * next page starts. For work done a page at a time across requests
	 * (emptying a prefix); list_files() lists everything in one call.
	 *
	 * @param string $prefix Key prefix.
	 * @param string $marker Where the page starts: '' for the first page, or the `next` of the page before.
	 * @return array{files: array<int, array<string, mixed>>, next: string} `next` is '' on the last page.
	 * @throws \Exception When the listing fails.
	 */
	public function list_page( string $prefix, string $marker = '' ): array;

	/**
	 * Get file URL for public access
	 *
	 * @param string $remote_path Remote path
	 * @return string Public URL
	 */
	public function get_file_url( string $remote_path ): string;

	/**
	 * Get storage provider name
	 *
	 * @return string Provider name (azure)
	 */
	public function get_provider_name(): string;

	/**
	 * Usage stats of the current site's files, cached for five minutes in
	 * the transient ConfigManager::STATS_TRANSIENTS names for this provider.
	 *
	 * @param bool $force_refresh Skip the cache and list the storage again.
	 * @return array{success: bool, data?: array<string, mixed>, message?: string}
	 */
	public function get_storage_stats( bool $force_refresh = false ): array;

	/**
	 * The error code and message of a raw error response body, for a log
	 * line or the failed-files list: ' - <service> <Code>: <Message>', or ''
	 * when the body carries neither. Never the signature or the string the
	 * server signed, which some services echo back on an authentication error.
	 *
	 * @param string $body Raw response body.
	 * @return string
	 */
	public function describe_error_body( string $body ): string;

	/**
	 * Whether a transfer the sync engine ran from one of this provider's
	 * upload handles (batch, or the commit of a chunked upload) succeeded.
	 *
	 * @param int    $status HTTP status of the response.
	 * @param string $body   Raw response body.
	 * @return string|null Null on success; otherwise the error line, starting with 'HTTP <status>'.
	 */
	public function verify_upload_response( int $status, string $body ): ?string;

	/**
	 * Prepare batch upload handle for parallel sync
	 * Used by SyncManager for optimized parallel uploads
	 *
	 * @param array<string, mixed> $file_info File information ['local_path' => string, 'remote_path' => string]
	 * @return array<string, mixed> ['success' => bool, 'handle' => resource|null, 'error' => string, 'file_handle' => resource|null]
	 */
	public function prepare_batch_upload_handle( array $file_info ): array;

	/**
	 * Start a large file's upload in parts, for the sync's pool: the sync
	 * then sends every part through prepare_part_handle() alongside the other
	 * transfers, reads each answer with finish_part(), and commits with
	 * prepare_commit_handle() once every part has its tag. Nothing is sent
	 * here but what names the upload (S3's CreateMultipartUpload; Azure
	 * needs no call, its upload is named by a nonce its block ids carry).
	 *
	 * With $resume_upload_id, the upload an earlier request left unfinished
	 * is taken up: the provider asks the service which parts it holds (S3's
	 * ListParts, Azure's uncommitted block list) and tags those whose size is
	 * right, so only the rest is sent. When the service no longer knows the
	 * upload, a new one starts.
	 *
	 * @param array<string, mixed> $file_info        File information ['local_path' => string, 'remote_path' => string]
	 * @param string|null          $resume_upload_id The name of the unfinished upload to take up (an S3 UploadId, an Azure nonce, or `#` and the SHA-1 of a name too long for the row, which the provider resolves), or null for a new one.
	 * @return array<string, mixed> ['success' => bool, 'error' => string, 'upload' => ChunkedUpload]
	 */
	public function begin_chunked_upload( array $file_info, ?string $resume_upload_id = null ): array;

	/**
	 * A cURL handle that sends one part, read from the file as it goes (no
	 * part is held in memory). The provider records the part's tag on the
	 * upload when the answer carries it in a header.
	 *
	 * @param \DiluxOneOffload\DTOs\ChunkedUpload $upload The upload.
	 * @param int                                 $part   Part number, from 1.
	 * @return array<string, mixed> ['success' => bool, 'handle' => resource|null, 'error' => string, 'file_handle' => resource|null]
	 */
	public function prepare_part_handle( \DiluxOneOffload\DTOs\ChunkedUpload $upload, int $part ): array;

	/**
	 * Read the answer to one part: null when it landed and its tag is on the
	 * upload, else the error line (the error code of the body at most, never
	 * the signature).
	 *
	 * @param \DiluxOneOffload\DTOs\ChunkedUpload $upload The upload.
	 * @param int                                 $part   Part number.
	 * @param int                                 $status HTTP status.
	 * @param string                              $body   Response body.
	 * @return string|null
	 */
	public function finish_part( \DiluxOneOffload\DTOs\ChunkedUpload $upload, int $part, int $status, string $body ): ?string;

	/**
	 * The cURL handle that assembles the parts into the object, read like
	 * any upload through verify_upload_response(). Its `on_failure` drops the
	 * upload when the commit fails.
	 *
	 * @param \DiluxOneOffload\DTOs\ChunkedUpload $upload The upload, every part tagged.
	 * @return array<string, mixed> ['success' => bool, 'handle' => resource|null, 'error' => string, 'file_handle' => null, 'on_failure' => callable]
	 */
	public function prepare_commit_handle( \DiluxOneOffload\DTOs\ChunkedUpload $upload ): array;

	/**
	 * Give up an upload whose part failed, so the parts sent are not kept
	 * (S3 aborts the multipart upload; Azure discards uncommitted blocks on
	 * its own after a week and needs no call). Its name may be the `#` form
	 * of a token, as begin_chunked_upload() takes it.
	 *
	 * @param \DiluxOneOffload\DTOs\ChunkedUpload $upload The upload.
	 */
	public function abort_chunked_upload( \DiluxOneOffload\DTOs\ChunkedUpload $upload ): void;

	/**
	 * Prepare download handle for parallel downloads
	 * Used by SyncManager for reverse sync (disconnect operation)
	 *
	 * @param array<string, mixed> $file_info File information ['local_path' => string, 'remote_path' => string]
	 * @return array<string, mixed> ['success' => bool, 'handle' => resource|null, 'error' => string, 'file_handle' => resource|null]
	 */
	public function prepare_download_handle( array $file_info ): array;
}
