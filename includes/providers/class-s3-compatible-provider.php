<?php
/**
 * S3-compatible storage provider.
 *
 * Talks the S3 REST API, signed with AWS Signature Version 4, to Amazon S3
 * and to every service that speaks it: Cloudflare R2, Backblaze B2,
 * DigitalOcean Spaces, Wasabi, Google Cloud Storage in interoperability
 * mode, MinIO. What differs between them (endpoint, region rule, path-style
 * or virtual-hosted addressing, public URL) comes from S3Presets and from
 * the fields the user saved; nothing here knows a service by name.
 *
 * As in AzureProvider, cURL is confined to the handles SyncManager runs
 * through curl_multi_* (batch upload, each part and the commit of a multipart upload, a
 * download to disk), which stream a file from or to disk; every other
 * request goes through wp_remote_*. The fopen/fread/fclose calls work on the
 * local files feeding those transfers, and prepare_download_handle() opens
 * the attachment's own path under uploads/ because that is where
 * "Disconnect from Cloud" puts the media back.
 *
 * Requests carry `x-amz-content-sha256: UNSIGNED-PAYLOAD`, which every
 * listed service accepts over TLS and which lets a file stream from disk
 * without being read twice; integrity is checked by the service instead,
 * through the Content-MD5 header every PUT of file bytes carries (a
 * mismatch is refused with BadDigest and nothing is stored).
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init
 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
 *
 * @package DiluxOneOffload\Providers
 * @since 2.0.0
 */

namespace DiluxOneOffload\Providers;

use DiluxOneOffload\CloudStreamWrapper;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\Interfaces\CloudStorageClientInterface;
use DiluxOneOffload\Logger;
use DiluxOneOffload\MimeHelper;
use DiluxOneOffload\DTOs\ChunkedUpload;
use DiluxOneOffload\DTOs\ConnectionResult;
use DiluxOneOffload\DTOs\FileInfo;
use DiluxOneOffload\DTOs\OperationResult;
use DiluxOneOffload\DTOs\UploadResult;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Implementation of CloudStorageClientInterface for S3-compatible storage.
 */
class S3CompatibleProvider implements CloudStorageClientInterface {

	use StorageStats;
	use TransientRetry;
	use PartUpload;

	/**
	 * Bytes per part of a multipart upload, and the largest file sent in a
	 * single PUT. 5 MiB is the smallest part every listed service accepts,
	 * and one size per file satisfies R2's rule that every part but the last
	 * be the same size. A file too large for the service's part limit at this
	 * size goes in larger parts (part_size()).
	 */
	const PART_SIZE = 5242880;

	/** Bytes of the object Test Connection writes, reads back and deletes. */
	const PROBE_BYTES = 32;

	/** @var string */
	private $endpoint;
	/** @var string */
	private $region;
	/** @var string */
	private $bucket;
	/** @var string */
	private $public_url;
	/** @var bool */
	private $path_style;
	/**
	 * Whether each written object carries x-amz-acl: public-read (the
	 * Advanced option, for services where public read is per object).
	 *
	 * @var bool
	 */
	private $object_acl;
	/** @var AwsSignatureV4 */
	private $signer;

	/**
	 * Headers every new object carries besides its type: Cache-Control and
	 * the storage class (Settings › Serving), when set and, for the class,
	 * offered by the service.
	 *
	 * @var array<string,string>
	 */
	private $new_object_headers;

	/**
	 * The most parts one multipart upload may have on this service
	 * (S3Presets::max_parts()).
	 *
	 * @var int
	 */
	private $max_parts;

	/**
	 * Seconds one transfer of file data may take: the "Transfer Timeout"
	 * setting, as in AzureProvider. Control requests keep short fixed ones.
	 *
	 * @var int
	 */
	private $transfer_timeout;

	/**
	 * Seconds a download may take: the setting, never under 300 s.
	 *
	 * @var int
	 */
	private $download_timeout;

	/**
	 * @param array<string, mixed> $config The saved provider_config, plus `upload_timeout`.
	 */
	public function __construct( array $config = array() ) {
		$this->endpoint   = rtrim( (string) ( $config['endpoint'] ?? '' ), '/' );
		$this->region     = (string) ( $config['region'] ?? '' );
		$this->bucket     = (string) ( $config['bucket'] ?? '' );
		$this->public_url = rtrim( (string) ( $config['public_url'] ?? '' ), '/' );
		$this->path_style = (bool) ( $config['path_style'] ?? true );
		$this->object_acl = ! empty( $config['object_acl'] );
		$this->max_parts  = S3Presets::max_parts( (string) ( $config['preset'] ?? '' ) );

		$this->new_object_headers = array();
		$cache_control            = (string) ( $config['cache_control'] ?? '' );
		if ( '' !== $cache_control ) {
			$this->new_object_headers['Cache-Control'] = $cache_control;
		}
		if ( 'infrequent' === ( $config['storage_class'] ?? '' ) && S3Presets::offers_infrequent( (string) ( $config['preset'] ?? '' ) ) ) {
			$this->new_object_headers['x-amz-storage-class'] = 'STANDARD_IA';
		}
		$this->transfer_timeout = max( 30, (int) ( $config['upload_timeout'] ?? 60 ) );
		$this->download_timeout = max( 300, $this->transfer_timeout );
		$this->signer           = new AwsSignatureV4(
			(string) ( $config['access_key_id'] ?? '' ),
			(string) ( $config['secret_access_key'] ?? '' ),
			$this->region
		);
	}

	/**
	 * Bytes per part for a file of this size: PART_SIZE, or the smallest
	 * whole number of MiB that keeps the file within the service's part
	 * limit (a 6 GB video on Scaleway, whose limit is 1,000 parts, goes in
	 * parts of 6 MiB). The same for every part of the file, and the same on
	 * every request, so an upload taken up later splits the file the same way.
	 *
	 * @param int $size File size in bytes.
	 * @return int<1, max>
	 */
	public function part_size( int $size ): int {
		$mib = 1048576;
		return max( self::PART_SIZE, (int) ceil( $size / $this->max_parts / $mib ) * $mib );
	}

	// ── Addressing ──────────────────────────────────────────

