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
