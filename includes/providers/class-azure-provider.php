<?php
/**
 * Azure Blob Storage Provider
 *
 * Talks to the Azure Blob REST API with a shared-key signature.
 *
 * cURL is confined to the parallel/streaming transfer path. No request is
 * executed here: prepare_batch_upload_handle(), prepare_part_handle(),
 * prepare_commit_handle() and prepare_download_handle() only build the handles that SyncManager then
 * runs through curl_multi_*, so many files move at once and multi-GB bodies
 * stream from a file handle instead of being buffered in PHP memory. The WP
 * HTTP API has no equivalent: it offers no streamed request body and no
 * parallel transport. Everything else — auth, metadata, existence checks,
 * checksums, delete, copy, single-file upload and download, and each block of
 * a file the stream wrapper writes — goes through wp_remote_*.
 *
 * The fopen/fread/fclose/file_get_contents calls operate on the local temp
 * files feeding those transfers, with one exception: prepare_download_handle()
 * opens the attachment's own path under the uploads directory, because that
 * is where "Disconnect from Cloud" puts the user's media back (see its
 * docblock). \WP_Filesystem does not apply to either.
 *
 * Only the rules below are suppressed, and only because of the above:
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init
 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
 *
 * @package DiluxOneOffload\Providers
 * @since 1.0.0
 */

namespace DiluxOneOffload\Providers;

use DiluxOneOffload\Interfaces\CloudStorageClientInterface;
use DiluxOneOffload\Logger;
use DiluxOneOffload\MimeHelper;
use DiluxOneOffload\DTOs\AzureConfig;
use DiluxOneOffload\DTOs\ChunkedUpload;
use DiluxOneOffload\DTOs\ConnectionResult;
use DiluxOneOffload\DTOs\UploadResult;
use DiluxOneOffload\DTOs\OperationResult;
use DiluxOneOffload\DTOs\FileInfo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Azure Blob Storage Provider
 *
 * Implementation of CloudStorageClientInterface for Azure Blob Storage.
 */
class AzureProvider implements CloudStorageClientInterface {

	use StorageStats;
	use TransientRetry;
	use PartUpload;

	/**
	 * Bytes per block, and the largest file sent in a single Put Blob request.
	 *
	 * It is the ceiling on how much of a file this class ever holds at once:
	 * a bigger file is read and sent one block at a time. Azure allows far
	 * larger single writes, and Microsoft's own SDK draws this same line at
	 * 32 MiB; 4 MiB keeps the ceiling well under any memory_limit worth
	 * supporting.
	 */
	private const BLOCK_SIZE = 4194304;

	/**
	 * Content-Type sent with — and signed into — each Put Block request.
	 *
	 * A block has no type of its own; the blob's type is set when the block
	 * list is committed. What matters is that the header and the signature
	 * say the same thing, because the WordPress HTTP API fills in
	 * application/x-www-form-urlencoded for a PUT that does not state one,
	 * and Azure then rejects the request.
	 */
	private const BLOCK_CONTENT_TYPE = 'application/octet-stream';

	/** @var string */
	private string $storage_account;
	/** @var string */
	private string $container_name;
	/** @var string */
	private string $access_key;

	/**
	 * Seconds one transfer of file data may take: the "Transfer Timeout"
	 * setting. Every request that carries file bytes honours it (a single
	 * PUT, each block, the block-list commit, the batch and chunked handles,
	 * a download to disk). Control requests (HEAD, listing, the health
	 * probe) keep their own short, fixed timeouts: a control request should
	 * not wait ten minutes.
	 *
	 * @var int
	 */
	private int $transfer_timeout;
	/**
	 * Seconds a download may take: the setting, but never under the 300 s
	 * downloads always had. A site that never touched the setting keeps
	 * reading its large media; the setting can only make downloads wait
	 * longer, not shorter.
	 *
	 * @var int
	 */
	private int $download_timeout;
	/** @var string */
	private string $endpoint;
	/**
	 * Headers a new blob carries besides its type: `x-ms-blob-cache-control`
	 * and `x-ms-access-tier` (Settings › Serving), when set.
	 *
	 * @var array<string,string>
	 */
	private array $new_blob_headers = array();
	// NOTE: use_https removed - HTTPS is always enforced (Azure requirement)

	/**
	 * Constructor
	 *
	 * Accepts array for backward compatibility, but uses AzureConfig DTO internally
	 *
	 * @param array<string, mixed> $config Configuration array
	 */
	public function __construct( array $config = array() ) {
		// Initialize primitive properties first (backward compatibility)
		$this->storage_account = $config['storage_account'] ?? '';
		$this->container_name  = $config['container_name'] ?? '';
		$this->access_key      = $config['access_key'] ?? '';
		// The key is still `upload_timeout`: ConfigManager::get_cloud_client()
		// passes the setting under that name and nothing else needs to change.
		$this->transfer_timeout = max( 30, (int) ( $config['upload_timeout'] ?? 60 ) );
		$this->download_timeout = max( 300, $this->transfer_timeout );

		$cache_control = (string) ( $config['cache_control'] ?? '' );
		if ( '' !== $cache_control ) {
			$this->new_blob_headers['x-ms-blob-cache-control'] = $cache_control;
		}
		if ( 'infrequent' === ( $config['storage_class'] ?? '' ) ) {
			$this->new_blob_headers['x-ms-access-tier'] = 'Cool';
		}

		// Build endpoint with HTTPS (Azure requirement - always enforced)
		$this->endpoint = "https://{$this->storage_account}.blob.core.windows.net";

		// Validate the config shape via AzureConfig::fromArray() — the DTO
		// itself is not retained because nothing currently reads from it,
		// but the constructor still throws on bad data which we log.
		if ( ! empty( $this->storage_account ) && ! empty( $this->container_name ) && ! empty( $this->access_key ) ) {
			try {
				AzureConfig::fromArray( $config );
				Logger::log( '[DiluxOne Offload AzureProvider] Initialized with account: ' . $this->storage_account, 'info' );
			} catch ( \InvalidArgumentException $e ) {
				Logger::log( '[DiluxOne Offload AzureProvider] Invalid config: ' . $e->getMessage(), 'error' );
			}
		}
	}

	/**
	 * Test connection to Azure Blob Storage
	 *
	 * @return array<string, mixed> ['success' => bool, 'message' => string]
	 */
	public function test_connection(): array {
		$result = $this->test_connection_dto();
		return $result->toArray();
	}

	/**
	 * The object key as it travels in a URL and in the string to sign.
	 *
	 * Each path segment is percent-encoded (spaces, accents, parentheses,
	 * `#`, `%`), the slashes stay. Azure verifies the signature against the
	 * encoded path of the request, so the same string goes into both the URL
	 * and the canonicalized resource: a raw key with a space in it answers
	 * 403, and one with a `#` in it would be cut at the fragment.
	 *
	 * @param string $remote_path Object key, with or without a leading slash.
	 * @return string
	 */
	private function object_path( string $remote_path ): string {
		return implode( '/', array_map( 'rawurlencode', explode( '/', ltrim( $remote_path, '/' ) ) ) );
	}

