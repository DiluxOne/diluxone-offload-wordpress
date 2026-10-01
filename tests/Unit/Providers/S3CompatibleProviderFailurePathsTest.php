<?php
namespace Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\DTOs\ChunkedUpload;
use DiluxOneOffload\Providers\S3CompatibleProvider;

/**
 * The S3-compatible provider's failure paths the main suite does not reach.
 *
 * The rule under test is the one the architecture states for every provider
 * call: a non-2xx status, a transport error and a 200 with an error body all
 * clean up, so no multipart upload is left stored and billed. Every test
 * asserts the requests that reached the (scripted) service, the abort
 * included, besides what the call returned.
 */
class S3CompatibleProviderFailurePathsTest extends TestCase {

	private const MIB = 1048576;

	private S3CompatibleProvider $provider;

	/** @var string[] */
	private array $cleanup = array();

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_wp_options']    = array();
		$GLOBALS['_test_wp_transients'] = array();
		$GLOBALS['_test_wp_http_log']   = array();
		unset( $GLOBALS['_test_wp_http'], $GLOBALS['_test_multisite'] );
		$this->provider = new S3CompatibleProvider(
			array(
				'preset'            => 'custom',
				'endpoint'          => 'https://s3.example.com',
				'region'            => 'us-east-1',
				'bucket'            => 'media',
				'access_key_id'     => 'AKIDUNIT',
				'secret_access_key' => 'unit-secret',
				'public_url'        => 'https://cdn.example.com/files',
				'path_style'        => true,
			)
		);
	}

	protected function tearDown(): void {
		foreach ( array_reverse( $this->cleanup ) as $path ) {
			@chmod( $path, 0777 );
			if ( is_dir( $path ) ) {
				@rmdir( $path );
			} else {
				@unlink( $path );
			}
		}
		unset( $GLOBALS['_test_wp_http'], $GLOBALS['_test_wp_http_log'], $GLOBALS['_test_wp_transients'], $GLOBALS['_test_wp_options'], $GLOBALS['_test_multisite'] );
		parent::tearDown();
	}

	/** @param array<string,string> $headers */
	private static function reply( int $code, string $body = '', array $headers = array() ): array {
		return array( 'response' => array( 'code' => $code, 'message' => '' ), 'body' => $body, 'headers' => $headers );
	}

	private static function error( string $code, string $message ): string {
		return '<?xml version="1.0" encoding="UTF-8"?><Error><Code>' . $code . '</Code><Message>' . $message . '</Message></Error>';
	}

	private static function created( string $id ): array {
		return self::reply( 200, '<InitiateMultipartUploadResult><UploadId>' . $id . '</UploadId></InitiateMultipartUploadResult>' );
	}

	/** @return array<int, array{method:string,url:string,args:array}> */
	private function requests(): array {
		return $GLOBALS['_test_wp_http_log'] ?? array();
	}

	/** @return string[] "METHOD url" of each request, for asserting the whole conversation at once. */
	private function conversation(): array {
		return array_map( fn( $r ) => $r['method'] . ' ' . $r['url'], $this->requests() );
	}

	private function tmp( int $bytes ): string {
		$f = (string) tempnam( sys_get_temp_dir(), 's3f' );
		$h = fopen( $f, 'wb' );
		for ( $left = $bytes; $left > 0; $left -= self::MIB ) {
			fwrite( $h, str_repeat( 'x', min( self::MIB, $left ) ) );
		}
		fclose( $h );
		$this->cleanup[] = $f;
		return $f;
	}

	private function make_unreadable( string $f ): void {
		chmod( $f, 0000 );
		if ( is_readable( $f ) ) {
			$this->markTestSkipped( 'Running as root: a mode of 0000 does not stop reads.' );
		}
	}

	/**
	 * Runs $fn with PHP warnings silenced, as on a production site, where a
	 * failed fopen() warns and returns false instead of throwing.
	 *
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 */
	private static function quietly( callable $fn ) {
		set_error_handler( static fn() => true, E_WARNING );
		try {
			return $fn();
		} finally {
			restore_error_handler();
		}
	}

	private static function method( string $name ): \ReflectionMethod {
		$m = new \ReflectionMethod( S3CompatibleProvider::class, $name );
		if ( PHP_VERSION_ID < 80100 ) { // Required before 8.1, deprecated from 8.5.
			$m->setAccessible( true );
		}
		return $m;
	}

	// ── Failure lines and error codes ───────────────────────

	public function test_a_301_without_an_error_code_is_read_as_the_wrong_region(): void {
		$this->assertSame( 'HTTP 301: wrong region for this bucket', $this->provider->verify_upload_response( 301, '' ) );
	}

	/** @return array<string, array{string,string}> */
	public function errorMessages(): array {
		return array(
			'status'        => array( 'HTTP 403: the keys cannot write to this bucket on a listing page', '403' ),
			'timeout'       => array( 'Listing failed: Operation timed out after 60000 milliseconds', 'timeout' ),
			'curl'          => array( 'Listing failed: cURL error 7: Failed to connect', 'network' ),
			'anything else' => array( 'Invalid listing page', 'unknown' ),
		);
	}

	/** @dataProvider errorMessages */
	public function test_a_failure_message_is_classified_by_its_status_or_kind( string $message, string $code ): void {
		$this->assertSame( $code, self::method( 'extract_error_code' )->invoke( $this->provider, $message ) );
	}

	// ── Connection probe ────────────────────────────────────

	/** The probe is 32 bytes: one that cannot be deleted is logged, never a refused connection. */
	public function test_a_probe_that_cannot_be_deleted_still_proves_the_connection(): void {
		$written = '';
		$GLOBALS['_test_wp_http'] = function ( string $method, string $url, array $args ) use ( &$written ) {
			if ( 'PUT' === $method ) {
				$written = (string) $args['body'];
				return self::reply( 200 );
			}
			if ( 'GET' === $method ) {
				return self::reply( 200, $written );
			}
			return self::reply( 403, self::error( 'AccessDenied', 'Access Denied' ) );
		};

		$r = $this->provider->test_connection();

		$this->assertTrue( $r['success'], (string) $r['message'] );
		$this->assertSame( array( 'PUT', 'GET', 'DELETE' ), array_column( $this->requests(), 'method' ) );
	}

	// ── Single PUT ──────────────────────────────────────────

	public function test_a_small_file_that_cannot_be_read_is_refused_without_a_request(): void {
		$f = $this->tmp( 10 );
		$this->make_unreadable( $f );
		$r = self::quietly( fn() => $this->provider->upload_file( $f, 'uploads/a.jpg' ) );
		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'Could not read local file', $r['error'] );
		$this->assertSame( array(), $this->requests() );
	}

	// ── Multipart through the HTTP API ──────────────────────

	public function test_a_multipart_upload_the_service_refuses_to_start_sends_no_part_and_nothing_to_abort(): void {
		$f = $this->tmp( 6 * self::MIB );
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 403, self::error( 'AccessDenied', 'Access Denied' ) );

		$r = $this->provider->upload_file( $f, 'uploads/big.bin' );

		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'Upload failed to start: HTTP 403: the keys cannot write to this bucket', $r['error'] );
		$this->assertSame( array( 'POST https://s3.example.com/media/uploads/big.bin?uploads=' ), $this->conversation() );
	}

	public function test_a_multipart_start_lost_in_transport_is_sent_once(): void {
		$f = $this->tmp( 6 * self::MIB );
		$GLOBALS['_test_wp_http'] = fn() => new \WP_Error( 'http_request_failed', 'Connection reset by peer' );

		$r = $this->provider->upload_file( $f, 'uploads/big.bin' );

		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'Upload failed to start: Connection reset by peer', $r['error'] );
		$this->assertCount( 1, $this->requests(), 'a POST is never repeated: a start that worked would be left behind' );
	}

	public function test_a_large_file_that_cannot_be_opened_aborts_the_upload_it_started(): void {
		$f = $this->tmp( 6 * self::MIB );
		$this->make_unreadable( $f );
		$GLOBALS['_test_wp_http'] = fn( string $method ) => 'POST' === $method ? self::created( 'U9' ) : self::reply( 204 );

		$r = self::quietly( fn() => $this->provider->upload_file( $f, 'uploads/big.bin' ) );

		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'Could not open local file', $r['error'] );
		$this->assertSame(
			array(
				'POST https://s3.example.com/media/uploads/big.bin?uploads=',
				'DELETE https://s3.example.com/media/uploads/big.bin?uploadId=U9',
			),
			$this->conversation()
		);
	}

	public function test_a_part_lost_in_transport_aborts_the_upload(): void {
		$f = $this->tmp( 6 * self::MIB );
		$GLOBALS['_test_wp_http'] = function ( string $method ) {
			if ( 'POST' === $method ) {
				return self::created( 'U3' );
			}
			return 'PUT' === $method ? new \WP_Error( 'http_request_failed', 'Connection reset by peer' ) : self::reply( 204 );
		};

		$r = $this->provider->upload_file( $f, 'uploads/big.bin' );

		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'Upload failed on part 1: Connection reset by peer', $r['error'] );
		$this->assertSame( array( 'POST', 'PUT', 'PUT', 'PUT', 'DELETE' ), array_column( $this->requests(), 'method' ), 'three tries of the part, then the abort' );
		$this->assertStringEndsWith( '?uploadId=U3', $this->requests()[4]['url'] );
	}

	/** An abort the service refuses is logged; the upload's own error is what the caller sees. */
	public function test_an_abort_the_service_refuses_keeps_the_original_error(): void {
		$f = $this->tmp( 6 * self::MIB );
		$GLOBALS['_test_wp_http'] = function ( string $method ) {
			if ( 'POST' === $method ) {
				return self::created( 'U4' );
			}
			return 'PUT' === $method ? self::reply( 400, self::error( 'BadDigest', 'digest' ) ) : self::reply( 403, self::error( 'AccessDenied', 'no' ) );
		};

		$r = $this->provider->upload_file( $f, 'uploads/big.bin' );

		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'part 1: HTTP 400 - S3 BadDigest', $r['error'] );
		$this->assertStringNotContainsString( 'AccessDenied', $r['error'] );
		$this->assertSame( 'DELETE', array_slice( $this->requests(), -1 )[0]['method'] );
	}

	/**
	 * A file that got shorter while its parts were sent would be committed
	 * truncated. The byte count catches it: no commit, and the parts go.
	 */
	public function test_a_file_that_shrinks_while_its_parts_are_sent_is_aborted_not_committed(): void {
		$f = $this->tmp( 6 * self::MIB );
		$GLOBALS['_test_wp_http'] = function ( string $method ) use ( $f ) {
			if ( 'POST' === $method ) {
				return self::created( 'U5' );
			}
			if ( 'PUT' === $method ) {
				$h = fopen( $f, 'r+b' );
				ftruncate( $h, 5 * self::MIB + 10 );
				fclose( $h );
				return self::reply( 200, '', array( 'ETag' => '"e"' ) );
			}
			return self::reply( 204 );
		};

		$r = $this->provider->upload_file( $f, 'uploads/big.bin' );

		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'Read ' . ( 5 * self::MIB + 10 ) . ' of ' . ( 6 * self::MIB ) . ' bytes', $r['error'] );
		$this->assertSame( array( 'POST', 'PUT', 'PUT', 'DELETE' ), array_column( $this->requests(), 'method' ), 'no commit (a second POST)' );
	}

	public function test_a_commit_lost_in_transport_aborts_the_upload(): void {
		$f = $this->tmp( 6 * self::MIB );
		$GLOBALS['_test_wp_http'] = function ( string $method, string $url ) {
			if ( 'POST' === $method ) {
				return false !== strpos( $url, '?uploads=' ) ? self::created( 'U6' ) : new \WP_Error( 'http_request_failed', 'Connection reset by peer' );
			}
			return 'PUT' === $method ? self::reply( 200, '', array( 'ETag' => '"e"' ) ) : self::reply( 204 );
		};

		$r = $this->provider->upload_file( $f, 'uploads/big.bin' );

		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'Upload failed on commit: Connection reset by peer', $r['error'] );
		$this->assertSame( array( 'POST', 'PUT', 'PUT', 'POST', 'DELETE' ), array_column( $this->requests(), 'method' ) );
		$this->assertStringEndsWith( '?uploadId=U6', $this->requests()[4]['url'] );
	}

	// ── HEAD and copy ───────────────────────────────────────

	public function test_file_exists_is_false_on_a_transport_error_and_on_an_unexpected_status(): void {
		$GLOBALS['_test_wp_http'] = fn() => new \WP_Error( 'http_request_failed', 'down' );
		$this->assertFalse( $this->provider->file_exists( 'uploads/a.jpg' ) );
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 403 );
		$this->assertFalse( $this->provider->file_exists( 'uploads/a.jpg' ) );
		$this->assertSame( 'HEAD', array_slice( $this->requests(), -1 )[0]['method'] );
	}

	public function test_a_copy_lost_in_transport_is_a_failure(): void {
		$GLOBALS['_test_wp_http'] = fn() => new \WP_Error( 'http_request_failed', 'down' );
		$r = $this->provider->copy_blob( 'uploads/a.jpg', 'uploads/b.jpg' );
		$this->assertFalse( $r['success'] );
		$this->assertSame( 'Copy failed: down', $r['error'] );
	}

	// ── Listing one page ────────────────────────────────────

	public function test_a_listing_page_lost_in_transport_throws(): void {
		$GLOBALS['_test_wp_http'] = fn() => new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
		$this->expectExceptionMessage( 'Listing failed: cURL error 7' );
		$this->provider->list_page( 'uploads/' );
	}

	public function test_a_listing_page_that_is_not_a_bucket_listing_throws(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 200, '<html><body>Captive portal</body></html>' );
		$this->expectExceptionMessage( 'Invalid listing page' );
		$this->provider->list_page( 'uploads/' );
	}

	public function test_a_listing_that_meets_a_dropped_connection_is_not_retried_on_top_of_the_transport(): void {
		$GLOBALS['_test_wp_http'] = fn() => new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
		try {
			$this->provider->list_files( 'uploads/' );
			$this->fail( 'expected an exception' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( 'after 3 attempts', $e->getMessage() );
		}
		$this->assertCount( 3, $this->requests(), 'three tries in the transport, not three times three' );
	}

	// ── Taking up a chunked upload ──────────────────────────

	public function test_parts_that_cannot_be_listed_start_nothing_new(): void {
		$f = $this->tmp( 11 * self::MIB );
		$GLOBALS['_test_wp_http'] = fn() => new \WP_Error( 'http_request_failed', 'Connection reset by peer' );

		$r = $this->provider->begin_chunked_upload( array( 'local_path' => $f, 'remote_path' => 'uploads/big.mov' ), 'U1' );

		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'Could not ask for the unfinished upload of uploads/big.mov: Connection reset by peer; it is taken up next time', $r['error'] );
		$this->assertNotContains( 'POST', array_column( $this->requests(), 'method' ) );
	}

	/** ListParts is followed for 20 pages at most (10,000 parts), whatever the service claims. */
	public function test_a_parts_listing_that_never_ends_stops_after_twenty_pages(): void {
		$f    = $this->tmp( 11 * self::MIB );
		$page = 0;
		$GLOBALS['_test_wp_http'] = function () use ( &$page ) {
			++$page;
			return self::reply( 200, '<ListPartsResult><Part><PartNumber>1</PartNumber><ETag>"e1"</ETag><Size>5242880</Size></Part><IsTruncated>true</IsTruncated><NextPartNumberMarker>' . $page . '</NextPartNumberMarker></ListPartsResult>' );
		};

		$r = $this->provider->begin_chunked_upload( array( 'local_path' => $f, 'remote_path' => 'uploads/big.mov' ), 'U1' );

		$this->assertTrue( $r['success'] );
		$this->assertCount( 20, $this->requests() );
		$this->assertSame( '"e1"', $r['upload']->tag( 1 ) );
		$this->assertSame( array( 2, 3 ), $r['upload']->missingParts() );
	}

	public function test_an_unfinished_uploads_listing_that_cannot_be_read_starts_nothing_new(): void {
		$f = $this->tmp( 11 * self::MIB );
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 200, 'not xml at all' );

		$r = $this->provider->begin_chunked_upload( array( 'local_path' => $f, 'remote_path' => 'uploads/big.mov' ), '#' . sha1( 'X' ) );

		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'taken up next time', $r['error'] );
		$this->assertSame( array( 'GET' ), array_column( $this->requests(), 'method' ) );
	}

	/** The unfinished uploads are paged with both markers until the one with that SHA-1 turns up. */
	public function test_an_upload_kept_as_its_sha1_is_found_on_a_later_page(): void {
		$f     = $this->tmp( 11 * self::MIB );
		$pages = array(
			'<ListMultipartUploadsResult><Upload><Key>uploads/big.mov</Key><UploadId>OLDER</UploadId></Upload><IsTruncated>true</IsTruncated><NextKeyMarker>uploads/big.mov</NextKeyMarker><NextUploadIdMarker>OLD ER</NextUploadIdMarker></ListMultipartUploadsResult>',
			'<ListMultipartUploadsResult><Upload><Key>uploads/big.mov</Key><UploadId>MINE</UploadId></Upload><IsTruncated>false</IsTruncated></ListMultipartUploadsResult>',
		);
		$GLOBALS['_test_wp_http'] = function ( string $method, string $url ) use ( &$pages ) {
			return false !== strpos( $url, '?uploads=' )
				? self::reply( 200, (string) array_shift( $pages ) )
				: self::reply( 200, '<ListPartsResult><IsTruncated>false</IsTruncated></ListPartsResult>' );
		};

		$r = $this->provider->begin_chunked_upload( array( 'local_path' => $f, 'remote_path' => 'uploads/big.mov' ), '#' . sha1( 'MINE' ) );

		$this->assertSame( 'MINE', $r['upload']->uploadId() );
		$this->assertSame( 'https://s3.example.com/media/?uploads=&prefix=uploads%2Fbig.mov&key-marker=uploads%2Fbig.mov&upload-id-marker=OLD%20ER', $this->requests()[1]['url'] );
		$this->assertStringEndsWith( '/uploads/big.mov?uploadId=MINE', $this->requests()[2]['url'] );
	}

	/** No unfinished upload has that SHA-1 any more: a new one starts, and an abort sends nothing. */
	public function test_an_upload_kept_as_its_sha1_that_is_gone_starts_over_and_aborts_nothing(): void {
		$f = $this->tmp( 11 * self::MIB );
		$GLOBALS['_test_wp_http'] = fn( string $method ) => 'GET' === $method
			? self::reply( 200, '<ListMultipartUploadsResult><Upload><Key>uploads/big.mov</Key><UploadId>SOMEONE-ELSE</UploadId></Upload><IsTruncated>false</IsTruncated></ListMultipartUploadsResult>' )
			: self::created( 'NEW' );

		$r = $this->provider->begin_chunked_upload( array( 'local_path' => $f, 'remote_path' => 'uploads/big.mov' ), '#' . sha1( 'GONE' ) );

		$this->assertSame( array( 'GET', 'POST' ), array_column( $this->requests(), 'method' ) );
		$this->assertSame( 'NEW', $r['upload']->uploadId() );

		$GLOBALS['_test_wp_http_log'] = array();
		$this->provider->abort_chunked_upload( new ChunkedUpload( $f, 'uploads/big.mov', 11 * self::MIB, 5 * self::MIB, '#' . sha1( 'GONE' ) ) );
		$this->assertSame( array( 'GET' ), array_column( $this->requests(), 'method' ), 'nothing to abort' );
	}

	/** A listing of unfinished uploads that never ends is followed for 20 pages at most. */
	public function test_an_unfinished_uploads_listing_that_never_ends_stops_after_twenty_pages(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 200, '<ListMultipartUploadsResult><IsTruncated>true</IsTruncated><NextKeyMarker>k</NextKeyMarker><NextUploadIdMarker>u</NextUploadIdMarker></ListMultipartUploadsResult>' );
		$this->provider->abort_chunked_upload( new ChunkedUpload( '/f', 'uploads/big.mov', 1, 1, '#' . sha1( 'X' ) ) );
		$this->assertCount( 20, $this->requests() );
		$this->assertNotContains( 'DELETE', array_column( $this->requests(), 'method' ) );
	}

	// ── Part, batch and download handles when the disk says no ─

	public function test_a_part_beyond_what_the_file_now_holds_gets_no_handle(): void {
		$f = $this->tmp( 11 * self::MIB );
		$GLOBALS['_test_wp_http'] = fn() => self::created( 'C1' );
		$upload = $this->provider->begin_chunked_upload( array( 'local_path' => $f, 'remote_path' => 'uploads/big.mov' ) )['upload'];
		$h = fopen( $f, 'r+b' );
		ftruncate( $h, 10 * self::MIB + 5 );
		fclose( $h );

		$r = $this->provider->prepare_part_handle( $upload, 3 );

		$this->assertFalse( $r['success'] );
		$this->assertSame( 'Could not read part 3 of: ' . $f, $r['error'] );
		$this->assertTrue( $this->provider->prepare_part_handle( $upload, 2 )['success'], 'a part the file still holds whole is fine' );
	}

	public function test_a_part_of_a_file_that_cannot_be_opened_gets_no_handle(): void {
		$f = $this->tmp( 11 * self::MIB );
		$GLOBALS['_test_wp_http'] = fn() => self::created( 'C1' );
		$upload = $this->provider->begin_chunked_upload( array( 'local_path' => $f, 'remote_path' => 'uploads/big.mov' ) )['upload'];
		$this->make_unreadable( $f );

		$r = self::quietly( fn() => $this->provider->prepare_part_handle( $upload, 1 ) );

		$this->assertFalse( $r['success'] );
		$this->assertSame( 'Could not read part 1 of: ' . $f, $r['error'] );
	}

	public function test_a_batch_handle_for_a_file_that_cannot_be_read_gets_no_handle(): void {
		$f = $this->tmp( 10 );
		$this->make_unreadable( $f );
		$r = self::quietly( fn() => $this->provider->prepare_batch_upload_handle( array( 'local_path' => $f, 'remote_path' => 'uploads/a.jpg' ) ) );
		$this->assertFalse( $r['success'] );
		$this->assertSame( 'File not found: ' . $f, $r['error'] );
	}

	public function test_a_download_handle_where_nothing_can_be_written_gets_no_handle(): void {
		$dir = sys_get_temp_dir() . '/s3f-ro-' . uniqid();
		mkdir( $dir );
		chmod( $dir, 0555 );
		$this->cleanup[] = $dir;
		if ( is_writable( $dir ) ) {
			$this->markTestSkipped( 'Running as root: a mode of 0555 does not stop writes.' );
		}

		$r = self::quietly( fn() => $this->provider->prepare_download_handle( array( 'local_path' => $dir . '/a.jpg', 'remote_path' => 'uploads/a.jpg' ) ) );

		$this->assertFalse( $r['success'] );
		$this->assertSame( 'Failed to open local file for writing', $r['error'] );
		$this->assertFileDoesNotExist( $dir . '/a.jpg.dlxpart' );
	}

	/**
	 * A file that got shorter after its part handle was built ends the body
	 * early: cURL fails the part rather than sending fewer bytes than it
	 * announced (or the next part's), and the part is not tagged. Run
	 * against the local server, which would tag whatever arrived.
	 */
	public function test_a_part_whose_file_shrinks_before_it_is_sent_fails_and_is_not_tagged(): void {
		$server = new \Tests\Integration\LocalBlobServer( 8779 );
		try {
			$f = $this->tmp( 5 * self::MIB );
			file_put_contents( $f, 'the second part', FILE_APPEND );
			$GLOBALS['_test_wp_http'] = fn() => self::created( 'U 7' );
			$p = new S3CompatibleProvider(
				array(
					'preset'            => 'custom',
					'endpoint'          => $server->base_url,
					'region'            => 'us-east-1',
					'bucket'            => 'status-200',
					'access_key_id'     => 'AKIDUNIT',
					'secret_access_key' => 'unit-secret',
					'public_url'        => 'https://cdn.example.com/files',
					'path_style'        => true,
				)
			);
			$upload = $p->begin_chunked_upload( array( 'local_path' => $f, 'remote_path' => 'uploads/big.mov' ) )['upload'];
			$r      = $p->prepare_part_handle( $upload, 2 );
			$this->assertTrue( $r['success'] );

			$h = fopen( $f, 'r+b' );
			ftruncate( $h, 5 * self::MIB + 5 );
			fclose( $h );
			$body   = (string) curl_exec( $r['handle'] );
			$errno  = curl_errno( $r['handle'] );
			$status = (int) curl_getinfo( $r['handle'], CURLINFO_HTTP_CODE );
			fclose( $r['file_handle'] );

			$this->assertNotSame( 0, $errno, 'cURL reports the short body' );
			$this->assertSame( '', $upload->tag( 2 ) );
			$this->assertNotNull( $p->finish_part( $upload, 2, $status, $body ) );
		} finally {
			$server->stop();
		}
	}
}
