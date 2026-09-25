<?php
namespace Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\Providers\AzureProvider;

/**
 * Unit tests for AzureProvider against a scripted HTTP layer.
 *
 * Nothing here touches the network: wp_remote_* are stubs that hand each
 * request to $GLOBALS['_test_wp_http'], so every test states exactly what
 * Azure "answers" and asserts what the provider does with it — status codes
 * mapped to results, XML errors surfaced, retries on transient failures,
 * the stats cache honoured. The request log is inspected too, because the
 * shared-key signature is only right if the method, URL and headers are.
 */
class AzureProviderTest extends TestCase {

	/** A fixed key, so a test can recompute the signature the provider must have produced. */
	private const KEY = 'dGhlLXVuaXQtdGVzdC1rZXktZm9yLWF6dXJlLXNpZ25hdHVyZXM9PT09PQ==';

	private const ACCOUNT   = 'unitacct';
	private const CONTAINER = 'media';

	private AzureProvider $provider;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_wp_options']    = array();
		$GLOBALS['_test_wp_transients'] = array();
		$GLOBALS['_test_wp_http_log']   = array();
		unset( $GLOBALS['_test_wp_http'] );

		$this->provider = new AzureProvider(
			array(
				'storage_account' => self::ACCOUNT,
				'container_name'  => self::CONTAINER,
				'access_key'      => self::KEY,
			)
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_test_wp_http'], $GLOBALS['_test_wp_http_log'], $GLOBALS['_test_wp_transients'], $GLOBALS['_test_wp_options'] );
		parent::tearDown();
	}

	// ── Helpers ─────────────────────────────────────────────

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

	private static function blobsXml( array $blobs, string $next = '' ): string {
		$items = '';
		foreach ( $blobs as $name => $size ) {
			$items .= "<Blob><Name>{$name}</Name><Properties><Content-Length>{$size}</Content-Length>"
				. '<Content-MD5>AAAA</Content-MD5><Last-Modified>Mon, 01 Jan 2026 00:00:00 GMT</Last-Modified>'
				. '</Properties></Blob>';
		}
		return '<?xml version="1.0" encoding="utf-8"?><EnumerationResults>'
			. "<Blobs>{$items}</Blobs><NextMarker>{$next}</NextMarker></EnumerationResults>";
	}

	// ── Identity ────────────────────────────────────────────

	public function test_provider_name(): void {
		$this->assertSame( 'azure', $this->provider->get_provider_name() );
	}

	public function test_file_url_is_the_public_blob_url(): void {
		$this->assertSame(
			'https://unitacct.blob.core.windows.net/media/2026/01/a.jpg',
			$this->provider->get_file_url( '/2026/01/a.jpg' )
		);
	}

	public function test_file_url_tolerates_a_missing_leading_slash(): void {
		$this->assertSame(
			'https://unitacct.blob.core.windows.net/media/x.png',
			$this->provider->get_file_url( 'x.png' )
		);
	}

	// ── test_connection ─────────────────────────────────────

	public function test_connection_succeeds_on_200_when_the_container_is_publicly_readable(): void {
		foreach ( array( 'blob', 'container' ) as $level ) {
			$this->answer( fn() => self::reply( 200, '', array( 'x-ms-blob-public-access' => $level ) ) );
			$r = $this->provider->test_connection();
			$this->assertTrue( $r['success'], $level );
		}
	}

