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
	 * @return array<string, mixed> List of files
	 */
	public function list_files( string $remote_path = '' ): array;

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
	 * Prepare chunked upload handle for large files (>10MB)
	 * Used by SyncManager for optimized chunked uploads
	 *
	 * @param array<string, mixed> $file_info File information ['local_path' => string, 'remote_path' => string]
	 * @return array<string, mixed> ['success' => bool, 'handle' => resource|null, 'error' => string, 'file_handle' => resource|null]
	 */
	public function prepare_chunked_upload_handle( array $file_info ): array;

	/**
	 * Prepare download handle for parallel downloads
	 * Used by SyncManager for reverse sync (disconnect operation)
	 *
	 * @param array<string, mixed> $file_info File information ['local_path' => string, 'remote_path' => string]
	 * @return array<string, mixed> ['success' => bool, 'handle' => resource|null, 'error' => string, 'file_handle' => resource|null]
	 */
	public function prepare_download_handle( array $file_info ): array;
}