	/**
	 * The object key as it travels in a URL and in the canonical request:
	 * each segment percent-encoded once, the slashes kept.
	 *
	 * @param string $key Object key, with or without a leading slash.
	 * @return string
	 */
	private function object_path( string $key ): string {
		return implode( '/', array_map( 'rawurlencode', explode( '/', ltrim( $key, '/' ) ) ) );
	}

	/**
	 * Where a request for `$key` goes: `endpoint/bucket/key` (path-style) or
	 * `scheme://bucket.host/key` (virtual-hosted, Amazon S3).
	 *
	 * @param string $key   Object key; '' for the bucket itself.
	 * @param string $query Query string, already encoded, without '?'.
	 * @return string
	 */
	private function request_url( string $key = '', string $query = '' ): string {
		$path = '' === $key ? '' : $this->object_path( $key );

		if ( $this->path_style ) {
			$url = $this->endpoint . '/' . rawurlencode( $this->bucket ) . '/' . $path;
		} else {
			$scheme = (string) wp_parse_url( $this->endpoint, PHP_URL_SCHEME );
			$host   = AwsSignatureV4::host( $this->endpoint );
			$url    = $scheme . '://' . $this->bucket . '.' . $host . '/' . $path;
		}

		return '' === $query ? $url : $url . '?' . $query;
	}

	/**
	 * Headers for wp_remote_* or curl: signed, without Host (the transport
	 * sends the same value from the URL; a second Host header is a 400).
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $url     Request URL.
	 * @param array<string,string> $headers Headers the request carries.
	 * @return array<string,string>
	 */
	private function signed( string $method, string $url, array $headers = array() ): array {
		$signed = $this->signer->sign( $method, $url, $headers );
		unset( $signed['Host'] );
		return $signed;
	}

	/**
	 * What a request that creates an object adds: the public-read ACL, when
	 * the Advanced option asks for it.
	 *
	 * @return array<string,string>
	 */
	private function acl_headers(): array {
		return $this->object_acl ? array( 'x-amz-acl' => 'public-read' ) : array();
	}

	/**
	 * What a request that writes a new object from the site's file adds: the
	 * ACL, Cache-Control and the storage class. Test Connection's probe
	 * carries them too, so a value the service refuses shows there.
	 *
	 * @return array<string,string>
	 */
	private function upload_headers(): array {
		return $this->acl_headers() + $this->new_object_headers;
	}

	/**
	 * The same headers, as curl wants them.
	 *
	 * @param array<string,string> $headers Headers.
	 * @return string[]
	 */
	private static function curl_headers( array $headers ): array {
		$lines = array();
		foreach ( $headers as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}
		// curl adds "Expect: 100-continue" to large PUTs; some S3-compatible
		// servers answer it badly, and the round trip buys nothing here.
		$lines[] = 'Expect:';
		return $lines;
	}

	/**
	 * One signed request through the WordPress HTTP API, sent again on a
	 * transient error (TransientRetry). The signature stays valid for the
	 * retries: it is minutes old at most.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $url     Request URL.
	 * @param array<string,string> $headers Headers to sign and send.
	 * @param string               $body    Request body.
	 * @param int                  $timeout Seconds.
	 * @param array<string,mixed>  $extra   More wp_remote_request() arguments.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function request( string $method, string $url, array $headers = array(), string $body = '', int $timeout = 30, array $extra = array() ) {
		$args = array(
			'method'      => $method,
			'headers'     => $this->signed( $method, $url, $headers ),
			'timeout'     => $timeout,
			'redirection' => 0,
		);
		if ( '' !== $body ) {
			$args['body'] = $body;
		}
		return $this->send_with_retry( $url, $args + $extra );
	}

	// ── Errors ──────────────────────────────────────────────

	/**
	 * The <Error> element of a response body, or null.
	 *
	 * @param string $body Raw response body.
	 * @return \SimpleXMLElement|null
	 */
	private static function error_element( string $body ): ?\SimpleXMLElement {
		$xml = self::parse_xml( $body );
		if ( null === $xml ) {
			return null;
		}
		if ( 'Error' === $xml->getName() ) {
			return $xml;
		}
		return isset( $xml->Error ) ? $xml->Error : null;
	}

	/**
	 * The error code and message of an S3 error body, and nothing else. A
	 * SignatureDoesNotMatch body also carries the string the server signed,
	 * the canonical request, the signature and the access key id; none of
	 * them is read.
	 *
	 * @param string $body Raw response body.
	 * @return string ' - S3 <Code>: <Message>' or ''.
	 */
	public function describe_error_body( string $body ): string {
		$error = self::error_element( $body );
		if ( null === $error || ! isset( $error->Code ) ) {
			return '';
		}

		$summary = ' - S3 ' . sanitize_text_field( (string) $error->Code );
		if ( isset( $error->Message ) ) {
			$summary .= ': ' . sanitize_text_field( (string) $error->Message );
		}
		return $summary;
	}

	/**
	 * A failure line for a response: the status first (the health check
	 * reads the code from it), then what it means in plain words for the
	 * codes a user can fix, then the service's own code and message. Never
	 * the bucket, the endpoint or a key: a bucket called media2024 would
	 * otherwise read as error 202.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Raw response body.
	 * @return string
	 */
	private function failure_line( int $status, string $body ): string {
		$error = self::error_element( $body );
		$code  = null !== $error && isset( $error->Code ) ? (string) $error->Code : '';

		$human = array(
			'SignatureDoesNotMatch'        => 'the Secret Access Key is wrong',
			'InvalidAccessKeyId'           => 'the Access Key ID is wrong',
			'NoSuchBucket'                 => 'the bucket does not exist in this region or endpoint',
			'RequestTimeTooSkewed'         => 'the server clock is more than 15 minutes off',
			'AccessDenied'                 => 'the keys cannot write to this bucket',
			'PermanentRedirect'            => 'wrong region for this bucket',
			'AuthorizationHeaderMalformed' => 'wrong region for this bucket',
		);

		$line = 'HTTP ' . $status;
		if ( isset( $human[ $code ] ) ) {
			$line .= ': ' . $human[ $code ];
		} elseif ( 301 === $status ) {
			$line .= ': ' . $human['PermanentRedirect'];
		}
		return $line . $this->describe_error_body( $body );
	}

