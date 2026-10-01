<?php
namespace Tests\Unit\CloudStorage;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\Crypto;
use DiluxOneOffload\Enums\PluginState;

/**
 * What ConfigManager writes to wp_options and reads back: credentials
 * encrypted at rest, the connection date that a key rotation must not move,
 * the state that follows the configuration, and the small records the
 * screens read (last upload, skipped files, sync progress, reset).
 */
class ConfigManagerPersistenceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_wp_options']    = array();
		$GLOBALS['_test_wp_transients'] = array();
		$GLOBALS['_test_wp_http_log']   = array();
		unset( $GLOBALS['_test_wp_http'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_test_wp_options'], $GLOBALS['_test_wp_transients'], $GLOBALS['_test_wp_http'], $GLOBALS['_test_wp_http_log'], $GLOBALS['_test_wp_mail'] );
		parent::tearDown();
	}

	/** @return array<string, mixed> */
	private static function azure( string $account = 'acct1', string $container = 'media', ?string $key = null ): array {
		return array(
			'cloud_provider'  => 'azure',
			'provider_config' => array(
				'storage_account' => $account,
				'container_name'  => $container,
				'access_key'      => $key ?? base64_encode( str_repeat( 'k', 32 ) ),
			),
		);
	}

	/** @return array<string, mixed> */
	private function stored(): array {
		return $GLOBALS['_test_wp_options'][ ConfigManager::CONFIG_OPTION ];
	}

	// ── Credentials at rest ─────────────────────────────────

	public function test_a_saved_access_key_is_stored_encrypted_and_read_back_in_clear(): void {
		$key = base64_encode( str_repeat( 'k', 32 ) );

		$this->assertTrue( ConfigManager::save_provider_config( self::azure( 'acct1', 'media', $key ) ) );

		$at_rest = $this->stored()['provider_config']['access_key'];
		$this->assertTrue( Crypto::is_encrypted( $at_rest ) );
		$this->assertStringNotContainsString( $key, serialize( $this->stored() ), 'the key never reaches the option in clear' );
		$this->assertSame( $key, ConfigManager::get_current_provider_config()['access_key'] );
		$this->assertSame( $key, ConfigManager::get_provider_config()['provider_config']['access_key'] );
	}

	public function test_saving_only_settings_keeps_the_key_encrypted_and_intact(): void {
		$key = base64_encode( str_repeat( 'k', 32 ) );
		ConfigManager::save_provider_config( self::azure( 'acct1', 'media', $key ) );

		// The settings save reads the config decrypted and writes it back:
		// the provider section must go back encrypted, never in clear.
		$this->assertTrue( ConfigManager::save_plugin_settings( array( 'timeout' => 120 ) ) );

		$at_rest = $this->stored()['provider_config']['access_key'];
		$this->assertTrue( Crypto::is_encrypted( $at_rest ) );
		$this->assertSame( $key, Crypto::decrypt( $at_rest ) );
		$this->assertSame( 120, ConfigManager::get_plugin_settings()['timeout'] );
	}

	public function test_s3_secrets_are_encrypted_too(): void {
		$secret = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
		$this->assertTrue(
			ConfigManager::save_provider_config(
				array(
					'cloud_provider'  => 's3',
					'provider_config' => array(
						'preset'            => 'custom',
						'endpoint'          => 'https://s3.example.test',
						'region'            => 'us-east-1',
						'bucket'            => 'media',
						'access_key_id'     => 'AKIDEXAMPLE',
						'secret_access_key' => $secret,
						'public_url'        => 'https://cdn.example.test',
					),
				)
			)
		);

		$this->assertTrue( Crypto::is_encrypted( $this->stored()['provider_config']['secret_access_key'] ) );
		$this->assertSame( $secret, ConfigManager::get_current_provider_config()['secret_access_key'] );
	}

	// ── save_provider_config ────────────────────────────────

	public function test_a_provider_missing_a_required_field_is_refused_and_nothing_is_written(): void {
		$config = self::azure();
		unset( $config['provider_config']['container_name'] );

		$this->assertFalse( ConfigManager::save_provider_config( $config ) );
		$this->assertArrayNotHasKey( ConfigManager::CONFIG_OPTION, $GLOBALS['_test_wp_options'] );
	}

	public function test_the_first_connection_is_dated_and_moves_the_state_to_configured(): void {
		$before = time();
		ConfigManager::save_provider_config( self::azure() );

		$this->assertGreaterThanOrEqual( $before, ConfigManager::get_timestamps()['connected_at'] );
		$this->assertSame( PluginState::CONFIGURED, ConfigManager::get_state() );
		$this->assertTrue( ConfigManager::is_configured() );
	}

	public function test_rotating_the_key_keeps_the_connection_date(): void {
		ConfigManager::save_provider_config( self::azure() );
		$GLOBALS['_test_wp_options'][ ConfigManager::TIMESTAMPS_OPTION ]['connected_at'] = 1000;

		$this->assertTrue( ConfigManager::save_provider_config( self::azure( 'acct1', 'media', base64_encode( str_repeat( 'z', 32 ) ) ) ) );

		$this->assertSame( 1000, ConfigManager::get_timestamps()['connected_at'] );
	}

	/** @return array<string, array{string, string}> */
	public function newConnections(): array {
		return array(
			'another account'   => array( 'acct2', 'media' ),
			'another container' => array( 'acct1', 'photos' ),
		);
	}

	/** @dataProvider newConnections */
	public function test_another_account_or_container_is_a_new_connection( string $account, string $container ): void {
		ConfigManager::save_provider_config( self::azure() );
		$GLOBALS['_test_wp_options'][ ConfigManager::TIMESTAMPS_OPTION ]['connected_at'] = 1000;

		ConfigManager::save_provider_config( self::azure( $account, $container ) );

		$this->assertGreaterThan( 1000, ConfigManager::get_timestamps()['connected_at'] );
	}

	public function test_clearing_the_provider_is_no_connection_and_drops_the_state_back(): void {
		ConfigManager::save_provider_config( self::azure() );
		$GLOBALS['_test_wp_options'][ ConfigManager::TIMESTAMPS_OPTION ]['connected_at'] = 1000;

		$this->assertTrue( ConfigManager::save_provider_config( array( 'cloud_provider' => '', 'provider_config' => array() ) ) );

		$this->assertSame( 1000, ConfigManager::get_timestamps()['connected_at'], 'no provider is not a new connection' );
		$this->assertSame( PluginState::NOT_CONFIGURED, ConfigManager::get_state() );
		$this->assertFalse( ConfigManager::is_configured() );
	}

	public function test_saving_the_same_provider_twice_reports_success(): void {
		ConfigManager::save_provider_config( self::azure() );
		$stored = $this->stored();

		$this->assertTrue( ConfigManager::save_provider_config( $stored ), 'nothing to write is not a failure' );
	}

	// ── save_config / validate_config ───────────────────────

	public function test_save_config_refuses_a_provider_with_an_empty_required_field(): void {
		$config                                     = self::azure();
		$config['provider_config']['access_key']    = '';

		$this->assertFalse( ConfigManager::save_config( $config ) );
		$this->assertArrayNotHasKey( ConfigManager::CONFIG_OPTION, $GLOBALS['_test_wp_options'] );
	}

	public function test_save_config_stores_a_complete_provider_encrypted_and_configures_the_plugin(): void {
		$this->assertTrue( ConfigManager::save_config( self::azure() ) );

		$this->assertTrue( Crypto::is_encrypted( $this->stored()['provider_config']['access_key'] ) );
		$this->assertSame( PluginState::CONFIGURED, ConfigManager::get_state() );
	}

	// ── State ───────────────────────────────────────────────

	public function test_offloading_is_enabled_only_in_its_own_state(): void {
		foreach ( PluginState::get_all_states() as $state ) {
			$GLOBALS['_test_wp_options'][ ConfigManager::STATE_OPTION ] = $state;
			$this->assertSame( PluginState::OFFLOADING_ACTIVE === $state, ConfigManager::is_offloading_enabled(), $state );
		}
	}

	public function test_only_a_configured_plugin_can_start_a_sync(): void {
		foreach ( PluginState::get_all_states() as $state ) {
			$this->assertSame( PluginState::CONFIGURED === $state, PluginState::can_start_sync( $state ), $state );
		}
	}

	// ── Records the screens read ────────────────────────────

	public function test_last_upload_is_null_until_one_happened(): void {
		$this->assertNull( ConfigManager::get_last_upload() );
		$GLOBALS['_test_wp_options'][ ConfigManager::LAST_UPLOAD_OPTION ] = array( 'path' => 'a.jpg', 'time' => 0 );
		$this->assertNull( ConfigManager::get_last_upload(), 'no time, no upload' );
		$GLOBALS['_test_wp_options'][ ConfigManager::LAST_UPLOAD_OPTION ] = 'garbage';
		$this->assertNull( ConfigManager::get_last_upload() );
	}

	public function test_last_upload_is_typed(): void {
		$GLOBALS['_test_wp_options'][ ConfigManager::LAST_UPLOAD_OPTION ] = array( 'path' => '2026/09/a.jpg', 'size' => '2048', 'time' => '1700000000' );

		$this->assertSame( array( 'path' => '2026/09/a.jpg', 'size' => 2048, 'time' => 1700000000 ), ConfigManager::get_last_upload() );
	}

	public function test_last_upload_without_path_or_size_reads_as_empty_and_zero(): void {
		$GLOBALS['_test_wp_options'][ ConfigManager::LAST_UPLOAD_OPTION ] = array( 'time' => 5 );

		$this->assertSame( array( 'path' => '', 'size' => 0, 'time' => 5 ), ConfigManager::get_last_upload() );
	}

	public function test_skipped_is_null_until_a_scan_ran(): void {
		$this->assertNull( ConfigManager::get_skipped() );
		$GLOBALS['_test_wp_options'][ ConfigManager::SKIPPED_OPTION ] = array( 'total' => 3 );
		$this->assertNull( ConfigManager::get_skipped() );
	}

	public function test_skipped_is_normalised_per_reason(): void {
		$GLOBALS['_test_wp_options'][ ConfigManager::SKIPPED_OPTION ] = array(
			'time'    => '1700000000',
			'total'   => '4',
			'reasons' => array(
				'too_large' => array( 'count' => '3', 'paths' => array( 5 => 'big.mov', 9 => 42 ) ),
				'excluded'  => array(),
			),
		);

		$this->assertSame(
			array(
				'time'    => 1700000000,
				'total'   => 4,
				'reasons' => array(
					'too_large' => array( 'count' => 3, 'paths' => array( 'big.mov', '42' ) ),
					'excluded'  => array( 'count' => 0, 'paths' => array() ),
				),
			),
			ConfigManager::get_skipped()
		);
	}

	public function test_sync_progress_round_trips_and_clears(): void {
		$this->assertNull( ConfigManager::get_sync_progress() );

		$progress = array( 'status' => 'started', 'processed' => 3 );
		$this->assertTrue( ConfigManager::save_sync_progress( $progress ) );
		$this->assertSame( $progress, ConfigManager::get_sync_progress() );
		$this->assertTrue( ConfigManager::save_sync_progress( $progress ), 'the same progress again is no failure' );

		ConfigManager::clear_sync_progress();
		$this->assertNull( ConfigManager::get_sync_progress() );
	}

	public function test_failed_files_are_merged_by_path_and_cleared(): void {
		ConfigManager::add_failed_files( array( array( 'file' => 'a.jpg', 'error' => 'first' ) ) );
		ConfigManager::add_failed_files( array( array( 'file' => 'a.jpg', 'error' => 'second' ), array( 'local_path' => 'b.jpg' ), array( 'error' => 'no path' ) ) );

		$this->assertSame(
			array( array( 'file' => 'a.jpg', 'error' => 'second' ), array( 'local_path' => 'b.jpg' ) ),
			ConfigManager::get_failed_files()
		);

		ConfigManager::clear_failed_files();
		$this->assertSame( array(), ConfigManager::get_failed_files() );
	}

	public function test_clear_connection_health_returns_to_unknown(): void {
		ConfigManager::record_connection_failure( '403', 'denied', 'upload' );
		$this->assertSame( 'unhealthy', ConfigManager::get_connection_health()['status'] );

		ConfigManager::clear_connection_health();

		$this->assertSame( ConfigManager::DEFAULT_HEALTH, ConfigManager::get_connection_health() );
	}

	public function test_reset_removes_everything_the_plugin_keeps_in_options(): void {
		ConfigManager::save_provider_config( self::azure() );
		ConfigManager::save_sync_progress( array( 'status' => 'started' ) );
		ConfigManager::record_connection_failure( '403', 'denied', 'upload' );
		$GLOBALS['_test_wp_options'][ ConfigManager::LAST_UPLOAD_OPTION ] = array( 'time' => 1 );
		$GLOBALS['_test_wp_options'][ ConfigManager::SKIPPED_OPTION ]     = array( 'time' => 1 );
		$GLOBALS['_test_wp_options']['blogname']                          = 'kept';

		$this->assertTrue( ConfigManager::reset() );

		$this->assertSame( array( 'blogname' => 'kept' ), $GLOBALS['_test_wp_options'] );
		$this->assertSame( PluginState::NOT_CONFIGURED, ConfigManager::get_state() );
	}

	// ── Health probe edge ───────────────────────────────────

	public function test_a_transport_that_throws_during_the_probe_is_recorded_as_a_failure(): void {
		ConfigManager::save_provider_config( self::azure() );
		$GLOBALS['_test_wp_http'] = static function () {
			throw new \RuntimeException( 'socket gone' );
		};

		$health = ConfigManager::check_connection_health( true );

		// The provider turns the throw into a failed result, so the health
		// records it with the message and no status code to key on.
		$this->assertSame( 'unhealthy', $health['status'] );
		$this->assertSame( '', $health['error_code'] );
		$this->assertStringContainsString( 'socket gone', $health['error_message'] );
		$this->assertSame( 'health_check', $health['error_source'] );
	}

	public function test_no_mail_goes_out_without_a_valid_admin_address(): void {
		$GLOBALS['_test_wp_mail']                     = array();
		$GLOBALS['_test_wp_options']['admin_email']   = 'not-an-address';
		$GLOBALS['_test_wp_options'][ ConfigManager::STATE_OPTION ] = PluginState::OFFLOADING_ACTIVE;

		for ( $i = 0; $i < ConfigManager::PAUSE_AFTER_FAILURES; $i++ ) {
			ConfigManager::record_connection_failure( '403', 'denied', 'upload' );
		}

		$this->assertSame( array(), $GLOBALS['_test_wp_mail'] );
	}
}
