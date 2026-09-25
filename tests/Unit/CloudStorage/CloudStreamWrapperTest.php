<?php
namespace Tests\Unit\CloudStorage;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\CloudStreamWrapper;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\Enums\PluginState;

/**
 * Unit tests for the stream wrapper — the feature the plugin exists for.
 *
 * WordPress writes to diluxoneoffload://uploads/... and reads it back through
 * plain PHP filesystem calls; the wrapper turns those into blob operations.
 * These tests drive it the same way WordPress does — file_put_contents,
 * file_get_contents, file_exists, unlink, filesize — with the HTTP layer
 * scripted, and assert on what reached "Azure" and what PHP got back.
 *
 * The provider is a real AzureProvider built by ConfigManager from a saved
 * config, so the whole chain from stream call to signed request is covered.
 */
class CloudStreamWrapperTest extends TestCase {

	private const PROTOCOL = 'diluxoneoffload';

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

		// The default WordPress layout, stated rather than assumed: the code
		// asks wp_upload_dir() where uploads are, so the test has to say.
		$GLOBALS['_test_wp_upload_dir'] = WP_CONTENT_DIR . '/uploads';

		// A configured Azure provider. The key is stored plain: Crypto treats an
		// unprefixed value as plaintext, which keeps the test independent of salts.
		$GLOBALS['_test_wp_options']['diluxone_offload_config'] = array(
			'cloud_provider'  => 'azure',
			'provider_config' => array(
				'storage_account' => 'wrapacct',
				'container_name'  => 'media',
				'access_key'      => base64_encode( random_bytes( 32 ) ),
			),
		);
		$GLOBALS['_test_wp_options']['diluxone_offload_plugin_state'] = PluginState::SYNCED;