	/**
	 * Media is served by the browser straight from the container, so valid
	 * credentials on a private container would give a working admin and a
	 * site full of 404s. The test says so before anything is saved.
	 */
	public function test_connection_refuses_a_private_container_and_says_what_to_change(): void {
		$this->answer( fn() => self::reply( 200 ) );
		$r = $this->provider->test_connection();
		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'private', $r['message'] );
		$this->assertStringContainsString( 'public access level', $r['message'] );
	}

	public function test_connection_probes_the_container_with_a_signed_get(): void {
		$this->answer( fn() => self::reply( 200, '', array( 'x-ms-blob-public-access' => 'blob' ) ) );
		$this->provider->test_connection();

		$req = $this->requests()[0];
		$this->assertSame( 'GET', $req['method'] );
		$this->assertStringStartsWith( 'https://unitacct.blob.core.windows.net/media?restype=container', $req['url'] );
		$this->assertArrayHasKey( 'Authorization', $req['args']['headers'] );
		$this->assertStringStartsWith( 'SharedKey unitacct:', $req['args']['headers']['Authorization'] );
		$this->assertArrayHasKey( 'x-ms-date', $req['args']['headers'] );
		$this->assertSame( '2020-04-08', $req['args']['headers']['x-ms-version'] );
	}

	public function test_connection_reports_the_status_on_failure(): void {
		$this->answer( fn() => self::reply( 403 ) );
		$r = $this->provider->test_connection();
		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( '403', $r['message'] );
	}

	public function test_connection_surfaces_azures_xml_error_message(): void {
		$xml = '<?xml version="1.0"?><Error><Code>AuthenticationFailed</Code><Message>Server failed to authenticate</Message></Error>';
		$this->answer( fn() => self::reply( 403, $xml ) );
		$r = $this->provider->test_connection();
		$this->assertStringContainsString( 'Server failed to authenticate', $r['message'] );
	}

	public function test_connection_survives_a_non_xml_error_body(): void {
		$this->answer( fn() => self::reply( 502, '<html>Bad gateway</html><<broken' ) );
		$r = $this->provider->test_connection();
		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( '502', $r['message'] );
	}

	public function test_connection_reports_a_transport_error(): void {
		$this->answer( fn() => new \WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) );
		$r = $this->provider->test_connection();
		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'timed out', $r['message'] );
	}

	// ── object keys with characters WordPress never produces but disks do ──

	public function test_keys_with_spaces_accents_and_hashes_are_percent_encoded_in_the_url(): void {
		$this->answer( fn() => self::reply( 200, '', array( 'content-length' => '1' ) ) );
		$this->provider->file_exists( 'uploads/2026/09/café photo (1)#2.png' );
		$req = $this->requests()[0];
		$this->assertStringEndsWith( '/media/uploads/2026/09/caf%C3%A9%20photo%20%281%29%232.png', $req['url'] );
		$this->assertStringNotContainsString( '#', $req['url'], 'a hash would be a fragment, not part of the key' );
	}

	public function test_the_signature_is_over_the_encoded_path(): void {
		$this->answer( fn() => self::reply( 200, '', array( 'content-length' => '1' ) ) );
		$this->provider->file_exists( 'uploads/with space.png' );
		$req  = $this->requests()[0];
		$date = $req['args']['headers']['x-ms-date'];
		// Recompute Azure's string to sign for a HEAD on this key with the stub key.
		$sts = "HEAD\n\n\n\n\n\n\n\n\n\n\n\nx-ms-date:{$date}\nx-ms-version:2020-04-08\n/unitacct/media/uploads/with%20space.png";
		$sig = base64_encode( hash_hmac( 'sha256', $sts, base64_decode( self::KEY ), true ) );
		$this->assertSame( 'SharedKey unitacct:' . $sig, $req['args']['headers']['Authorization'] );
	}

	// ── upload / delete / exists ────────────────────────────

	public function test_upload_sends_a_put_with_the_file_body(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'az' );
		file_put_contents( $tmp, 'hello-blob' );
		$this->answer( fn() => self::reply( 201 ) );

		$r = $this->provider->upload_file( $tmp, '2026/01/hello.txt' );
		@unlink( $tmp );

		$this->assertTrue( $r['success'] );
		$req = $this->requests()[0];
		$this->assertSame( 'PUT', $req['method'] );
		$this->assertStringEndsWith( '/media/2026/01/hello.txt', $req['url'] );
		$this->assertSame( 'hello-blob', $req['args']['body'] );
		$this->assertSame( 'BlockBlob', $req['args']['headers']['x-ms-blob-type'] );
	}

	public function test_upload_fails_when_the_local_file_is_missing(): void {
		$r = $this->provider->upload_file( '/nope/missing.jpg', 'x.jpg' );
		$this->assertFalse( $r['success'] );
		$this->assertSame( array(), $this->requests(), 'no request must be made for a missing file' );
	}

	public function test_upload_fails_on_a_non_201(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'az' );
		file_put_contents( $tmp, 'x' );
		$this->answer( fn() => self::reply( 500 ) );
		$r = $this->provider->upload_file( $tmp, 'x.txt' );
		@unlink( $tmp );
		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( '500', $r['error'] );
	}

	public function test_delete_succeeds_on_202(): void {
		$this->answer( fn() => self::reply( 202 ) );
		$this->assertTrue( $this->provider->delete_file( 'a.jpg' )['success'] );
		$this->assertSame( 'DELETE', $this->requests()[0]['method'] );
	}

	public function test_delete_fails_on_404(): void {
		$this->answer( fn() => self::reply( 404 ) );
		$this->assertFalse( $this->provider->delete_file( 'a.jpg' )['success'] );
	}

	public function test_file_exists_true_on_200(): void {
		$this->answer( fn() => self::reply( 200 ) );
		$this->assertTrue( $this->provider->file_exists( 'a.jpg' ) );
		$this->assertSame( 'HEAD', $this->requests()[0]['method'] );
	}

	public function test_file_exists_false_on_404(): void {
		$this->answer( fn() => self::reply( 404 ) );
		$this->assertFalse( $this->provider->file_exists( 'a.jpg' ) );
	}

	public function test_file_exists_false_on_transport_error(): void {
		$this->answer( fn() => new \WP_Error( 'x', 'down' ) );
		$this->assertFalse( $this->provider->file_exists( 'a.jpg' ) );
	}

	public function test_checksum_comes_from_the_content_md5_header(): void {
		$this->answer( fn() => self::reply( 200, '', array( 'content-md5' => 'abc123==' ) ) );
		$this->assertSame( 'abc123==', $this->provider->get_file_checksum( 'a.jpg' ) );
	}

	public function test_checksum_is_false_when_the_blob_is_missing(): void {
		$this->answer( fn() => self::reply( 404 ) );
		$this->assertFalse( $this->provider->get_file_checksum( 'a.jpg' ) );
	}

	// ── list_files ──────────────────────────────────────────

	public function test_list_files_parses_blobs(): void {
		$this->answer( fn() => self::reply( 200, self::blobsXml( array( 'uploads/a.jpg' => 10, 'uploads/b.png' => 20 ) ) ) );
		$files = $this->provider->list_files( 'uploads/' );
		$this->assertCount( 2, $files );
		$paths = array_column( $files, 'path' );
		$this->assertContains( 'uploads/a.jpg', $paths );
		$this->assertSame( 10, (int) $files[ array_search( 'uploads/a.jpg', $paths, true ) ]['size'] );
	}

	public function test_list_files_follows_the_next_marker(): void {
		$page = 0;
		$this->answer(
			function ( $m, $url ) use ( &$page ) {
				++$page;
				if ( strpos( $url, 'marker=' ) === false ) {
					return self::reply( 200, self::blobsXml( array( 'uploads/1.jpg' => 1 ), 'MARK' ) );
				}
				return self::reply( 200, self::blobsXml( array( 'uploads/2.jpg' => 2 ) ) );
			}
		);
		$files = $this->provider->list_files( 'uploads/' );
		$this->assertCount( 2, $files );
		$this->assertSame( 2, $page, 'two pages requested' );
	}

	public function test_list_files_retries_a_transient_failure_then_succeeds(): void {
		$calls = 0;
		$this->answer(
			function () use ( &$calls ) {
				++$calls;
				return $calls === 1 ? self::reply( 503 ) : self::reply( 200, self::blobsXml( array( 'uploads/ok.jpg' => 5 ) ) );
			}
		);
		$files = $this->provider->list_files( 'uploads/' );
		$this->assertCount( 1, $files );
		$this->assertSame( 2, $calls );
	}

	// ── get_container_stats ─────────────────────────────────

	public function test_stats_count_and_classify_files(): void {
		$this->answer( fn() => self::reply( 200, self::blobsXml( array( 'uploads/a.jpg' => 100, 'uploads/b.mp4' => 200, 'uploads/c.pdf' => 300 ) ) ) );
		$r = $this->provider->get_container_stats( true );
		$this->assertTrue( $r['success'] );
		$this->assertSame( 3, $r['data']['fileCount'] );
		$this->assertSame( 600, $r['data']['storageUsedBytes'] );
		$this->assertSame( 1, $r['data']['filesByType']['images'] );
		$this->assertSame( 1, $r['data']['filesByType']['videos'] );
		$this->assertSame( 1, $r['data']['filesByType']['other'] );
		$this->assertNull( $r['data']['bandwidthUsedBytes'], 'Azure reports no bandwidth' );
	}

	public function test_stats_are_served_from_the_transient_when_cached(): void {
		$GLOBALS['_test_wp_transients']['diluxone_offload_azure_stats'] = array( 'fileCount' => 42 );
		$this->answer( fn() => self::reply( 500 ) );
		$r = $this->provider->get_container_stats();
		$this->assertTrue( $r['success'] );
		$this->assertSame( 42, $r['data']['fileCount'] );
		$this->assertSame( array(), $this->requests(), 'a warm cache must not hit the network' );
	}

	public function test_force_refresh_bypasses_the_transient(): void {
		$GLOBALS['_test_wp_transients']['diluxone_offload_azure_stats'] = array( 'fileCount' => 42 );
		$this->answer( fn() => self::reply( 200, self::blobsXml( array( 'uploads/a.jpg' => 1 ) ) ) );
		$r = $this->provider->get_container_stats( true );
		$this->assertSame( 1, $r['data']['fileCount'] );
		$this->assertNotEmpty( $this->requests() );
	}

	public function test_stats_write_the_transient_on_success(): void {
		$this->answer( fn() => self::reply( 200, self::blobsXml( array( 'uploads/a.jpg' => 1 ) ) ) );
		$this->provider->get_container_stats( true );
		$this->assertSame( 1, $GLOBALS['_test_wp_transients']['diluxone_offload_azure_stats']['fileCount'] );
	}

	public function test_stats_failure_clears_the_transient_and_reports(): void {
		$GLOBALS['_test_wp_transients']['diluxone_offload_azure_stats'] = array( 'fileCount' => 42 );
		$this->answer( fn() => self::reply( 403 ) );
		$r = $this->provider->get_container_stats( true );
		$this->assertFalse( $r['success'] );
		$this->assertArrayNotHasKey( 'diluxone_offload_azure_stats', $GLOBALS['_test_wp_transients'] );
	}
}
