<?php
namespace Tests\Unit\CloudStorage;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\CloudStreamWrapper;
use DiluxOneOffload\Enums\PluginState;

/**
 * The wrapper when its temp file cannot be written: a full or read-only
 * temp directory. The open must fail (so WordPress reports the upload or
 * the read as failed), the reason must reach the log, and no request goes
 * to the cloud for a write that never got a file to hold its bytes.
 *
 * Each test runs in its own process: the unwritable temp file comes from a
 * namespaced stand-in for wp_tempnam() (tests/Unit/Support/tempnam-faults.php)
 * that must not reach the rest of the suite.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class StreamWrapperTempFileFaultsTest extends TestCase {

	private const P = 'diluxoneoffload';

	private string $sink = '';
	private string $dir  = '';

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__ ) . '/Support/tempnam-faults.php';
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/dlx-wp-content' );
		}
		if ( ! defined( 'DAY_IN_SECONDS' ) ) {
			define( 'DAY_IN_SECONDS', 86400 );
		}
		$GLOBALS['_test_wp_options']    = array(
			'diluxone_offload_config'       => array(
				'cloud_provider'  => 'azure',
				'provider_config' => array( 'storage_account' => 'tmpacct', 'container_name' => 'media', 'access_key' => base64_encode( random_bytes( 32 ) ) ),
			),
			'diluxone_offload_plugin_state' => PluginState::SYNCED,
		);
		$GLOBALS['_test_wp_transients'] = array();
		$GLOBALS['_test_wp_http_log']   = array();
		$GLOBALS['_test_wp_hooks']      = array();
		$GLOBALS['_test_wp_http']       = static fn() => array( 'response' => array( 'code' => 201, 'message' => '' ), 'body' => '', 'headers' => array() );
		// A directory where a file is expected: nothing can be written to it.
		$this->dir = sys_get_temp_dir() . '/dlx-not-a-file-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir );
		$this->sink = (string) tempnam( sys_get_temp_dir(), 'dlxlog' );
		ini_set( 'error_log', $this->sink );
		CloudStreamWrapper::register();
	}

	protected function tearDown(): void {
		CloudStreamWrapper::unregister();
		@rmdir( $this->dir );
		@unlink( $this->sink );
		unset( $GLOBALS['_dlx_tempnam_answer'], $GLOBALS['_test_wp_http'], $GLOBALS['_test_wp_http_log'], $GLOBALS['_test_wp_transients'], $GLOBALS['_test_wp_options'], $GLOBALS['_test_wp_hooks'] );
		parent::tearDown();
	}

	public function test_a_write_without_a_writable_temp_file_fails_to_open_and_sends_nothing(): void {
		$GLOBALS['_dlx_tempnam_answer'] = $this->dir;

		$this->assertFalse( @fopen( self::P . '://uploads/2026/01/new.jpg', 'w' ) );

		$this->assertStringContainsString( 'Could not open the temp file backing uploads/2026/01/new.jpg', (string) file_get_contents( $this->sink ) );
		$this->assertSame( array(), $GLOBALS['_test_wp_http_log'], 'no upload for bytes that had nowhere to go' );
		$this->assertDirectoryExists( $this->dir, 'cleaning up never removes what it did not create' );
	}

	public function test_a_read_of_a_cached_file_without_a_writable_temp_file_fails_to_open(): void {
		// A write leaves the small file in the per-request cache.
		$fh = fopen( self::P . '://uploads/2026/01/cached.txt', 'w' );
		fwrite( $fh, 'cached bytes' );
		$this->assertTrue( fclose( $fh ) );
		$sent = count( $GLOBALS['_test_wp_http_log'] );

		$GLOBALS['_dlx_tempnam_answer'] = $this->dir;

		$this->assertFalse( @fopen( self::P . '://uploads/2026/01/cached.txt', 'r' ) );
		$this->assertCount( $sent, $GLOBALS['_test_wp_http_log'], 'the cache answered; the cloud was not asked' );
	}
}