	/**
	 * Test connection to Azure Blob Storage (internal DTO version)
	 *
	 * @return ConnectionResult
	 */
	private function test_connection_dto(): ConnectionResult {
		try {
			$url     = $this->endpoint . '/' . $this->container_name . '?restype=container';
			$headers = $this->get_auth_headers( 'GET', $url );

			$response = $this->send_with_retry(
				$url,
				array(
					'method'  => 'GET',
					'headers' => $headers,
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $response ) ) {
				return ConnectionResult::failure( 'Connection failed: ' . $response->get_error_message() );
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );

			if ( $response_code === 200 ) {
				// The credentials work; now the part the browser needs. Media is
				// served straight from the container, without the plugin in
				// between, so its public access level has to allow anonymous
				// blob reads. Azure reports it on the container properties; a
				// private container has no such header.
				$header = wp_remote_retrieve_header( $response, 'x-ms-blob-public-access' );
				$access = is_array( $header ) ? (string) reset( $header ) : (string) $header;
				if ( ! in_array( strtolower( $access ), array( 'blob', 'container' ), true ) ) {
					return ConnectionResult::failure( 'Credentials are valid, but the container is private: browsers could not load your media. In the Azure portal set the container\'s public access level to "Blob" (anonymous read access for blobs only), then test again.' );
				}
				return ConnectionResult::success( 'Connection successful' );
			}

			// Try to extract error message from XML response
			$error_message = 'Connection failed with status: ' . $response_code;
			if ( ! empty( $response_body ) ) {
				$xml = self::parse_xml( $response_body );
				if ( $xml && isset( $xml->Message ) ) {
					$error_message .= ' - ' . sanitize_text_field( (string) $xml->Message );
				}
			}

			return ConnectionResult::failure( $error_message );

		} catch ( \Exception $e ) {
			return ConnectionResult::failure( 'Connection error: ' . $e->getMessage() );
		}
	}

	/**
	 * Upload file to Azure Blob Storage
	 *
	 * @param string               $local_path Local file path
	 * @param string               $remote_path Remote path in cloud
	 * @param array<string, mixed> $options Additional options
	 * @return array<string, mixed> ['success' => bool, 'url' => string, 'error' => string]
	 */
	public function upload_file( string $local_path, string $remote_path, array $options = array() ): array {
		$result = $this->upload_file_dto( $local_path, $remote_path, $options );
		return $result->toArray();
	}

	/**
	 * Upload file to Azure Blob Storage (internal DTO version)
	 *
	 * @param string               $local_path Local file path
	 * @param string               $remote_path Remote path in cloud
	 * @param array<string, mixed> $options Additional options
	 * @return UploadResult
	 */
	private function upload_file_dto( string $local_path, string $remote_path, array $options = array() ): UploadResult {
		try {
			if ( ! file_exists( $local_path ) ) {
				return UploadResult::failure( 'Local file not found: ' . $local_path );
			}

			// Normalize remote path (remove leading slash)
			$remote_path = ltrim( $remote_path, '/' );

			// ⭐ CRITICAL FIX: Use destination path for MIME type detection (not temp file path)
			// Temp files from wp_tempnam() have no extension, causing 'application/octet-stream'
			// For CSS/JS files, browser REQUIRES correct Content-Type (text/css, application/javascript)
			$path_for_mime = $options['mime_type_from_path'] ?? $local_path;
			$content_type  = MimeHelper::get_mime_type( $path_for_mime );

			// The signature below is computed over the body, so the size has to
			// be the size of what is actually on disk right now.
			clearstatcache( true, $local_path );
			$file_size = filesize( $local_path );
			if ( false === $file_size ) {
				return UploadResult::failure( 'Could not read local file: ' . $local_path );
			}

			// Anything larger than a single block is sent block by block, so no
			// request body — and no PHP string — ever holds more than one block,
			// whatever the file weighs. Microsoft's own SDK splits the same way,
			// at a larger threshold. Below the threshold the read is bounded by
			// it, which is what keeps a single PUT from being a memory risk.
			if ( $file_size > self::BLOCK_SIZE ) {
				return $this->upload_file_in_blocks( $local_path, $remote_path, $content_type, $file_size );
			}

			$url          = $this->endpoint . '/' . $this->container_name . '/' . $this->object_path( $remote_path );
			$file_content = file_get_contents( $local_path );
			if ( $file_content === false ) {
				return UploadResult::failure( 'Could not read local file: ' . $local_path );
			}

			// Get auth headers (already includes x-ms-blob-type)
			$headers = $this->get_auth_headers( 'PUT', $url, $file_content, $content_type );

			$response = $this->send_with_retry(
				$url,
				array(
					'method'  => 'PUT',
					'headers' => $headers,
					'body'    => $file_content,
					'timeout' => $this->transfer_timeout,
				)
			);

			if ( is_wp_error( $response ) ) {
				return UploadResult::failure( 'Upload failed: ' . $response->get_error_message() );
			}

			$response_code = wp_remote_retrieve_response_code( $response );

			if ( $response_code === 201 ) {
				return UploadResult::success( $url, $remote_path );
			}

			return UploadResult::failure( 'Upload failed with status: ' . $response_code );

		} catch ( \Exception $e ) {
			return UploadResult::failure( 'Upload error: ' . $e->getMessage() );
		}
	}

	/**
	 * Download file from Azure Blob Storage
	 *
	 * @param string $remote_path Remote path in cloud
	 * @param string $local_path Local destination path
	 * @return array<string, mixed> ['success' => bool, 'error' => string]
	 */
	public function download_file( string $remote_path, string $local_path ): array {
		$result = $this->download_file_dto( $remote_path, $local_path );
		return $result->toArray();
	}

