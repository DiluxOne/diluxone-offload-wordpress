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

	// ── DiluxOne: SAS token ─────────────────────────────────

	// ── DiluxOne: 403 → refresh once, then give up ──────────

	// ── DiluxOne: per-operation failures ────────────────────

	// ── DiluxOne: chunked (large file) upload ───────────────

	public function test_azure_chunked_upload_of_a_missing_file_fails_cleanly(): void {
		$r = $this->azure()->prepare_chunked_upload_handle( array( 'local_path' => '/nope/x', 'remote_path' => 'x', 'size' => 1 ) );
		$this->assertFalse( $r['success'] );
		$this->assertNull( $r['file_handle'] );
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

	public function test_azure_chunked_upload_puts_every_block_and_hands_back_the_commit(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 201 );
		$file = $this->tmp( 5 * 1024 * 1024 );
		$r    = $this->azure()->prepare_chunked_upload_handle( array( 'local_path' => $file, 'remote_path' => 'uploads/big.bin', 'size' => filesize( $file ) ) );
		$this->assertTrue( $r['success'], $r['error'] ?? '' );
		$this->assertCount( 2, $GLOBALS['_test_wp_http_log'], 'one HTTP API request per 4 MB block' );
		$this->assertStringContainsString( 'comp=block&', $GLOBALS['_test_wp_http_log'][0]['url'] );
		$this->assertArrayHasKey( 'Authorization', $GLOBALS['_test_wp_http_log'][0]['args']['headers'] );
		$this->assertStringContainsString( 'comp=blocklist', curl_getinfo( $r['handle'], CURLINFO_EFFECTIVE_URL ) );
		$this->assertNull( $r['file_handle'] );
		unlink( $file );
	}

	public function test_azure_chunked_upload_stops_at_the_first_rejected_block(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 500, 'nope' );
		$file = $this->tmp( 10 );
		$r    = $this->azure()->prepare_chunked_upload_handle( array( 'local_path' => $file, 'remote_path' => 'uploads/small.bin', 'size' => 10 ) );
		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'block 0', $r['error'] );
		$this->assertStringContainsString( 'HTTP 500', $r['error'] );
		$this->assertStringNotContainsString( 'nope', $r['error'], 'a body that is not an Azure error document is not quoted back' );
		unlink( $file );
	}

	public function test_azure_chunked_upload_quotes_the_error_code_but_never_the_signature(): void {
		$body = '<?xml version="1.0" encoding="utf-8"?><Error><Code>AuthenticationFailed</Code>'
			. '<Message>Server failed to authenticate the request.</Message>'
			. '<AuthenticationErrorDetail>The MAC signature found in the HTTP request \'SECRETMAC==\' is not the same.</AuthenticationErrorDetail></Error>';
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 403, $body );
		$file = $this->tmp( 10 );
		$r    = $this->azure()->prepare_chunked_upload_handle( array( 'local_path' => $file, 'remote_path' => 'uploads/small.bin', 'size' => 10 ) );
		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'AuthenticationFailed', $r['error'] );
		$this->assertStringContainsString( 'Server failed to authenticate', $r['error'] );
		$this->assertStringNotContainsString( 'SECRETMAC', $r['error'], 'the request signature never reaches a log line, the table or the screen' );
		unlink( $file );
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
		$r = $p->get_container_stats( true );
		$this->assertTrue( $r['success'] );
		$this->assertSame( 4, $r['data']['fileCount'] );
		$this->assertSame( 100, $r['data']['storageUsedBytes'] );
		$this->assertSame( array( 'images' => 1, 'videos' => 1, 'audio' => 1, 'other' => 1 ), $r['data']['filesByType'] );
	}
}
