<?php
namespace Tests\Unit\Sync;

use DiluxOneOffload\SyncManager;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\FakeWpdb;
use Tests\Unit\Support\ScriptedCloudClient;

require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';

/**
 * What every SyncManager test stands on: an uploads directory of its own,
 * fresh options, a scripted $wpdb for the tracking table and a scripted
 * provider handed to the manager in place of the configured one.
 */
abstract class SyncTestCase extends TestCase {

	protected FakeWpdb $db;

	protected ScriptedCloudClient $client;

	protected string $uploads;

	/** @var mixed */
	private $previous_wpdb;

	protected function setUp(): void {
		parent::setUp();
		// Not under /tmp: the sync skips any path with /tmp/, /temp/ or
		// /cache/ in it, the uploads directory's own path included (see
		// SyncManagerScanTest's BUG test).
		$this->uploads                    = DILUXONE_OFFLOAD_DIR . 'build/unit-sync/' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->uploads, 0777, true );
		$GLOBALS['_test_wp_upload_dir']   = $this->uploads;
		$GLOBALS['_test_wp_options']      = array();
		$GLOBALS['_test_wp_transients']   = array();
		$GLOBALS['_test_wp_http_log']     = array();
		$this->previous_wpdb              = $GLOBALS['wpdb'] ?? null;
		$this->db                         = new FakeWpdb();
		$GLOBALS['wpdb']                  = $this->db;
		$this->client                     = new ScriptedCloudClient();
		unset( $GLOBALS['_test_multisite'], $GLOBALS['_test_wp_http'] );
	}

	protected function tearDown(): void {
		self::remove( $this->uploads );
		@rmdir( dirname( $this->uploads ) ); // Only when no other test's directory is left in it.
		if ( null === $this->previous_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		}
		unset( $GLOBALS['_test_wp_upload_dir'], $GLOBALS['_test_wp_options'], $GLOBALS['_test_wp_transients'], $GLOBALS['_test_wp_http_log'], $GLOBALS['_test_multisite'] );
		parent::tearDown();
	}

	private static function remove( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
	}

	/** A manager on the scripted provider, or on none. */
	protected function manager( bool $with_client = true ): SyncManager {
		$manager = new SyncManager();
		$this->set( $manager, 'cloud_client', $with_client ? $this->client : null );
		return $manager;
	}

	/** @param mixed $value */
	protected function set( object $object, string $property, $value ): void {
		$p = new \ReflectionProperty( $object, $property );
		if ( PHP_VERSION_ID < 80100 ) { // Required before 8.1, deprecated from 8.5.
			$p->setAccessible( true );
		}
		$p->setValue( $object, $value );
	}

	/** @return mixed */
	protected function get( object $object, string $property ) {
		$p = new \ReflectionProperty( $object, $property );
		if ( PHP_VERSION_ID < 80100 ) {
			$p->setAccessible( true );
		}
		return $p->getValue( $object );
	}

	/**
	 * @param array<int, mixed> $args
	 * @return mixed
	 */
	protected function call( object $object, string $method, array $args = array() ) {
		$m = new \ReflectionMethod( $object, $method );
		if ( PHP_VERSION_ID < 80100 ) {
			$m->setAccessible( true );
		}
		return $m->invokeArgs( $object, $args );
	}

	/** Write a file under uploads/ and return its absolute path. */
	protected function file( string $relative, string $content = 'x' ): string {
		$path = $this->uploads . '/' . ltrim( $relative, '/' );
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}
		file_put_contents( $path, $content );
		return $path;
	}

	/** @param array<string, mixed> $config Flat plugin config (settings and provider_config). */
	protected function config( array $config ): void {
		$GLOBALS['_test_wp_options']['diluxone_offload_config'] = $config;
	}

	protected function state( string $state ): void {
		$GLOBALS['_test_wp_options']['diluxone_offload_plugin_state'] = $state;
	}

	/** @param array<string, mixed> $meta */
	protected function meta( array $meta ): void {
		$GLOBALS['_test_wp_options']['diluxone_offload_sync_meta'] = $meta;
	}

	/** @return mixed */
	protected function option( string $name ) {
		return $GLOBALS['_test_wp_options'][ $name ] ?? null;
	}

	/** The stats row the tracking table answers with. */
	protected function stats( int $total, int $synced, int $failed = 0, int $pending = 0 ): void {
		$this->db->on(
			'get_row',
			'/COUNT\(\*\) as total_files/',
			array( 'total_files' => (string) $total, 'synced_files' => (string) $synced, 'failed_files' => (string) $failed, 'pending_files' => (string) $pending )
		);
	}

	/** @return array<int, string> Paths whose error counter went up, in order. */
	protected function attempts(): array {
		$paths = array();
		foreach ( $this->db->prepared as $p ) {
			if ( false !== strpos( $p['query'], 'SET errors = errors + 1' ) ) {
				$paths[] = (string) end( $p['args'] );
			}
		}
		return $paths;
	}

	/** @return array<string, string> Path => error message stored with it. */
	protected function stored_errors(): array {
		$errors = array();
		foreach ( $this->db->prepared as $p ) {
			if ( false !== strpos( $p['query'], 'error_message = %s' ) ) {
				$errors[ (string) $p['args'][1] ] = (string) $p['args'][0];
			}
		}
		return $errors;
	}
}
