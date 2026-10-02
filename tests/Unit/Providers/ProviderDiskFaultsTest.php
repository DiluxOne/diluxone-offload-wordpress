<?php
namespace Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\DTOs\ChunkedUpload;
use DiluxOneOffload\Providers\AzureProvider;
use DiluxOneOffload\Providers\PartUpload;
use DiluxOneOffload\Providers\S3CompatibleProvider;
use Tests\Unit\Support\FaultyStream;

/**
 * What the providers do when the local disk fails under them: a read error
 * in the middle of a file, a file gone between the check and the read, a
 * file that ends before the size it reported, a handle that cannot seek.
 *
 * Every test asserts both the answer and what reached the (scripted)
 * service: a read that failed must never become a committed object, and a
 * multipart upload that was started must be aborted, or its parts stay
 * stored and billed.
 */
class ProviderDiskFaultsTest extends TestCase {

	private const MIB = 1048576;

	/** @var string[] */
	private array $cleanup = array();

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_wp_options']    = array();
		$GLOBALS['_test_wp_transients'] = array();
		$GLOBALS['_test_wp_http_log']   = array();
		unset( $GLOBALS['_test_wp_http'] );
		FaultyStream::register();
	}

	protected function tearDown(): void {
		FaultyStream::unregister();
		foreach ( $this->cleanup as $path ) {
			@unlink( $path );
		}
		unset( $GLOBALS['_test_wp_http'], $GLOBALS['_test_wp_http_log'], $GLOBALS['_test_wp_transients'], $GLOBALS['_test_wp_options'] );
		parent::tearDown();
	}

	private function azure(): AzureProvider {
		return new AzureProvider( array( 'storage_account' => 'facct', 'container_name' => 'media', 'access_key' => base64_encode( random_bytes( 32 ) ) ) );
	}

	private function s3(): S3CompatibleProvider {
		return new S3CompatibleProvider(
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

	/** @param array<string,string> $headers */
	private static function reply( int $code, string $body = '', array $headers = array() ): array {
		return array( 'response' => array( 'code' => $code, 'message' => '' ), 'body' => $body, 'headers' => $headers );
	}

	/** @return string[] "METHOD url" of each request. */
	private function conversation(): array {
		return array_map( fn( $r ) => $r['method'] . ' ' . $r['url'], $GLOBALS['_test_wp_http_log'] ?? array() );
	}

	/**
	 * Runs $fn with PHP warnings silenced, as on a production site, where a
	 * failed stat or fopen() warns and returns false instead of throwing.
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

	private function local_target(): string {
		$f               = (string) tempnam( sys_get_temp_dir(), 'dlxpart' );
		$this->cleanup[] = $f;
		return $f;
	}

	// ── Azure ───────────────────────────────────────────────

	public function test_azure_a_file_gone_between_the_check_and_the_size_is_refused_without_a_request(): void {
		$path = FaultyStream::file( 'gone.jpg', array( 'content' => 'abc', 'stats_ok' => 1 ) );

		$r = self::quietly( fn() => $this->azure()->upload_file( $path, 'uploads/gone.jpg' ) );

		$this->assertFalse( $r['success'] );
		$this->assertSame( 'Could not read local file: ' . $path, $r['error'] );
		$this->assertSame( array(), $this->conversation(), 'nothing is sent for a file that cannot be measured' );
	}

	public function test_azure_a_read_error_on_the_first_block_fails_before_any_block_is_sent(): void {
		$path = FaultyStream::file( 'bad.mov', array( 'content' => 'abc', 'size' => 5 * self::MIB, 'reads_ok' => 0 ) );

		$r = $this->azure()->upload_file( $path, 'uploads/bad.mov' );

		$this->assertFalse( $r['success'] );
		$this->assertSame( 'Could not read block 0 of: ' . $path, $r['error'] );
		$this->assertSame( array(), $this->conversation(), 'no block, and above all no block list, is sent after a failed read' );
	}

	public function test_azure_a_file_that_turns_out_empty_commits_no_empty_blob(): void {
		$path = FaultyStream::file( 'emptied.mov', array( 'content' => '', 'size' => 5 * self::MIB ) );

		$r = $this->azure()->upload_file( $path, 'uploads/emptied.mov' );

		$this->assertFalse( $r['success'] );
		$this->assertSame( 'Could not read local file: ' . $path, $r['error'] );
		$this->assertSame( array(), $this->conversation(), 'an empty block list is never committed' );
	}

	// ── S3 ──────────────────────────────────────────────────

	public function test_s3_a_read_error_on_a_part_aborts_the_upload_it_started(): void {
		$path = FaultyStream::file( 'bad.mov', array( 'content' => 'abc', 'size' => 6 * self::MIB, 'reads_ok' => 0 ) );
		$GLOBALS['_test_wp_http'] = static function ( string $method ) {
			return 'POST' === $method
				? self::reply( 200, '<InitiateMultipartUploadResult><UploadId>U1</UploadId></InitiateMultipartUploadResult>' )
				: self::reply( 204 );
		};

		$r = $this->s3()->upload_file( $path, 'uploads/bad.mov' );

		$this->assertFalse( $r['success'] );
		$this->assertSame( 'Could not read part 1 of: ' . $path, $r['error'] );
		$this->assertSame(
			array(
				'POST https://s3.example.com/media/uploads/bad.mov?uploads=',
				'DELETE https://s3.example.com/media/uploads/bad.mov?uploadId=U1',
			),
			$this->conversation(),
			'the upload is started, no part is sent, and it is aborted'
		);
	}

	public function test_s3_a_file_that_ends_before_its_size_is_aborted_not_committed(): void {
		$path = FaultyStream::file( 'short.mov', array( 'content' => '', 'size' => 6 * self::MIB, 'never_eof' => true ) );
		$GLOBALS['_test_wp_http'] = static function ( string $method ) {
			return 'POST' === $method
				? self::reply( 200, '<InitiateMultipartUploadResult><UploadId>U2</UploadId></InitiateMultipartUploadResult>' )
				: self::reply( 204 );
		};

		$r = $this->s3()->upload_file( $path, 'uploads/short.mov' );

		$this->assertFalse( $r['success'] );
		$this->assertSame( 'Read 0 of ' . ( 6 * self::MIB ) . ' bytes from: ' . $path, $r['error'] );
		$this->assertSame(
			array(
				'POST https://s3.example.com/media/uploads/short.mov?uploads=',
				'DELETE https://s3.example.com/media/uploads/short.mov?uploadId=U2',
			),
			$this->conversation(),
			'no commit for parts that do not add up to the file'
		);
	}

	public function test_s3_a_batch_handle_for_a_file_that_cannot_be_opened_after_hashing_gets_no_handle(): void {
		// The hash is read on the first open; the second, the body's, fails.
		$path = FaultyStream::file( 'flaky.jpg', array( 'content' => 'abc', 'opens_ok' => 1 ) );

		$r = self::quietly( fn() => $this->s3()->prepare_batch_upload_handle( array( 'local_path' => $path, 'remote_path' => 'uploads/flaky.jpg' ) ) );

		$this->assertFalse( $r['success'] );
		$this->assertSame( 'Failed to open file for reading', $r['error'] );
		$this->assertNull( $r['handle'] ?? null );
	}

	public function test_s3_a_part_whose_file_cannot_be_opened_for_its_body_gets_no_handle(): void {
		// The checksum is read on the first open; the body's open fails.
		$path   = FaultyStream::file( 'flaky.mov', array( 'content' => 'AAAAABBBBB', 'opens_ok' => 1 ) );
		$upload = new ChunkedUpload( $path, 'uploads/flaky.mov', 10, 5, 'U3' );

		$r = self::quietly( fn() => $this->s3()->prepare_part_handle( $upload, 2 ) );

		$this->assertFalse( $r['success'] );
		$this->assertSame( 'Could not read part 2 of: ' . $path, $r['error'] );
		$this->assertSame( 2, FaultyStream::$calls['flaky.mov']['opens'], 'the checksum was read, the body could not be' );
		$this->assertSame( array(), $this->conversation() );
	}

	public function test_s3_a_listing_body_that_is_not_a_listing_is_asked_again_once_and_then_read(): void {
		$answers = array(
			self::reply( 200, '<html><body>Captive portal</body></html>' ),
			self::reply( 200, '<ListBucketResult><IsTruncated>false</IsTruncated><Contents><Key>uploads/a.jpg</Key><Size>3</Size><ETag>"x"</ETag><LastModified>2026-01-01T00:00:00.000Z</LastModified></Contents></ListBucketResult>' ),
		);
		$GLOBALS['_test_wp_http'] = static function () use ( &$answers ) {
			return array_shift( $answers );
		};

		$files = $this->s3()->list_files( 'uploads/' );

		$this->assertSame( array( 'uploads/a.jpg' ), array_column( $files, 'path' ), 'the second answer is the listing' );
		$this->assertCount( 2, $this->conversation(), 'one more request after the bad body, no more' );
	}

	// ── The part body, as cURL reads it ─────────────────────

	public function test_a_part_body_never_reads_into_the_next_part_even_when_curl_asks_for_more(): void {
		$src = (string) tempnam( sys_get_temp_dir(), 'dlxsrc' );
		file_put_contents( $src, 'AAAAABBBBB' );
		$this->cleanup[] = $src;
		$dst             = $this->local_target();
		$upload          = new ChunkedUpload( $src, 'uploads/x.bin', 10, 5, 'U4' );

		$ch = curl_init( 'file://' . $dst );
		$fh = PartUploadHarness::part( $ch, $upload, 1 );
		$this->assertIsResource( $fh );
		// An unknown length makes cURL read until the body says it is over.
		curl_setopt( $ch, CURLOPT_INFILESIZE, -1 );
		curl_exec( $ch );
		fclose( $fh );

		$this->assertSame( 'AAAAA', file_get_contents( $dst ), 'part 1 is its five bytes and none of part 2' );
	}

	public function test_a_part_body_ends_at_a_read_error_instead_of_sending_garbage(): void {
		$src    = FaultyStream::file( 'readfail.bin', array( 'content' => 'AAAAABBBBB', 'reads_ok' => 0 ) );
		$dst    = $this->local_target();
		$upload = new ChunkedUpload( $src, 'uploads/x.bin', 10, 5, 'U5' );

		$ch = curl_init( 'file://' . $dst );
		$fh = PartUploadHarness::part( $ch, $upload, 1 );
		$this->assertIsResource( $fh );
		curl_setopt( $ch, CURLOPT_INFILESIZE, -1 );
		curl_exec( $ch );
		fclose( $fh );

		$this->assertSame( '', file_get_contents( $dst ), 'nothing is sent from a read that failed' );
	}

	public function test_a_part_whose_file_cannot_seek_to_its_offset_gets_no_body(): void {
		$src    = FaultyStream::file( 'noseek.bin', array( 'content' => 'AAAAABBBBB', 'seek_fails' => true ) );
		$upload = new ChunkedUpload( $src, 'uploads/x.bin', 10, 5, 'U6' );

		$this->assertNull( PartUploadHarness::part( curl_init(), $upload, 2 ) );
	}
}

/** The trait under test, on its own. */
final class PartUploadHarness {
	use PartUpload;

	/**
	 * @param resource|\CurlHandle $ch
	 * @return resource|null
	 */
	public static function part( $ch, ChunkedUpload $upload, int $part ) {
		return self::stream_part( $ch, $upload, $part );
	}
}