		CloudStreamWrapper::clear_stat_cache();
		CloudStreamWrapper::clear_file_cache();
		$this->assertTrue( CloudStreamWrapper::register(), 'wrapper must register' );
	}

	protected function tearDown(): void {
		CloudStreamWrapper::unregister();
		CloudStreamWrapper::clear_stat_cache();
		CloudStreamWrapper::clear_file_cache();
		unset( $GLOBALS['_test_wp_http'], $GLOBALS['_test_wp_http_log'], $GLOBALS['_test_wp_transients'], $GLOBALS['_test_wp_options'], $GLOBALS['_test_wp_hooks'] );
		parent::tearDown();
	}

	// ── Helpers ─────────────────────────────────────────────

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
	private function requests( string $method = '' ): array {
		$all = $GLOBALS['_test_wp_http_log'] ?? array();
		return $method === '' ? $all : array_values( array_filter( $all, fn( $r ) => $r['method'] === $method ) );
	}

	// ── Registration ────────────────────────────────────────

	public function test_protocol_is_registered_with_php(): void {
		$this->assertContains( self::PROTOCOL, stream_get_wrappers() );
		$this->assertTrue( CloudStreamWrapper::is_active() );
	}

	public function test_unregister_removes_the_protocol(): void {
		CloudStreamWrapper::unregister();
		$this->assertNotContains( self::PROTOCOL, stream_get_wrappers() );
		$this->assertFalse( CloudStreamWrapper::is_active() );
	}

	public function test_register_is_idempotent(): void {
		$this->assertTrue( CloudStreamWrapper::register() );
		$this->assertSame( 1, count( array_keys( stream_get_wrappers(), self::PROTOCOL, true ) ) );
	}

	// ── Writing: file_put_contents → PUT ────────────────────

	public function test_writing_a_file_uploads_it_as_a_block_blob(): void {
		$this->answer( fn() => self::reply( 201 ) );

		$bytes = file_put_contents( self::PROTOCOL . '://uploads/2026/01/hello.txt', 'hello cloud' );

		$this->assertSame( 11, $bytes );
		$puts = $this->requests( 'PUT' );
		$this->assertCount( 1, $puts, 'exactly one upload' );
		$this->assertStringEndsWith( '/media/uploads/2026/01/hello.txt', $puts[0]['url'] );
		$this->assertSame( 'hello cloud', $puts[0]['args']['body'] );
		$this->assertSame( 'BlockBlob', $puts[0]['args']['headers']['x-ms-blob-type'] );
	}

	public function test_content_type_is_derived_from_the_destination_name(): void {
		$this->answer( fn() => self::reply( 201 ) );
		file_put_contents( self::PROTOCOL . '://uploads/style.css', 'body{}' );
		$this->assertSame( 'text/css', $this->requests( 'PUT' )[0]['args']['headers']['Content-Type'] );
	}

	public function test_a_written_file_is_readable_back_from_cache_without_a_download(): void {
		$this->answer( fn() => self::reply( 201 ) );
		file_put_contents( self::PROTOCOL . '://uploads/cached.txt', 'round trip' );

		$this->answer( fn() => self::reply( 500 ) ); // any GET now would fail loudly
		$this->assertSame( 'round trip', file_get_contents( self::PROTOCOL . '://uploads/cached.txt' ) );
		$this->assertCount( 0, $this->requests( 'GET' ), 'served from the write-through cache' );
	}

	// ── Reading: file_get_contents → GET (streamed) ─────────

	public function test_reading_downloads_the_blob(): void {
		$this->answer(
			fn( $m ) => $m === 'GET' ? self::reply( 200, 'blob bytes' ) : self::reply( 200 )
		);
		$this->assertSame( 'blob bytes', file_get_contents( self::PROTOCOL . '://uploads/2026/01/img.jpg' ) );
		$get = $this->requests( 'GET' )[0];
		$this->assertStringEndsWith( '/media/uploads/2026/01/img.jpg', $get['url'] );
	}

	public function test_reading_a_missing_blob_fails_without_throwing(): void {
		$this->answer( fn() => self::reply( 404 ) );
		$this->assertFalse( @file_get_contents( self::PROTOCOL . '://uploads/missing.jpg' ) );
	}

	public function test_second_read_of_the_same_file_hits_the_cache(): void {
		$gets = 0;
		$this->answer(
			function ( $m ) use ( &$gets ) {
				if ( $m === 'GET' ) { ++$gets; }
				return self::reply( 200, 'once' );
			}
		);
		file_get_contents( self::PROTOCOL . '://uploads/once.txt' );
		file_get_contents( self::PROTOCOL . '://uploads/once.txt' );
		$this->assertSame( 1, $gets );
	}

	// ── stat: file_exists / filesize → HEAD ─────────────────

	public function test_file_exists_asks_with_head_and_caches_the_answer(): void {
		$heads = 0;
		$this->answer(
			function ( $m ) use ( &$heads ) {
				if ( $m === 'HEAD' ) { ++$heads; }
				return self::reply( 200, '', array( 'content-length' => '321', 'last-modified' => 'Mon, 01 Jan 2026 00:00:00 GMT' ) );
			}
		);
		$p = self::PROTOCOL . '://uploads/2026/01/exists.jpg';
		$this->assertTrue( file_exists( $p ) );
		$this->assertTrue( file_exists( $p ) );
		$this->assertSame( 1, $heads, 'stat is cached per request' );
	}

	public function test_a_missing_blob_does_not_exist(): void {
		$this->answer( fn() => self::reply( 404 ) );
		$this->assertFalse( file_exists( self::PROTOCOL . '://uploads/nope.jpg' ) );
	}

	/** Directories have no extension; WordPress stats them constantly. No request. */
	public function test_a_directory_path_is_a_directory_without_a_request(): void {
		$this->answer( fn() => self::reply( 500 ) );
		$this->assertTrue( is_dir( self::PROTOCOL . '://uploads/2026/01' ) );
		$this->assertSame( array(), $this->requests() );
	}

	// ── unlink → DELETE ─────────────────────────────────────

	public function test_unlink_deletes_the_blob(): void {
		$this->answer( fn() => self::reply( 202 ) );
		$this->assertTrue( unlink( self::PROTOCOL . '://uploads/gone.jpg' ) );
		$this->assertStringEndsWith( '/media/uploads/gone.jpg', $this->requests( 'DELETE' )[0]['url'] );
	}

	/** After unlink the file must read as absent even if the cloud says otherwise. */
	public function test_unlink_marks_the_path_as_absent_in_the_stat_cache(): void {
		$this->answer( fn() => self::reply( 202 ) );
		unlink( self::PROTOCOL . '://uploads/gone.jpg' );
		$this->answer( fn() => self::reply( 200, '', array( 'content-length' => '1' ) ) );
		$this->assertFalse( file_exists( self::PROTOCOL . '://uploads/gone.jpg' ) );
	}

	public function test_unlink_of_a_missing_blob_still_succeeds(): void {
		$this->answer( fn() => self::reply( 404 ) );
		$this->assertTrue( unlink( self::PROTOCOL . '://uploads/already-gone.jpg' ), 'goal is "file does not exist" — achieved' );
	}

	// ── mkdir / rmdir are virtual on object storage ─────────

	public function test_mkdir_succeeds_without_a_request(): void {
		$this->answer( fn() => self::reply( 500 ) );
		$this->assertTrue( mkdir( self::PROTOCOL . '://uploads/2026/02', 0755, true ) );
		$this->assertSame( array(), $this->requests() );
	}

	// ── Connection health: no local fallback ────────────────

	/**
	 * With three consecutive failures recorded, a write is refused at open:
	 * the caller gets false and reports it, nothing goes to the cloud, and
	 * nothing is written to the server. The plugin never writes a file of
	 * its own to disk, whatever the state of the connection.
	 */
	public function test_writes_are_refused_while_the_connection_is_down_and_nothing_lands_on_disk(): void {
		$GLOBALS['_test_wp_options']['diluxone_offload_connection_health'] = array(
			'status'               => 'unhealthy',
			'consecutive_failures' => 3,
			'error_code'           => '500',
			'last_check'           => time(),
		);
		$this->answer( fn() => self::reply( 500 ) );

		$ok = @file_put_contents( self::PROTOCOL . '://uploads/2026/03/refused.txt', 'never saved' );

		$this->assertFalse( $ok );
		$this->assertCount( 0, $this->requests( 'PUT' ), 'must not try the cloud' );
		$this->assertFileDoesNotExist( WP_CONTENT_DIR . '/uploads/2026/03/refused.txt' );
		$this->assertArrayNotHasKey( 'diluxone_offload_fallback_uploads', $GLOBALS['_test_wp_transients'] ?? array() );
	}

	// ── upload_dir filter ───────────────────────────────────

	public function test_filter_upload_dir_rewrites_paths_to_the_protocol_and_urls_to_the_cloud(): void {
		$in = array(
			'path'    => WP_CONTENT_DIR . '/uploads/2026/01',
			'url'     => 'http://site.test/wp-content/uploads/2026/01',
			'subdir'  => '/2026/01',
			'basedir' => WP_CONTENT_DIR . '/uploads',
			'baseurl' => 'http://site.test/wp-content/uploads',
			'error'   => false,
		);
		$out = CloudStreamWrapper::filter_upload_dir( $in );

		$this->assertSame( self::PROTOCOL . '://uploads/2026/01', $out['path'] );
		$this->assertSame( self::PROTOCOL . '://uploads', $out['basedir'] );
		$this->assertSame( 'https://wrapacct.blob.core.windows.net/media/uploads/2026/01', $out['url'] );
		$this->assertSame( 'https://wrapacct.blob.core.windows.net/media/uploads', $out['baseurl'] );
	}

	/** Core updates unpack into wp-content/upgrade; that must stay on disk. */
	public function test_filter_upload_dir_leaves_the_upgrade_directory_alone(): void {
		$in  = array( 'path' => WP_CONTENT_DIR . '/upgrade/x', 'basedir' => WP_CONTENT_DIR . '/upgrade', 'url' => 'u', 'baseurl' => 'b' );
		$this->assertSame( $in, CloudStreamWrapper::filter_upload_dir( $in ) );
	}

	// ── activate / deactivate ───────────────────────────────

	public function test_activate_offloading_from_synced_hooks_upload_dir_and_flips_state(): void {
		$this->assertTrue( CloudStreamWrapper::activate_offloading() );
		$this->assertSame( PluginState::OFFLOADING_ACTIVE, ConfigManager::get_state() );
		$this->assertNotEmpty( $GLOBALS['_test_wp_hooks']['filter']['upload_dir'] ?? array(), 'upload_dir filter registered' );
	}

	public function test_activate_offloading_refuses_from_not_configured(): void {
		$GLOBALS['_test_wp_options']['diluxone_offload_plugin_state'] = PluginState::NOT_CONFIGURED;
		$this->assertFalse( CloudStreamWrapper::activate_offloading() );
		$this->assertSame( PluginState::NOT_CONFIGURED, ConfigManager::get_state() );
	}

	public function test_deactivate_offloading_returns_to_synced(): void {
		CloudStreamWrapper::activate_offloading();
		CloudStreamWrapper::deactivate_offloading();
		$this->assertSame( PluginState::SYNCED, ConfigManager::get_state() );
	}
}