	/**
	 * Download file from Azure Blob Storage (internal DTO version)
	 *
	 * The response is streamed straight to $local_path by the WordPress HTTP
	 * API, so the blob never passes through PHP memory and a large video costs
	 * no more than a small image. This is what core's own download_url() does,
	 * and what Microsoft's Azure plugin does to fetch a blob.
	 *
	 * The only caller is the stream wrapper, and it always passes a
	 * wp_tempnam() path in the PHP temp directory: the blob is read into a
	 * scratch file that is deleted in the same request. Nothing here writes
	 * under uploads/ or anywhere else in the WordPress install.
	 *
	 * @param string $remote_path Remote path in cloud
	 * @param string $local_path Local destination path (a temp file)
	 * @return OperationResult
	 */
	private function download_file_dto( string $remote_path, string $local_path ): OperationResult {
		try {
			$remote_path = ltrim( $remote_path, '/' );
			$url         = $this->endpoint . '/' . $this->container_name . '/' . $this->object_path( $remote_path );

			$headers = $this->get_auth_headers( 'GET', $url );

			// Create directory if it doesn't exist — the transport opens the
			// destination itself and will not create the path for us.
			$dir = dirname( $local_path );
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}

			$response = $this->send_with_retry(
				$url,
				array(
					'method'      => 'GET',
					'headers'     => $headers,
					'timeout'     => $this->download_timeout,
					'stream'      => true,
					'filename'    => $local_path,
					'redirection' => 0,
				)
			);

			if ( is_wp_error( $response ) ) {
				// A transport that failed mid-body still leaves the partial file.
				if ( file_exists( $local_path ) ) {
					wp_delete_file( $local_path );
				}
				return OperationResult::failure( 'Download failed: ' . $response->get_error_message() );
			}

			$response_code = wp_remote_retrieve_response_code( $response );

			if ( $response_code === 200 ) {
				return OperationResult::success();
			}

			// Streaming writes the body whatever the status is, so on an error
			// the file now holds Azure's XML error document, not the blob.
			if ( file_exists( $local_path ) ) {
				wp_delete_file( $local_path );
			}

			return OperationResult::failure( 'Download failed with status: ' . $response_code );

		} catch ( \Exception $e ) {
			if ( file_exists( $local_path ) ) {
				wp_delete_file( $local_path );
			}
			return OperationResult::failure( 'Download error: ' . $e->getMessage() );
		}
	}

	/**
	 * Upload a file with Put Block / Put Block List.
	 *
	 * Each request carries exactly one block, so neither a PHP string nor a
	 * request body ever holds more than BLOCK_SIZE bytes, no matter how large
	 * the file is. This is the path Microsoft documents for large blobs, and
	 * every request goes through the WordPress HTTP API.
	 *
	 * @param string $local_path   File to upload.
	 * @param string $remote_path  Destination path, already normalised, NOT url-encoded.
	 * @param string $content_type MIME type to store on the blob.
	 * @param int    $file_size    Size of $local_path in bytes.
	 * @return UploadResult
	 */
	private function upload_file_in_blocks( string $local_path, string $remote_path, string $content_type, int $file_size ): UploadResult {
		$fp = fopen( $local_path, 'rb' );
		if ( ! $fp ) {
			return UploadResult::failure( 'Could not open local file: ' . $local_path );
		}

		// The URL and the signature carry the same percent-encoded path.
		$encoded_path = $this->object_path( $remote_path );
		$base_url     = $this->endpoint . '/' . $this->container_name . '/' . $encoded_path;
		$resource     = '/' . $this->storage_account . '/' . $this->container_name . '/' . $encoded_path;

		$block_ids   = array();
		$block_index = 0;
		$nonce       = self::new_nonce();
		$bytes_read  = 0;

		while ( ! feof( $fp ) ) {
			$chunk = fread( $fp, self::BLOCK_SIZE );

			// A read error and the end of the file both stop the loop, but only
			// one of them means the blob is complete. Committing after a failed
			// read would store a truncated file and call it a success.
			if ( false === $chunk ) {
				fclose( $fp );
				return UploadResult::failure( 'Could not read block ' . $block_index . ' of: ' . $local_path );
			}

			if ( '' === $chunk ) {
				break;
			}

			$block_id    = self::block_id( $nonce, $block_index + 1 );
			$block_ids[] = $block_id;

			$date           = gmdate( 'D, d M Y H:i:s T' );
			$content_length = strlen( $chunk );
			$url            = $base_url . '?comp=block&blockid=' . rawurlencode( $block_id );

			// Two things Azure is unforgiving about here, both confirmed
			// against the service itself. First, the query parameters in the
			// signature go in alphabetical order, so blockid comes before
			// comp. Second, Content-Type is part of the string to sign, and
			// the WordPress HTTP API sends application/x-www-form-urlencoded
			// when a PUT carries a body and no type of its own; stating it
			// explicitly is what keeps the signature and the request agreeing.
			$string_to_sign = "PUT\n\n\n{$content_length}\n\n" . self::BLOCK_CONTENT_TYPE . "\n\n\n\n\n\n\nx-ms-date:{$date}\nx-ms-version:2020-04-08\n{$resource}\nblockid:{$block_id}\ncomp:block";
			$signature      = base64_encode( hash_hmac( 'sha256', $string_to_sign, base64_decode( $this->access_key ), true ) );

			$response = $this->send_with_retry(
				$url,
				array(
					'method'  => 'PUT',
					'headers' => array(
						'Authorization'  => 'SharedKey ' . $this->storage_account . ':' . $signature,
						'Content-Type'   => self::BLOCK_CONTENT_TYPE,
						'Content-Length' => (string) $content_length,
						'x-ms-date'      => $date,
						'x-ms-version'   => '2020-04-08',
					),
					'body'    => $chunk,
					'timeout' => $this->transfer_timeout,
				)
			);

			if ( is_wp_error( $response ) ) {
				fclose( $fp );
				return UploadResult::failure( 'Upload failed on block ' . $block_index . ': ' . $response->get_error_message() );
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( 201 !== $code ) {
				fclose( $fp );
				return UploadResult::failure( 'Upload failed on block ' . $block_index . ' with status: ' . $code );
			}

			$bytes_read += $content_length;
			++$block_index;
		}

		fclose( $fp );

		if ( empty( $block_ids ) ) {
			return UploadResult::failure( 'Could not read local file: ' . $local_path );
		}

		// Last guard before the blob becomes visible: the blocks Azure is about
		// to assemble have to add up to the file we were asked to upload.
		if ( $bytes_read !== $file_size ) {
			return UploadResult::failure( 'Read ' . $bytes_read . ' of ' . $file_size . ' bytes from: ' . $local_path );
		}

		// Commit. This request also carries the blob's content type, which is
		// what a browser needs for the CSS and JS a page builder writes.
		$block_list_xml = '<?xml version="1.0" encoding="utf-8"?><BlockList>';
		foreach ( $block_ids as $block_id ) {
			$block_list_xml .= '<Latest>' . $block_id . '</Latest>';
		}
		$block_list_xml .= '</BlockList>';

		$date           = gmdate( 'D, d M Y H:i:s T' );
		$content_length = strlen( $block_list_xml );
		$ms             = $this->commit_ms( $content_type, $date );
		$string_to_sign = "PUT\n\n\n{$content_length}\n\napplication/xml\n\n\n\n\n\n\n" . self::canonical_ms( $ms ) . "\n{$resource}\ncomp:blocklist";
		$signature      = base64_encode( hash_hmac( 'sha256', $string_to_sign, base64_decode( $this->access_key ), true ) );

		$response = $this->send_with_retry(
			$base_url . '?comp=blocklist',
			array(
				'method'  => 'PUT',
				'headers' => array(
					'Authorization'  => 'SharedKey ' . $this->storage_account . ':' . $signature,
					'Content-Type'   => 'application/xml',
					'Content-Length' => (string) $content_length,
				) + $ms,
				'body'    => $block_list_xml,
				'timeout' => $this->transfer_timeout,
			)
		);

		if ( is_wp_error( $response ) ) {
			return UploadResult::failure( 'Upload failed on commit: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 201 !== $code ) {
			return UploadResult::failure( 'Upload failed on commit with status: ' . $code );
		}

		Logger::debug( '[DiluxOne Offload AzureProvider] Uploaded ' . $remote_path . ' in ' . count( $block_ids ) . ' blocks (' . $file_size . ' bytes)' );

		return UploadResult::success( $base_url, $remote_path );
	}

	/**
	 * Check if file exists in Azure Blob Storage
	 *
	 * @param string $remote_path Remote path
	 * @return bool
	 */
	public function file_exists( string $remote_path ): bool {
		try {
			$remote_path = ltrim( $remote_path, '/' );
			$url         = $this->endpoint . '/' . $this->container_name . '/' . $this->object_path( $remote_path );

			$headers = $this->get_auth_headers( 'HEAD', $url );

			$response = $this->send_with_retry(
				$url,
				array(
					'method'  => 'HEAD',
					'headers' => $headers,
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $response ) ) {
				return false;
			}

			return wp_remote_retrieve_response_code( $response ) === 200;

		} catch ( \Exception $e ) {
			Logger::info( '[DiluxOne Offload AzureProvider] file_exists error: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Get file checksum from Azure Blob Storage
	 *
	 * @param string $remote_path Remote path
	 * @return string|false MD5 hash or false if error
	 */
	public function get_file_checksum( string $remote_path ) {
		try {
			$remote_path = ltrim( $remote_path, '/' );
			$url         = $this->endpoint . '/' . $this->container_name . '/' . $this->object_path( $remote_path );

			$headers = $this->get_auth_headers( 'HEAD', $url );

			$response = $this->send_with_retry(
				$url,
				array(
					'method'  => 'HEAD',
					'headers' => $headers,
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $response ) ) {
				return false;
			}

			if ( wp_remote_retrieve_response_code( $response ) === 200 ) {
				$headers = wp_remote_retrieve_headers( $response );
				return $headers['content-md5'] ?? false;
			}

			return false;

		} catch ( \Exception $e ) {
			Logger::info( '[DiluxOne Offload AzureProvider] get_file_checksum error: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Get file information from Azure (size + MD5)
	 *
	 * @param string $remote_path Remote file path
	 * @return array<string, mixed>|false ['size' => int, 'md5' => string, 'last_modified' => string] or false if not found
	 */
	public function get_file_info( string $remote_path ) {
		$file_info = $this->get_file_info_dto( $remote_path );
		return $file_info ? $file_info->toArray() : false;
	}

	/**
	 * Get file information from Azure (internal DTO version)
	 *
	 * @param string $remote_path Remote file path
	 * @return FileInfo|null FileInfo object or null if not found
	 */
	private function get_file_info_dto( string $remote_path ): ?FileInfo {
		try {
			$remote_path = ltrim( $remote_path, '/' );
			$url         = $this->endpoint . '/' . $this->container_name . '/' . $this->object_path( $remote_path );

			$headers = $this->get_auth_headers( 'HEAD', $url );

			$response = $this->send_with_retry(
				$url,
				array(
					'method'  => 'HEAD',
					'headers' => $headers,
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $response ) ) {
				return null;
			}

			if ( wp_remote_retrieve_response_code( $response ) === 200 ) {
				$response_headers = wp_remote_retrieve_headers( $response );

				return new FileInfo(
					$remote_path,
					(int) ( $response_headers['content-length'] ?? 0 ),
					$response_headers['content-md5'] ?? null,
					$response_headers['last-modified'] ?? null
				);
			}

			return null;

		} catch ( \Exception $e ) {
			Logger::info( '[DiluxOne Offload AzureProvider] get_file_info error: ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * Delete file from Azure Blob Storage
	 *
	 * @param string $remote_path Remote path
	 * @return array<string, mixed> ['success' => bool, 'error' => string]
	 */
	public function delete_file( string $remote_path ): array {
		$result = $this->delete_file_dto( $remote_path );
		return $result->toArray();
	}

	/**
	 * Delete file from Azure Blob Storage (internal DTO version)
	 *
	 * @param string $remote_path Remote path
	 * @return OperationResult
	 */
	private function delete_file_dto( string $remote_path ): OperationResult {
		try {
			$remote_path = ltrim( $remote_path, '/' );
			$url         = $this->endpoint . '/' . $this->container_name . '/' . $this->object_path( $remote_path );

			$headers = $this->get_auth_headers( 'DELETE', $url );

			$response = $this->send_with_retry(
				$url,
				array(
					'method'  => 'DELETE',
					'headers' => $headers,
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $response ) ) {
				return OperationResult::failure( 'Delete failed: ' . $response->get_error_message() );
			}

			$response_code = wp_remote_retrieve_response_code( $response );

			// 202: deleted. 404: the blob was not there, which is what a delete
			// is for; callers can trust that a success means "gone" and a
			// failure means "maybe still there" (a network error, a 403, a 5xx).
			if ( $response_code === 202 || $response_code === 404 ) {
				return OperationResult::success();
			}

			return OperationResult::failure( 'Delete failed with status: ' . $response_code );

		} catch ( \Exception $e ) {
			return OperationResult::failure( 'Delete error: ' . $e->getMessage() );
		}
	}

	/**
	 * Copy blob from one path to another within the same container
	 * Uses Azure Copy Blob API for efficient server-side copy
	 *
	 * @param string $source_path Source blob path
	 * @param string $dest_path Destination blob path
	 * @return array<string, mixed> Result array with 'success' and optional 'error'
	 */
	public function copy_blob( string $source_path, string $dest_path ): array {
		$result = $this->copy_blob_dto( $source_path, $dest_path );
		return $result->toArray();
	}

	/**
	 * Copy blob from one path to another (internal DTO version)
	 *
	 * Azure Copy Blob API documentation:
	 * https://docs.microsoft.com/en-us/rest/api/storageservices/copy-blob
	 *
	 * @param string $source_path Source blob path
	 * @param string $dest_path Destination blob path
	 * @return OperationResult
	 */
	private function copy_blob_dto( string $source_path, string $dest_path ): OperationResult {
		try {
			$source_path = ltrim( $source_path, '/' );
			$dest_path   = ltrim( $dest_path, '/' );

			// Build URLs
			$source_url = $this->endpoint . '/' . $this->container_name . '/' . $this->object_path( $source_path );
			$dest_url   = $this->endpoint . '/' . $this->container_name . '/' . $this->object_path( $dest_path );

			// Build canonicalized headers for PUT with copy source
			$date       = gmdate( 'D, d M Y H:i:s T' );
			$parsed_url = wp_parse_url( $dest_url );
			if ( ! is_array( $parsed_url ) || empty( $parsed_url['path'] ) ) {
				return OperationResult::failure( 'Invalid destination URL for copy: ' . $dest_url );
			}

			// Build canonicalized resource
			$canonicalized_resource = '/' . $this->storage_account . $parsed_url['path'];

			// The copy keeps the source's properties (Cache-Control among
			// them) but not its tier: the new blob is told it again.
			$ms                    = array(
				'x-ms-copy-source' => $source_url,
				'x-ms-date'        => $date,
				'x-ms-version'     => '2020-04-08',
			) + array_intersect_key( $this->new_blob_headers, array( 'x-ms-access-tier' => true ) );
			$canonicalized_headers = self::canonical_ms( $ms );

			// Build string to sign
			$string_to_sign = "PUT\n" .
							"\n" . // Content-Encoding
							"\n" . // Content-Language
							"\n" . // Content-Length (empty for copy)
							"\n" . // Content-MD5
							"\n" . // Content-Type
							"\n" . // Date
							"\n" . // If-Modified-Since
							"\n" . // If-Match
							"\n" . // If-None-Match
							"\n" . // If-Unmodified-Since
							"\n" . // Range
							$canonicalized_headers . "\n" .
							$canonicalized_resource;

			$signature     = base64_encode( hash_hmac( 'sha256', $string_to_sign, base64_decode( $this->access_key ), true ) );
			$authorization = 'SharedKey ' . $this->storage_account . ':' . $signature;

			// Build headers
			$headers = array(
				'Authorization'  => $authorization,
				'Content-Length' => '0',
			) + $ms;

			// Execute copy request
			$response = $this->send_with_retry(
				$dest_url,
				array(
					'method'  => 'PUT',
					'headers' => $headers,
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $response ) ) {
				return OperationResult::failure( 'Copy failed: ' . $response->get_error_message() );
			}

			$response_code = wp_remote_retrieve_response_code( $response );

			// Azure returns 202 (Accepted) for successful copy
			if ( $response_code === 202 ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					Logger::info( '[DiluxOne Offload AzureProvider] Copy successful: ' . $source_path . ' -> ' . $dest_path );
				}
				return OperationResult::success( 'Blob copied successfully' );
			}

			return OperationResult::failure( 'Copy failed with status ' . $response_code . $this->describe_error_body( wp_remote_retrieve_body( $response ) ) );

		} catch ( \Exception $e ) {
			return OperationResult::failure( 'Copy error: ' . $e->getMessage() );
		}
	}

	/**
	 * List all files in Azure Blob Storage
	 *
	 * @param string $prefix Filter by prefix (e.g., 'uploads/')
	 * @return array<int, array<string, mixed>> Array of file info: [['path' => string, 'size' => int, 'md5' => string], ...]
	 */
	public function list_files( string $prefix = 'uploads/' ): array {
		$file_infos = $this->list_files_dto( $prefix );

		// Convert FileInfo[] to array[]
		return array_map(
			function ( FileInfo $file_info ) {
				return $file_info->toArray();
			},
			$file_infos
		);
	}

	/**
	 * One List Blobs page (up to 5000 blobs); `next` is its NextMarker.
	 *
	 * @param string $prefix Key prefix.
	 * @param string $marker NextMarker of the page before, or ''.
	 * @return array{files: array<int, array<string, mixed>>, next: string}
	 * @throws \Exception When the page cannot be listed.
	 */
	public function list_page( string $prefix, string $marker = '' ): array {
		$page = $this->list_page_dto( $prefix, $marker );
		return array(
			'files' => array_map(
				static function ( FileInfo $file ): array {
					return $file->toArray();
				},
				$page['files']
			),
			'next'  => $page['next'],
		);
	}

	/**
	 * @param string $prefix Key prefix.
	 * @param string $marker NextMarker of the page before, or ''.
	 * @return array{files: FileInfo[], next: string}
	 * @throws \Exception When the page cannot be listed: a transport error, an HTTP error, an empty or unparseable body.
	 */
	private function list_page_dto( string $prefix, string $marker ): array {
		$url = $this->endpoint . '/' . $this->container_name . '?restype=container&comp=list';
		if ( $prefix ) {
			$url .= '&prefix=' . rawurlencode( $prefix );
		}
		if ( '' !== $marker ) {
			$url .= '&marker=' . rawurlencode( $marker );
		}

		$response = $this->send_with_retry(
			$url,
			array(
				'method'  => 'GET',
				'headers' => $this->get_auth_headers( 'GET', $url ),
				'timeout' => 60,
			)
		);

		// Raise instead of breaking out silently with a partial listing.
		if ( is_wp_error( $response ) ) {
			throw new \Exception( 'Azure API error: ' . esc_html( $response->get_error_message() ) );
		}

		// Azure returns 403/401 as valid HTTP responses.
		$http_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $http_code >= 400 ) {
			throw new \Exception( 'Azure returned HTTP ' . (int) $http_code );
		}

		// An empty body is an error, not an empty container.
		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			throw new \Exception( 'Azure returned an empty listing' );
		}

		$xml = self::parse_xml( $body );
		if ( null === $xml ) {
			throw new \Exception( 'Invalid XML response from Azure' );
		}

		$files = array();
		if ( isset( $xml->Blobs->Blob ) ) {
			foreach ( $xml->Blobs->Blob as $blob ) {
				$files[] = new FileInfo(
					(string) $blob->Name,
					(int) $blob->Properties->{'Content-Length'},
					isset( $blob->Properties->{'Content-MD5'} ) ? (string) $blob->Properties->{'Content-MD5'} : null,
					(string) $blob->Properties->{'Last-Modified'}
				);
			}
		}

		return array(
			'files' => $files,
			'next'  => isset( $xml->NextMarker ) ? (string) $xml->NextMarker : '',
		);
	}

	/**
	 * List all files in Azure Blob Storage (internal DTO version)
	 *
	 * @param string $prefix Filter by prefix (e.g., 'uploads/')
	 * @return FileInfo[] Array of FileInfo objects
	 *
	 * @throws \Exception When the Azure REST call fails after all retries.
	 */
	private function list_files_dto( string $prefix = 'uploads/' ): array {
		$max_retries = 3;
		$retry_delay = 2; // seconds

		// Up to three attempts before giving up.
		for ( $attempt = 1; $attempt <= $max_retries; $attempt++ ) {
			try {
				$files       = array();
				$marker      = '';
				$page_number = 0;

				// Azure List Blobs API uses pagination
				do {
					++$page_number;
					$page   = $this->list_page_dto( $prefix, $marker );
					$files  = array_merge( $files, $page['files'] );
					$marker = $page['next'];
				} while ( '' !== $marker );

				// ✅ SUCCESS: Listado completo exitoso
				Logger::info( '[DiluxOne Offload AzureProvider] ✅ Successfully listed ' . count( $files ) . ' files from Azure in ' . $page_number . ' pages (attempt ' . $attempt . ')' );
				return $files;

			} catch ( \Exception $e ) {
				$error_code = $this->extract_error_code( $e->getMessage() );

				// Do NOT retry client errors (4xx) — they won't resolve on retry
				if ( $this->is_non_retryable_error( $error_code ) ) {
					Logger::info( '[DiluxOne Offload AzureProvider] Non-retryable error (' . $error_code . '): ' . $e->getMessage() );
					\DiluxOneOffload\ConfigManager::record_connection_failure(
						$error_code,
						$e->getMessage(),
						'list_files'
					);
					throw $e;
				}

				// A 5xx or a dropped connection was already asked again three
				// times by send_with_retry(); a timeout or a bad body is retried here.
				if ( $attempt < $max_retries && ! self::retried_inside( $error_code ) ) {
					Logger::error( '[DiluxOne Offload AzureProvider] Attempt ' . $attempt . ' failed (retryable), retrying in ' . $retry_delay . 's... Error: ' . $e->getMessage() );
					sleep( $retry_delay );
					continue;
				} else {
					Logger::info( '[DiluxOne Offload AzureProvider] All ' . $max_retries . ' attempts failed. Last error: ' . $e->getMessage() );
					throw new \Exception( 'Failed to list Azure files after ' . esc_html( (string) $max_retries ) . ' attempts: ' . esc_html( $e->getMessage() ) );
				}
			}
		}

		// Unreachable: the loop above either returns or throws.
		throw new \Exception( 'Unexpected error in list_files_dto retry loop' );
	}

	/**
	 * Get public URL for file
	 *
	 * @param string $remote_path Remote path
	 * @return string Public URL
	 */
	public function get_file_url( string $remote_path ): string {
		$remote_path = ltrim( $remote_path, '/' );
		return $this->endpoint . '/' . $this->container_name . '/' . $this->object_path( $remote_path );
	}

	/**
	 * Get provider name
	 *
	 * @return string Provider name
	 */
	public function get_provider_name(): string {
		return 'azure';
	}

	/**
	 * The x-ms-* headers of a request as the string to sign wants them: one
	 * `name:value` line each, in alphabetical order, no trailing newline.
	 *
	 * @param array<string,string> $ms The request's x-ms-* headers.
	 * @return string
	 */
	private static function canonical_ms( array $ms ): string {
		ksort( $ms );
		$lines = array();
		foreach ( $ms as $name => $value ) {
			$lines[] = $name . ':' . $value;
		}
		return implode( "\n", $lines );
	}

	/**
	 * The same headers as curl wants them.
	 *
	 * @param array<string,string> $ms Headers.
	 * @return string[]
	 */
	private static function header_lines( array $ms ): array {
		$lines = array();
		foreach ( $ms as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}
		return $lines;
	}

	/**
	 * The x-ms-* headers of a Put Block List: the blob's content type and the
	 * new-blob headers, which Azure sets on the blob the list commits.
	 *
	 * @param string $content_type The blob's content type.
	 * @param string $date         The request's date.
	 * @return array<string,string>
	 */
	private function commit_ms( string $content_type, string $date ): array {
		return array(
			'x-ms-blob-content-type' => $content_type,
			'x-ms-date'              => $date,
			'x-ms-version'           => '2020-04-08',
		) + $this->new_blob_headers;
	}

	/**
	 * Generate Azure Blob Storage authentication headers
	 *
	 * @param string $method HTTP method
	 * @param string $url Request URL
	 * @param string $body Request body
	 * @param string $content_type Content type
	 * @return array<string, mixed> Headers array
	 */
	private function get_auth_headers( string $method, string $url, string $body = '', string $content_type = '' ): array {
		$date       = gmdate( 'D, d M Y H:i:s T' );
		$parsed_url = wp_parse_url( $url );
		if ( ! is_array( $parsed_url ) ) {
			$parsed_url = array();
		}

		// Build canonicalized resource
		$canonicalized_resource = '/' . $this->storage_account . ( $parsed_url['path'] ?? '' );

		// Add canonicalized query parameters (sorted alphabetically)
		if ( isset( $parsed_url['query'] ) ) {
			parse_str( $parsed_url['query'], $query_params );
			ksort( $query_params ); // Sort alphabetically

			foreach ( $query_params as $key => $value ) {
				$canonicalized_resource .= "\n" . strtolower( (string) $key ) . ':' . ( is_array( $value ) ? wp_json_encode( $value ) : (string) $value );
			}
		}

		$content_length = strlen( $body );
		$content_md5    = $body ? base64_encode( md5( $body, true ) ) : '';

		// Use provided content_type or default to octet-stream
		if ( empty( $content_type ) && $body ) {
			$content_type = 'application/octet-stream';
		}

		// Every x-ms-* header the request sends is signed, in alphabetical
		// order (canonical_ms()). A PUT with a body is a Put Blob: it carries
		// the blob's type and content type and the new-blob headers.
		$ms = array(
			'x-ms-date'    => $date,
			'x-ms-version' => '2020-04-08',
		);
		if ( $body && $method === 'PUT' ) {
			$ms += array(
				'x-ms-blob-content-type' => $content_type,
				'x-ms-blob-type'         => 'BlockBlob',
			) + $this->new_blob_headers;
		}
		$canonicalized_headers = self::canonical_ms( $ms );

		$string_to_sign = $method . "\n" .
						"\n" . // Content-Encoding
						"\n" . // Content-Language
						( $content_length > 0 ? $content_length : '' ) . "\n" . // Content-Length
						$content_md5 . "\n" . // Content-MD5
						$content_type . "\n" . // Content-Type
						"\n" . // Date
						"\n" . // If-Modified-Since
						"\n" . // If-Match
						"\n" . // If-None-Match
						"\n" . // If-Unmodified-Since
						"\n" . // Range
						$canonicalized_headers . "\n" .
						$canonicalized_resource;

		$signature = base64_encode( hash_hmac( 'sha256', $string_to_sign, base64_decode( $this->access_key ), true ) );

		$authorization = 'SharedKey ' . $this->storage_account . ':' . $signature;

		$headers = array( 'Authorization' => $authorization ) + $ms;

		if ( $body && $method === 'PUT' ) {
			$headers['Content-Type'] = $content_type;
			if ( $content_md5 ) {
				$headers['Content-MD5'] = $content_md5;
			}
		}

		return $headers;
	}

	/**
	 * Prepare batch upload handle for parallel sync
	 * Provider-specific implementation for Azure Blob Storage
	 *
	 * @param array<string, mixed> $file_info File information ['local_path' => string, 'remote_path' => string]
	 * @return array<string, mixed> ['success' => bool, 'handle' => resource|null, 'error' => string, 'file_handle' => resource|null]
	 */
	public function prepare_batch_upload_handle( array $file_info ): array {
		$local_path  = $file_info['local_path'];
		$remote_path = $file_info['remote_path'];

		if ( ! file_exists( $local_path ) ) {
			return array(
				'success'     => false,
				'error'       => 'File not found: ' . $local_path,
				'file_handle' => null,
			);
		}

		try {
			$file_size = filesize( $local_path );

			// Build Azure URL with proper encoding for spaces and special characters
			// HTTPS is always enforced (Azure requirement)
			$endpoint    = $this->endpoint;
			$remote_path = ltrim( $remote_path, '/' );

			// Percent-encode each segment; the signature is over the same encoded path.
			$path_parts    = explode( '/', $remote_path );
			$encoded_parts = array_map( 'rawurlencode', $path_parts );
			$encoded_path  = implode( '/', $encoded_parts );

			$url = "{$endpoint}/{$this->container_name}/{$encoded_path}";

			// Get MIME type from remote path (extension-based)
			$content_type = MimeHelper::get_mime_type( $remote_path );

			// Generate Azure Shared Key signature
			$date           = gmdate( 'D, d M Y H:i:s T' );
			$ms             = array(
				'x-ms-blob-type' => 'BlockBlob',
				'x-ms-date'      => $date,
				'x-ms-version'   => '2020-04-08',
			) + $this->new_blob_headers;
			$string_to_sign = "PUT\n\n\n{$file_size}\n\n{$content_type}\n\n\n\n\n\n\n" . self::canonical_ms( $ms ) . "\n/{$this->storage_account}/{$this->container_name}/{$encoded_path}";
			$signature      = base64_encode( hash_hmac( 'sha256', $string_to_sign, base64_decode( $this->access_key ), true ) );

			// OPTIMIZED: Open file as stream instead of reading into memory
			$file_handle = fopen( $local_path, 'rb' );
			if ( ! $file_handle ) {
				return array(
					'success'     => false,
					'error'       => 'Failed to open file for reading',
					'file_handle' => null,
				);
			}

			// Prepare cURL handle with streaming
			$ch = curl_init();
			curl_setopt_array(
				$ch,
				array(
					CURLOPT_URL            => $url,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_CUSTOMREQUEST  => 'PUT',
					CURLOPT_UPLOAD         => true, // Enable upload mode
					CURLOPT_INFILE         => $file_handle, // STREAMING: Read from file handle
					CURLOPT_INFILESIZE     => $file_size, // Tell cURL the file size
					CURLOPT_HTTPHEADER     => array_merge(
						array(
							'Authorization: SharedKey ' . $this->storage_account . ':' . $signature,
							'Content-Type: ' . $content_type,
							'Content-Length: ' . $file_size,
						),
						self::header_lines( $ms )
					),
					CURLOPT_TIMEOUT        => $this->transfer_timeout,
					CURLOPT_CONNECTTIMEOUT => 30,
				)
			);

			return array(
				'success'     => true,
				'handle'      => $ch,
				'file_handle' => $file_handle, // Must be kept open until upload completes
			);

		} catch ( \Exception $e ) {
			return array(
				'success'     => false,
				'error'       => 'Exception: ' . $e->getMessage(),
				'file_handle' => null,
			);
		}
	}

	/**
	 * A large file goes up as 4 MiB blocks, each a Put Block the sync sends
	 * through its pool, then one Put Block List. Azure needs no call to
	 * start: the upload's name is a nonce its block ids carry. To take up an
	 * unfinished upload, the uncommitted block list says which of its blocks
	 * Azure holds; blocks with another nonce are not this upload's.
	 *
	 * @param array<string, mixed> $file_info        File information ['local_path' => string, 'remote_path' => string]
	 * @param string|null          $resume_upload_id The nonce of the upload to take up, or null to start over.
	 * @return array<string, mixed> ['success' => bool, 'error' => string, 'upload' => ChunkedUpload]
	 */
	public function begin_chunked_upload( array $file_info, ?string $resume_upload_id = null ): array {
		$local_path = (string) $file_info['local_path'];
		$size       = is_file( $local_path ) ? (int) filesize( $local_path ) : 0;
		if ( $size <= 0 ) {
			return array(
				'success' => false,
				'error'   => 'File not found: ' . $local_path,
			);
		}
		$resume = null !== $resume_upload_id && 1 === preg_match( '/^[0-9a-f]{6}$/', $resume_upload_id );
		$upload = new ChunkedUpload( $local_path, ltrim( (string) $file_info['remote_path'], '/' ), $size, self::BLOCK_SIZE, $resume ? (string) $resume_upload_id : self::new_nonce() );
		if ( $resume ) {
			foreach ( $this->uncommitted_blocks( $upload->remotePath(), $upload->uploadId() ) as $part => $block_size ) {
				if ( $part <= $upload->partCount() && $block_size === $upload->length( $part ) ) {
					$upload->recordTag( $part, self::block_id( $upload->uploadId(), $part ) );
				}
			}
		}
		return array(
			'success' => true,
			'upload'  => $upload,
		);
	}

	/**
	 * The blocks of one upload Azure holds for a blob and nobody committed
	 * yet, by part number, with their sizes. Nothing when there are none,
	 * when the blob does not exist or when Azure cannot be asked: the upload
	 * starts over.
	 *
	 * @param string $remote_path Blob path.
	 * @param string $nonce       The upload's nonce.
	 * @return array<int, int>
	 */
	private function uncommitted_blocks( string $remote_path, string $nonce ): array {
		$url      = "{$this->endpoint}/{$this->container_name}/" . $this->object_path( $remote_path ) . '?blocklisttype=uncommitted&comp=blocklist';
		$response = $this->send_with_retry(
			$url,
			array(
				'method'  => 'GET',
				'headers' => $this->get_auth_headers( 'GET', $url ),
				'timeout' => 30,
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}
		$xml = self::parse_xml( (string) wp_remote_retrieve_body( $response ) );
		if ( null === $xml || ! isset( $xml->UncommittedBlocks->Block ) ) {
			return array();
		}
		$blocks = array();
		foreach ( $xml->UncommittedBlocks->Block as $block ) {
			// This upload's ids are its nonce and the zero-based index, three
			// bytes each; any other block is not its own and is left out.
			$name = base64_decode( (string) $block->Name, true );
			if ( false !== $name && 6 === strlen( $name ) && hex2bin( $nonce ) === substr( $name, 0, 3 ) ) {
				$index = unpack( 'N', "\0" . substr( $name, 3 ) );
				$part  = (int) ( is_array( $index ) ? $index[1] : 0 ) + 1;

				$blocks[ $part ] = (int) $block->Size;
			}
		}
		return $blocks;
	}

	/**
	 * One Put Block, its body streamed from the file.
	 *
	 * @param ChunkedUpload $upload The upload.
	 * @param int           $part   Part number, from 1.
	 * @return array<string, mixed> ['success' => bool, 'handle' => resource|null, 'error' => string, 'file_handle' => resource|null]
	 */
	public function prepare_part_handle( ChunkedUpload $upload, int $part ): array {
		$encoded_path   = $this->object_path( $upload->remotePath() );
		$block_id       = self::block_id( $upload->uploadId(), $part );
		$content_length = $upload->length( $part );
		$date           = gmdate( 'D, d M Y H:i:s T' );

		// Query parameters in alphabetical order and the Content-Type stated,
		// as upload_file_in_blocks() signs them.
		$string_to_sign = "PUT\n\n\n{$content_length}\n\n" . self::BLOCK_CONTENT_TYPE . "\n\n\n\n\n\n\nx-ms-date:{$date}\nx-ms-version:2020-04-08\n/{$this->storage_account}/{$this->container_name}/{$encoded_path}\nblockid:{$block_id}\ncomp:block";
		$signature      = base64_encode( hash_hmac( 'sha256', $string_to_sign, base64_decode( $this->access_key ), true ) );

		$ch = curl_init();
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_URL            => "{$this->endpoint}/{$this->container_name}/{$encoded_path}?comp=block&blockid=" . rawurlencode( $block_id ),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CUSTOMREQUEST  => 'PUT',
				CURLOPT_HTTPHEADER     => array(
					'Authorization: SharedKey ' . $this->storage_account . ':' . $signature,
					'Content-Type: ' . self::BLOCK_CONTENT_TYPE,
					'Content-Length: ' . $content_length,
					'x-ms-date: ' . $date,
					'x-ms-version: 2020-04-08',
				),
				CURLOPT_TIMEOUT        => $this->transfer_timeout,
				CURLOPT_CONNECTTIMEOUT => 30,
			)
		);
		$file_handle = self::stream_part( $ch, $upload, $part );
		if ( null === $file_handle ) {
			return array(
				'success'     => false,
				'error'       => "Failed to read block {$part} of " . $upload->localPath(),
				'handle'      => null,
				'file_handle' => null,
			);
		}

		return array(
			'success'     => true,
			'handle'      => $ch,
			'file_handle' => $file_handle,
		);
	}

	/**
	 * A block landed when Azure answers 201; its id is its tag.
	 *
	 * @param ChunkedUpload $upload The upload.
	 * @param int           $part   Part number.
	 * @param int           $status HTTP status.
	 * @param string        $body   Response body.
	 * @return string|null
	 */
	public function finish_part( ChunkedUpload $upload, int $part, int $status, string $body ): ?string {
		if ( 201 !== $status ) {
			return "Failed to upload block {$part}: HTTP {$status}" . $this->describe_error_body( $body );
		}
		$upload->recordTag( $part, self::block_id( $upload->uploadId(), $part ) );
		return null;
	}

	/**
	 * Put Block List: the blocks, in order, become the blob.
	 *
	 * @param ChunkedUpload $upload The upload, every part tagged.
	 * @return array<string, mixed> ['success' => bool, 'handle' => resource|null, 'error' => string, 'file_handle' => null, 'on_failure' => callable]
	 */
	public function prepare_commit_handle( ChunkedUpload $upload ): array {
		$tags = $upload->tags();
		if ( null === $tags ) {
			return array(
				'success'     => false,
				'error'       => 'A block of ' . $upload->localPath() . ' was not uploaded',
				'handle'      => null,
				'file_handle' => null,
			);
		}

		$encoded_path   = $this->object_path( $upload->remotePath() );
		$block_list_xml = '<?xml version="1.0" encoding="utf-8"?><BlockList>';
		foreach ( $tags as $block_id ) {
			$block_list_xml .= '<Latest>' . $block_id . '</Latest>';
		}
		$block_list_xml .= '</BlockList>';

		$date           = gmdate( 'D, d M Y H:i:s T' );
		$content_length = strlen( $block_list_xml );
		$content_type   = MimeHelper::get_mime_type( $upload->remotePath() );

		$ms             = $this->commit_ms( $content_type, $date );
		$string_to_sign = "PUT\n\n\n{$content_length}\n\napplication/xml\n\n\n\n\n\n\n" . self::canonical_ms( $ms ) . "\n/{$this->storage_account}/{$this->container_name}/{$encoded_path}\ncomp:blocklist";
		$signature      = base64_encode( hash_hmac( 'sha256', $string_to_sign, base64_decode( $this->access_key ), true ) );

		$ch = curl_init();
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_URL            => "{$this->endpoint}/{$this->container_name}/{$encoded_path}?comp=blocklist",
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CUSTOMREQUEST  => 'PUT',
				CURLOPT_POSTFIELDS     => $block_list_xml,
				CURLOPT_HTTPHEADER     => array_merge(
					array(
						'Authorization: SharedKey ' . $this->storage_account . ':' . $signature,
						'Content-Type: application/xml',
						'Content-Length: ' . $content_length,
					),
					self::header_lines( $ms )
				),
				CURLOPT_TIMEOUT        => $this->transfer_timeout,
				CURLOPT_CONNECTTIMEOUT => 30,
			)
		);

		return array(
			'success'     => true,
			'handle'      => $ch,
			'file_handle' => null,
			'on_failure'  => function () use ( $upload ): void {
				$this->abort_chunked_upload( $upload );
			},
		);
	}

	/**
	 * Nothing to call: Azure discards blocks nobody committed after a week,
	 * and they never show as a blob.
	 *
	 * @param ChunkedUpload $upload The upload.
	 */
	public function abort_chunked_upload( ChunkedUpload $upload ): void {
	}

	/**
	 * A block's id: base64 of six bytes, the upload's three-byte nonce and
	 * the zero-based index in three bytes. The nonce tells this upload's
	 * blocks from any other left uncommitted on the same blob (an earlier
	 * content of the file, another writer), which a resumed upload must
	 * never commit. Every id is eight characters, the length of the ids
	 * 2.0.0 used (six ASCII digits): Azure refuses a block whose id length
	 * differs from the blob's uncommitted blocks (InvalidBlobOrBlock), so a
	 * blob an older upload left blocks on still takes this one's.
	 *
	 * @param string $nonce The upload's nonce, six hex characters (new_nonce()).
	 * @param int    $part  Part number, from 1.
	 * @return string
	 */
	private static function block_id( string $nonce, int $part ): string {
		return base64_encode( (string) hex2bin( $nonce ) . substr( pack( 'N', $part - 1 ), 1 ) );
	}

	/**
	 * A new upload's nonce, six hex characters; never three ASCII digits, so
	 * no id of it reads as one of 2.0.0's.
	 *
	 * @return string
	 */
	private static function new_nonce(): string {
		do {
			$bytes = random_bytes( 3 );
		} while ( ctype_digit( $bytes ) );
		return bin2hex( $bytes );
	}

	/**
	 * Prepare a cURL handle for downloading a file from Azure Blob Storage.
	 *
	 * Used by SyncManager for the parallel downloads of "Disconnect from
	 * Cloud". The local path is the attachment's own path under the uploads
	 * directory, as recorded by WordPress and resolved at runtime with
	 * wp_upload_dir(); SyncManager has already rejected anything outside that
	 * directory and any script or executable file name. This is the user's
	 * media going back where WordPress expects it, not plugin data.
	 *
	 * The bytes land in a sibling `.dlxpart` file, never in the attachment's
	 * path itself: a copy that is already there survives a timeout or an
	 * error, and SyncManager renames the part over it only once the status
	 * and the byte count check out.
	 *
	 * @param array<string, mixed> $file_info File information ['local_path' => string, 'remote_path' => string]
	 * @return array<string, mixed> ['success' => bool, 'handle' => resource|null, 'error' => string, 'file_handle' => resource|null, 'part_path' => string]
	 */
	public function prepare_download_handle( array $file_info ): array {
		$remote_path = $file_info['remote_path'];
		$local_path  = $file_info['local_path'];

		try {
			// HTTPS is always enforced (Azure requirement)

			// Build Azure URL with proper encoding for spaces and special characters
			$endpoint          = $this->endpoint;
			$remote_path_clean = ltrim( $remote_path, '/' );

			// Percent-encode each segment; the signature is over the same encoded path.
			$path_parts    = explode( '/', $remote_path_clean );
			$encoded_parts = array_map( 'rawurlencode', $path_parts );
			$encoded_path  = implode( '/', $encoded_parts );

			$url = "{$endpoint}/{$this->container_name}/{$encoded_path}";

			// Generate Azure Shared Key signature for GET
			$date                   = gmdate( 'D, d M Y H:i:s T' );
			$canonicalized_resource = "/{$this->storage_account}/{$this->container_name}/{$encoded_path}";
			$string_to_sign         = "GET\n\n\n\n\n\n\n\n\n\n\n\nx-ms-date:{$date}\nx-ms-version:2020-04-08\n{$canonicalized_resource}";
			$signature              = base64_encode( hash_hmac( 'sha256', $string_to_sign, base64_decode( $this->access_key ), true ) );

			// Create directory if needed
			$dir = dirname( $local_path );
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}

			// Write next to the attachment, not onto it.
			$part_path   = $local_path . '.dlxpart';
			$file_handle = fopen( $part_path, 'wb' );
			if ( ! $file_handle ) {
				return array(
					'success'     => false,
					'error'       => 'Failed to open local file for writing',
					'file_handle' => null,
				);
			}

			// Prepare cURL handle with streaming download
			$ch = curl_init();
			curl_setopt_array(
				$ch,
				array(
					CURLOPT_URL            => $url,
					CURLOPT_RETURNTRANSFER => false, // Don't return, write to file
					CURLOPT_FILE           => $file_handle, // STREAMING: Write directly to file
					CURLOPT_HTTPHEADER     => array(
						'Authorization: SharedKey ' . $this->storage_account . ':' . $signature,
						'x-ms-date: ' . $date,
						'x-ms-version: 2020-04-08',
					),
					CURLOPT_TIMEOUT        => $this->download_timeout,
					CURLOPT_CONNECTTIMEOUT => 30,
					CURLOPT_FOLLOWLOCATION => false,
				)
			);

			return array(
				'success'     => true,
				'handle'      => $ch,
				'file_handle' => $file_handle, // Must be kept open until download completes
				'part_path'   => $part_path,
			);

		} catch ( \Exception $e ) {
			return array(
				'success'     => false,
				'error'       => 'Download preparation exception: ' . $e->getMessage(),
				'file_handle' => null,
			);
		}
	}

	/**
	 * Extract HTTP error code from exception message.
	 *
	 * @param string $message Caught exception message
	 * @return string Error code (e.g. '403', '401', 'network')
	 */
	private function extract_error_code( string $message ): string {
		if ( preg_match( '/\b(400|401|403|404|409|500|502|503|504)\b/', $message, $matches ) ) {
			return $matches[1];
		}
		if ( stripos( $message, 'timeout' ) !== false ) {
			return 'timeout';
		}
		if ( stripos( $message, 'cURL' ) !== false || stripos( $message, 'network' ) !== false ) {
			return 'network';
		}
		return 'unknown';
	}

	/**
	 * Check if error code is non-retryable (client errors).
	 *
	 * @param string $error_code Error code from extract_error_code()
	 * @return bool True if error should NOT be retried
	 */
	private function is_non_retryable_error( string $error_code ): bool {
		return in_array( $error_code, array( '400', '401', '403', '404', '409' ), true );
	}

	/**
	 * The error code and message of an Azure error body, and nothing else.
	 *
	 * On a 403 the full body also carries the request's MAC signature and
	 * the string the server signed, which have no place in a log line, the
	 * tracking table or the admin screen.
	 *
	 * @param string $body Raw response body.
	 * @return string ' - Azure <Code>: <Message>' or '' when the body carries neither.
	 */
	public function describe_error_body( string $body ): string {
		$xml = self::parse_xml( $body );
		if ( ! $xml || ! isset( $xml->Code ) ) {
			return '';
		}

		$summary = ' - Azure ' . sanitize_text_field( (string) $xml->Code );
		if ( isset( $xml->Message ) ) {
			$summary .= ': ' . sanitize_text_field( (string) $xml->Message );
		}

		return $summary;
	}

	/**
	 * A batch upload (Put Blob) or a chunked upload's commit (Put Block
	 * List) answers 201 Created.
	 *
	 * @param int    $status HTTP status of the response.
	 * @param string $body   Raw response body.
	 * @return string|null Null on success; otherwise 'HTTP <status>' plus the Azure error.
	 */
	public function verify_upload_response( int $status, string $body ): ?string {
		if ( 201 === $status || 200 === $status ) {
			return null;
		}
		return 'HTTP ' . $status . ( $status >= 400 ? $this->describe_error_body( $body ) : '' );
	}

	/**
	 * Parse an Azure REST error body without emitting PHP warnings.
	 *
	 * Azure returns its error detail as XML, but an error body is not
	 * guaranteed to be well-formed (proxies and gateways sometimes return
	 * HTML). libxml's internal error buffer is the supported way to parse
	 * untrusted XML quietly; the `@` operator would hide real problems too.
	 *
	 * @param string $body Raw response body.
	 * @return \SimpleXMLElement|null Parsed XML, or null when it is not valid XML.
	 */
	private static function parse_xml( string $body ): ?\SimpleXMLElement {
		if ( trim( $body ) === '' ) {
			return null;
		}

		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $xml === false ? null : $xml;
	}
}
