<?php
namespace Tests\Unit\CloudStorage;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\CloudStreamWrapper;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\Enums\PluginState;

/**
 * The stream wrapper when things go wrong: no provider configured, the
 * provider throwing, downloads failing, writes refused while the connection
 * is down, stat() on missing blobs, oversized cache entries.
 */
class StreamWrapperEdgeCasesTest extends TestCase {

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

		// The default WordPress layout, stated rather than assumed: the code
		// asks wp_upload_dir() where uploads are, so the test has to say.
		$GLOBALS['_test_wp_upload_dir'] = WP_CONTENT_DIR . '/uploads';
		$this->configure();
		CloudStreamWrapper::clear_stat_cache();
		CloudStreamWrapper::clear_file_cache();
		$this->resetClient();
		CloudStreamWrapper::register();
	}

	protected function tearDown(): void {
		CloudStreamWrapper::unregister();
		$this->resetClient();
		unset( $GLOBALS['_test_wp_http'], $GLOBALS['_test_wp_http_log'], $GLOBALS['_test_wp_transients'], $GLOBALS['_test_wp_options'], $GLOBALS['_test_wp_hooks'] );
		parent::tearDown();
	}

	private function configure(): void {
		$GLOBALS['_test_wp_options']['diluxone_offload_config']       = array(
			'cloud_provider'  => 'azure',
			'provider_config' => array( 'storage_account' => 'edgeacct', 'container_name' => 'media', 'access_key' => base64_encode( random_bytes( 32 ) ) ),
		);
		$GLOBALS['_test_wp_options']['diluxone_offload_plugin_state'] = PluginState::SYNCED;
	}

	private function unconfigure(): void {
		$GLOBALS['_test_wp_options']['diluxone_offload_config'] = array( 'cloud_provider' => '', 'provider_config' => array() );
		$this->resetClient();
	}

	/** The wrapper memoises its client; each test wants a fresh one. */
	private function resetClient(): void {
		$p = new \ReflectionProperty( CloudStreamWrapper::class, 'cloud_client' );
		if ( PHP_VERSION_ID < 80100 ) { // Required before 8.1, deprecated from 8.5.
			$p->setAccessible( true );
		}
		$p->setValue( null, null );
	}

	private static function reply( int $code, string $body = '', array $headers = array() ): array {
		return array( 'response' => array( 'code' => $code, 'message' => '' ), 'body' => $body, 'headers' => $headers );
	}

	// ── Streaming: nothing holds a whole file in memory ──────

	/**
	 * The guarantee the whole change exists for: writing a file much larger
	 * than the memory we allow ourselves must not grow PHP's heap by anything
	 * like the size of the file. Before this, stream_write() concatenated
	 * every chunk into a string property and a big upload died on
	 * memory_limit.
	 */
	public function test_writing_a_large_file_does_not_hold_it_in_memory(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 201 );

		$chunk = str_repeat( 'x', 1024 * 1024 ); // 1 MB per write
		$before = memory_get_usage( true );

		$fh = fopen( self::P . '://uploads/big.bin', 'w' );
		for ( $i = 0; $i < 12; $i++ ) {
			fwrite( $fh, $chunk );
		}
		$peak = memory_get_usage( true ) - $before;
		fclose( $fh );

		$this->assertLessThan( 4 * 1024 * 1024, $peak, '12 MB written must not cost 12 MB of memory' );
	}

	/**
	 * Appending to a large blob must not read it into memory either: the
	 * download lands in the temp file, the new bytes go after it on disk, and
	 * the upload streams from there. Before this, append and r+ loaded the
	 * whole blob into a string on open and rewrote it on every flush.
	 */
	public function test_appending_to_a_large_blob_does_not_hold_it_in_memory(): void {
		$existing                 = str_repeat( 'e', 12 * 1024 * 1024 );
		$GLOBALS['_test_wp_http'] = fn( $m ) => $m === 'GET' ? self::reply( 200, $existing ) : self::reply( 201 );

		$before = memory_get_usage( true );
		$fh     = fopen( self::P . '://uploads/log.txt', 'a' );
		fwrite( $fh, 'one more line' );
		$peak = memory_get_usage( true ) - $before;
		fclose( $fh );

		$this->assertLessThan( 6 * 1024 * 1024, $peak, 'a 12 MB blob appended to must not cost 12 MB of memory' );
	}

	/**
	 * A blob past the cache ceiling is served from the temp file the download
	 * streamed into, and never read into memory — the size is asked for first,
	 * so the content is only loaded when it is small enough to be worth
	 * keeping. The read itself still works.
	 */
	public function test_a_blob_past_the_cache_ceiling_is_read_without_being_cached(): void {
		$big                      = str_repeat( 'y', CloudStreamWrapper::CACHE_MAX_BYTES + 1 );
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 200, $big );

		$fh = fopen( self::P . '://uploads/big-read.bin', 'r' );
		$this->assertNotFalse( $fh );
		$this->assertSame( str_repeat( 'y', 16 ), fread( $fh, 16 ), 'served from the streamed temp file' );
		fclose( $fh );

		$GLOBALS['_test_wp_http'] = fn() => self::reply( 200, 'from-cloud' );
		$this->assertSame( 'from-cloud', file_get_contents( self::P . '://uploads/big-read.bin' ), 'it was never cached' );
	}

	/** A download is streamed to the destination file, not buffered. */
	public function test_a_download_is_streamed_straight_to_the_destination(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 200, 'streamed-bytes' );

		$fh = fopen( self::P . '://uploads/streamed.txt', 'r' );
		$this->assertNotFalse( $fh );
		$this->assertSame( 'streamed-bytes', stream_get_contents( $fh ) );
		fclose( $fh );

		$args = $GLOBALS['_test_wp_http_log'][0]['args'];
		$this->assertTrue( $args['stream'] ?? false, 'the request asks the transport to stream' );
		$this->assertNotEmpty( $args['filename'] ?? '', 'and names the file to stream into' );
	}

	/**
	 * Streaming writes the body whatever the status is, so an Azure error
	 * document must not be left behind looking like the blob.
	 */
	public function test_a_failed_download_leaves_no_file_behind(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 403, '<Error><Code>AuthenticationFailed</Code></Error>' );

		$before = glob( sys_get_temp_dir() . '/*' ) ?: array();
		$this->assertFalse( @fopen( self::P . '://uploads/denied.txt', 'r' ) );
		$after = glob( sys_get_temp_dir() . '/*' ) ?: array();

		$this->assertSame( count( $before ), count( $after ), 'the partial file is removed' );
	}

	// ── Writes reach the cloud on every closing path ─────────

	/** file_put_contents(): fopen → fwrite → fflush → fclose. */
	public function test_a_flushed_write_is_uploaded_once(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 201 );

		$this->assertSame( 5, file_put_contents( self::P . '://uploads/flushed.txt', 'hello' ) );

		$puts = array_filter( $GLOBALS['_test_wp_http_log'], fn( $r ) => 'PUT' === $r['method'] );
		$this->assertCount( 1, $puts, 'uploaded exactly once' );
		$this->assertSame( 'hello', $puts[ array_key_first( $puts ) ]['args']['body'] );
	}

	/**
	 * fopen/fwrite/fclose with no fflush: PHP never calls stream_flush(), so
	 * stream_close() is the only chance. Getting this wrong means WordPress
	 * records an attachment for a file that never reached Azure.
	 */
	public function test_a_write_closed_without_flushing_is_still_uploaded(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 201 );

		$fh = fopen( self::P . '://uploads/unflushed.txt', 'w' );
		fwrite( $fh, 'closed without flush' );
		fclose( $fh );

		$puts = array_values( array_filter( $GLOBALS['_test_wp_http_log'], fn( $r ) => 'PUT' === $r['method'] ) );
		$this->assertCount( 1, $puts, 'the close uploaded it' );
		$this->assertSame( 'closed without flush', $puts[0]['args']['body'] );
	}

	/** Opening for writing and closing without writing uploads nothing. */
	public function test_opening_for_writing_and_writing_nothing_uploads_nothing(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 201 );

		$fh = fopen( self::P . '://uploads/untouched.txt', 'w' );
		fclose( $fh );

		$this->assertSame( array(), array_filter( $GLOBALS['_test_wp_http_log'], fn( $r ) => 'PUT' === $r['method'] ) );
	}

	/**
	 * A file past one block goes up block by block, and every block request
	 * states its own Content-Type.
	 *
	 * That header is not decoration: Azure signs it, and the WordPress HTTP
	 * API fills in application/x-www-form-urlencoded for a PUT that does not
	 * state one, which made the service answer 403 for every block. The stub
	 * answers 201 to anything, so what this test can defend is the shape of
	 * the request — verified against the real service when it was written.
	 */
	public function test_a_file_past_one_block_is_uploaded_in_blocks_that_state_their_content_type(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 201 );

		$fh = fopen( self::P . '://uploads/blocks.css', 'w' );
		for ( $i = 0; $i < 5; $i++ ) {
			fwrite( $fh, str_repeat( 'c', 1024 * 1024 ) ); // 5 MB: two blocks
		}
		fclose( $fh );

		$puts = array_values( array_filter( $GLOBALS['_test_wp_http_log'], fn( $r ) => 'PUT' === $r['method'] ) );
		$blocks = array_values( array_filter( $puts, fn( $r ) => strpos( $r['url'], 'comp=block&' ) !== false ) );
		$commit = array_values( array_filter( $puts, fn( $r ) => strpos( $r['url'], 'comp=blocklist' ) !== false ) );

		$this->assertCount( 2, $blocks, 'two blocks for 5 MB' );
		$this->assertCount( 1, $commit, 'and one commit' );

		foreach ( $blocks as $block ) {
			$this->assertNotEmpty( $block['args']['headers']['Content-Type'] ?? '', 'every block states its Content-Type' );
			$this->assertLessThanOrEqual( 4 * 1024 * 1024, strlen( $block['args']['body'] ), 'no request carries more than one block' );
		}

		// The blob's own type is set when the list is committed — this is what
		// a browser reads, and what breaks a site's CSS when it is wrong.
		$this->assertSame( 'text/css', $commit[0]['args']['headers']['x-ms-blob-content-type'] ?? '' );
	}

	/** A write stream reports the size of what has been written so far. */
	public function test_fstat_on_a_temp_backed_write_reports_the_written_size(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 201 );

		$fh = fopen( self::P . '://uploads/sized.txt', 'w' );
		fwrite( $fh, '0123456789' );
		$stat = fstat( $fh );
		fclose( $fh );

		$this->assertSame( 10, $stat['size'] );
	}

	/** A failed upload must not be reported to the caller as a success. */
	public function test_a_rejected_write_reports_failure_and_records_it(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 500, 'nope' );

		$fh = fopen( self::P . '://uploads/rejected.txt', 'w' );
		fwrite( $fh, 'x' );
		$this->assertFalse( @fflush( $fh ) );
		fclose( $fh );

		$this->assertSame( 'unhealthy', ConfigManager::get_connection_health()['status'] );
		$upload = array( 'file' => self::P . '://uploads/rejected.txt', 'url' => 'https://x/y', 'type' => 'text/plain' );
		$this->assertArrayHasKey( 'error', CloudStreamWrapper::fail_upload_if_write_failed( $upload, 'upload' ) );
	}

	private function unhealthy( int $failures = 3 ): void {
		$GLOBALS['_test_wp_options']['diluxone_offload_connection_health'] = array(
			'status'               => 'unhealthy',
			'consecutive_failures' => $failures,
			'error_code'           => '403',
			'error_message'        => 'x',
			'last_check'           => time(),
			'last_success'         => 0,
			'error_source'         => 'azure',
		);
	}

	// ── no provider ─────────────────────────────────────────

	public function test_nothing_works_without_a_provider(): void {
		$this->unconfigure();
		$this->assertFalse( @fopen( self::P . '://uploads/a.txt', 'w' ) );
		$this->assertFalse( @fopen( self::P . '://uploads/a.txt', 'r' ) );
		$this->assertFalse( @unlink( self::P . '://uploads/a.txt' ) );
		$this->assertFalse( @file_exists( self::P . '://uploads/a.txt' ) );
		$this->assertSame( array(), $GLOBALS['_test_wp_http_log'] );
	}

	public function test_filter_upload_dir_without_a_provider_still_rewrites_paths(): void {
		$this->unconfigure();
		$out = CloudStreamWrapper::filter_upload_dir( array( 'path' => WP_CONTENT_DIR . '/uploads/2026', 'basedir' => WP_CONTENT_DIR . '/uploads', 'url' => 'http://x/u', 'baseurl' => 'http://x/u' ) );
		$this->assertSame( self::P . '://uploads/2026', $out['path'] );
		$this->assertSame( 'http://x/u', $out['url'], 'URL untouched without a provider' );
	}

	public function test_filter_upload_dir_skips_an_upgrade_basedir(): void {
		$in = array( 'path' => '/x/wp-content/uploads', 'basedir' => '/x/wp-content/upgrade/tmp', 'url' => 'u', 'baseurl' => 'u' );
		$this->assertSame( $in, CloudStreamWrapper::filter_upload_dir( $in ) );
	}

	// ── reads ───────────────────────────────────────────────

	public function test_a_failed_download_makes_open_fail(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 500 );
		$this->assertFalse( @fopen( self::P . '://uploads/missing.txt', 'r' ) );
	}

	public function test_a_transport_error_on_read_is_not_fatal(): void {
		$GLOBALS['_test_wp_http'] = fn() => new \WP_Error( 'x', 'reset' );
		$this->assertFalse( @fopen( self::P . '://uploads/missing.txt', 'r' ) );
	}

	public function test_append_to_a_blob_that_cannot_be_downloaded_starts_empty(): void {
		$GLOBALS['_test_wp_http'] = fn( $m ) => $m === 'GET' ? self::reply( 404 ) : self::reply( 201 );
		$fh = fopen( self::P . '://uploads/app.txt', 'a' );
		fwrite( $fh, 'new' );
		fclose( $fh );
		$puts = array_values( array_filter( $GLOBALS['_test_wp_http_log'], fn( $r ) => $r['method'] === 'PUT' ) );
		$this->assertSame( 'new', $puts[0]['args']['body'] );
	}

	/**
	 * Only a confirmed 404 turns an append into a new file. A transport
	 * error or a server error means the blob may well still be there, and
	 * starting from empty would upload the new bytes alone over it.
	 */
	public function test_append_refuses_to_open_when_the_download_fails_for_another_reason(): void {
		foreach ( array( new \WP_Error( 'x', 'reset' ), self::reply( 500 ), self::reply( 503 ) ) as $answer ) {
			$GLOBALS['_test_wp_http_log'] = array();
			$GLOBALS['_test_wp_http']     = fn( $m ) => $m === 'GET' ? $answer : self::reply( 201 );
			$this->assertFalse( @fopen( self::P . '://uploads/app.txt', 'a' ), 'the open fails' );
			$puts = array_filter( $GLOBALS['_test_wp_http_log'], fn( $r ) => $r['method'] === 'PUT' );
			$this->assertCount( 0, $puts, 'and nothing is uploaded over the blob' );
		}
	}

	public function test_flush_on_a_read_handle_is_refused(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 200, 'abc' );
		$fh = fopen( self::P . '://uploads/r.txt', 'r' );
		$this->assertFalse( fflush( $fh ) );
		fclose( $fh );
	}

	public function test_an_unknown_mode_is_refused(): void {
		$this->assertFalse( @fopen( self::P . '://uploads/x.txt', 'x' ) );
	}

	// ── writes ──────────────────────────────────────────────

	public function test_writing_nothing_uploads_nothing(): void {
		$fh = fopen( self::P . '://uploads/empty.txt', 'w' );
		$this->assertTrue( fclose( $fh ) );
		$this->assertSame( array(), $GLOBALS['_test_wp_http_log'] );
	}

	public function test_a_rejected_upload_records_the_failure_with_its_code(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 403 );
		$fh = fopen( self::P . '://uploads/no.txt', 'w' );
		fwrite( $fh, 'x' );
		$this->assertFalse( @fflush( $fh ) );
		fclose( $fh );
		$health = ConfigManager::get_connection_health();
		$this->assertSame( '403', $health['error_code'] );
		$this->assertSame( 'upload', $health['error_source'] );
	}

	public function test_a_transport_error_on_upload_is_recorded_as_an_exception(): void {
		$GLOBALS['_test_wp_http'] = fn() => new \WP_Error( 'x', 'reset' );
		$fh = fopen( self::P . '://uploads/no.txt', 'w' );
		fwrite( $fh, 'x' );
		$this->assertFalse( @fflush( $fh ) );
		fclose( $fh );
		$this->assertSame( 'unhealthy', ConfigManager::get_connection_health()['status'] );
	}

	public function test_a_successful_upload_heals_an_unhealthy_connection(): void {
		$this->unhealthy( 1 ); // below the refusal threshold: still tries the cloud
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 201 );
		$fh = fopen( self::P . '://uploads/ok.txt', 'w' );
		fwrite( $fh, 'x' );
		fclose( $fh );
		$this->assertSame( 'healthy', ConfigManager::get_connection_health()['status'] );
	}

	public function test_a_write_is_refused_at_open_while_the_connection_is_down(): void {
		$this->unhealthy(); // 3 failures, checked just now: no re-probe yet
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 201 );
		$this->assertFalse( @fopen( self::P . '://uploads/fb/new.txt', 'w' ) );
		$this->assertSame( array(), $GLOBALS['_test_wp_http_log'], 'nothing went to the cloud' );
		$this->assertFileDoesNotExist( WP_CONTENT_DIR . '/uploads/fb/new.txt', 'and nothing landed on disk' );
	}

	public function test_writes_reopen_once_a_fresh_health_check_passes(): void {
		$this->unhealthy(); // 3 failures...
		$GLOBALS['_test_wp_options']['diluxone_offload_connection_health']['last_check'] = time() - 400; // ...but last checked long enough ago to re-probe
		$GLOBALS['_test_wp_http'] = fn( string $method ) => 'GET' === $method ? self::reply( 200, '', array( 'x-ms-blob-public-access' => 'blob' ) ) : self::reply( 201 );
		$fh = fopen( self::P . '://uploads/back/x.txt', 'w' );
		$this->assertNotFalse( $fh, 'the probe passed, so the write is allowed' );
		fwrite( $fh, 'x' );
		$this->assertTrue( @fflush( $fh ) );
		fclose( $fh );
		$this->assertSame( 'healthy', ConfigManager::get_connection_health()['status'] );
		$methods = array_column( $GLOBALS['_test_wp_http_log'], 'method' );
		$this->assertContains( 'GET', $methods, 'one probe' );
		$this->assertContains( 'PUT', $methods, 'then the upload' );
	}

	public function test_the_third_failed_upload_closes_the_gate_and_the_next_open_does_not_probe_again(): void {
		$this->unhealthy( 2 ); // two failures: writes still go to the cloud
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 500 );
		$fh = fopen( self::P . '://uploads/third.txt', 'w' );
		$this->assertNotFalse( $fh, 'two failures do not refuse a write' );
		fwrite( $fh, 'x' );
		$this->assertFalse( @fflush( $fh ) );
		fclose( $fh );
		$this->assertSame( 3, ConfigManager::get_connection_health()['consecutive_failures'] );

		$GLOBALS['_test_wp_http_log'] = array();
		$this->assertFalse( @fopen( self::P . '://uploads/fourth.txt', 'w' ), 'the third failure closes the gate' );
		$this->assertSame( array(), $GLOBALS['_test_wp_http_log'], 'checked just now: no probe, no upload' );
	}

	public function test_a_failed_upload_is_reported_back_to_wordpress_as_a_failed_upload(): void {
		$this->unhealthy( 1 );
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 500 );
		// PHP ignores the return of stream_flush()/stream_close(), so from
		// file_put_contents()'s point of view this "worked".
		$this->assertNotFalse( @file_put_contents( self::P . '://uploads/2026/09/lost.txt', 'x' ) );

		$upload = array( 'file' => self::P . '://uploads/2026/09/lost.txt', 'url' => 'https://x/lost.txt', 'type' => 'text/plain' );
		$result = CloudStreamWrapper::fail_upload_if_write_failed( $upload, 'upload' );
		$this->assertArrayHasKey( 'error', $result, 'the wp_handle_upload filter turns it into the error it should have been' );
		$this->assertStringContainsString( '500', $result['error'] );

		$this->assertSame( $upload, CloudStreamWrapper::fail_upload_if_write_failed( $upload, 'upload' ), 'consumed: reported once' );
		$local = array( 'file' => '/var/www/html/wp-content/uploads/local.txt' ) + $upload;
		$this->assertSame( $local, CloudStreamWrapper::fail_upload_if_write_failed( $local, 'upload' ), 'a file outside the wrapper is left alone' );
	}

	// ── stat ────────────────────────────────────────────────

	public function test_stat_on_a_missing_blob_warns_and_fails(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 404 );
		$this->assertFalse( @stat( self::P . '://uploads/missing.jpg' ) );
	}

	public function test_stat_survives_a_transport_error(): void {
		$GLOBALS['_test_wp_http'] = fn() => new \WP_Error( 'x', 'reset' );
		$this->assertFalse( @file_exists( self::P . '://uploads/missing.jpg' ) );
	}

	public function test_stat_on_an_existing_blob_reports_a_file(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 200, '', array( 'content-length' => '5' ) );
		$this->assertTrue( is_file( self::P . '://uploads/there.jpg' ) );
		$this->assertFalse( is_dir( self::P . '://uploads/there.jpg' ) );
	}

	// ── cache ───────────────────────────────────────────────

	public function test_oversized_content_is_not_cached(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 201 );
		$big = str_repeat( 'x', CloudStreamWrapper::CACHE_MAX_BYTES + 1 );
		$fh  = fopen( self::P . '://uploads/big.bin', 'w' );
		fwrite( $fh, $big );
		fclose( $fh );
		$GLOBALS['_test_wp_http'] = fn( $m ) => $m === 'GET' ? self::reply( 200, 'from-cloud' ) : self::reply( 200 );
		$this->assertSame( 'from-cloud', file_get_contents( self::P . '://uploads/big.bin' ), 'a second read goes to the cloud' );
	}

	public function test_read_write_mode_uploads_the_edited_blob_on_close(): void {
		$GLOBALS['_test_wp_http'] = fn( $m ) => $m === 'GET' ? self::reply( 200, 'hello world' ) : self::reply( 201 );
		$fh = fopen( self::P . '://uploads/rw.txt', 'r+' );
		$this->assertSame( 'hello', fread( $fh, 5 ) );
		fseek( $fh, 6 );
		fwrite( $fh, 'there' );
		$this->assertTrue( fflush( $fh ) );
		fclose( $fh );
		$puts = array_values( array_filter( $GLOBALS['_test_wp_http_log'], fn( $r ) => $r['method'] === 'PUT' ) );
		$this->assertCount( 1, $puts, 'flush uploads, close sees the cache and does not repeat it' );
		$this->assertSame( 'hello there', $puts[0]['args']['body'] );
	}

	public function test_read_write_mode_on_a_missing_blob_fails_to_open(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 404 );
		$this->assertFalse( @fopen( self::P . '://uploads/missing.txt', 'r+' ) );
	}

	public function test_read_write_mode_serves_a_cached_blob_without_a_download(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 201 );
		$fh = fopen( self::P . '://uploads/c.txt', 'w' );
		fwrite( $fh, 'cached' );
		fclose( $fh );
		$fh = fopen( self::P . '://uploads/c.txt', 'r+' );
		$this->assertSame( 'cached', fread( $fh, 10 ) );
		fclose( $fh );
		$this->assertCount( 0, array_filter( $GLOBALS['_test_wp_http_log'], fn( $r ) => $r['method'] === 'GET' ) );
	}

	// ── append and read-write handles ───────────────────────

	public function test_append_plus_reads_back_what_was_appended(): void {
		$GLOBALS['_test_wp_http'] = fn( $m ) => $m === 'GET' ? self::reply( 200, 'old' ) : self::reply( 201 );
		$fh = fopen( self::P . '://uploads/rw.txt', 'a+' );
		fwrite( $fh, '-new' );
		rewind( $fh );
		$this->assertSame( 'old-new', fread( $fh, 100 ) );
		fclose( $fh );
	}

	public function test_fstat_on_a_write_stream_reports_the_temp_file_size(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 201 );
		$fh = fopen( self::P . '://uploads/st.txt', 'w' );
		fwrite( $fh, 'twelve bytes' );
		$st = fstat( $fh );
		$this->assertSame( 12, $st['size'] );
		// The stat comes from the temp file backing the stream, so the mode is
		// a real regular file's.
		$this->assertSame( 0100000, $st['mode'] & 0170000, 'a regular file' );
		fclose( $fh );
	}


	public function test_unlink_with_a_provider_that_throws_still_succeeds(): void {
		$GLOBALS['_test_wp_http'] = function () {
			throw new \RuntimeException( 'provider exploded' );
		};
		$this->assertTrue( unlink( self::P . '://uploads/x.jpg' ) );
	}

	public function test_rename_with_a_provider_that_throws_fails(): void {
		$GLOBALS['_test_wp_http'] = function () {
			throw new \RuntimeException( 'provider exploded' );
		};
		$this->assertFalse( @rename( self::P . '://uploads/a.jpg', self::P . '://uploads/b.jpg' ) );
	}
}
