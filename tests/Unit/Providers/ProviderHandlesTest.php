<?php
namespace Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\Providers\AzureProvider;

/**
 * The cURL handle builders the Azure provider exposes for the parallel sync
 * loops, plus its copy and file-info calls.
 *
 * Handle builders are asserted on what they return — a live cURL handle and
 * an open file handle, or a clean failure — without executing anything.
 */
class ProviderHandlesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_wp_options']    = array();
		$GLOBALS['_test_wp_transients'] = array();
		$GLOBALS['_test_wp_http_log']   = array();
		unset( $GLOBALS['_test_wp_http'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_test_wp_http'], $GLOBALS['_test_wp_http_log'], $GLOBALS['_test_wp_transients'], $GLOBALS['_test_wp_options'] );
		parent::tearDown();
	}

	private function azure(): AzureProvider {
		return new AzureProvider( array( 'storage_account' => 'hacct', 'container_name' => 'media', 'access_key' => base64_encode( random_bytes( 32 ) ) ) );
	}

	private static function raw( int $code, string $body = '', array $headers = array() ): array {
		return array( 'response' => array( 'code' => $code, 'message' => '' ), 'body' => $body, 'headers' => $headers );
	}

	private function tmpFile( string $content = 'data' ): string {
		$f = tempnam( sys_get_temp_dir(), 'ph' );
		file_put_contents( $f, $content );
		return $f;
	}

	private static function isCurl( $h ): bool {
		return $h instanceof \CurlHandle || ( is_resource( $h ) && get_resource_type( $h ) === 'curl' );
	}

	// ── Azure handles ───────────────────────────────────────

	public function test_azure_batch_upload_handle(): void {
		$f = $this->tmpFile();
		$r = $this->azure()->prepare_batch_upload_handle( array( 'local_path' => $f, 'remote_path' => 'uploads/x.txt', 'size' => 4 ) );
		$this->assertTrue( $r['success'] );
		$this->assertTrue( self::isCurl( $r['handle'] ) );
		$this->assertIsResource( $r['file_handle'] );
		fclose( $r['file_handle'] );
		unlink( $f );
	}

	public function test_azure_batch_upload_handle_for_a_missing_file(): void {
		$r = $this->azure()->prepare_batch_upload_handle( array( 'local_path' => '/nope/x', 'remote_path' => 'uploads/x.txt', 'size' => 1 ) );
		$this->assertFalse( $r['success'] );
		$this->assertNull( $r['file_handle'] );
	}

	public function test_azure_download_handle(): void {
		$dest = sys_get_temp_dir() . '/dlx-dl-' . uniqid() . '/a/b.bin';
		$r = $this->azure()->prepare_download_handle( array( 'remote_path' => 'uploads/b.bin', 'local_path' => $dest ) );
		$this->assertTrue( $r['success'], $r['error'] ?? '' );
		$this->assertTrue( self::isCurl( $r['handle'] ) );
		fclose( $r['file_handle'] );
		// The bytes land next to the attachment, never on it, until the
		// download checks out and SyncManager renames the part over it.
		$this->assertSame( $dest . '.dlxpart', $r['part_path'] );
		$this->assertFileExists( $dest . '.dlxpart' );
		$this->assertFileDoesNotExist( $dest );
		@unlink( $dest . '.dlxpart' ); @rmdir( dirname( $dest ) ); @rmdir( dirname( $dest, 2 ) );
	}

	// ── Azure copy / info ───────────────────────────────────

	public function test_azure_copy_blob_success_and_failure(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 202 );
		$this->assertTrue( $this->azure()->copy_blob( 'a.jpg', 'b.jpg' )['success'] );
		$req = $GLOBALS['_test_wp_http_log'][0];
		$this->assertSame( 'PUT', $req['method'] );
		$this->assertStringContainsString( '/media/a.jpg', $req['args']['headers']['x-ms-copy-source'] );

		$GLOBALS['_test_wp_http'] = fn() => self::raw( 404 );
		$this->assertFalse( $this->azure()->copy_blob( 'a.jpg', 'b.jpg' )['success'] );

		$GLOBALS['_test_wp_http'] = fn() => new \WP_Error( 'x', 'down' );
		$this->assertFalse( $this->azure()->copy_blob( 'a.jpg', 'b.jpg' )['success'] );
	}

	public function test_azure_file_info_from_headers(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 200, '', array( 'content-length' => '77', 'content-md5' => 'abc', 'last-modified' => 'Mon, 01 Jan 2026 00:00:00 GMT' ) );
		$info = $this->azure()->get_file_info( 'a.jpg' );
		$this->assertSame( 77, (int) $info['size'] );
		$this->assertSame( 'abc', $info['md5'] );

		$GLOBALS['_test_wp_http'] = fn() => self::raw( 404 );
		$this->assertFalse( $this->azure()->get_file_info( 'a.jpg' ) );
	}

}
