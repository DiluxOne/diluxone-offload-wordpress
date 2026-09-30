<?php
namespace Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use Tests\Integration\LocalBlobServer;
use DiluxOneOffload\Providers\AzureProvider;
use DiluxOneOffload\ConfigManager;

/**
 * What the Azure provider does when the cloud says no: transport errors,
 * 4xx/5xx answers, unparseable bodies, and the chunked upload path for large
 * files, whose block PUTs go through the WP HTTP API and whose final commit is
 * the one cURL handle SyncManager runs.
 */
class ProviderErrorPathsTest extends TestCase {

	private static ?LocalBlobServer $server = null;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$server = new LocalBlobServer( 8772 );
	}

	public static function tearDownAfterClass(): void {
		if ( self::$server ) {
			self::$server->stop();
			self::$server = null;
		}
		parent::tearDownAfterClass();
	}

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_wp_options']    = array(
			'diluxone_offload_config' => array( 'cloud_provider' => 'azure', 'provider_config' => array( 'storage_account' => 'eacct', 'container_name' => 'media', 'access_key' => 'k' ) ),
		);
		$GLOBALS['_test_wp_transients'] = array();
		$GLOBALS['_test_wp_http_log']   = array();
		unset( $GLOBALS['_test_wp_http'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_test_wp_http'], $GLOBALS['_test_wp_http_log'], $GLOBALS['_test_wp_transients'], $GLOBALS['_test_wp_options'] );
		parent::tearDown();
	}

	private static function raw( int $code, string $body = '', array $headers = array() ): array {
		return array( 'response' => array( 'code' => $code, 'message' => '' ), 'body' => $body, 'headers' => $headers );
	}

	private function azure(): AzureProvider {
		return new AzureProvider( array( 'storage_account' => 'eacct', 'container_name' => 'media', 'access_key' => base64_encode( random_bytes( 32 ) ) ) );
	}

	private function tmp( int $bytes ): string {
		$f = tempnam( sys_get_temp_dir(), 'pe' );
		file_put_contents( $f, str_repeat( 'x', $bytes ) );
		return $f;
	}

	// ── Chunked (large file) upload ─────────────────────────

	public function test_azure_chunked_upload_of_a_missing_file_fails_cleanly(): void {
		$r = $this->azure()->begin_chunked_upload( array( 'local_path' => '/nope/x', 'remote_path' => 'x', 'size' => 1 ) );
		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'File not found', $r['error'] );
	}

	// ── Azure ───────────────────────────────────────────────

	public function test_azure_connection_failures(): void {
		$p = $this->azure();
		$GLOBALS['_test_wp_http'] = fn() => new \WP_Error( 'x', 'offline' );
		$this->assertStringContainsString( 'offline', $p->test_connection()['message'] );
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 404, '<Error><Message>ContainerNotFound</Message></Error>' );
		$this->assertStringContainsString( 'ContainerNotFound', $p->test_connection()['message'] );
	}

	public function test_azure_upload_failures(): void {
		$p = $this->azure();
		$this->assertStringContainsString( 'not found', $p->upload_file( '/nope', 'x.jpg' )['error'] );
		$GLOBALS['_test_wp_http'] = fn() => new \WP_Error( 'x', 'reset' );
		$this->assertStringContainsString( 'reset', $p->upload_file( $this->tmp( 2 ), 'x.jpg' )['error'] );
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 500 );
		$this->assertStringContainsString( '500', $p->upload_file( $this->tmp( 2 ), 'x.jpg' )['error'] );
	}

	public function test_azure_download_outcomes(): void {
		$p    = $this->azure();
		$dest = sys_get_temp_dir() . '/dlx-az-' . uniqid() . '/d.bin';
		$GLOBALS['_test_wp_http'] = fn() => new \WP_Error( 'x', 'reset' );
		$this->assertStringContainsString( 'reset', $p->download_file( 'd.bin', $dest )['error'] );
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 404 );
		$this->assertStringContainsString( '404', $p->download_file( 'd.bin', $dest )['error'] );
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 200, 'payload' );
		$this->assertTrue( $p->download_file( 'd.bin', $dest )['success'] );
		$this->assertSame( 'payload', file_get_contents( $dest ) );
		unlink( $dest );
		rmdir( dirname( $dest ) );
	}

	public function test_azure_head_lookups_swallow_transport_errors(): void {
		$p = $this->azure();
		$GLOBALS['_test_wp_http'] = fn() => new \WP_Error( 'x', 'down' );
		$this->assertFalse( $p->file_exists( 'a.jpg' ) );
		$this->assertFalse( $p->get_file_checksum( 'a.jpg' ) );
		$this->assertFalse( $p->get_file_info( 'a.jpg' ) );
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 404 );
		$this->assertFalse( $p->get_file_checksum( 'a.jpg' ) );
	}

	public function test_azure_copy_reports_the_error_code_and_message_on_failure(): void {
		$p = $this->azure();
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 409, '<Error><Code>LeaseIdMissing</Code><Message>busy</Message><AuthenticationErrorDetail>SIGNATURE</AuthenticationErrorDetail></Error>' );
		$r = $p->copy_blob( 'a.jpg', 'b.jpg' );
		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'Azure LeaseIdMissing: busy', $r['error'] );
		$this->assertStringNotContainsString( 'SIGNATURE', $r['error'], 'the rest of the body never reaches a log line' );
	}

	public function test_azure_list_gives_up_on_a_non_retryable_error_and_records_it(): void {
		$p = $this->azure();
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 403, '<Error><Message>AuthenticationFailed</Message></Error>' );
		try {
			$p->list_files( 'uploads/' );
			$this->fail( 'expected an exception' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( '403', $e->getMessage() );
		}
		$this->assertCount( 1, $GLOBALS['_test_wp_http_log'], 'no retries on 403' );
		$this->assertSame( '403', ConfigManager::get_connection_health()['error_code'] );
	}

	public function test_azure_list_rejects_an_empty_body_and_garbage_xml_after_retries(): void {
		$p = $this->azure();
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 200, '' );
		try {
			$p->list_files( 'uploads/' );
			$this->fail( 'expected an exception' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( 'after 3 attempts', $e->getMessage() );
		}
	}

	public function test_azure_delete_transport_error(): void {
		$GLOBALS['_test_wp_http'] = fn() => new \WP_Error( 'x', 'reset' );
		$r = $this->azure()->delete_file( 'a.jpg' );
		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'reset', $r['error'] );
	}

	public function test_azure_download_to_an_unwritable_target_fails_cleanly(): void {
		$blocker = sys_get_temp_dir() . '/dlx-blk-' . uniqid();
		file_put_contents( $blocker, 'a file where a directory is needed' );
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 200, 'payload' );
		$r = $this->azure()->download_file( 'd.bin', $blocker . '/d.bin' );
		$this->assertFalse( $r['success'] );
		unlink( $blocker );
	}

	/** Points the provider at the local stand-in instead of *.blob.core.windows.net. */
	private function azureAt( string $endpoint, string $container = 'media' ): AzureProvider {
		$p    = new AzureProvider( array( 'storage_account' => 'eacct', 'container_name' => $container, 'access_key' => base64_encode( random_bytes( 32 ) ) ) );
		$prop = new \ReflectionProperty( $p, 'endpoint' );
		if ( PHP_VERSION_ID < 80100 ) { // Required before 8.1, deprecated from 8.5.
			$prop->setAccessible( true );
		}
		$prop->setValue( $p, $endpoint );
		return $p;
	}

	/** Nothing is sent to start: the blocks are 4 MiB, the last one the rest. */
	public function test_azure_chunked_upload_starts_without_a_request_and_cuts_4_mib_blocks(): void {
		$file = $this->tmp( 5 * 1024 * 1024 );
		$r    = $this->azure()->begin_chunked_upload( array( 'local_path' => $file, 'remote_path' => '/uploads/big.bin' ) );
		unlink( $file );
		$this->assertTrue( $r['success'] );
		$this->assertSame( array(), $GLOBALS['_test_wp_http_log'] );
		$upload = $r['upload'];
		$this->assertSame( 'uploads/big.bin', $upload->remotePath() );
		$this->assertSame( 2, $upload->partCount() );
		$this->assertSame( array( 4194304, 1048576 ), array( $upload->length( 1 ), $upload->length( 2 ) ) );
	}

	/** Each block is its own streamed Put Block, sending exactly its bytes. */
	public function test_azure_a_block_handle_sends_that_block_and_its_id_is_its_tag(): void {
		$file = tempnam( sys_get_temp_dir(), 'pe' );
		file_put_contents( $file, random_bytes( 4194304 ) . 'the-last-block' );
		$p      = $this->azureAt( self::$server->base_url );
		$upload = $p->begin_chunked_upload( array( 'local_path' => $file, 'remote_path' => 'uploads/big file.bin' ) )['upload'];

		$r = $p->prepare_part_handle( $upload, 2 );
		$this->assertTrue( $r['success'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}$/', $upload->uploadId(), 'a new upload is named by a nonce' );
		$this->assertStringContainsString( '/media/uploads/big%20file.bin?comp=block&blockid=' . rawurlencode( base64_encode( $upload->uploadId() . '000001' ) ), curl_getinfo( $r['handle'], CURLINFO_EFFECTIVE_URL ) );
		curl_setopt( $r['handle'], CURLOPT_HEADER, true );
		$answer = (string) curl_exec( $r['handle'] );
		fclose( $r['file_handle'] );
		$this->assertStringContainsString( md5( 'the-last-block' ), $answer, 'the server received the last block and nothing else' );

		$this->assertNull( $p->finish_part( $upload, 2, 201, '' ) );
		$this->assertSame( base64_encode( $upload->uploadId() . '000001' ), $upload->tag( 2 ) );
		$this->assertNull( $upload->tags(), 'the commit waits for block 1' );
		unlink( $file );
	}

	/**
	 * Taking up an upload: the uncommitted block list tags this upload's
	 * blocks of the right size, and only those. Blocks another upload left
	 * on the same blob (an earlier content of the file) carry another nonce
	 * and are never committed.
	 */
	public function test_azure_an_upload_is_taken_up_from_the_uncommitted_blocks(): void {
		$block = fn( string $name, int $size ) => '<Block><Name>' . $name . '</Name><Size>' . $size . '</Size></Block>';
		$xml   = '<?xml version="1.0" encoding="utf-8"?><BlockList><CommittedBlocks /><UncommittedBlocks>'
			. $block( base64_encode( 'a1b2c3d4000000' ), 4194304 )
			. $block( base64_encode( 'a1b2c3d4000001' ), 12 )      // Cut short: sent again.
			. $block( base64_encode( 'ffffffff000002' ), 1048576 ) // Another upload's: never committed.
			. $block( base64_encode( '000002' ), 1048576 )         // Not a block of this plugin.
			. '</UncommittedBlocks></BlockList>';
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 200, $xml );
		$file = $this->tmp( 9 * 1024 * 1024 );

		$upload = $this->azure()->begin_chunked_upload( array( 'local_path' => $file, 'remote_path' => 'uploads/big file.bin' ), 'a1b2c3d4' )['upload'];
		unlink( $file );

		$this->assertCount( 1, $GLOBALS['_test_wp_http_log'] );
		$this->assertStringContainsString( '/media/uploads/big%20file.bin?blocklisttype=uncommitted&comp=blocklist', $GLOBALS['_test_wp_http_log'][0]['url'] );
		$this->assertArrayHasKey( 'Authorization', $GLOBALS['_test_wp_http_log'][0]['args']['headers'] );
		$this->assertSame( 'a1b2c3d4', $upload->uploadId() );
		$this->assertSame( array( 2, 3 ), $upload->missingParts() );
	}

	public function test_azure_a_blob_with_no_blocks_to_take_up_starts_over(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 404, '<?xml version="1.0"?><Error><Code>BlobNotFound</Code></Error>' );
		$file   = $this->tmp( 5 * 1024 * 1024 );
		$upload = $this->azure()->begin_chunked_upload( array( 'local_path' => $file, 'remote_path' => 'uploads/big.bin' ), 'a1b2c3d4' )['upload'];
		unlink( $file );
		$this->assertSame( array( 1, 2 ), $upload->missingParts() );
	}

	/** A token from before the nonce, or anything that is not one, starts a new upload without asking Azure. */
	public function test_azure_a_resume_name_that_is_not_a_nonce_starts_over(): void {
		$file   = $this->tmp( 5 * 1024 * 1024 );
		$upload = $this->azure()->begin_chunked_upload( array( 'local_path' => $file, 'remote_path' => 'uploads/big.bin' ), '' )['upload'];
		unlink( $file );
		$this->assertSame( array(), $GLOBALS['_test_wp_http_log'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}$/', $upload->uploadId() );
		$this->assertSame( array( 1, 2 ), $upload->missingParts() );
	}

	public function test_azure_a_rejected_block_quotes_the_error_code_but_never_the_signature(): void {
		$body   = '<?xml version="1.0" encoding="utf-8"?><Error><Code>AuthenticationFailed</Code>'
			. '<Message>Server failed to authenticate the request.</Message>'
			. '<AuthenticationErrorDetail>The MAC signature found in the HTTP request \'SECRETMAC==\' is not the same.</AuthenticationErrorDetail></Error>';
		$file   = $this->tmp( 10 );
		$p      = $this->azure();
		$upload = $p->begin_chunked_upload( array( 'local_path' => $file, 'remote_path' => 'uploads/small.bin' ) )['upload'];
		unlink( $file );

		$error = (string) $p->finish_part( $upload, 1, 403, $body );
		$this->assertStringContainsString( 'block 1', $error );
		$this->assertStringContainsString( 'AuthenticationFailed', $error );
		$this->assertStringNotContainsString( 'SECRETMAC', $error, 'the request signature never reaches a log line, the table or the screen' );
		$this->assertSame( '', $upload->tag( 1 ) );
		$this->assertStringNotContainsString( 'nope', (string) $p->finish_part( $upload, 1, 500, 'nope' ), 'a body that is not an Azure error document is not quoted back' );
	}

	public function test_azure_the_commit_lists_the_blocks_once_all_landed(): void {
		$file   = $this->tmp( 5 * 1024 * 1024 );
		$p      = $this->azure();
		$upload = $p->begin_chunked_upload( array( 'local_path' => $file, 'remote_path' => 'uploads/big.bin' ) )['upload'];
		unlink( $file );
		$this->assertFalse( $p->prepare_commit_handle( $upload )['success'], 'no commit while a block is missing' );

		$p->finish_part( $upload, 1, 201, '' );
		$p->finish_part( $upload, 2, 201, '' );
		$r = $p->prepare_commit_handle( $upload );
		$this->assertTrue( $r['success'] );
		$this->assertStringContainsString( 'comp=blocklist', curl_getinfo( $r['handle'], CURLINFO_EFFECTIVE_URL ) );
		$this->assertIsCallable( $r['on_failure'] );
		( $r['on_failure'] )();
		$this->assertSame( array(), $GLOBALS['_test_wp_http_log'], 'uncommitted blocks need no call to go' );
	}

	public function test_azure_constructor_logs_an_invalid_config_without_throwing(): void {
		$p = new AzureProvider( array( 'storage_account' => 'Not Valid', 'container_name' => 'media', 'access_key' => 'k' ) );
		$this->assertInstanceOf( AzureProvider::class, $p );
	}

	public function test_azure_operations_survive_a_transport_that_throws(): void {
		$GLOBALS['_test_wp_http'] = function () {
			throw new \RuntimeException( 'transport exploded' );
		};
		$p = $this->azure();
		$this->assertStringContainsString( 'transport exploded', $p->test_connection()['message'] );
		$this->assertStringContainsString( 'transport exploded', $p->upload_file( $this->tmp( 2 ), 'x.jpg' )['error'] );
		$this->assertStringContainsString( 'transport exploded', $p->download_file( 'x.jpg', sys_get_temp_dir() . '/dlx-x-' . uniqid() )['error'] );
		$this->assertFalse( $p->file_exists( 'x.jpg' ) );
		$this->assertFalse( $p->get_file_checksum( 'x.jpg' ) );
		$this->assertFalse( $p->get_file_info( 'x.jpg' ) );
		$this->assertStringContainsString( 'transport exploded', $p->copy_blob( 'a', 'b' )['error'] );
		$this->assertStringContainsString( 'transport exploded', $p->delete_file( 'a' )['error'] );
	}

	public function test_azure_connection_refusal_with_an_unparseable_body(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 404, '<<not xml' );
		$this->assertStringContainsString( '404', $this->azure()->test_connection()['message'] );
	}

	/** @dataProvider retryableTransportErrors */
	public function test_azure_list_retries_transport_errors_and_classifies_them( string $message, string $code ): void {
		$GLOBALS['_test_wp_http'] = fn() => new \WP_Error( 'x', $message );
		try {
			$this->azure()->list_files( 'uploads/' );
			$this->fail( 'expected an exception' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( 'after 3 attempts', $e->getMessage() );
		}
		// A timeout: three listings of one request each. A dropped connection:
		// one listing whose request was tried three times, not three times three.
		$this->assertCount( 3, $GLOBALS['_test_wp_http_log'] );
		$this->assertNotSame( $code, ConfigManager::get_connection_health()['error_code'], 'retryable errors are not recorded as final' );
	}

	/** @return array<string, array{string,string}> */
	public function retryableTransportErrors(): array {
		return array(
			'timeout' => array( 'Operation timed out after 60000 ms', 'timeout' ),
			'network' => array( 'cURL error 7: Failed to connect', 'network' ),
		);
	}

	public function test_azure_list_gives_up_on_garbage_xml(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 200, '<<not xml' );
		$this->expectExceptionMessage( 'after 3 attempts' );
		$this->azure()->list_files( 'uploads/' );
	}

	// ── chunked upload edge cases, both providers ───────────

	public function test_azure_stats_classify_files_by_type(): void {
		$p   = $this->azure();
		$xml = '<?xml version="1.0"?><EnumerationResults><Blobs>'
			. '<Blob><Name>uploads/a.jpg</Name><Properties><Content-Length>10</Content-Length><Last-Modified>x</Last-Modified></Properties></Blob>'
			. '<Blob><Name>uploads/b.mp4</Name><Properties><Content-Length>20</Content-Length><Last-Modified>x</Last-Modified></Properties></Blob>'
			. '<Blob><Name>uploads/c.mp3</Name><Properties><Content-Length>30</Content-Length><Last-Modified>x</Last-Modified></Properties></Blob>'
			. '<Blob><Name>uploads/d.pdf</Name><Properties><Content-Length>40</Content-Length><Last-Modified>x</Last-Modified></Properties></Blob>'
			. '</Blobs><NextMarker></NextMarker></EnumerationResults>';
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 200, $xml );
		$r = $p->get_storage_stats( true );
		$this->assertTrue( $r['success'] );
		$this->assertSame( 4, $r['data']['fileCount'] );
		$this->assertSame( 100, $r['data']['storageUsedBytes'] );
		$this->assertSame( array( 'images' => 1, 'videos' => 1, 'audio' => 1, 'other' => 1 ), $r['data']['filesByType'] );
	}
}
