<?php
namespace Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\Providers\S3CompatibleProvider;

/**
 * Unit tests for S3CompatibleProvider against a scripted HTTP layer.
 *
 * As for AzureProvider: wp_remote_* hand every request to
 * $GLOBALS['_test_wp_http'], so each test states what the service answers
 * and asserts both the result and the requests the provider made (method,
 * URL, which ones are signed), because an S3 request is only right if all
 * of those are.
 */
class S3CompatibleProviderTest extends TestCase {

	private const SECRET = 'unit-test-secret-access-key/with+symbols';

	private S3CompatibleProvider $provider;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_wp_options']    = array();
		$GLOBALS['_test_wp_transients'] = array();
		$GLOBALS['_test_wp_http_log']   = array();
		unset( $GLOBALS['_test_wp_http'], $GLOBALS['_test_multisite'] );
		$this->provider = self::make();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_test_wp_http'], $GLOBALS['_test_wp_http_log'], $GLOBALS['_test_wp_transients'], $GLOBALS['_test_wp_options'], $GLOBALS['_test_multisite'] );
		parent::tearDown();
	}

	/** @param array<string,mixed> $overrides */
	private static function make( array $overrides = array() ): S3CompatibleProvider {
		return new S3CompatibleProvider(
			$overrides + array(
				'preset'            => 'custom',
				'endpoint'          => 'https://s3.example.com',
				'region'            => 'us-east-1',
				'bucket'            => 'media',
				'access_key_id'     => 'AKIDUNIT',
				'secret_access_key' => self::SECRET,
				'public_url'        => 'https://cdn.example.com/files',
				'path_style'        => true,
			)
		);
	}

	/** @param array<string,string> $headers */
	private static function reply( int $code, string $body = '', array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $code, 'message' => '' ),
			'body'     => $body,
			'headers'  => $headers,
		);
	}

	private function answer( callable $fn ): void {
		$GLOBALS['_test_wp_http'] = $fn;
	}

	/** @return array<int, array{method:string,url:string,args:array}> */
	private function requests(): array {
		return $GLOBALS['_test_wp_http_log'] ?? array();
	}

	private static function error( string $code, string $message, string $extra = '' ): string {
		return '<?xml version="1.0" encoding="UTF-8"?><Error><Code>' . $code . '</Code><Message>' . $message . '</Message>' . $extra . '</Error>';
	}

	private function tmp( int $bytes, string $fill = 'x' ): string {
		$f = (string) tempnam( sys_get_temp_dir(), 's3u' );
		$h = fopen( $f, 'wb' );
		for ( $left = $bytes; $left > 0; $left -= 1048576 ) {
			fwrite( $h, str_repeat( $fill, min( 1048576, $left ) ) );
		}
		fclose( $h );
		return $f;
	}

	// ── Identity and addressing ─────────────────────────────

	public function test_provider_name(): void {
		$this->assertSame( 's3', $this->provider->get_provider_name() );
	}

	public function test_the_file_url_is_the_public_url_with_its_path_and_the_encoded_key(): void {
		$this->assertSame( 'https://cdn.example.com/files/uploads/2026/09/a%20b%2Bc%C3%B1.jpg', $this->provider->get_file_url( '/uploads/2026/09/a b+cñ.jpg' ) );
		$this->assertSame( 'https://pub.r2.dev/x.png', self::make( array( 'public_url' => 'https://pub.r2.dev/' ) )->get_file_url( 'x.png' ) );
	}

	public function test_path_style_puts_the_bucket_in_the_path_and_amazon_in_the_host(): void {
		$this->provider->file_exists( 'uploads/a.jpg' );
		self::make(
			array(
				'endpoint'   => 'https://s3.eu-west-1.amazonaws.com',
				'region'     => 'eu-west-1',
				'path_style' => false,
			)
		)->file_exists( 'uploads/a.jpg' );

		$this->assertSame( 'https://s3.example.com/media/uploads/a.jpg', $this->requests()[0]['url'] );
		$this->assertSame( 'https://media.s3.eu-west-1.amazonaws.com/uploads/a.jpg', $this->requests()[1]['url'] );
	}

	public function test_every_request_to_the_endpoint_is_signed_and_never_carries_the_secret(): void {
		$this->provider->file_exists( 'uploads/a.jpg' );
		$request = $this->requests()[0];

		$this->assertStringStartsWith( 'AWS4-HMAC-SHA256 Credential=AKIDUNIT/', $request['args']['headers']['Authorization'] );
		$this->assertSame( 'UNSIGNED-PAYLOAD', $request['args']['headers']['x-amz-content-sha256'] );
		$this->assertArrayNotHasKey( 'Host', $request['args']['headers'], 'the transport sends Host from the URL; a second one is a 400' );
		$this->assertStringNotContainsString( self::SECRET, (string) json_encode( $request ) );
	}

	// ── test_connection ─────────────────────────────────────

	public function test_connection_writes_a_probe_reads_it_back_anonymously_and_deletes_it(): void {
		$written = '';
		$this->answer(
			function ( string $method, string $url, array $args ) use ( &$written ) {
				if ( 'PUT' === $method ) {
					$written = $args['body'];
					return self::reply( 200 );
				}
				if ( 'GET' === $method ) {
					return self::reply( 200, $written );
				}
				return self::reply( 204 );
			}
		);

		$r = $this->provider->test_connection();

		$this->assertTrue( $r['success'], $r['message'] );
		$log = $this->requests();
		$this->assertSame( array( 'PUT', 'GET', 'DELETE' ), array_column( $log, 'method' ) );
		$this->assertMatchesRegularExpression( '#^https://s3\.example\.com/media/uploads/\.diluxone-offload-probe-[0-9a-f]{16}$#', $log[0]['url'] );
		$this->assertSame( 32, strlen( $written ) );
		$this->assertArrayHasKey( 'Authorization', $log[0]['args']['headers'] );
		$this->assertStringStartsWith( 'https://cdn.example.com/files/uploads/.diluxone-offload-probe-', $log[1]['url'], 'read back at the Public URL' );
		$this->assertArrayNotHasKey( 'headers', $log[1]['args'], 'anonymously: no credentials at all' );
		$this->assertSame( $log[0]['url'], $log[2]['url'] );
	}

	public function test_a_subsite_probes_its_own_prefix(): void {
		$GLOBALS['_test_multisite'] = array( 'enabled' => true, 'main' => false, 'blog_id' => 3 );
		$this->provider->test_connection();
		$this->assertStringContainsString( '/media/uploads/sites/', $this->requests()[0]['url'] );
	}

	/** @return array<string,array{0:int,1:string,2:string}> */
	public function refusals(): array {
		return array(
			'wrong secret'   => array( 403, self::error( 'SignatureDoesNotMatch', 'The request signature we calculated does not match', '<StringToSign>AWS4-HMAC-SHA256 secret-ish</StringToSign><SignatureProvided>abc123</SignatureProvided><AWSAccessKeyId>AKIDUNIT</AWSAccessKeyId><CanonicalRequest>PUT /media</CanonicalRequest>' ), 'HTTP 403: the Secret Access Key is wrong' ),
			'wrong key id'   => array( 403, self::error( 'InvalidAccessKeyId', 'The key does not exist' ), 'HTTP 403: the Access Key ID is wrong' ),
			'no bucket'      => array( 404, self::error( 'NoSuchBucket', 'The specified bucket does not exist' ), 'HTTP 404: the bucket does not exist in this region or endpoint' ),
			'wrong region'   => array( 301, self::error( 'PermanentRedirect', 'Use the right endpoint' ), 'HTTP 301: wrong region for this bucket' ),
			'no permission'  => array( 403, self::error( 'AccessDenied', 'Access Denied' ), 'HTTP 403: the keys cannot write to this bucket' ),
			'clock'          => array( 403, self::error( 'RequestTimeTooSkewed', 'Too skewed' ), 'HTTP 403: the server clock is more than 15 minutes off' ),
		);
	}

	/** @dataProvider refusals */
	public function test_a_refused_probe_says_why_in_plain_words_and_nothing_secret( int $status, string $body, string $starts ): void {
		$this->answer( fn() => self::reply( $status, $body ) );

		$r = $this->provider->test_connection();

		$this->assertFalse( $r['success'] );
		$this->assertStringStartsWith( $starts, $r['message'] );
		foreach ( array( 'StringToSign', 'secret-ish', 'abc123', 'AKIDUNIT', 'CanonicalRequest', 'media' ) as $never ) {
			$this->assertStringNotContainsString( $never, $r['message'] );
		}
		$this->assertCount( 1, $this->requests(), 'stops at the first failure' );
	}

	public function test_a_bucket_that_is_not_publicly_readable_is_refused(): void {
		$this->answer( fn( string $method ) => 'GET' === $method ? self::reply( 403, self::error( 'AccessDenied', 'no' ) ) : self::reply( 200 ) );

		$r = $this->provider->test_connection();

		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'not readable at the Public URL', $r['message'] );
		$this->assertSame( 'DELETE', $this->requests()[2]['method'], 'the probe is still deleted' );
	}

	public function test_a_public_url_that_serves_something_else_is_refused(): void {
		$this->answer( fn( string $method ) => 'GET' === $method ? self::reply( 200, '<html>a CDN error page</html>' ) : self::reply( 200 ) );
		$this->assertStringContainsString( 'not readable at the Public URL', $this->provider->test_connection()['message'] );
	}

	public function test_a_transport_failure_is_a_connection_failure(): void {
		$this->answer( fn() => new \WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' ) );
		$r = $this->provider->test_connection();
		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'Could not resolve host', $r['message'] );
	}

	// ── upload_file ─────────────────────────────────────────

	public function test_a_small_file_is_one_put_with_its_md5_and_type(): void {
		$f = $this->tmp( 1000 );
		$this->answer( fn() => self::reply( 200 ) );

		$r = $this->provider->upload_file( $f, '/uploads/2026/09/pic.jpg', array( 'mime_type_from_path' => 'uploads/2026/09/pic.jpg' ) );
		unlink( $f );

		$this->assertTrue( $r['success'], (string) $r['error'] );
		$this->assertSame( 'https://cdn.example.com/files/uploads/2026/09/pic.jpg', $r['url'] );
		$put = $this->requests()[0];
		$this->assertSame( 'PUT', $put['method'] );
		$this->assertSame( 'image/jpeg', $put['args']['headers']['Content-Type'] );
		$this->assertSame( base64_encode( md5( str_repeat( 'x', 1000 ), true ) ), $put['args']['headers']['Content-MD5'], 'the service refuses a body that does not match' );
		$this->assertStringContainsString( 'content-md5;content-type;', $put['args']['headers']['Authorization'] );
	}

	public function test_with_the_acl_option_every_object_written_is_public_read_and_signed(): void {
		$provider = self::make( array( 'object_acl' => true ) );
		$f        = $this->tmp( 10 );
		$this->answer( fn( string $method ) => 'PUT' === $method && false === strpos( (string) json_encode( $this->requests() ), 'copy-source' ) ? self::reply( 200 ) : self::reply( 200, '<CopyObjectResult/>' ) );

		$provider->upload_file( $f, 'uploads/a.jpg' );
		$provider->copy_blob( 'uploads/a.jpg', 'uploads/b.jpg' );
		unlink( $f );

		foreach ( $this->requests() as $request ) {
			$this->assertSame( 'public-read', $request['args']['headers']['x-amz-acl'] );
			$this->assertStringContainsString( 'x-amz-acl;', $request['args']['headers']['Authorization'] );
		}
	}

	public function test_without_the_acl_option_no_acl_is_sent(): void {
		$f = $this->tmp( 10 );
		$this->provider->upload_file( $f, 'uploads/a.jpg' );
		unlink( $f );
		$this->assertArrayNotHasKey( 'x-amz-acl', $this->requests()[0]['args']['headers'] );
	}

	public function test_a_refused_put_is_a_failure_that_starts_with_its_status(): void {
		$f = $this->tmp( 10 );
		$this->answer( fn() => self::reply( 400, self::error( 'BadDigest', 'The Content-MD5 you specified did not match' ) ) );
		$r = $this->provider->upload_file( $f, 'uploads/a.jpg' );
		unlink( $f );
		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'HTTP 400 - S3 BadDigest', $r['error'] );
	}

	public function test_a_large_file_goes_in_five_mebibyte_parts_and_is_committed(): void {
		$f = $this->tmp( 12 * 1048576 );
		$this->answer(
			function ( string $method, string $url ) {
				if ( 'POST' === $method && false !== strpos( $url, '?uploads=' ) ) {
					return self::reply( 200, '<InitiateMultipartUploadResult><UploadId>up/1+2</UploadId></InitiateMultipartUploadResult>' );
				}
				if ( 'PUT' === $method ) {
					preg_match( '/partNumber=(\d+)/', $url, $m );
					return self::reply( 200, '', array( 'ETag' => '"etag-' . $m[1] . '"' ) );
				}
				return self::reply( 200, '<CompleteMultipartUploadResult><Key>k</Key></CompleteMultipartUploadResult>' );
			}
		);

		$r = $this->provider->upload_file( $f, 'uploads/video.mp4' );
		unlink( $f );

		$this->assertTrue( $r['success'], (string) $r['error'] );
		$log = $this->requests();
		$this->assertSame( array( 'POST', 'PUT', 'PUT', 'PUT', 'POST' ), array_column( $log, 'method' ) );
		$this->assertSame( 'video/mp4', $log[0]['args']['headers']['Content-Type'], 'the type is set when the upload starts' );
		$this->assertSame( array( 5242880, 5242880, 2097152 ), array_map( fn( $p ) => strlen( $p['args']['body'] ), array_slice( $log, 1, 3 ) ) );
		$this->assertStringContainsString( '?partNumber=2&uploadId=up%2F1%2B2', $log[2]['url'] );
		$this->assertSame( base64_encode( md5( str_repeat( 'x', 2097152 ), true ) ), $log[3]['args']['headers']['Content-MD5'] );
		$this->assertStringEndsWith( '?uploadId=up%2F1%2B2', $log[4]['url'] );
		$this->assertStringContainsString( '<Part><PartNumber>3</PartNumber><ETag>&quot;etag-3&quot;</ETag></Part>', $log[4]['args']['body'] );
	}

	public function test_a_failing_part_aborts_the_upload(): void {
		$f = $this->tmp( 6 * 1048576 );
		$this->answer(
			function ( string $method, string $url ) {
				if ( 'POST' === $method ) {
					return self::reply( 200, '<InitiateMultipartUploadResult><UploadId>U1</UploadId></InitiateMultipartUploadResult>' );
				}
				if ( 'PUT' === $method ) {
					return false !== strpos( $url, 'partNumber=2' ) ? self::reply( 500, self::error( 'InternalError', 'boom' ) ) : self::reply( 200, '', array( 'ETag' => '"e"' ) );
				}
				return self::reply( 204 );
			}
		);

		$r = $this->provider->upload_file( $f, 'uploads/big.bin' );
		unlink( $f );

		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'part 2: HTTP 500', $r['error'] );
		$last = array_slice( $this->requests(), -1 )[0];
		$this->assertSame( 'DELETE', $last['method'] );
		$this->assertStringEndsWith( '/media/uploads/big.bin?uploadId=U1', $last['url'] );
	}

	public function test_a_commit_that_answers_200_with_an_error_is_a_failure_and_aborts(): void {
		$f = $this->tmp( 6 * 1048576 );
		$this->answer(
			function ( string $method, string $url ) {
				if ( 'POST' === $method && false !== strpos( $url, '?uploads=' ) ) {
					return self::reply( 200, '<InitiateMultipartUploadResult><UploadId>U2</UploadId></InitiateMultipartUploadResult>' );
				}
				if ( 'PUT' === $method ) {
					return self::reply( 200, '', array( 'ETag' => '"e"' ) );
				}
				if ( 'POST' === $method ) {
					return self::reply( 200, self::error( 'InternalError', 'We encountered an internal error' ) );
				}
				return self::reply( 204 );
			}
		);

		$r = $this->provider->upload_file( $f, 'uploads/big.bin' );
		unlink( $f );

		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'commit: HTTP 200 - S3 InternalError', $r['error'] );
		$this->assertSame( 'DELETE', array_slice( $this->requests(), -1 )[0]['method'] );
	}

	public function test_a_missing_file_is_not_uploaded(): void {
		$r = $this->provider->upload_file( '/nope/missing.jpg', 'uploads/x.jpg' );
		$this->assertFalse( $r['success'] );
		$this->assertSame( array(), $this->requests() );
	}

	// ── download, head, delete, copy ────────────────────────

	public function test_download_streams_the_signed_object_to_the_file(): void {
		$this->answer( fn() => self::reply( 200, 'the bytes' ) );
		$dest = sys_get_temp_dir() . '/s3u-' . uniqid() . '/x.jpg';

		$r = $this->provider->download_file( 'uploads/x.jpg', $dest );

		$this->assertTrue( $r['success'] );
		$this->assertSame( 'the bytes', file_get_contents( $dest ) );
		$this->assertSame( 'https://s3.example.com/media/uploads/x.jpg', $this->requests()[0]['url'], 'the endpoint, not the public URL' );
		$this->assertArrayHasKey( 'Authorization', $this->requests()[0]['args']['headers'] );
		unlink( $dest );
		rmdir( dirname( $dest ) );
	}

	public function test_a_missing_object_downloads_nothing_and_says_404(): void {
		$this->answer( fn() => self::reply( 404, self::error( 'NoSuchKey', 'The specified key does not exist.' ) ) );
		$dest = (string) tempnam( sys_get_temp_dir(), 's3d' );

		$r = $this->provider->download_file( 'uploads/gone.jpg', $dest );

		$this->assertFalse( $r['success'] );
		$this->assertMatchesRegularExpression( '/\b404\b/', $r['error'], 'the stream wrapper reads a missing object from the 404' );
		$this->assertFileDoesNotExist( $dest, 'the error document is not left behind' );
	}

	public function test_file_exists_is_a_head(): void {
		$this->answer( fn( string $m, string $url ) => self::reply( false !== strpos( $url, 'here' ) ? 200 : 404 ) );
		$this->assertTrue( $this->provider->file_exists( 'uploads/here.jpg' ) );
		$this->assertFalse( $this->provider->file_exists( 'uploads/gone.jpg' ) );
		$this->assertSame( 'HEAD', $this->requests()[0]['method'] );
	}

	public function test_file_info_reads_size_md5_and_date_and_no_md5_for_a_multipart_object(): void {
		$md5 = md5( 'abc' );
		$this->answer(
			fn( string $m, string $url ) => self::reply(
				200,
				'',
				array(
					'Content-Length' => '3',
					'ETag'           => false !== strpos( $url, 'multi' ) ? '"' . $md5 . '-3"' : '"' . $md5 . '"',
					'Last-Modified'  => 'Mon, 01 Jan 2026 00:00:00 GMT',
				)
			)
		);

		$info = $this->provider->get_file_info( 'uploads/a.txt' );
		$this->assertSame( 3, $info['size'] );
		$this->assertSame( base64_encode( md5( 'abc', true ) ), $info['md5'], 'base64, like Azure\'s Content-MD5' );
		$this->assertSame( 'Mon, 01 Jan 2026 00:00:00 GMT', $info['last_modified'] );
		$this->assertSame( base64_encode( md5( 'abc', true ) ), $this->provider->get_file_checksum( 'uploads/a.txt' ) );
		$this->assertFalse( $this->provider->get_file_checksum( 'uploads/multi.bin' ), 'a multipart ETag is not an MD5' );
	}

	public function test_file_info_of_a_missing_object_is_false(): void {
		$this->answer( fn() => self::reply( 404 ) );
		$this->assertFalse( $this->provider->get_file_info( 'uploads/none' ) );
	}

	public function test_delete_succeeds_whether_or_not_the_object_was_there(): void {
		$this->answer( fn() => self::reply( 204 ) );
		$this->assertTrue( $this->provider->delete_file( 'uploads/a.jpg' )['success'] );
		$this->answer( fn() => self::reply( 404 ) );
		$this->assertTrue( $this->provider->delete_file( 'uploads/a.jpg' )['success'] );
	}

	public function test_delete_fails_when_the_object_may_still_be_there(): void {
		$this->answer( fn() => self::reply( 403, self::error( 'AccessDenied', 'Access Denied' ) ) );
		$r = $this->provider->delete_file( 'uploads/a.jpg' );
		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'HTTP 403', $r['error'] );
		$this->answer( fn() => new \WP_Error( 'x', 'cURL error 28' ) );
		$this->assertFalse( $this->provider->delete_file( 'uploads/a.jpg' )['success'] );
	}

	public function test_copy_is_server_side_and_needs_a_copy_result(): void {
		$this->answer( fn() => self::reply( 200, '<CopyObjectResult><ETag>"e"</ETag></CopyObjectResult>' ) );
		$this->assertTrue( $this->provider->copy_blob( 'uploads/a b.jpg', 'uploads/c.jpg' )['success'] );
		$put = $this->requests()[0];
		$this->assertSame( 'PUT', $put['method'] );
		$this->assertStringEndsWith( '/media/uploads/c.jpg', $put['url'] );
		$this->assertSame( '/media/uploads/a%20b.jpg', $put['args']['headers']['x-amz-copy-source'] );
		$this->assertSame( 'COPY', $put['args']['headers']['x-amz-metadata-directive'] );
		$this->assertSame( '0', $put['args']['headers']['Content-Length'], 'a bodiless PUT states its length (Google answers 411 otherwise)' );
		$this->assertStringContainsString( 'x-amz-copy-source;', $put['args']['headers']['Authorization'] );
	}

	public function test_a_copy_that_answers_200_with_an_error_is_a_failure(): void {
		$this->answer( fn() => self::reply( 200, self::error( 'InternalError', 'try again' ) ) );
		$r = $this->provider->copy_blob( 'uploads/a.jpg', 'uploads/b.jpg' );
		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'HTTP 200 - S3 InternalError', $r['error'] );
	}

	// ── list_files and stats ────────────────────────────────

	private static function listing( array $keys, string $next = '' ): string {
		$items = '';
		foreach ( $keys as $key => $size ) {
			$items .= '<Contents><Key>' . $key . '</Key><Size>' . $size . '</Size><ETag>"' . md5( $key ) . '"</ETag><LastModified>2026-09-27T00:00:00.000Z</LastModified></Contents>';
		}
		return '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Name>media</Name>' . $items
			. '<IsTruncated>' . ( '' === $next ? 'false' : 'true' ) . '</IsTruncated>' . ( '' === $next ? '' : '<NextContinuationToken>' . $next . '</NextContinuationToken>' ) . '</ListBucketResult>';
	}

	public function test_listing_follows_the_continuation_token_across_pages(): void {
		$this->answer( fn( string $m, string $url ) => self::reply( 200, false !== strpos( $url, 'continuation-token' ) ? self::listing( array( 'uploads/c.jpg' => 3 ) ) : self::listing( array( 'uploads/a.jpg' => 1, 'uploads/b.jpg' => 2 ), 'tok/en=' ) ) );

		$files = $this->provider->list_files( 'uploads/' );

		$this->assertSame( array( 'uploads/a.jpg', 'uploads/b.jpg', 'uploads/c.jpg' ), array_column( $files, 'path' ) );
		$this->assertSame( array( 1, 2, 3 ), array_column( $files, 'size' ) );
		$this->assertSame( 'https://s3.example.com/media/?list-type=2&prefix=uploads%2F', $this->requests()[0]['url'] );
		$this->assertStringEndsWith( '&continuation-token=tok%2Fen%3D', $this->requests()[1]['url'] );
	}

	public function test_a_refused_listing_is_not_retried_and_is_recorded(): void {
		$this->answer( fn() => self::reply( 403, self::error( 'AccessDenied', 'Access Denied' ) ) );
		try {
			$this->provider->list_files( 'uploads/' );
			$this->fail( 'a 403 listing must throw' );
		} catch ( \Exception $e ) {
			$this->assertStringStartsWith( 'HTTP 403', $e->getMessage() );
		}
		$this->assertCount( 1, $this->requests() );
		$health = $GLOBALS['_test_wp_options']['diluxone_offload_connection_health'];
		$this->assertSame( '403', $health['error_code'] );
		$this->assertSame( 'list_files', $health['error_source'] );
	}

	public function test_stats_are_listed_classified_and_cached_under_the_s3_transient(): void {
		$this->answer( fn() => self::reply( 200, self::listing( array( 'uploads/a.jpg' => 10, 'uploads/b.mp4' => 20, 'uploads/c.pdf' => 5 ) ) ) );

		$r = $this->provider->get_storage_stats( true );

		$this->assertTrue( $r['success'] );
		$this->assertSame( 3, $r['data']['fileCount'] );
		$this->assertSame( 35, $r['data']['storageUsedBytes'] );
		$this->assertSame( array( 'images' => 1, 'videos' => 1, 'audio' => 0, 'other' => 1 ), $r['data']['filesByType'] );
		$this->assertSame( 3, $GLOBALS['_test_wp_transients']['diluxone_offload_s3_stats']['fileCount'] );
	}

	// ── what the sync engine asks ───────────────────────────

	public function test_an_upload_verifies_by_status_and_by_the_absence_of_an_error_body(): void {
		$this->assertNull( $this->provider->verify_upload_response( 200, '' ) );
		$this->assertNull( $this->provider->verify_upload_response( 200, '<CompleteMultipartUploadResult><Key>k</Key></CompleteMultipartUploadResult>' ) );
		$this->assertSame( 'HTTP 200 - S3 InternalError: boom', $this->provider->verify_upload_response( 200, self::error( 'InternalError', 'boom' ) ) );
		$this->assertSame( 'HTTP 403: the Secret Access Key is wrong - S3 SignatureDoesNotMatch: no', $this->provider->verify_upload_response( 403, self::error( 'SignatureDoesNotMatch', 'no', '<SignatureProvided>zzz</SignatureProvided>' ) ) );
	}

	public function test_describe_error_body_keeps_code_and_message_only(): void {
		$line = $this->provider->describe_error_body( self::error( 'SignatureDoesNotMatch', 'mismatch', '<StringToSign>sts</StringToSign><CanonicalRequest>cr</CanonicalRequest><SignatureProvided>sig</SignatureProvided><AWSAccessKeyId>AKIDUNIT</AWSAccessKeyId>' ) );
		$this->assertSame( ' - S3 SignatureDoesNotMatch: mismatch', $line );
		$this->assertSame( '', $this->provider->describe_error_body( '<html>proxy</html>' ) );
		$this->assertSame( '', $this->provider->describe_error_body( '' ) );
	}

	public function test_the_batch_handle_is_a_signed_streamed_put_with_the_files_md5(): void {
		$f = $this->tmp( 100 );
		$r = $this->provider->prepare_batch_upload_handle( array( 'local_path' => $f, 'remote_path' => 'uploads/a.png' ) );

		$this->assertTrue( $r['success'] );
		$this->assertIsResource( $r['file_handle'] );
		$this->assertSame( 'https://s3.example.com/media/uploads/a.png', curl_getinfo( $r['handle'], CURLINFO_EFFECTIVE_URL ) );
		fclose( $r['file_handle'] );
		unlink( $f );
		$this->assertSame( array(), $this->requests(), 'nothing is sent until the sync runs the handle' );
	}

	public function test_the_batch_handle_of_a_missing_file_fails(): void {
		$r = $this->provider->prepare_batch_upload_handle( array( 'local_path' => '/nope/x', 'remote_path' => 'x' ) );
		$this->assertFalse( $r['success'] );
		$this->assertNull( $r['file_handle'] );
	}

	public function test_the_chunked_handle_sends_the_parts_now_and_leaves_the_commit(): void {
		$f = $this->tmp( 11 * 1048576 );
		$this->answer(
			fn( string $method ) => 'POST' === $method
				? self::reply( 200, '<InitiateMultipartUploadResult><UploadId>C1</UploadId></InitiateMultipartUploadResult>' )
				: self::reply( 200, '', array( 'ETag' => '"p"' ) )
		);

		$r = $this->provider->prepare_chunked_upload_handle( array( 'local_path' => $f, 'remote_path' => 'uploads/big.mov' ) );
		unlink( $f );

		$this->assertTrue( $r['success'], (string) ( $r['error'] ?? '' ) );
		$this->assertSame( array( 'POST', 'PUT', 'PUT', 'PUT' ), array_column( $this->requests(), 'method' ), 'create and three parts; the commit is the handle' );
		$this->assertSame( 'https://s3.example.com/media/uploads/big.mov?uploadId=C1', curl_getinfo( $r['handle'], CURLINFO_EFFECTIVE_URL ) );

		// If the sync's commit fails, the provider drops the parts it sent.
		$this->assertIsCallable( $r['on_failure'] );
		$this->answer( fn() => self::reply( 204 ) );
		( $r['on_failure'] )();
		$abort = array_slice( $this->requests(), -1 )[0];
		$this->assertSame( 'DELETE', $abort['method'] );
		$this->assertSame( 'https://s3.example.com/media/uploads/big.mov?uploadId=C1', $abort['url'] );
	}

	public function test_the_download_handle_writes_to_a_part_file(): void {
		$dest = sys_get_temp_dir() . '/s3h-' . uniqid() . '/a.jpg';
		$r    = $this->provider->prepare_download_handle( array( 'local_path' => $dest, 'remote_path' => 'uploads/a.jpg' ) );

		$this->assertTrue( $r['success'] );
		$this->assertSame( $dest . '.dlxpart', $r['part_path'] );
		fclose( $r['file_handle'] );
		unlink( $r['part_path'] );
		rmdir( dirname( $dest ) );
	}
}
