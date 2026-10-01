<?php
namespace Tests\Unit\CloudStorage;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\SiteHealth;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\Enums\PluginState;

/**
 * Tools › Site Health: the connection test reports what the plugin already
 * recorded (it never calls the storage), and the Info section names the
 * provider and service but never a credential.
 */
class SiteHealthTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_wp_options']  = array();
		$GLOBALS['_test_wp_hooks']    = array();
		$GLOBALS['_test_wp_http_log'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_test_wp_options'], $GLOBALS['_test_wp_hooks'], $GLOBALS['_test_wp_http_log'] );
		parent::tearDown();
	}

	private function configure_s3( string $preset = 'r2' ): void {
		$GLOBALS['_test_wp_options'][ ConfigManager::CONFIG_OPTION ] = array(
			'cloud_provider'  => 's3',
			'provider_config' => array(
				'preset'            => $preset,
				'endpoint'          => 'https://acct.r2.cloudflarestorage.com',
				'region'            => 'auto',
				'bucket'            => 'media',
				'access_key_id'     => 'AKID-VISIBLE-NOWHERE',
				'secret_access_key' => 'SECRET-VISIBLE-NOWHERE',
				'public_url'        => 'https://cdn.example.test',
			),
		);
	}

	/** @param array<string, mixed> $health */
	private function health( array $health ): void {
		$GLOBALS['_test_wp_options'][ ConfigManager::HEALTH_OPTION ] = array_merge( ConfigManager::DEFAULT_HEALTH, $health );
	}

	public function test_init_hooks_the_test_and_the_info_section(): void {
		SiteHealth::init();

		$this->assertSame( array( SiteHealth::class, 'register_test' ), $GLOBALS['_test_wp_hooks']['filter']['site_status_tests'][0]['callback'] );
		$this->assertSame( array( SiteHealth::class, 'debug_information' ), $GLOBALS['_test_wp_hooks']['filter']['debug_information'][0]['callback'] );
	}

	public function test_the_test_is_added_to_the_direct_tests_keeping_the_others(): void {
		$tests = SiteHealth::register_test( array( 'direct' => array( 'core' => array( 'x' ) ), 'async' => array( 'y' ) ) );

		$this->assertSame( array( 'x' ), $tests['direct']['core'] );
		$this->assertSame( array( 'y' ), $tests['async'] );
		$this->assertSame( array( SiteHealth::class, 'connection_test' ), $tests['direct']['diluxone_offload_connection']['test'] );
	}

	public function test_a_broken_tests_value_from_another_filter_is_replaced(): void {
		$tests = SiteHealth::register_test( 'broken' );
		$this->assertSame( array( 'diluxone_offload_connection' ), array_keys( $tests['direct'] ) );
	}

	public function test_nothing_configured_is_good_and_says_media_stays_local(): void {
		$result = SiteHealth::connection_test();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( 'blue', $result['badge']['color'] );
		$this->assertStringContainsString( 'no storage configured', $result['label'] );
		$this->assertArrayNotHasKey( 'actions', $result );
		$this->assertSame( array(), $GLOBALS['_test_wp_http_log'], 'the test never calls the storage' );
	}

	public function test_a_healthy_connection_names_the_service(): void {
		$this->configure_s3();
		$this->health( array( 'status' => 'healthy' ) );

		$result = SiteHealth::connection_test();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( '<p>The last request to Cloudflare R2 went through.</p>', $result['description'] );
		$this->assertStringContainsString( 'admin.php?page=diluxone-offload-status&tab=health', $result['actions'] );
		$this->assertSame( 'diluxone_offload_connection', $result['test'] );
	}

	public function test_a_failure_is_critical_and_escapes_what_the_storage_answered(): void {
		$this->configure_s3();
		$this->health( array( 'status' => 'unhealthy', 'error_code' => '403', 'error_message' => '<b>AccessDenied</b>', 'consecutive_failures' => 1 ) );

		$result = SiteHealth::connection_test();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertSame( 'red', $result['badge']['color'] );
		$this->assertSame( 'DiluxOne Offload could not reach its storage', $result['label'] );
		$this->assertSame( '<p>The last attempt failed: 403 &lt;b&gt;AccessDenied&lt;/b&gt;</p>', $result['description'] );
	}

	public function test_three_failures_while_offloading_say_uploads_are_paused(): void {
		$this->configure_s3();
		$this->health( array( 'status' => 'unhealthy', 'error_code' => '500', 'consecutive_failures' => ConfigManager::PAUSE_AFTER_FAILURES ) );
		$GLOBALS['_test_wp_options'][ ConfigManager::STATE_OPTION ] = PluginState::OFFLOADING_ACTIVE;

		$this->assertStringStartsWith( 'Media uploads are paused', SiteHealth::connection_test()['label'] );
	}

	public function test_three_failures_without_offloading_are_not_a_pause(): void {
		$this->configure_s3();
		$this->health( array( 'status' => 'unhealthy', 'consecutive_failures' => 5 ) );
		$GLOBALS['_test_wp_options'][ ConfigManager::STATE_OPTION ] = PluginState::SYNCED;

		$this->assertSame( 'DiluxOne Offload could not reach its storage', SiteHealth::connection_test()['label'] );
	}

	public function test_the_info_section_names_provider_service_state_and_health_but_no_secret(): void {
		$this->configure_s3();
		$this->health( array( 'status' => 'healthy' ) );
		$GLOBALS['_test_wp_options'][ ConfigManager::STATE_OPTION ] = PluginState::SYNCED;

		$info = SiteHealth::debug_information( array( 'wp-core' => array( 'label' => 'WordPress' ) ) );

		$this->assertSame( 'WordPress', $info['wp-core']['label'], 'other sections are kept' );
		$fields = array_map( fn( $f ) => $f['value'], $info['diluxone-offload']['fields'] );
		$this->assertSame(
			array(
				'version'  => DILUXONE_OFFLOAD_VERSION,
				'provider' => 'S3-compatible storage',
				'service'  => 'Cloudflare R2',
				'state'    => PluginState::SYNCED,
				'health'   => 'healthy',
			),
			$fields
		);
		$dump = serialize( $info );
		$this->assertStringNotContainsString( 'SECRET-VISIBLE-NOWHERE', $dump );
		$this->assertStringNotContainsString( 'AKID-VISIBLE-NOWHERE', $dump );
	}

	public function test_a_custom_s3_service_is_shown_as_the_family(): void {
		$this->configure_s3( 'custom' );
		$info = SiteHealth::debug_information( array() );
		$this->assertSame( 'S3-compatible storage', $info['diluxone-offload']['fields']['service']['value'] );
	}

	public function test_the_info_section_without_a_provider_says_none(): void {
		$info = SiteHealth::debug_information( 'broken' );

		$this->assertSame( 'None', $info['diluxone-offload']['fields']['provider']['value'] );
		$this->assertSame( 'None', $info['diluxone-offload']['fields']['service']['value'] );
		$this->assertSame( PluginState::NOT_CONFIGURED, $info['diluxone-offload']['fields']['state']['value'] );
		$this->assertSame( 'unknown', $info['diluxone-offload']['fields']['health']['value'] );
	}
}
