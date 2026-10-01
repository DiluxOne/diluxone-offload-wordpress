<?php
namespace Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\Providers\AzureProvider;

/**
 * The Azure provider's failure paths the other suites do not reach: a block
 * upload that fails on a block or on its commit, a file that changes size
 * while it is sent, a local file that cannot be opened, a listing page read
 * on its own, and the handle builders when the disk says no.
 *
 * Every test asserts what reached the (scripted) service: a failed block
 * upload must never send the commit, because the commit is what makes a
 * truncated blob visible.
 */
class AzureProviderFailurePathsTest extends TestCase {

	private const MIB = 1048576;

	/** @var string[] */
	private array $cleanup = array();

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_wp_options']    = array();
		$GLOBALS['_test_wp_transients'] = array();
		$GLOBALS['_test_wp_http_log']   = array();
		unset( $GLOBALS['_test_wp_http'] );
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
		unset( $GLOBALS['_test_wp_http'], $GLOBALS['_test_wp_http_log'], $GLOBALS['_test_wp_transients'], $GLOBALS['_test_wp_options'] );
		parent::tearDown();
	}

	private function azure(): AzureProvider {
		return new AzureProvider( array( 'storage_account' => 'facct', 'container_name' => 'media', 'access_key' => base64_encode( random_bytes( 32 ) ) ) );
	}

	private static function reply( int $code, string $body = '' ): array {
		return array( 'response' => array( 'code' => $code, 'message' => '' ), 'body' => $body, 'headers' => array() );
	}

	/** @return array<int, array{method:string,url:string,args:array}> */
	private function requests(): array {
		return $GLOBALS['_test_wp_http_log'] ?? array();
	}

	private function tmp( int $bytes ): string {
		$f = (string) tempnam( sys_get_temp_dir(), 'azf' );
		$h = fopen( $f, 'wb' );
		for ( $left = $bytes; $left > 0; $left -= self::MIB ) {
			fwrite( $h, str_repeat( 'x', min( self::MIB, $left ) ) );
		}
		fclose( $h );
		$this->cleanup[] = $f;
		return $f;
	}

	/** A file that exists, has a size, and cannot be opened: what a wrong owner on uploads/ looks like. */
	private function unreadable( int $bytes ): string {
		$f = $this->tmp( $bytes );
		chmod( $f, 0000 );
		if ( is_readable( $f ) ) {
			$this->markTestSkipped( 'Running as root: a mode of 0000 does not stop reads.' );
		}
		return $f;
	}

	/** A directory nothing can be created in. */
	private function unwritable_dir(): string {
		$d = sys_get_temp_dir() . '/azf-ro-' . uniqid();
		mkdir( $d );
		chmod( $d, 0555 );
		$this->cleanup[] = $d;
		if ( is_writable( $d ) ) {
			$this->markTestSkipped( 'Running as root: a mode of 0555 does not stop writes.' );
		}
		return $d;
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

	private static function is_block( array $request ): bool {
		return false !== strpos( $request['url'], 'comp=block&' );
	}

	private static function is_commit( array $request ): bool {
		return false !== strpos( $request['url'], 'comp=blocklist' );
	}

	// ── Single PUT and block upload: local file problems ────

	public function test_a_small_file_that_cannot_be_read_is_refused_without_a_request(): void {
		$f = $this->unreadable( 10 );
		$r = self::quietly( fn() => $this->azure()->upload_file( $f, 'uploads/a.jpg' ) );
		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'Could not read local file', $r['error'] );
		$this->assertSame( array(), $this->requests() );
	}

	public function test_a_large_file_that_cannot_be_opened_is_refused_without_a_request(): void {
		$f = $this->unreadable( 4 * self::MIB + 1 );
		$r = self::quietly( fn() => $this->azure()->upload_file( $f, 'uploads/big.bin' ) );
		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'Could not open local file', $r['error'] );
		$this->assertSame( array(), $this->requests() );
	}

	// ── Block upload: the service says no ───────────────────

	public function test_a_transport_error_on_a_block_fails_the_upload_and_never_commits(): void {
		$f = $this->tmp( 4 * self::MIB + 10 );
		$GLOBALS['_test_wp_http'] = fn() => new \WP_Error( 'http_request_failed', 'Connection reset by peer' );

		$r = $this->azure()->upload_file( $f, 'uploads/big.bin' );

		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'Upload failed on block 0: Connection reset by peer', $r['error'] );
		$this->assertCount( 3, $this->requests(), 'the first block is tried three times' );
		$this->assertSame( array(), array_filter( $this->requests(), array( self::class, 'is_commit' ) ), 'no commit after a failed block' );
	}

	public function test_a_refused_second_block_fails_the_upload_and_never_commits(): void {
		$f     = $this->tmp( 4 * self::MIB + 10 );
		$block = 0;
		$GLOBALS['_test_wp_http'] = function () use ( &$block ) {
			return self::reply( 0 === $block++ ? 201 : 403, '<Error><Code>AuthenticationFailed</Code></Error>' );
		};

		$r = $this->azure()->upload_file( $f, 'uploads/big.bin' );

		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'Upload failed on block 1 with status: 403', $r['error'] );
		$this->assertSame( array( 'PUT', 'PUT' ), array_column( $this->requests(), 'method' ) );
		$this->assertSame( array(), array_filter( $this->requests(), array( self::class, 'is_commit' ) ) );
	}

	public function test_a_transport_error_on_the_commit_is_a_failure(): void {
		$f = $this->tmp( 4 * self::MIB + 10 );
		$GLOBALS['_test_wp_http'] = fn( string $method, string $url ) => false !== strpos( $url, 'comp=blocklist' )
			? new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' )
			: self::reply( 201 );

		$r = $this->azure()->upload_file( $f, 'uploads/big.bin' );

		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'Upload failed on commit: cURL error 28', $r['error'] );
		$this->assertCount( 2, array_filter( $this->requests(), array( self::class, 'is_block' ) ) );
		$this->assertCount( 1, array_filter( $this->requests(), array( self::class, 'is_commit' ) ), 'a timeout is not retried' );
	}

	public function test_a_refused_commit_is_a_failure_that_names_the_status(): void {
		$f = $this->tmp( 4 * self::MIB + 10 );
		$GLOBALS['_test_wp_http'] = fn( string $method, string $url ) => false !== strpos( $url, 'comp=blocklist' )
			? self::reply( 400, '<Error><Code>InvalidBlockList</Code></Error>' )
			: self::reply( 201 );

		$r = $this->azure()->upload_file( $f, 'uploads/big.bin' );

		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'Upload failed on commit with status: 400', $r['error'] );
	}

	/**
	 * A file that got shorter between the size check and the last block (an
	 * editor rewriting it) would commit a truncated blob as a success. The
	 * byte count check catches it, and the commit is never sent.
	 */
	public function test_a_file_that_shrinks_while_its_blocks_are_sent_is_never_committed(): void {
		$f = $this->tmp( 4 * self::MIB + 100 );
		$GLOBALS['_test_wp_http'] = function () use ( $f ) {
			$h = fopen( $f, 'r+b' );
			ftruncate( $h, 4 * self::MIB + 10 );
			fclose( $h );
			return self::reply( 201 );
		};

		$r = $this->azure()->upload_file( $f, 'uploads/big.bin' );

		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'Read ' . ( 4 * self::MIB + 10 ) . ' of ' . ( 4 * self::MIB + 100 ) . ' bytes', $r['error'] );
		$this->assertSame( array(), array_filter( $this->requests(), array( self::class, 'is_commit' ) ) );
	}

	// ── Listing one page ────────────────────────────────────

	public function test_a_listing_page_is_read_on_its_own_with_its_marker_and_the_next_one(): void {
		$xml = '<?xml version="1.0"?><EnumerationResults><Blobs>'
			. '<Blob><Name>uploads/a.jpg</Name><Properties><Content-Length>12</Content-Length><Content-MD5>bWQ1</Content-MD5><Last-Modified>Mon, 01 Jan 2026 00:00:00 GMT</Last-Modified></Properties></Blob>'
			. '</Blobs><NextMarker>page-3</NextMarker></EnumerationResults>';
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 200, $xml );

		$page = $this->azure()->list_page( 'uploads/', 'page-2' );

		$this->assertSame( 'page-3', $page['next'] );
		$this->assertCount( 1, $page['files'] );
		$this->assertIsArray( $page['files'][0], 'a page hands arrays, not DTOs' );
		$this->assertSame( 'uploads/a.jpg', $page['files'][0]['path'] );
		$this->assertSame( 12, (int) $page['files'][0]['size'] );
		$this->assertCount( 1, $this->requests() );
		$this->assertStringContainsString( 'marker=page-2', $this->requests()[0]['url'] );
		$this->assertStringContainsString( 'prefix=uploads', $this->requests()[0]['url'] );
	}

	// ── Error classification ────────────────────────────────

	/** @return array<string, array{string,string}> */
	public function errorMessages(): array {
		return array(
			'status in the message' => array( 'Azure returned HTTP 403 on a listing page', '403' ),
			'server error'          => array( 'HTTP 503 Server Busy', '503' ),
			'timeout'               => array( 'Connection timeout while listing', 'timeout' ),
			'curl error'            => array( 'cURL error 6: Could not resolve host', 'network' ),
			'network'               => array( 'network unreachable', 'network' ),
			'anything else'         => array( 'Invalid XML in listing', 'unknown' ),
			'a size is not a code'  => array( 'listed 4030 blobs', 'unknown' ),
		);
	}

	/**
	 * The code decides whether the listing is retried and what the health
	 * screen records, so a status inside a word or a number must not count.
	 *
	 * @dataProvider errorMessages
	 */
	public function test_a_failure_message_is_classified_by_its_status_or_kind( string $message, string $code ): void {
		$m = new \ReflectionMethod( AzureProvider::class, 'extract_error_code' );
		if ( PHP_VERSION_ID < 80100 ) { // Required before 8.1, deprecated from 8.5.
			$m->setAccessible( true );
		}
		$this->assertSame( $code, $m->invoke( $this->azure(), $message ) );
	}

	// ── Taking up an upload ─────────────────────────────────

	public function test_a_block_list_with_no_uncommitted_blocks_starts_the_upload_over(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 200, '<?xml version="1.0"?><BlockList><CommittedBlocks /></BlockList>' );
		$f = $this->tmp( 5 * self::MIB );

		$upload = $this->azure()->begin_chunked_upload( array( 'local_path' => $f, 'remote_path' => 'uploads/big.bin' ), 'a1b2c3' )['upload'];

		$this->assertCount( 1, $this->requests() );
		$this->assertSame( 'a1b2c3', $upload->uploadId() );
		$this->assertSame( array( 1, 2 ), $upload->missingParts() );
	}

	// ── Handle builders when the disk says no ───────────────

	public function test_a_batch_handle_for_a_file_that_cannot_be_opened_is_a_clean_failure(): void {
		$f = $this->unreadable( 10 );
		$r = self::quietly( fn() => $this->azure()->prepare_batch_upload_handle( array( 'local_path' => $f, 'remote_path' => 'uploads/a.jpg' ) ) );
		$this->assertFalse( $r['success'] );
		$this->assertSame( 'Failed to open file for reading', $r['error'] );
		$this->assertNull( $r['file_handle'] );
	}

	public function test_a_block_handle_for_a_file_that_cannot_be_opened_is_a_clean_failure(): void {
		$f        = $this->tmp( 5 * self::MIB );
		$provider = $this->azure();
		$upload   = $provider->begin_chunked_upload( array( 'local_path' => $f, 'remote_path' => 'uploads/big.bin' ) )['upload'];
		chmod( $f, 0000 );
		if ( is_readable( $f ) ) {
			$this->markTestSkipped( 'Running as root: a mode of 0000 does not stop reads.' );
		}

		$r = self::quietly( fn() => $provider->prepare_part_handle( $upload, 2 ) );

		$this->assertFalse( $r['success'] );
		$this->assertSame( 'Failed to read block 2 of ' . $f, $r['error'] );
		$this->assertNull( $r['handle'] );
		$this->assertNull( $r['file_handle'] );
	}

	public function test_a_download_handle_where_nothing_can_be_written_is_a_clean_failure(): void {
		$dir = $this->unwritable_dir();
		$r   = self::quietly( fn() => $this->azure()->prepare_download_handle( array( 'remote_path' => 'uploads/a.jpg', 'local_path' => $dir . '/a.jpg' ) ) );
		$this->assertFalse( $r['success'] );
		$this->assertSame( 'Failed to open local file for writing', $r['error'] );
		$this->assertNull( $r['file_handle'] );
		$this->assertFileDoesNotExist( $dir . '/a.jpg.dlxpart' );
	}
}