	/**
	 * A batch upload answers 200; the commit of a multipart upload answers
	 * 200 too, and can still carry an <Error> in its body when the parts do
	 * not assemble, so a 200 alone is not enough.
	 *
	 * @param int    $status HTTP status of the response.
	 * @param string $body   Raw response body.
	 * @return string|null Null on success; otherwise 'HTTP <status>' and the error.
	 */
	public function verify_upload_response( int $status, string $body ): ?string {
		if ( 200 === $status && null === self::error_element( $body ) ) {
			return null;
		}
		return $this->failure_line( $status, $body );
	}

	/**
	 * Error code for the connection-health system, read from a message.
	 *
	 * @param string $message Failure message.
	 * @return string
	 */
	private function extract_error_code( string $message ): string {
		$code = ConfigManager::error_code_from_message( $message );
		if ( '' !== $code ) {
			return $code;
		}
		return stripos( $message, 'cURL' ) !== false ? 'network' : 'unknown';
	}

	// ── Connection ──────────────────────────────────────────

	/**
	 * Prove the two things the plugin needs, in order: the keys can write to
	 * the bucket, and what is written is readable anonymously at the Public
	 * URL, which is where every browser will load the media from. A 32-byte
	 * probe under this site's prefix is written, read back without
	 * credentials and deleted.
	 *
	 * @return array<string, mixed> ['success' => bool, 'message' => string]
	 */
	public function test_connection(): array {
		$key  = CloudStreamWrapper::key_prefix() . '/.diluxone-offload-probe-' . bin2hex( random_bytes( 8 ) );
		$body = random_bytes( self::PROBE_BYTES );
		$url  = $this->request_url( $key );

		$put = $this->request(
			'PUT',
			$url,
			array(
				'Content-Type' => 'application/octet-stream',
				'Content-MD5'  => base64_encode( md5( $body, true ) ),
			) + $this->upload_headers(),
			$body
		);
		if ( is_wp_error( $put ) ) {
			return self::failed( 'Connection failed: ' . $put->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $put );
		if ( 200 !== $status ) {
			return self::failed( $this->failure_line( $status, (string) wp_remote_retrieve_body( $put ) ) );
		}

		$get      = $this->send_with_retry(
			$this->public_url . '/' . $this->object_path( $key ),
			array(
				'method'      => 'GET',
				'timeout'     => 30,
				'redirection' => 0,
			)
		);
		$readable = ! is_wp_error( $get )
			&& 200 === (int) wp_remote_retrieve_response_code( $get )
			&& hash_equals( $body, (string) wp_remote_retrieve_body( $get ) );

		$delete = $this->request( 'DELETE', $url );
		if ( is_wp_error( $delete ) || ! in_array( (int) wp_remote_retrieve_response_code( $delete ), array( 200, 204, 404 ), true ) ) {
			Logger::info( '[DiluxOne Offload S3CompatibleProvider] The connection probe could not be deleted; it is ' . self::PROBE_BYTES . ' bytes.' );
		}

		if ( ! $readable ) {
			$seen = is_wp_error( $get ) ? $get->get_error_message() : 'HTTP ' . (int) wp_remote_retrieve_response_code( $get );
			return self::failed( 'The keys work, but the object is not readable at the Public URL (' . $seen . '). Either the bucket does not allow anonymous read of objects (on a service that sets it per object, turn on "Make each upload public" under Advanced), or the Public URL is wrong.' );
		}

		return ConnectionResult::success( 'Connection successful' )->toArray();
	}

	/**
	 * @param string $message Failure message.
	 * @return array<string, mixed>
	 */
	private static function failed( string $message ): array {
		return ConnectionResult::failure( $message )->toArray();
	}

	// ── Upload ──────────────────────────────────────────────

	/**
	 * Upload a file: one PUT up to PART_SIZE, a multipart upload above it.
	 *
	 * @param string               $local_path  Local file path.
	 * @param string               $remote_path Object key.
	 * @param array<string, mixed> $options     `mime_type_from_path`: the path whose extension gives the type; the key's otherwise (a temp file has none).
	 * @return array<string, mixed> ['success' => bool, 'url' => string, 'error' => string]
	 */
	public function upload_file( string $local_path, string $remote_path, array $options = array() ): array {
		$key          = ltrim( $remote_path, '/' );
		$content_type = MimeHelper::get_mime_type( (string) ( $options['mime_type_from_path'] ?? $key ) );

		clearstatcache( true, $local_path );
		$size = is_file( $local_path ) ? filesize( $local_path ) : false;
		if ( false === $size ) {
			return self::upload_failed( 'Local file not found: ' . $local_path );
		}

		if ( $size > self::PART_SIZE ) {
			$error = $this->multipart_upload( $local_path, $key, $content_type, $size );
			return null === $error ? $this->uploaded( $key ) : self::upload_failed( $error );
		}

		$body = file_get_contents( $local_path );
		if ( false === $body ) {
			return self::upload_failed( 'Could not read local file: ' . $local_path );
		}

		$response = $this->request(
			'PUT',
			$this->request_url( $key ),
			array(
				'Content-Type' => $content_type,
				'Content-MD5'  => base64_encode( md5( $body, true ) ),
			) + $this->upload_headers(),
			$body,
			$this->transfer_timeout
		);
		if ( is_wp_error( $response ) ) {
			return self::upload_failed( 'Upload failed: ' . $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return self::upload_failed( 'Upload failed: ' . $this->failure_line( $status, (string) wp_remote_retrieve_body( $response ) ) );
		}
		return $this->uploaded( $key );
	}

	/**
	 * @param string $key Object key.
	 * @return array<string, mixed>
	 */
	private function uploaded( string $key ): array {
		return UploadResult::success( $this->get_file_url( $key ), $key )->toArray();
	}

	/**
	 * @param string $error Failure line.
	 * @return array<string, mixed>
	 */
	private static function upload_failed( string $error ): array {
		return UploadResult::failure( $error )->toArray();
	}

	/**
	 * Start a multipart upload and send every part; the upload id and the
	 * parts' ETags for the commit, or an error line (the upload is then
	 * aborted, so no orphan parts are billed).
	 *
	 * @param string $local_path   File.
	 * @param string $key          Object key.
	 * @param string $content_type Type stored on the object.
	 * @param int    $size         Size of the file.
	 * @return array{upload_id: string, etags: string[]}|string
	 */
	private function upload_parts( string $local_path, string $key, string $content_type, int $size ) {
		$url       = $this->request_url( $key );
		$upload_id = $this->create_multipart( $key, $content_type );
		if ( ! is_array( $upload_id ) ) {
			return $upload_id;
		}
		$upload_id = $upload_id[0];

		$fp = fopen( $local_path, 'rb' );
		if ( ! $fp ) {
			$this->abort( $url, $upload_id );
			return 'Could not open local file: ' . $local_path;
		}

		$etags     = array();
		$read      = 0;
		$part      = 0;
		$part_size = $this->part_size( $size );
		while ( ! feof( $fp ) ) {
			$chunk = fread( $fp, $part_size );
			if ( false === $chunk ) {
				fclose( $fp );
				$this->abort( $url, $upload_id );
				return 'Could not read part ' . ( $part + 1 ) . ' of: ' . $local_path;
			}
			if ( '' === $chunk ) {
				break;
			}
			++$part;

			$response = $this->request(
				'PUT',
				$url . '?partNumber=' . $part . '&uploadId=' . rawurlencode( $upload_id ),
				array(
					'Content-Type' => 'application/octet-stream',
					'Content-MD5'  => base64_encode( md5( $chunk, true ) ),
				),
				$chunk,
				$this->transfer_timeout
			);
			if ( is_wp_error( $response ) ) {
				fclose( $fp );
				$this->abort( $url, $upload_id );
				return 'Upload failed on part ' . $part . ': ' . $response->get_error_message();
			}
			$status = (int) wp_remote_retrieve_response_code( $response );
			$etag   = self::header( $response, 'etag' );
			if ( 200 !== $status || '' === $etag ) {
				fclose( $fp );
				$this->abort( $url, $upload_id );
				return 'Upload failed on part ' . $part . ': ' . $this->failure_line( $status, (string) wp_remote_retrieve_body( $response ) );
			}
			$etags[ $part ] = $etag;
			$read          += strlen( $chunk );
		}
		fclose( $fp );

		// The parts about to be assembled have to add up to the file.
		if ( $read !== $size || array() === $etags ) {
			$this->abort( $url, $upload_id );
			return 'Read ' . $read . ' of ' . $size . ' bytes from: ' . $local_path;
		}

		return array(
			'upload_id' => $upload_id,
			'etags'     => $etags,
		);
	}

	/**
	 * CreateMultipartUpload: the UploadId every part and the commit name.
	 *
	 * @param string $key          Object key.
	 * @param string $content_type Type stored on the object.
	 * @return array{0: string}|string The UploadId, or the error line.
	 */
	private function create_multipart( string $key, string $content_type ) {
		$create = $this->request( 'POST', $this->request_url( $key ) . '?uploads=', array( 'Content-Type' => $content_type ) + $this->upload_headers() );
		if ( is_wp_error( $create ) ) {
			return 'Upload failed to start: ' . $create->get_error_message();
		}
		$status = (int) wp_remote_retrieve_response_code( $create );
		$xml    = self::parse_xml( (string) wp_remote_retrieve_body( $create ) );
		if ( 200 !== $status || null === $xml || ! isset( $xml->UploadId ) ) {
			return 'Upload failed to start: ' . $this->failure_line( $status, (string) wp_remote_retrieve_body( $create ) );
		}
		return array( (string) $xml->UploadId );
	}

	/**
	 * The CompleteMultipartUpload body for the parts' ETags.
	 *
	 * @param string[] $etags ETag per part number.
	 * @return string
	 */
	private static function complete_body( array $etags ): string {
		$xml = '<CompleteMultipartUpload>';
		foreach ( $etags as $number => $etag ) {
			$xml .= '<Part><PartNumber>' . (int) $number . '</PartNumber><ETag>' . esc_html( $etag ) . '</ETag></Part>';
		}
		return $xml . '</CompleteMultipartUpload>';
	}

	/**
	 * Multipart upload through the WordPress HTTP API, parts and commit.
	 *
	 * @param string $local_path   File.
	 * @param string $key          Object key.
	 * @param string $content_type Type stored on the object.
	 * @param int    $size         Size of the file.
	 * @return string|null Null on success, else the error line.
	 */
	private function multipart_upload( string $local_path, string $key, string $content_type, int $size ): ?string {
		$parts = $this->upload_parts( $local_path, $key, $content_type, $size );
		if ( is_string( $parts ) ) {
			return $parts;
		}

		$url      = $this->request_url( $key );
		$body     = self::complete_body( $parts['etags'] );
		$response = $this->request( 'POST', $url . '?uploadId=' . rawurlencode( $parts['upload_id'] ), array( 'Content-Type' => 'application/xml' ), $body, $this->transfer_timeout );
		if ( is_wp_error( $response ) ) {
			$this->abort( $url, $parts['upload_id'] );
			return 'Upload failed on commit: ' . $response->get_error_message();
		}
		$verdict = $this->verify_upload_response( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_body( $response ) );
		if ( null !== $verdict ) {
			$this->abort( $url, $parts['upload_id'] );
			return 'Upload failed on commit: ' . $verdict;
		}
		return null;
	}

	/**
	 * AbortMultipartUpload, so the parts sent so far are not kept (and billed).
	 *
	 * @param string $url       Object URL.
	 * @param string $upload_id Upload id.
	 */
	private function abort( string $url, string $upload_id ): void {
		$response = $this->request( 'DELETE', $url . '?uploadId=' . rawurlencode( $upload_id ) );
		if ( is_wp_error( $response ) || ! in_array( (int) wp_remote_retrieve_response_code( $response ), array( 204, 200, 404 ), true ) ) {
			Logger::info( '[DiluxOne Offload S3CompatibleProvider] A failed multipart upload could not be aborted; a lifecycle rule for incomplete uploads removes its parts.' );
		}
	}

	// ── Read, inspect, delete, copy ─────────────────────────

	/**
	 * Download an object to a local (temp) file, streamed to disk. Signed,
	 * through the endpoint rather than the public URL, so a wrong Public
	 * URL never breaks the plugin's own reads.
	 *
	 * @param string $remote_path Object key.
	 * @param string $local_path  Destination (a temp file).
	 * @return array<string, mixed> ['success' => bool, 'error' => string]
	 */
	public function download_file( string $remote_path, string $local_path ): array {
		$dir = dirname( $local_path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$response = $this->request(
			'GET',
			$this->request_url( ltrim( $remote_path, '/' ) ),
			array(),
			'',
			$this->download_timeout,
			array(
				'stream'   => true,
				'filename' => $local_path,
			)
		);

		$status = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $status ) {
			return OperationResult::success()->toArray();
		}

		// Streaming writes whatever came back: on an error, the error document.
		if ( file_exists( $local_path ) ) {
			wp_delete_file( $local_path );
		}
		// The stream wrapper tells a missing object from a failure by the 404.
		return OperationResult::failure( is_wp_error( $response ) ? 'Download failed: ' . $response->get_error_message() : 'HTTP ' . $status . ( 404 === $status ? ': not found' : '' ) )->toArray();
	}

	/**
	 * HEAD an object.
	 *
	 * @param string $remote_path Object key.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function head( string $remote_path ) {
		return $this->request( 'HEAD', $this->request_url( ltrim( $remote_path, '/' ) ) );
	}

	/**
	 * @param string $remote_path Object key.
	 * @return bool
	 */
	public function file_exists( string $remote_path ): bool {
		$response = $this->head( $remote_path );
		if ( is_wp_error( $response ) ) {
			Logger::info( '[DiluxOne Offload S3CompatibleProvider] file_exists error: ' . $response->get_error_message() );
			return false;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status && 404 !== $status ) {
			Logger::info( '[DiluxOne Offload S3CompatibleProvider] file_exists answered HTTP ' . $status );
		}
		return 200 === $status;
	}

	/**
	 * The object's MD5, base64 like Azure's Content-MD5; false when the
	 * ETag is not an MD5 (a multipart object's ETag ends in -<parts>).
	 *
	 * @param string $remote_path Object key.
	 * @return string|false
	 */
	public function get_file_checksum( string $remote_path ) {
		$info = $this->get_file_info( $remote_path );
		return is_array( $info ) && is_string( $info['md5'] ) ? $info['md5'] : false;
	}

	/**
	 * @param string $remote_path Object key.
	 * @return array<string, mixed>|false
	 */
	public function get_file_info( string $remote_path ) {
		$response = $this->head( $remote_path );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}
		$info = new FileInfo(
			ltrim( $remote_path, '/' ),
			(int) self::header( $response, 'content-length' ),
			self::md5_of_etag( self::header( $response, 'etag' ) ),
			self::header( $response, 'last-modified' )
		);
		return $info->toArray();
	}

	/**
	 * The base64 MD5 an ETag stands for, or null when it is not one.
	 *
	 * @param string $etag ETag, quoted or not.
	 * @return string|null
	 */
	private static function md5_of_etag( string $etag ): ?string {
		$hex = strtolower( trim( $etag, "\" \t" ) );
		if ( ! preg_match( '/^[0-9a-f]{32}$/', $hex ) ) {
			return null;
		}
		return base64_encode( (string) hex2bin( $hex ) );
	}

	/**
	 * S3 answers 204 whether the object was there or not; both mean it is gone.
	 *
	 * @param string $remote_path Object key.
	 * @return array<string, mixed> ['success' => bool, 'error' => string]
	 */
	public function delete_file( string $remote_path ): array {
		$response = $this->request( 'DELETE', $this->request_url( ltrim( $remote_path, '/' ) ) );
		if ( is_wp_error( $response ) ) {
			return OperationResult::failure( 'Delete failed: ' . $response->get_error_message() )->toArray();
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( in_array( $status, array( 200, 204, 404 ), true ) ) {
			return OperationResult::success()->toArray();
		}
		return OperationResult::failure( 'Delete failed: ' . $this->failure_line( $status, (string) wp_remote_retrieve_body( $response ) ) )->toArray();
	}

	/**
	 * Server-side copy (CopyObject). A 200 can carry an <Error> in its body.
	 *
	 * @param string $source_path Source key.
	 * @param string $dest_path   Destination key.
	 * @return array<string, mixed> ['success' => bool, 'error' => string]
	 */
	public function copy_blob( string $source_path, string $dest_path ): array {
		// The metadata directive copies Cache-Control with the object; the
		// storage class is not copied, so the new object is told it again.
		$response = $this->request(
			'PUT',
			$this->request_url( ltrim( $dest_path, '/' ) ),
			array(
				'x-amz-copy-source'        => '/' . rawurlencode( $this->bucket ) . '/' . $this->object_path( $source_path ),
				'x-amz-metadata-directive' => 'COPY',
				// A PUT without a body: Google answers 411 unless the length is stated.
				'Content-Length'           => '0',
			) + $this->acl_headers() + array_intersect_key( $this->new_object_headers, array( 'x-amz-storage-class' => true ) ),
			'',
			$this->transfer_timeout
		);
		if ( is_wp_error( $response ) ) {
			return OperationResult::failure( 'Copy failed: ' . $response->get_error_message() )->toArray();
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$xml    = self::parse_xml( $body );
		if ( 200 === $status && null !== $xml && 'CopyObjectResult' === $xml->getName() ) {
			return OperationResult::success()->toArray();
		}
		return OperationResult::failure( 'Copy failed: ' . $this->failure_line( $status, $body ) )->toArray();
	}

	// ── Listing ─────────────────────────────────────────────

	/**
	 * Every object under a prefix (ListObjectsV2, all pages). Three
	 * attempts on a server or network error, none on a client error, which
	 * is recorded as a connection failure.
	 *
	 * @param string $prefix Key prefix.
	 * @return array<int, array<string, mixed>> One ['path', 'size', 'md5', 'last_modified'] per object
	 * @throws \Exception When the listing fails.
	 */
	public function list_files( string $prefix = 'uploads/' ): array {
		$max_retries = 3;

		for ( $attempt = 1; ; $attempt++ ) {
			try {
				return $this->list_all( $prefix );
			} catch ( \Exception $e ) {
				$code = $this->extract_error_code( $e->getMessage() );
				if ( in_array( $code, array( '400', '401', '403', '404', '409' ), true ) ) {
					ConfigManager::record_connection_failure( $code, $e->getMessage(), 'list_files' );
					throw $e;
				}
				// A 5xx or a dropped connection was already asked again three
				// times by send_with_retry(); only a bad listing body is retried here.
				if ( $attempt >= $max_retries || self::retried_inside( $code ) ) {
					throw new \Exception( 'Failed to list files after ' . (int) $max_retries . ' attempts: ' . esc_html( $e->getMessage() ) );
				}
				Logger::error( '[DiluxOne Offload S3CompatibleProvider] Attempt ' . $attempt . ' failed (retryable), retrying in 2s... Error: ' . $e->getMessage() );
				sleep( 2 );
			}
		}
	}

	/**
	 * @param string $prefix Key prefix.
	 * @return array<int, array<string, mixed>>
	 * @throws \Exception On any failed page.
	 */
	private function list_all( string $prefix ): array {
		$files  = array();
		$marker = '';
		do {
			$page   = $this->list_page( $prefix, $marker );
			$files  = array_merge( $files, $page['files'] );
			$marker = $page['next'];
		} while ( '' !== $marker );

		return $files;
	}

	/**
	 * One ListObjectsV2 page (up to 1000 objects); `next` is its continuation token.
	 *
	 * @param string $prefix Key prefix.
	 * @param string $marker Continuation token of the page before, or ''.
	 * @return array{files: array<int, array<string, mixed>>, next: string}
	 * @throws \Exception When the page cannot be listed.
	 */
	public function list_page( string $prefix, string $marker = '' ): array {
		$query = 'list-type=2&prefix=' . rawurlencode( $prefix );
		if ( '' !== $marker ) {
			$query .= '&continuation-token=' . rawurlencode( $marker );
		}

		$response = $this->request( 'GET', $this->request_url( '', $query ), array(), '', 60 );
		if ( is_wp_error( $response ) ) {
			throw new \Exception( 'Listing failed: ' . esc_html( $response->get_error_message() ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		if ( 200 !== $status ) {
			throw new \Exception( esc_html( $this->failure_line( $status, $body ) ) . ' on a listing page' );
		}
		$xml = self::parse_xml( $body );
		if ( null === $xml || 'ListBucketResult' !== $xml->getName() ) {
			throw new \Exception( 'Invalid listing page' );
		}

		$files = array();
		foreach ( $xml->Contents as $object ) {
			$info    = new FileInfo(
				(string) $object->Key,
				(int) $object->Size,
				self::md5_of_etag( (string) $object->ETag ),
				(string) $object->LastModified
			);
			$files[] = $info->toArray();
		}

		return array(
			'files' => $files,
			'next'  => 'true' === (string) $xml->IsTruncated ? (string) $xml->NextContinuationToken : '',
		);
	}

	// ── Identity ────────────────────────────────────────────

	/**
	 * Public URL of an object: the Public URL field (a CDN or custom domain
	 * when the user set one) and the encoded key.
	 *
	 * @param string $remote_path Object key.
	 * @return string
	 */
	public function get_file_url( string $remote_path ): string {
		return $this->public_url . '/' . $this->object_path( $remote_path );
	}

	/**
	 * @return string
	 */
	public function get_provider_name(): string {
		return 's3';
	}

	// ── Handles for the sync engine ─────────────────────────

	/**
	 * A streamed PUT of a whole file (up to the sync's chunk threshold).
	 *
	 * @param array<string, mixed> $file_info ['local_path' => string, 'remote_path' => string]
	 * @return array<string, mixed> ['success' => bool, 'handle' => resource|null, 'error' => string, 'file_handle' => resource|null]
	 */
	public function prepare_batch_upload_handle( array $file_info ): array {
		$local_path = (string) $file_info['local_path'];
		$key        = ltrim( (string) $file_info['remote_path'], '/' );

		$size = is_file( $local_path ) ? filesize( $local_path ) : false;
		$md5  = false === $size ? false : md5_file( $local_path, true );
		if ( false === $size || false === $md5 ) {
			return self::no_handle( 'File not found: ' . $local_path );
		}

		$file_handle = fopen( $local_path, 'rb' );
		if ( ! $file_handle ) {
			return self::no_handle( 'Failed to open file for reading' );
		}

		$url     = $this->request_url( $key );
		$headers = $this->signed(
			'PUT',
			$url,
			array(
				'Content-Type' => MimeHelper::get_mime_type( $key ),
				'Content-MD5'  => base64_encode( $md5 ),
			) + $this->upload_headers()
		);

		$ch = curl_init();
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_URL            => $url,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CUSTOMREQUEST  => 'PUT',
				CURLOPT_UPLOAD         => true,
				CURLOPT_INFILE         => $file_handle,
				CURLOPT_INFILESIZE     => $size,
				CURLOPT_HTTPHEADER     => self::curl_headers( $headers ),
				CURLOPT_TIMEOUT        => $this->transfer_timeout,
				CURLOPT_CONNECTTIMEOUT => 30,
			)
		);

		return array(
			'success'     => true,
			'handle'      => $ch,
			'file_handle' => $file_handle,
		);
	}

	/**
	 * A large file: the multipart upload is started here; its parts and its
	 * commit go through the sync's pool. An unfinished one is taken up with
	 * ListParts; a new one starts only when the service says it does not
	 * know that upload. When it cannot be asked (a 5xx, a timeout), nothing
	 * starts: the file fails this round and its row keeps the token, so the
	 * upload is taken up later instead of being left behind, billed.
	 *
	 * @param array<string, mixed> $file_info        ['local_path' => string, 'remote_path' => string]
	 * @param string|null          $resume_upload_id The UploadId to take up (or its `#` form), or null for a new upload.
	 * @return array<string, mixed> ['success' => bool, 'error' => string, 'upload' => ChunkedUpload]
	 */
	public function begin_chunked_upload( array $file_info, ?string $resume_upload_id = null ): array {
		$local_path = (string) $file_info['local_path'];
		$key        = ltrim( (string) $file_info['remote_path'], '/' );

		$size = is_file( $local_path ) ? (int) filesize( $local_path ) : 0;
		if ( $size <= 0 ) {
			return self::no_handle( 'File not found: ' . $local_path );
		}

		if ( null !== $resume_upload_id && '' !== $resume_upload_id ) {
			$upload_id = $this->resolve_upload_id( $key, $resume_upload_id );
			$landed    = null === $upload_id || false === $upload_id ? $upload_id : $this->list_parts( $key, $upload_id );
			if ( false === $landed || is_string( $landed ) ) {
				return self::no_handle( 'Could not ask for the unfinished upload of ' . $key . ( is_string( $landed ) ? ': ' . $landed : '' ) . '; it is taken up next time' );
			}
			if ( null !== $landed && is_string( $upload_id ) ) {
				$upload = new ChunkedUpload( $local_path, $key, $size, $this->part_size( $size ), $upload_id );
				foreach ( $landed as $part => $landed_part ) {
					if ( $part <= $upload->partCount() && $landed_part['size'] === $upload->length( $part ) ) {
						$upload->recordTag( $part, $landed_part['etag'] );
					}
				}
				return array(
					'success' => true,
					'upload'  => $upload,
				);
			}
		}

		$upload_id = $this->create_multipart( $key, MimeHelper::get_mime_type( $key ) );
		if ( ! is_array( $upload_id ) ) {
			Logger::info( '[DiluxOne Offload S3CompatibleProvider] Chunked upload error: ' . $upload_id );
			return self::no_handle( $upload_id );
		}

		return array(
			'success' => true,
			'upload'  => new ChunkedUpload( $local_path, $key, $size, $this->part_size( $size ), $upload_id[0] ),
		);
	}

	/**
	 * ListParts, every page: the parts the service holds for an upload, by
	 * number, with their ETag and size. Null when the service no longer
	 * knows the upload (404 NoSuchUpload: aborted or expired); the error line
	 * when it cannot be asked.
	 *
	 * @param string $key       Object key.
	 * @param string $upload_id The UploadId.
	 * @return array<int, array{etag: string, size: int}>|string|null
	 */
	private function list_parts( string $key, string $upload_id ) {
		$parts  = array();
		$marker = 0;
		for ( $page = 0; $page < 20; $page++ ) { // 1000 parts a page, 10000 at most.
			$query    = '?uploadId=' . rawurlencode( $upload_id ) . ( $marker > 0 ? '&part-number-marker=' . $marker : '' );
			$response = $this->request( 'GET', $this->request_url( $key ) . $query );
			if ( is_wp_error( $response ) ) {
				return $response->get_error_message();
			}
			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( 404 === $status ) {
				return null;
			}
			$xml = 200 === $status ? self::parse_xml( (string) wp_remote_retrieve_body( $response ) ) : null;
			if ( null === $xml ) {
				return $this->failure_line( $status, (string) wp_remote_retrieve_body( $response ) );
			}
			foreach ( $xml->Part as $part ) {
				$parts[ (int) $part->PartNumber ] = array(
					'etag' => (string) $part->ETag,
					'size' => (int) $part->Size,
				);
			}
			$marker = (int) $xml->NextPartNumberMarker;
			if ( 'true' !== strtolower( (string) $xml->IsTruncated ) || $marker <= 0 ) {
				return $parts;
			}
		}
		return $parts;
	}

	/**
	 * One UploadPart, its body streamed from the file, with the Content-MD5
	 * of exactly those bytes (read once to hash, a part at a time). The
	 * ETag of the answer is recorded as the part's tag.
	 *
	 * @param ChunkedUpload $upload The upload.
	 * @param int           $part   Part number, from 1.
	 * @return array<string, mixed> ['success' => bool, 'handle' => resource|null, 'error' => string, 'file_handle' => resource|null]
	 */
	public function prepare_part_handle( ChunkedUpload $upload, int $part ): array {
		$md5 = self::part_md5( $upload, $part );
		if ( null === $md5 ) {
			return self::no_handle( 'Could not read part ' . $part . ' of: ' . $upload->localPath() );
		}

		$url     = $this->request_url( $upload->remotePath() ) . '?partNumber=' . $part . '&uploadId=' . rawurlencode( $upload->uploadId() );
		$headers = $this->signed(
			'PUT',
			$url,
			array(
				'Content-Type' => 'application/octet-stream',
				'Content-MD5'  => base64_encode( $md5 ),
			)
		);

		$ch = curl_init();
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_URL            => $url,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CUSTOMREQUEST  => 'PUT',
				CURLOPT_HTTPHEADER     => self::curl_headers( $headers ),
				CURLOPT_HEADERFUNCTION => static function ( $handle, string $line ) use ( $upload, $part ): int {
					if ( 0 === stripos( $line, 'etag:' ) ) {
						$upload->recordTag( $part, trim( substr( $line, 5 ) ) );
					}
					return strlen( $line );
				},
				CURLOPT_TIMEOUT        => $this->transfer_timeout,
				CURLOPT_CONNECTTIMEOUT => 30,
			)
		);
		$file_handle = self::stream_part( $ch, $upload, $part );
		if ( null === $file_handle ) {
			return self::no_handle( 'Could not read part ' . $part . ' of: ' . $upload->localPath() );
		}

		return array(
			'success'     => true,
			'handle'      => $ch,
			'file_handle' => $file_handle,
		);
	}

	/**
	 * A part landed when the answer is 200 with an ETag.
	 *
	 * @param ChunkedUpload $upload The upload.
	 * @param int           $part   Part number.
	 * @param int           $status HTTP status.
	 * @param string        $body   Response body.
	 * @return string|null
	 */
	public function finish_part( ChunkedUpload $upload, int $part, int $status, string $body ): ?string {
		if ( 200 !== $status || '' === $upload->tag( $part ) ) {
			return 'Upload failed on part ' . $part . ': ' . $this->failure_line( $status, $body );
		}
		return null;
	}

	/**
	 * CompleteMultipartUpload, read like any upload; if it fails, the parts go.
	 *
	 * @param ChunkedUpload $upload The upload, every part tagged.
	 * @return array<string, mixed> ['success' => bool, 'handle' => resource|null, 'error' => string, 'file_handle' => null, 'on_failure' => callable]
	 */
	public function prepare_commit_handle( ChunkedUpload $upload ): array {
		$tags = $upload->tags();
		if ( null === $tags ) {
			return self::no_handle( 'A part of ' . $upload->localPath() . ' was not uploaded' );
		}

		$url     = $this->request_url( $upload->remotePath() ) . '?uploadId=' . rawurlencode( $upload->uploadId() );
		$headers = $this->signed( 'POST', $url, array( 'Content-Type' => 'application/xml' ) );

		$ch = curl_init();
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_URL            => $url,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CUSTOMREQUEST  => 'POST',
				CURLOPT_POSTFIELDS     => self::complete_body( $tags ),
				CURLOPT_HTTPHEADER     => self::curl_headers( $headers ),
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
	 * AbortMultipartUpload, so the parts sent are not kept and billed.
	 *
	 * @param ChunkedUpload $upload The upload.
	 */
	public function abort_chunked_upload( ChunkedUpload $upload ): void {
		$upload_id = $this->resolve_upload_id( $upload->remotePath(), $upload->uploadId() );
		if ( is_string( $upload_id ) ) {
			$this->abort( $this->request_url( $upload->remotePath() ), $upload_id );
		}
	}

	/**
	 * The UploadId a token names: itself, or, for the `#` form a name too
	 * long for the row is kept as, the unfinished upload of the key whose
	 * UploadId has that SHA-1 (ListMultipartUploads, every page). Null when
	 * the service holds none, false when it cannot be asked.
	 *
	 * @param string $key  Object key.
	 * @param string $name UploadId, or `#` and its SHA-1.
	 * @return string|false|null
	 */
	private function resolve_upload_id( string $key, string $name ) {
		if ( '#' !== substr( $name, 0, 1 ) ) {
			return $name;
		}
		$sha1  = substr( $name, 1 );
		$query = 'uploads=&prefix=' . rawurlencode( $key );
		for ( $page = 0; $page < 20; $page++ ) {
			$response = $this->request( 'GET', $this->request_url( '', $query ) );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return false;
			}
			$xml = self::parse_xml( (string) wp_remote_retrieve_body( $response ) );
			if ( null === $xml ) {
				return false;
			}
			foreach ( $xml->Upload as $unfinished ) {
				if ( $key === (string) $unfinished->Key && hash_equals( $sha1, sha1( (string) $unfinished->UploadId ) ) ) {
					return (string) $unfinished->UploadId;
				}
			}
			if ( 'true' !== strtolower( (string) $xml->IsTruncated ) ) {
				return null;
			}
			$query = 'uploads=&prefix=' . rawurlencode( $key ) . '&key-marker=' . rawurlencode( (string) $xml->NextKeyMarker ) . '&upload-id-marker=' . rawurlencode( (string) $xml->NextUploadIdMarker );
		}
		return null;
	}

	/**
	 * The raw MD5 of one part's bytes, streamed from the file.
	 *
	 * @param ChunkedUpload $upload The upload.
	 * @param int           $part   Part number.
	 * @return string|null Null when the file cannot be read there.
	 */
	private static function part_md5( ChunkedUpload $upload, int $part ): ?string {
		$fp = fopen( $upload->localPath(), 'rb' );
		if ( ! $fp ) {
			return null;
		}
		$ctx  = hash_init( 'md5' );
		$read = 0 === fseek( $fp, $upload->offset( $part ) ) ? hash_update_stream( $ctx, $fp, $upload->length( $part ) ) : 0;
		fclose( $fp );
		return $read === $upload->length( $part ) ? hash_final( $ctx, true ) : null;
	}

	/**
	 * A streamed, signed GET into `{local}.dlxpart`, which SyncManager
	 * renames over the attachment once status and size check out. The local
	 * path is the attachment's own path under uploads/, already checked by
	 * SyncManager: this is "Disconnect from Cloud" putting the media back.
	 *
	 * @param array<string, mixed> $file_info ['local_path' => string, 'remote_path' => string]
	 * @return array<string, mixed> ['success' => bool, 'handle' => resource|null, 'error' => string, 'file_handle' => resource|null, 'part_path' => string]
	 */
	public function prepare_download_handle( array $file_info ): array {
		$local_path = (string) $file_info['local_path'];
		$url        = $this->request_url( ltrim( (string) $file_info['remote_path'], '/' ) );

		$dir = dirname( $local_path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$part_path   = $local_path . '.dlxpart';
		$file_handle = fopen( $part_path, 'wb' );
		if ( ! $file_handle ) {
			return self::no_handle( 'Failed to open local file for writing' );
		}

		$ch = curl_init();
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_URL            => $url,
				CURLOPT_RETURNTRANSFER => false,
				CURLOPT_FILE           => $file_handle,
				CURLOPT_HTTPHEADER     => self::curl_headers( $this->signed( 'GET', $url ) ),
				CURLOPT_TIMEOUT        => $this->download_timeout,
				CURLOPT_CONNECTTIMEOUT => 30,
				CURLOPT_FOLLOWLOCATION => false,
			)
		);

		return array(
			'success'     => true,
			'handle'      => $ch,
			'file_handle' => $file_handle,
			'part_path'   => $part_path,
		);
	}

	/**
	 * @param string $error Failure line.
	 * @return array<string, mixed>
	 */
	private static function no_handle( string $error ): array {
		return array(
			'success'     => false,
			'error'       => $error,
			'file_handle' => null,
		);
	}

	/**
	 * One response header as a string (a repeated header comes back as an array: its first value).
	 *
	 * @param array<string,mixed> $response wp_remote_* response.
	 * @param string              $name     Header name.
	 * @return string
	 */
	private static function header( array $response, string $name ): string {
		$value = wp_remote_retrieve_header( $response, $name );
		return is_array( $value ) ? (string) reset( $value ) : (string) $value;
	}

	/**
	 * Parse an XML body quietly; null when it is not XML (a proxy's HTML page).
	 *
	 * @param string $body Raw response body.
	 * @return \SimpleXMLElement|null
	 */
	private static function parse_xml( string $body ): ?\SimpleXMLElement {
		if ( '' === trim( $body ) ) {
			return null;
		}
		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return false === $xml ? null : $xml;
	}
}
