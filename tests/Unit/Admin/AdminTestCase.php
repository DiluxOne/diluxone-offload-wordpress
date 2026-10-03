<?php
namespace Tests\Unit\Admin;

use DiluxOneOffload\Admin;
use Mockery;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/admin-functions.php';

/**
 * Shared setup for the Admin unit tests.
 *
 * The admin calls WordPress functions the shared stubs do not carry (they
 * belong to wp-admin, not to the helpers and DTOs the stubs were written
 * for). admin-functions.php supplies them with WordPress's behaviour, and a
 * test that needs another answer overrides one function with answer().
 */
abstract class AdminTestCase extends TestCase {

	/** @var mixed */
	private $previous_wpdb;

	/** @var array<string, mixed>|null What the tracking table query returns. */
	public ?array $tracking_row = null;

	/** @var string[] Queries the fake $wpdb received. */
	public array $queries = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
			define( 'MINUTE_IN_SECONDS', 60 );
		}
		if ( ! defined( 'ARRAY_A' ) ) {
			define( 'ARRAY_A', 'ARRAY_A' );
		}

		// The class file runs Admin::init() when it is first loaded: load it
		// before the hooks are emptied, so its hooks never land in the test
		// that happens to load it first (the order is random).
		class_exists( Admin::class );
		$GLOBALS['_test_wp_options']    = array();
		$GLOBALS['_test_wp_transients'] = array();
		$GLOBALS['_test_wp_hooks']      = array();
		$_GET                           = array();
		$_POST                          = array();

		$GLOBALS['_test_admin_fn']    = array();
		$GLOBALS['_test_admin_calls'] = array();

		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$test                = $this;
		$GLOBALS['wpdb']     = new class( $test ) {
			/** @var string */
			public $prefix = 'wp_';
			/** @var AdminTestCase */
			private $test;
			public function __construct( $test ) {
				$this->test = $test;
			}
			/** @return array<string, mixed>|null */
			public function get_row( $query, $output = null ) {
				$this->test->queries[] = (string) $query;
				return $this->test->tracking_row;
			}
		};
		self::forget_tracking_counts();
	}

	protected function tearDown(): void {
		self::forget_tracking_counts();
		if ( null === $this->previous_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		}
		unset( $GLOBALS['_test_wp_options'], $GLOBALS['_test_wp_transients'], $GLOBALS['_test_wp_hooks'], $GLOBALS['_wp_admin_css_colors'] );
		$_GET  = array();
		$_POST = array();
		unset( $GLOBALS['_test_admin_fn'], $GLOBALS['_test_admin_calls'] );
		Mockery::close();
		parent::tearDown();
	}

	/** Give one wp-admin function another answer for this test. */
	protected function answer( string $function, callable $answer ): void {
		$GLOBALS['_test_admin_fn'][ $function ] = $answer;
	}

	/** @param mixed $value */
	protected function returns( string $function, $value ): void {
		$this->answer( $function, static fn() => $value );
	}

	/** The per-request cache of tracking_counts(), cleared. */
	protected static function forget_tracking_counts(): void {
		$p = new \ReflectionProperty( Admin::class, 'tracking_counts' );
		if ( PHP_VERSION_ID < 80100 ) { // Required before 8.1, deprecated from 8.5.
			$p->setAccessible( true );
		}
		$p->setValue( null, null );
	}

	/**
	 * Call one of Admin's private static methods.
	 *
	 * @param mixed ...$args
	 * @return mixed
	 */
	protected static function call( string $method, ...$args ) {
		$m = new \ReflectionMethod( Admin::class, $method );
		if ( PHP_VERSION_ID < 80100 ) { // Required before 8.1, deprecated from 8.5.
			$m->setAccessible( true );
		}
		return $m->invoke( null, ...$args );
	}

	protected static function url( string $query ): string {
		return 'https://example.test/wp-admin/admin.php?' . $query;
	}

	/** @param array<string, mixed> $provider_config */
	protected function configure( string $provider, array $provider_config ): void {
		$GLOBALS['_test_wp_options']['diluxone_offload_config'] = array(
			'cloud_provider'  => $provider,
			'provider_config' => $provider_config,
		);
	}

	protected function configure_azure( string $container = 'media' ): void {
		$this->configure(
			'azure',
			array( 'storage_account' => 'acct1', 'container_name' => $container, 'access_key' => base64_encode( str_repeat( 'k', 32 ) ) )
		);
	}

	protected function configure_s3( string $preset = 'r2' ): void {
		$this->configure(
			's3',
			array(
				'preset'            => $preset,
				'endpoint'          => 'https://acct.r2.cloudflarestorage.com',
				'region'            => 'auto',
				'bucket'            => 'photos',
				'access_key_id'     => 'AKID',
				'secret_access_key' => 'SECRET',
				'public_url'        => 'https://cdn.example.test',
			)
		);
	}

	protected function state( string $state ): void {
		$GLOBALS['_test_wp_options']['diluxone_offload_plugin_state'] = $state;
	}
}
