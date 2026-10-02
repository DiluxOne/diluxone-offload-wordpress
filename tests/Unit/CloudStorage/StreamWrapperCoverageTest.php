<?php
namespace Tests\Unit\CloudStorage;

use DiluxOneOffload\CloudStreamWrapper;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\Enums\PluginState;
use DiluxOneOffload\Interfaces\CloudStorageClientInterface;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The stream wrapper's remaining contracts: a client that throws instead of
 * answering (the real providers catch their transport errors, so only a
 * client of another shape reaches these paths), a client that disappears
 * between open and upload, the cache and stat transfer on rename, the
 * native-basedir helper and the methods PHP may call on a stream without a
 * handle.
 */
class StreamWrapperCoverageTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const P = 'diluxoneoffload';

	protected function setUp(): void {
		parent::setUp();
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/dlx-wp-content' );
		}
		if ( ! defined( 'DAY_IN_SECONDS' ) ) {
			define( 'DAY_IN_SECONDS', 86400 );
		}
		$GLOBALS['_test_wp_options']    = array(
			'diluxone_offload_config'       => array(
				'cloud_provider'  => 'azure',
				'provider_config' => array( 'storage_account' => 'covacct', 'container_name' => 'media', 'access_key' => base64_encode( random_bytes( 32 ) ) ),
			),
			'diluxone_offload_plugin_state' => PluginState::SYNCED,
		);
		$GLOBALS['_test_wp_transients'] = array();
		$GLOBALS['_test_wp_http_log']   = array();
		$GLOBALS['_test_wp_hooks']      = array();
		$GLOBALS['_test_wp_upload_dir'] = WP_CONTENT_DIR . '/uploads';
		unset( $GLOBALS['_test_wp_http'] );
		CloudStreamWrapper::clear_stat_cache();
		CloudStreamWrapper::clear_file_cache();
		$this->setClient( null );
		CloudStreamWrapper::register();
	}

	protected function tearDown(): void {
		CloudStreamWrapper::unregister();
		$this->setClient( null );
		CloudStreamWrapper::clear_stat_cache();
		CloudStreamWrapper::clear_file_cache();
		unset( $GLOBALS['_test_wp_http'], $GLOBALS['_test_wp_http_log'], $GLOBALS['_test_wp_transients'], $GLOBALS['_test_wp_options'], $GLOBALS['_test_wp_hooks'], $GLOBALS['_test_wp_upload_dir'] );
		parent::tearDown();
	}

	// ── Helpers ─────────────────────────────────────────────

	/** @param mixed $client */
	private function setClient( $client ): void {
		self::setStatic( 'cloud_client', $client );
	}

	/** @param mixed $value */
	private static function setStatic( string $name, $value ): void {
		$p = new \ReflectionProperty( CloudStreamWrapper::class, $name );
		if ( PHP_VERSION_ID < 80100 ) { // Required before 8.1, deprecated from 8.5.
			$p->setAccessible( true );
		}
		$p->setValue( null, $value );
	}

	/** @return mixed */
	private static function getStatic( string $name ) {
		$p = new \ReflectionProperty( CloudStreamWrapper::class, $name );
		if ( PHP_VERSION_ID < 80100 ) {
			$p->setAccessible( true );
		}
		return $p->getValue();
	}

	/** @param mixed $value */
	private static function setProp( object $obj, string $name, $value ): void {
		$p = new \ReflectionProperty( $obj, $name );
		if ( PHP_VERSION_ID < 80100 ) {
			$p->setAccessible( true );
		}
		$p->setValue( $obj, $value );
	}

	/** @return mixed */
	private static function getProp( object $obj, string $name ) {
		$p = new \ReflectionProperty( $obj, $name );
		if ( PHP_VERSION_ID < 80100 ) {
			$p->setAccessible( true );
		}
		return $p->getValue( $obj );
	}

	/** @return \Mockery\MockInterface&CloudStorageClientInterface */
	private function mockClient() {
		$client = Mockery::mock( CloudStorageClientInterface::class );
		$this->setClient( $client );
		return $client;
	}

	private static function reply( int $code, string $body = '' ): array {
		return array( 'response' => array( 'code' => $code, 'message' => '' ), 'body' => $body, 'headers' => array() );
	}

	/** @return array<int, array{method:string,url:string,args:array}> */
	private function requests( string $method ): array {
		return array_values( array_filter( $GLOBALS['_test_wp_http_log'] ?? array(), fn( $r ) => $r['method'] === $method ) );
	}

	// ── fail_upload_if_write_failed ─────────────────────────

	public function test_an_upload_into_the_cloud_with_no_recorded_failure_is_passed_through(): void {
		$upload = array( 'file' => self::P . '://uploads/2026/10/ok.jpg', 'url' => 'https://x/ok.jpg', 'type' => 'image/jpeg' );

		$this->assertSame( $upload, CloudStreamWrapper::fail_upload_if_write_failed( $upload ) );
	}

	public function test_a_local_upload_is_never_turned_into_a_failure(): void {
		$upload = array( 'file' => '/var/www/uploads/a.jpg', 'url' => 'https://x/a.jpg', 'type' => 'image/jpeg' );

		$this->assertSame( $upload, CloudStreamWrapper::fail_upload_if_write_failed( $upload ) );
	}

	public function test_an_upload_wordpress_already_failed_keeps_its_own_error(): void {
		$upload = array( 'error' => 'Sorry, you are not allowed to upload this file type.' );

		$this->assertSame( $upload, CloudStreamWrapper::fail_upload_if_write_failed( $upload ) );
		$this->assertSame( array( 'file' => '' ), CloudStreamWrapper::fail_upload_if_write_failed( array( 'file' => '' ) ) );
	}

	// ── filter_upload_dir ───────────────────────────────────

	public function test_filter_upload_dir_keeps_the_native_urls_when_the_client_throws_on_a_url(): void {
		$client = $this->mockClient();
		$client->shouldReceive( 'get_file_url' )->andThrow( new \RuntimeException( 'no url for you' ) );

		$in  = array(
			'path'    => '/srv/uploads/2026/10',
			'url'     => 'http://site.test/wp-content/uploads/2026/10',
			'subdir'  => '/2026/10',
			'basedir' => '/srv/uploads',
			'baseurl' => 'http://site.test/wp-content/uploads',
			'error'   => false,
		);
		$out = CloudStreamWrapper::filter_upload_dir( $in );

		$this->assertSame( self::P . '://uploads/2026/10', $out['path'], 'paths still go to the cloud' );
		$this->assertSame( self::P . '://uploads', $out['basedir'] );
		$this->assertSame( $in['url'], $out['url'], 'a URL the client could not build falls back to the native one' );
		$this->assertSame( $in['baseurl'], $out['baseurl'] );
	}

	public function test_filter_upload_dir_with_the_path_equal_to_the_basedir_maps_to_the_prefix(): void {
		$client = $this->mockClient();
		$client->shouldReceive( 'get_file_url' )->with( 'uploads' )->andReturn( 'https://cdn.test/uploads' );

		// WordPress at the root of uploads (no year/month folders), its basedir spelt with a trailing slash.
		$out = CloudStreamWrapper::filter_upload_dir( array( 'path' => '/srv/uploads', 'basedir' => '/srv/uploads/', 'url' => '', 'baseurl' => '' ) );

		$this->assertSame( self::P . '://uploads', $out['path'] );
		$this->assertSame( 'https://cdn.test/uploads', $out['url'] );
		$this->assertSame( 'https://cdn.test/uploads', $out['baseurl'] );
	}

	public function test_filter_upload_dir_with_a_path_outside_the_basedir_strips_nothing_into_the_key(): void {
		$client = $this->mockClient();
		$client->shouldReceive( 'get_file_url' )->with( 'uploads' )->andReturn( 'https://cdn.test/uploads' );

		// A plugin moved 'path' somewhere unrelated: no part of it may leak into an object key.
		$out = CloudStreamWrapper::filter_upload_dir( array( 'path' => '/elsewhere/2026/10', 'basedir' => '/srv/uploads', 'url' => '', 'baseurl' => '' ) );

		$this->assertSame( self::P . '://uploads', $out['path'] );
		$this->assertSame( 'https://cdn.test/uploads', $out['url'] );
	}

	// ── native_upload_basedir ───────────────────────────────

	public function test_native_upload_basedir_without_the_filter_is_wordpress_basedir_untrailed(): void {
		$GLOBALS['_test_wp_upload_dir'] = WP_CONTENT_DIR . '/uploads/';

		$this->assertSame( WP_CONTENT_DIR . '/uploads', CloudStreamWrapper::native_upload_basedir() );
		$this->assertFalse( has_filter( 'upload_dir', array( CloudStreamWrapper::class, 'filter_upload_dir' ) ), 'it must not add a filter that was not there' );
	}

	public function test_native_upload_basedir_steps_the_filter_aside_and_restores_it_at_its_priority(): void {
		$cb = array( CloudStreamWrapper::class, 'filter_upload_dir' );
		add_filter( 'upload_dir', $cb, 15, 1 );

		$this->assertSame( WP_CONTENT_DIR . '/uploads', CloudStreamWrapper::native_upload_basedir() );

		$registered = array_values( array_filter( $GLOBALS['_test_wp_hooks']['filter']['upload_dir'], fn( $h ) => $h['callback'] == $cb ) );
		$this->assertCount( 1, $registered, 'exactly one copy of the filter afterwards' );
		$this->assertSame( 15, $registered[0]['priority'], 'back at the priority it held' );
	}

	// ── Downloads that throw ────────────────────────────────

	public function test_a_read_whose_download_throws_fails_and_removes_its_temp_file(): void {
		$client = $this->mockClient();
		$temp   = null;
		$client->shouldReceive( 'download_file' )->once()->andReturnUsing(
			function ( $remote, $local ) use ( &$temp ) {
				$temp = $local;
				file_put_contents( $local, 'partial' );
				throw new \RuntimeException( 'socket closed' );
			}
		);

		$this->assertFalse( @fopen( self::P . '://uploads/thrown.txt', 'r' ) );
		$this->assertNotNull( $temp );
		$this->assertFileDoesNotExist( $temp, 'the half-downloaded temp file is cleaned up' );
	}

	public function test_an_append_whose_download_throws_refuses_to_open_rather_than_starting_empty(): void {
		$client = $this->mockClient();
		$client->shouldReceive( 'download_file' )->once()->andThrow( new \RuntimeException( 'socket closed' ) );
		$client->shouldNotReceive( 'upload_file' );

		$this->assertFalse( @fopen( self::P . '://uploads/log.txt', 'a' ) );
	}

	// ── Uploads ─────────────────────────────────────────────

	public function test_a_flush_without_new_bytes_succeeds_without_uploading(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 201 );

		$w = new CloudStreamWrapper();
		$opened = null;
		$this->assertTrue( $w->stream_open( self::P . '://uploads/clean.txt', 'w', 0, $opened ) );

		$this->assertTrue( $w->stream_flush(), 'nothing written: nothing to send, and nothing failed' );
		$this->assertCount( 0, $this->requests( 'PUT' ) );
		$w->stream_close();
	}

	public function test_an_upload_fails_and_is_reported_when_the_client_is_gone_by_flush_time(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 201 );
		$fh = fopen( self::P . '://uploads/gone.txt', 'w' );
		fwrite( $fh, 'data' );

		// The provider is removed between the open and the upload.
		$GLOBALS['_test_wp_options']['diluxone_offload_config'] = array( 'cloud_provider' => '', 'provider_config' => array() );
		$this->setClient( null );

		$this->assertFalse( @fflush( $fh ) );
		fclose( $fh );

		$this->assertCount( 0, $this->requests( 'PUT' ) );
		$this->assertSame( 'Cloud client not available', CloudStreamWrapper::take_write_failure( 'uploads/gone.txt' ) );
	}

	public function test_an_upload_fails_when_its_temp_file_has_vanished(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 201 );
		$w      = new CloudStreamWrapper();
		$opened = null;
		$this->assertTrue( $w->stream_open( self::P . '://uploads/vanished.txt', 'w', 0, $opened ) );
		$this->assertSame( 4, $w->stream_write( 'data' ) );

		// Something (a temp-dir cleaner) removes the file under the open handle.
		unlink( (string) self::getProp( $w, 'temp_file' ) );

		$this->assertFalse( @$w->stream_flush() );
		$this->assertCount( 0, $this->requests( 'PUT' ), 'nothing is sent when the file cannot be sized' );
		$this->assertSame( 'Could not read the file to upload', CloudStreamWrapper::take_write_failure( self::P . '://uploads/vanished.txt' ) );
		$w->stream_close();
	}

	public function test_an_upload_that_throws_fails_records_an_exception_and_is_reported_to_wordpress(): void {
		$client = $this->mockClient();
		$client->shouldReceive( 'upload_file' )->once()->andThrow( new \RuntimeException( 'TLS handshake failed' ) );

		$fh = fopen( self::P . '://uploads/2026/10/photo.jpg', 'w' );
		fwrite( $fh, 'jpegbytes' );
		$this->assertFalse( @fflush( $fh ) );
		$this->assertTrue( fclose( $fh ), 'close does not retry the failed upload (once() above) and never throws' );

		$health = ConfigManager::get_connection_health();
		$this->assertSame( 'exception', $health['error_code'] );
		$this->assertSame( 'upload', $health['error_source'] );
		$this->assertStringContainsString( 'TLS handshake failed', $health['error_message'] );

		$result = CloudStreamWrapper::fail_upload_if_write_failed( array( 'file' => self::P . '://uploads/2026/10/photo.jpg', 'url' => 'u', 'type' => 'image/jpeg' ), 'sideload' );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'TLS handshake failed', $result['error'] );
	}

	public function test_close_uploads_pending_bytes_when_php_did_not_flush_first(): void {
		$client = $this->mockClient();
		$sent   = null;
		$client->shouldReceive( 'upload_file' )->once()->andReturnUsing(
			function ( $local, $remote, $opts ) use ( &$sent ) {
				$sent = array( file_get_contents( $local ), $remote, $opts );
				return array( 'success' => true );
			}
		);

		$w      = new CloudStreamWrapper();
		$opened = null;
		$this->assertTrue( $w->stream_open( self::P . '://uploads/late.css', 'w', 0, $opened ) );
		$w->stream_write( 'body{}' );
		$temp = (string) self::getProp( $w, 'temp_file' );

		$this->assertTrue( $w->stream_close() );

		$this->assertSame( array( 'body{}', 'uploads/late.css', array( 'mime_type_from_path' => 'uploads/late.css' ) ), $sent );
		$this->assertFileDoesNotExist( $temp, 'the temp file goes with the handle' );
		$this->assertNull( CloudStreamWrapper::take_write_failure( 'uploads/late.css' ) );
	}

	// ── Methods PHP may call on a stream without a handle ───

	public function test_a_stream_without_a_handle_reads_nothing_writes_nothing_and_is_at_zero(): void {
		$w = new CloudStreamWrapper();

		$this->assertSame( '', $w->stream_read( 10 ) );
		$this->assertSame( 0, $w->stream_write( 'x' ) );
		$this->assertSame( 0, $w->stream_tell() );
		$this->assertFalse( $w->stream_stat() );
		$this->assertTrue( $w->stream_eof() );
		$this->assertFalse( $w->stream_seek( 0 ) );
	}

	public function test_a_read_of_zero_bytes_returns_an_empty_string(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 200, 'abc' );
		$w      = new CloudStreamWrapper();
		$opened = null;
		$this->assertTrue( $w->stream_open( self::P . '://uploads/zero.txt', 'r', 0, $opened ) );

		$this->assertSame( '', $w->stream_read( 0 ) );
		$this->assertSame( 'abc', $w->stream_read( 10 ), 'the zero-byte read did not move the position' );
		$w->stream_close();
	}

	public function test_a_write_the_local_file_refuses_reports_zero_bytes_and_leaves_the_stream_clean(): void {
		$w    = new CloudStreamWrapper();
		$file = tempnam( sys_get_temp_dir(), 'dlxro' );
		$ro   = fopen( $file, 'rb' );
		self::setProp( $w, 'handle', $ro );

		$this->assertSame( 0, @$w->stream_write( 'x' ) );
		$this->assertFalse( self::getProp( $w, 'dirty' ), 'a refused write must not trigger an upload' );

		fclose( $ro );
		unlink( $file );
	}

	public function test_flush_of_a_write_stream_with_no_handle_fails(): void {
		$w = new CloudStreamWrapper();
		self::setProp( $w, 'mode', 'w' );

		$this->assertFalse( $w->stream_flush() );
	}

	public function test_flush_of_a_handle_that_has_no_temp_file_only_flushes_locally(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 201 );
		$w    = new CloudStreamWrapper();
		$file = tempnam( sys_get_temp_dir(), 'dlxfl' );
		$fh   = fopen( $file, 'w+b' );
		self::setProp( $w, 'mode', 'w' );
		self::setProp( $w, 'handle', $fh );
		self::setProp( $w, 'dirty', true );

		$this->assertTrue( $w->stream_flush() );
		$this->assertCount( 0, $this->requests( 'PUT' ), 'without its temp file the stream cannot upload' );

		fclose( $fh );
		unlink( $file );
	}

	public function test_stream_exists_assumes_the_path_is_there(): void {
		$this->assertTrue( ( new CloudStreamWrapper() )->stream_exists( self::P . '://uploads/any.jpg' ) );
		$this->assertCount( 0, $GLOBALS['_test_wp_http_log'], 'no HEAD request is paid for it' );
	}

	// ── stat ────────────────────────────────────────────────

	public function test_file_exists_is_quietly_false_when_the_client_throws_and_the_answer_is_cached(): void {
		$client = $this->mockClient();
		$client->shouldReceive( 'get_file_info' )->once()->andThrow( new \RuntimeException( 'HEAD timed out' ) );

		$this->assertFalse( file_exists( self::P . '://uploads/x.jpg' ) );
		$this->assertFalse( file_exists( self::P . '://uploads/x.jpg' ), 'second answer from the stat cache (once() above)' );
	}

	public function test_stat_warns_with_the_cloud_error_when_the_client_throws(): void {
		$client = $this->mockClient();
		$client->shouldReceive( 'get_file_info' )->andThrow( new \RuntimeException( 'HEAD timed out' ) );

		$warnings = array();
		set_error_handler(
			function ( $no, $str ) use ( &$warnings ) {
				$warnings[] = $str;
				return true;
			}
		);
		try {
			$result = stat( self::P . '://uploads/y.jpg' );
		} finally {
			restore_error_handler();
		}

		$this->assertFalse( $result );
		$this->assertContains( 'Cloud error: HEAD timed out', $warnings, 'WP_DEBUG is on in the unit suite: the detail is shown' );
	}

	/** filesize() and filemtime() of an object read its size and date from the one HEAD request stat() makes. */
	public function test_stat_of_an_object_reports_its_size_and_modification_time(): void {
		$client = $this->mockClient();
		$client->shouldReceive( 'get_file_info' )->once()->with( 'uploads/2026/09/old.jpg' )->andReturn(
			array( 'size' => 48213, 'md5' => null, 'last_modified' => 'Tue, 01 Sep 2026 10:00:00 GMT' )
		);

		$this->assertSame( 48213, filesize( self::P . '://uploads/2026/09/old.jpg' ) );
		$this->assertSame( strtotime( 'Tue, 01 Sep 2026 10:00:00 GMT' ), filemtime( self::P . '://uploads/2026/09/old.jpg' ), 'from the stat cache, no second HEAD' );
		$this->assertTrue( is_file( self::P . '://uploads/2026/09/old.jpg' ) );
	}

	public function test_a_quiet_link_stat_of_a_missing_blob_is_an_empty_stat_not_a_failure(): void {
		$client = $this->mockClient();
		$client->shouldReceive( 'get_file_info' )->with( 'uploads/missing.jpg' )->andReturn( false );

		$stat = ( new CloudStreamWrapper() )->url_stat( self::P . '://uploads/missing.jpg', STREAM_URL_STAT_QUIET | STREAM_URL_STAT_LINK );

		$this->assertIsArray( $stat, 'lstat()-style callers get a stat they can read' );
		$this->assertSame( 0, $stat['mode'], 'neither a file nor a directory' );
		$this->assertSame( 0, $stat['size'] );
	}

	public function test_url_stat_without_a_client_warns_and_fails(): void {
		$GLOBALS['_test_wp_options']['diluxone_offload_config'] = array( 'cloud_provider' => '', 'provider_config' => array() );

		$this->assertFalse( @( new CloudStreamWrapper() )->url_stat( self::P . '://uploads/n.jpg', 0 ) );
		$this->assertFalse( ( new CloudStreamWrapper() )->url_stat( self::P . '://uploads/n2.jpg', STREAM_URL_STAT_QUIET ) );
	}

	// ── unlink ──────────────────────────────────────────────

	public function test_unlink_whose_delete_throws_still_succeeds_and_forgets_the_cached_file(): void {
		$client = $this->mockClient();
		$client->shouldReceive( 'upload_file' )->andReturn( array( 'success' => true ) );
		$client->shouldReceive( 'delete_file' )->once()->with( 'uploads/del.txt' )->andThrow( new \RuntimeException( 'reset by peer' ) );
		$client->shouldNotReceive( 'get_file_info' );

		file_put_contents( self::P . '://uploads/del.txt', 'cached' );
		$this->assertArrayHasKey( 'uploads/del.txt', self::getStatic( 'file_cache' ) );

		$this->assertTrue( unlink( self::P . '://uploads/del.txt' ) );

		$this->assertArrayNotHasKey( 'uploads/del.txt', self::getStatic( 'file_cache' ) );
		$this->assertFalse( self::getStatic( 'stat_cache' )['uploads/del.txt'] );
		$this->assertFalse( file_exists( self::P . '://uploads/del.txt' ), 'answered from the stat cache, no HEAD' );
	}

	public function test_a_successful_unlink_tells_the_tracking_table_and_still_succeeds_without_a_database(): void {
		// The wrapper only reaches the tracking table when its class is loaded;
		// in the unit suite there is no $wpdb, so forgetting is a no-op.
		require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';
		$client = $this->mockClient();
		$client->shouldReceive( 'delete_file' )->once()->with( 'uploads/2026/10/a.jpg' )->andReturn( array( 'success' => true ) );

		$this->assertTrue( unlink( self::P . '://uploads/2026/10/a.jpg' ) );
		$this->assertFalse( self::getStatic( 'stat_cache' )['uploads/2026/10/a.jpg'] );
	}

	/** Deleting an attachment while its local copy is still here removes the copy too, once the object is gone. */
	public function test_a_successful_unlink_removes_the_local_copy_of_this_site_only(): void {
		$base = WP_CONTENT_DIR . '/uploads';
		@mkdir( $base . '/2026/10', 0777, true );
		file_put_contents( $base . '/2026/10/copy.jpg', 'local bytes' );
		file_put_contents( $base . '/2026/10/kept.jpg', 'another file' );
		$client = $this->mockClient();
		$client->shouldReceive( 'delete_file' )->with( 'uploads/2026/10/copy.jpg' )->andReturn( array( 'success' => true ) );
		$client->shouldReceive( 'delete_file' )->with( 'elsewhere/2026/10/kept.jpg' )->andReturn( array( 'success' => true ) );
		$client->shouldReceive( 'delete_file' )->with( 'uploads/../2026/10/kept.jpg' )->andReturn( array( 'success' => true ) );
		try {
			$this->assertTrue( unlink( self::P . '://uploads/2026/10/copy.jpg' ) );
			$this->assertFileDoesNotExist( $base . '/2026/10/copy.jpg', 'the local copy goes with the object' );

			unlink( self::P . '://elsewhere/2026/10/kept.jpg' );
			unlink( self::P . '://uploads/../2026/10/kept.jpg' );
			$this->assertFileExists( $base . '/2026/10/kept.jpg', 'a key outside this site, or one that climbs out of uploads/, never touches the disk' );
		} finally {
			@unlink( $base . '/2026/10/copy.jpg' );
			@unlink( $base . '/2026/10/kept.jpg' );
		}
	}

	/** A delete the provider did not confirm keeps the local copy: it may be the only one. */
	public function test_a_failed_unlink_keeps_the_local_copy(): void {
		$base = WP_CONTENT_DIR . '/uploads';
		@mkdir( $base . '/2026/10', 0777, true );
		file_put_contents( $base . '/2026/10/only.jpg', 'local bytes' );
		$client = $this->mockClient();
		$client->shouldReceive( 'delete_file' )->once()->andReturn( array( 'success' => false, 'error' => 'HTTP 503' ) );
		try {
			unlink( self::P . '://uploads/2026/10/only.jpg' );
			$this->assertFileExists( $base . '/2026/10/only.jpg' );
		} finally {
			@unlink( $base . '/2026/10/only.jpg' );
		}
	}

	public function test_unlink_without_a_client_fails(): void {
		$GLOBALS['_test_wp_options']['diluxone_offload_config'] = array( 'cloud_provider' => '', 'provider_config' => array() );

		$this->assertFalse( @unlink( self::P . '://uploads/a.txt' ) );
	}

	// ── rename ──────────────────────────────────────────────

	public function test_rename_without_a_client_fails(): void {
		$GLOBALS['_test_wp_options']['diluxone_offload_config'] = array( 'cloud_provider' => '', 'provider_config' => array() );

		$this->assertFalse( @rename( self::P . '://uploads/a.txt', self::P . '://uploads/b.txt' ) );
		$this->assertCount( 0, $GLOBALS['_test_wp_http_log'] );
	}

	public function test_rename_whose_copy_throws_fails_without_deleting_the_source(): void {
		$client = $this->mockClient();
		$client->shouldReceive( 'copy_blob' )->once()->with( 'uploads/a.txt', 'uploads/b.txt' )->andThrow( new \RuntimeException( 'copy exploded' ) );
		$client->shouldNotReceive( 'delete_file' );

		$this->assertFalse( @rename( self::P . '://uploads/a.txt', self::P . '://uploads/b.txt' ) );
	}

	public function test_rename_moves_the_cached_content_and_stat_to_the_destination_even_if_the_delete_throws(): void {
		$client = $this->mockClient();
		$client->shouldReceive( 'upload_file' )->once()->andReturn( array( 'success' => true ) );
		$client->shouldReceive( 'copy_blob' )->once()->with( 'uploads/tmp.css', 'uploads/final.css' )->andReturn( array( 'success' => true ) );
		$client->shouldReceive( 'delete_file' )->once()->with( 'uploads/tmp.css' )->andThrow( new \RuntimeException( 'delete exploded' ) );
		// The destination is served from what was moved: no HEAD, no download.
		$client->shouldNotReceive( 'get_file_info' );
		$client->shouldNotReceive( 'download_file' );

		file_put_contents( self::P . '://uploads/tmp.css', 'a{color:red}' );

		$this->assertTrue( rename( self::P . '://uploads/tmp.css', self::P . '://uploads/final.css' ) );

		$this->assertTrue( file_exists( self::P . '://uploads/final.css' ) );
		$this->assertSame( 12, filesize( self::P . '://uploads/final.css' ) );
		$this->assertSame( 'a{color:red}', file_get_contents( self::P . '://uploads/final.css' ) );
		$this->assertFalse( file_exists( self::P . '://uploads/tmp.css' ), 'the source is marked gone' );
	}

	// ── Directories ─────────────────────────────────────────

	public function test_opendir_without_a_client_fails(): void {
		$GLOBALS['_test_wp_options']['diluxone_offload_config'] = array( 'cloud_provider' => '', 'provider_config' => array() );

		$this->assertFalse( @opendir( self::P . '://uploads/2026' ) );
	}

	public function test_rewinddir_reopens_the_same_directory(): void {
		$dh = opendir( self::P . '://uploads/2026/' );
		$this->assertIsResource( $dh );
		rewinddir( $dh );
		$this->assertFalse( readdir( $dh ) );
		closedir( $dh );
	}
}
