<?php
namespace Tests\Unit\CloudStorage;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\CloudStreamWrapper;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\Enums\PluginState;

/**
 * The wrapper on top of a provider whose transport *throws*. Every wrapper
 * operation must survive that — an image upload must not take the whole
 * request down with an uncaught exception.
 */
class StreamWrapperProviderFailuresTest extends TestCase {

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
				'provider_config' => array( 'storage_account' => 'throwacct', 'container_name' => 'media', 'access_key' => base64_encode( random_bytes( 32 ) ) ),
			),
			'diluxone_offload_plugin_state' => PluginState::SYNCED,
		);
		$GLOBALS['_test_wp_transients'] = array();
		$GLOBALS['_test_wp_http_log']   = array();
		$GLOBALS['_test_wp_hooks']      = array();
		$GLOBALS['_test_wp_http'] = function () {
			throw new \RuntimeException( 'transport exploded' );
		};
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

	private function resetClient(): void {
		$p = new \ReflectionProperty( CloudStreamWrapper::class, 'cloud_client' );
		if ( PHP_VERSION_ID < 80100 ) { // Required before 8.1, deprecated from 8.5.
			$p->setAccessible( true );
		}
		$p->setValue( null, null );
	}

	public function test_a_write_whose_upload_throws_fails_and_records_the_failure(): void {
		$fh = fopen( self::P . '://uploads/x.txt', 'w' );
		fwrite( $fh, 'x' );
		$this->assertFalse( @fflush( $fh ) );
		$this->assertTrue( fclose( $fh ), 'close never throws' );
		$health = ConfigManager::get_connection_health();
		$this->assertSame( 'unhealthy', $health['status'] );
		$this->assertSame( 'upload', $health['error_source'] );
		$this->assertStringContainsString( 'transport exploded', $health['error_message'] );
	}

	public function test_a_read_whose_download_throws_fails_to_open(): void {
		$this->assertFalse( @fopen( self::P . '://uploads/x.txt', 'r' ) );
	}

	public function test_read_write_whose_download_throws_fails_to_open(): void {
		$this->assertFalse( @fopen( self::P . '://uploads/x.txt', 'r+' ) );
	}

	public function test_append_whose_download_throws_refuses_to_open(): void {
		// An exception is not "the blob is missing": starting from empty would
		// upload the new bytes alone over whatever is there.
		$this->assertFalse( @fopen( self::P . '://uploads/x.txt', 'a' ) );
	}

	public function test_unlink_whose_delete_throws_still_reports_success(): void {
		$this->assertTrue( unlink( self::P . '://uploads/x.txt' ) );
	}

	public function test_rename_whose_copy_throws_fails(): void {
		$this->assertFalse( @rename( self::P . '://uploads/a.txt', self::P . '://uploads/b.txt' ) );
	}

	public function test_rename_whose_delete_throws_after_a_good_copy_still_succeeds(): void {
		$GLOBALS['_test_wp_http'] = function ( $m ) {
			if ( $m === 'PUT' ) {
				return array( 'response' => array( 'code' => 202, 'message' => '' ), 'body' => '', 'headers' => array() );
			}
			throw new \RuntimeException( 'transport exploded' );
		};
		$this->assertTrue( rename( self::P . '://uploads/a.txt', self::P . '://uploads/b.txt' ) );
	}

	public function test_stat_whose_head_throws_reports_missing(): void {
		$this->assertFalse( @file_exists( self::P . '://uploads/x.txt' ) );
	}
}
