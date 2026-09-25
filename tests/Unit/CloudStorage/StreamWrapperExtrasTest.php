<?php
namespace Tests\Unit\CloudStorage;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\CloudStreamWrapper;
use DiluxOneOffload\Enums\PluginState;

/**
 * The rest of the stream wrapper: rename, directory listing, seeking,
 * metadata, the HTTPS-forcing filters and the admin tear-down.
 */
class StreamWrapperExtrasTest extends TestCase {

	private const P = 'diluxoneoffload';

	protected function setUp(): void {
		parent::setUp();
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/dlx-wp-content' );
		}
		if ( ! defined( 'DAY_IN_SECONDS' ) ) {
			define( 'DAY_IN_SECONDS', 86400 );
		}
		$GLOBALS['_test_wp_options']    = array();
		$GLOBALS['_test_wp_transients'] = array();
		$GLOBALS['_test_wp_http_log']   = array();
		$GLOBALS['_test_wp_hooks']      = array();
		unset( $GLOBALS['_test_wp_http'] );
		$this->config( 'wrapacct' );
		$GLOBALS['_test_wp_options']['diluxone_offload_plugin_state'] = PluginState::SYNCED;
		CloudStreamWrapper::clear_stat_cache();
		CloudStreamWrapper::clear_file_cache();
		$this->resetHostCache();
		CloudStreamWrapper::register();
	}

	protected function tearDown(): void {
		CloudStreamWrapper::unregister();
		$this->resetHostCache();
		unset( $GLOBALS['_test_wp_http'], $GLOBALS['_test_wp_http_log'], $GLOBALS['_test_wp_transients'], $GLOBALS['_test_wp_options'], $GLOBALS['_test_wp_hooks'] );
		parent::tearDown();
	}

	private function config( string $account, array $extra = array(), bool $force_https = true, string $provider = 'azure' ): void {
		$GLOBALS['_test_wp_options']['diluxone_offload_config'] = array(
			'cloud_provider'       => $provider,
			'provider_config'      => array_merge(
				array( 'storage_account' => $account, 'container_name' => 'media', 'access_key' => base64_encode( random_bytes( 32 ) ) ),
				$extra
			),
			'force_https_on_cloud' => $force_https,
		);
	}

	/** get_cloud_host() memoises per request; tests need a clean slate. */
	private function resetHostCache(): void {
		$p = new \ReflectionProperty( CloudStreamWrapper::class, 'cloud_host_cache' );
		if ( PHP_VERSION_ID < 80100 ) { // Required before 8.1, deprecated from 8.5.
			$p->setAccessible( true );
		}
		$p->setValue( null, null );
	}

	private static function reply( int $code, array $headers = array() ): array {
		return array( 'response' => array( 'code' => $code, 'message' => '' ), 'body' => '', 'headers' => $headers );
	}

	/** @return array<int, array{method:string,url:string,args:array}> */
	private function requests( string $method ): array {
		return array_values( array_filter( $GLOBALS['_test_wp_http_log'] ?? array(), fn( $r ) => $r['method'] === $method ) );
	}

	// ── rename: copy then delete ────────────────────────────

	public function test_rename_copies_then_deletes_the_source(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 202 );
		$this->assertTrue( rename( self::P . '://uploads/a.jpg', self::P . '://uploads/b.jpg' ) );
		$puts = $this->requests( 'PUT' );
		$this->assertCount( 1, $puts );
		$this->assertStringEndsWith( '/media/uploads/b.jpg', $puts[0]['url'] );
		$this->assertStringContainsString( '/media/uploads/a.jpg', $puts[0]['args']['headers']['x-ms-copy-source'] );
		$this->assertStringEndsWith( '/media/uploads/a.jpg', $this->requests( 'DELETE' )[0]['url'] );
	}

	public function test_rename_fails_when_the_copy_is_refused(): void {
		$GLOBALS['_test_wp_http'] = fn( $m ) => $m === 'PUT' ? self::reply( 404 ) : self::reply( 202 );
		$this->assertFalse( @rename( self::P . '://uploads/a.jpg', self::P . '://uploads/b.jpg' ) );
		$this->assertCount( 0, $this->requests( 'DELETE' ), 'nothing is deleted if the copy failed' );
	}

	public function test_rename_still_succeeds_if_the_delete_of_the_source_fails(): void {
		$GLOBALS['_test_wp_http'] = fn( $m ) => $m === 'DELETE' ? self::reply( 500 ) : self::reply( 202 );
		$this->assertTrue( rename( self::P . '://uploads/a.jpg', self::P . '://uploads/b.jpg' ) );
	}

	// ── directories ─────────────────────────────────────────

	public function test_rmdir_is_a_no_op_that_succeeds(): void {
		$this->assertTrue( rmdir( self::P . '://uploads/2026' ) );
	}

	public function test_directory_listing_is_empty_but_well_behaved(): void {
		$h = opendir( self::P . '://uploads/2026/09' );
		$this->assertNotFalse( $h );
		$this->assertFalse( readdir( $h ), 'object storage lists nothing through the wrapper' );
		rewinddir( $h );
		$this->assertFalse( readdir( $h ) );
		closedir( $h );
	}

	public function test_touch_is_accepted(): void {
		$this->assertTrue( touch( self::P . '://uploads/x.jpg' ) );
	}

	// ── seek / tell / eof ───────────────────────────────────

	public function test_seek_and_tell_on_a_write_buffer(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 201 );
		$fh = fopen( self::P . '://uploads/seek.txt', 'w' );
		fwrite( $fh, 'abcdef' );
		$this->assertSame( 6, ftell( $fh ) );
		// A write stream is a real file handle now, so feof() answers the way
		// it does for any file: false until a read runs past the end, not
		// "the cursor is at the end" as the old in-memory buffer reported.
		$this->assertFalse( feof( $fh ) );
		$this->assertSame( 0, fseek( $fh, 2 ) );
		$this->assertSame( 2, ftell( $fh ) );
		fwrite( $fh, 'XY' );
		fclose( $fh );
		$this->assertSame( 'abXYef', $this->requests( 'PUT' )[0]['args']['body'] );
	}

	public function test_seek_from_end_and_current(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 201 );
		$fh = fopen( self::P . '://uploads/seek2.txt', 'w' );
		fwrite( $fh, '0123456789' );
		fseek( $fh, -3, SEEK_END );
		$this->assertSame( 7, ftell( $fh ) );
		fseek( $fh, 1, SEEK_CUR );
		$this->assertSame( 8, ftell( $fh ) );
		fclose( $fh );
	}

	public function test_seek_and_eof_on_a_downloaded_read_stream(): void {
		$GLOBALS['_test_wp_http'] = fn( $m ) => $m === 'GET' ? array( 'response' => array( 'code' => 200, 'message' => '' ), 'body' => 'hello world', 'headers' => array() ) : self::reply( 200 );
		$fh = fopen( self::P . '://uploads/read.txt', 'r' );
		$this->assertSame( 'hello', fread( $fh, 5 ) );
		$this->assertSame( 5, ftell( $fh ) );
		fseek( $fh, 6 );
		$this->assertSame( 'world', fread( $fh, 5 ) );
		fread( $fh, 1 );
		$this->assertTrue( feof( $fh ) );
		fclose( $fh );
	}

	public function test_append_to_a_new_file_uploads_what_was_written(): void {
		// A 404 on the download is the one answer that means "new file".
		$GLOBALS['_test_wp_http'] = fn( $m ) => $m === 'GET' ? self::reply( 404 ) : self::reply( 201 );
		$fh = fopen( self::P . '://uploads/app.txt', 'a' );
		fwrite( $fh, 'one' );
		fwrite( $fh, 'two' );
		fclose( $fh );
		$this->assertSame( 'onetwo', $this->requests( 'PUT' )[0]['args']['body'] );
	}

	// ── HTTPS forcing ───────────────────────────────────────

	public function test_cloud_urls_are_upgraded_to_https(): void {
		$this->assertSame(
			'https://wrapacct.blob.core.windows.net/media/uploads/a.jpg',
			CloudStreamWrapper::force_https_on_url( 'http://wrapacct.blob.core.windows.net/media/uploads/a.jpg' )
		);
	}

	public function test_other_hosts_are_left_alone(): void {
		$this->assertSame( 'http://example.com/a.jpg', CloudStreamWrapper::force_https_on_url( 'http://example.com/a.jpg' ) );
		$this->assertSame( '', CloudStreamWrapper::force_https_on_url( '' ) );
		$this->assertNull( CloudStreamWrapper::force_https_on_url( null ) );
	}

	public function test_upgrade_is_off_when_the_setting_is_off(): void {
		$this->config( 'wrapacct', array(), false );
		$this->resetHostCache();
		$this->assertSame( 'http://wrapacct.blob.core.windows.net/x', CloudStreamWrapper::force_https_on_url( 'http://wrapacct.blob.core.windows.net/x' ) );
	}

	public function test_no_provider_means_no_upgrade(): void {
		$GLOBALS['_test_wp_options']['diluxone_offload_config'] = array( 'cloud_provider' => '', 'provider_config' => array(), 'force_https_on_cloud' => true );
		$this->resetHostCache();
		$this->assertSame( 'http://x/y', CloudStreamWrapper::force_https_on_url( 'http://x/y' ) );
	}

	public function test_srcset_sources_are_upgraded_individually(): void {
		$in  = array(
			300 => array( 'url' => 'http://wrapacct.blob.core.windows.net/a-300.jpg', 'descriptor' => 'w', 'value' => 300 ),
			600 => array( 'url' => 'http://elsewhere.test/a-600.jpg', 'descriptor' => 'w', 'value' => 600 ),
			'bad' => 'not an array',
		);
		$out = CloudStreamWrapper::force_https_on_srcset( $in );
		$this->assertSame( 'https://wrapacct.blob.core.windows.net/a-300.jpg', $out[300]['url'] );
		$this->assertSame( 'http://elsewhere.test/a-600.jpg', $out[600]['url'] );
		$this->assertSame( 'not an array', $out['bad'] );
		$this->assertSame( 'x', CloudStreamWrapper::force_https_on_srcset( 'x' ) );
	}

	public function test_force_https_filters_register_and_unregister(): void {
		CloudStreamWrapper::register_force_https_filters();
		$this->assertNotEmpty( $GLOBALS['_test_wp_hooks']['filter']['wp_get_attachment_url'] );
		$this->assertNotEmpty( $GLOBALS['_test_wp_hooks']['filter']['wp_calculate_image_srcset'] );
		CloudStreamWrapper::unregister_force_https_filters();
		$this->assertEmpty( $GLOBALS['_test_wp_hooks']['filter']['wp_get_attachment_url'] );
	}

	// ── tear_down for core/plugin updates ───────────────────

	public function test_tear_down_removes_the_upload_dir_filter(): void {
		CloudStreamWrapper::activate_offloading();
		$this->assertNotEmpty( $GLOBALS['_test_wp_hooks']['filter']['upload_dir'] );
		CloudStreamWrapper::tear_down();
		$this->assertEmpty( $GLOBALS['_test_wp_hooks']['filter']['upload_dir'] );
	}

	public function test_activating_twice_keeps_a_single_upload_dir_filter(): void {
		CloudStreamWrapper::activate_offloading();
		CloudStreamWrapper::activate_offloading();
		$this->assertCount( 1, $GLOBALS['_test_wp_hooks']['filter']['upload_dir'] );
	}
}
