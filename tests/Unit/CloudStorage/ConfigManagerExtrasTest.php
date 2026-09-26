<?php
namespace Tests\Unit\CloudStorage;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\Enums\PluginState;
use DiluxOneOffload\Enums\SyncStatus;

/**
 * ConfigManager's connection-health probe and small accessors, and the two
 * enums — all pure enough to run on the option stubs.
 */
class ConfigManagerExtrasTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/dlx-wp-content' );
		}
		$GLOBALS['_test_wp_options']    = array();
		$GLOBALS['_test_wp_transients'] = array();
		$GLOBALS['_test_wp_http_log']   = array();
		$GLOBALS['_test_wp_hooks']      = array();
		unset( $GLOBALS['_test_wp_http'] );
	}

	protected function tearDown(): void {
		\DiluxOneOffload\CloudStreamWrapper::unregister();
		unset( $GLOBALS['_test_wp_http'], $GLOBALS['_test_wp_http_log'], $GLOBALS['_test_wp_transients'], $GLOBALS['_test_wp_options'], $GLOBALS['_test_wp_hooks'] );
		parent::tearDown();
	}

	private function configure(): void {
		$GLOBALS['_test_wp_options']['diluxone_offload_config'] = array(
			'cloud_provider'  => 'azure',
			'provider_config' => array( 'storage_account' => 'cmacct', 'container_name' => 'media', 'access_key' => base64_encode( random_bytes( 32 ) ) ),
		);
	}

	private static function reply( int $code, array $headers = array() ): array {
		return array( 'response' => array( 'code' => $code, 'message' => '' ), 'body' => '', 'headers' => $headers );
	}

	/** A healthy container: 200 and a public access level browsers can read from. */
	private static function healthy(): array {
		return self::reply( 200, array( 'x-ms-blob-public-access' => 'blob' ) );
	}

	// ── check_connection_health ─────────────────────────────

	public function test_health_check_does_nothing_when_not_configured(): void {
		$h = ConfigManager::check_connection_health();
		$this->assertSame( 'unknown', $h['status'] );
		$this->assertSame( array(), $GLOBALS['_test_wp_http_log'] );
	}

	public function test_health_check_records_success(): void {
		$this->configure();
		$GLOBALS['_test_wp_http'] = fn() => self::healthy();
		$h = ConfigManager::check_connection_health();
		$this->assertSame( 'healthy', $h['status'] );
		$this->assertSame( 0, $h['consecutive_failures'] );
	}

	public function test_health_check_records_a_refusal_with_its_code(): void {
		$this->configure();
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 403 );
		$h = ConfigManager::check_connection_health();
		$this->assertSame( 'unhealthy', $h['status'] );
		$this->assertSame( '403', $h['error_code'] );
		$this->assertSame( 1, $h['consecutive_failures'] );
	}

	public function test_health_check_is_throttled_to_five_minutes(): void {
		$this->configure();
		$GLOBALS['_test_wp_options']['diluxone_offload_connection_health'] = array( 'status' => 'healthy', 'last_check' => time() - 10, 'consecutive_failures' => 0, 'error_code' => '' );
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 500 );
		$h = ConfigManager::check_connection_health();
		$this->assertSame( 'healthy', $h['status'], 'recent check: not probed again' );
		$this->assertSame( array(), $GLOBALS['_test_wp_http_log'] );
	}

	public function test_health_check_records_a_transport_exception(): void {
		$this->configure();
		$GLOBALS['_test_wp_http'] = fn() => new \WP_Error( 'x', 'name lookup timed out' );
		$h = ConfigManager::check_connection_health();
		$this->assertSame( 'unhealthy', $h['status'] );
	}

	// ── small accessors ─────────────────────────────────────

	public function test_test_connection_without_a_provider(): void {
		$r = ConfigManager::test_connection();
		$this->assertFalse( $r['success'] );
	}

	public function test_test_connection_with_a_provider(): void {
		$this->configure();
		$GLOBALS['_test_wp_http'] = fn() => self::healthy();
		$this->assertTrue( ConfigManager::test_connection()['success'] );
	}

	public function test_current_provider_config(): void {
		$this->configure();
		$this->assertSame( 'cmacct', ConfigManager::get_current_provider_config()['storage_account'] );
	}

	public function test_cached_cloud_stats_only_reads_the_transient(): void {
		$this->configure();
		$this->assertNull( ConfigManager::get_cached_cloud_stats() );
		$GLOBALS['_test_wp_transients']['diluxone_offload_azure_stats'] = array( 'fileCount' => 9 );
		$this->assertSame( 9, ConfigManager::get_cached_cloud_stats()['data']['fileCount'] );
		$this->assertSame( array(), $GLOBALS['_test_wp_http_log'] );
	}

	public function test_cached_cloud_stats_is_null_for_an_unknown_provider(): void {
		$GLOBALS['_test_wp_options']['diluxone_offload_config'] = array( 'cloud_provider' => 'gcp', 'provider_config' => array() );
		$this->assertNull( ConfigManager::get_cached_cloud_stats() );
	}

	// ── state, config and offloading switches ───────────────

	public function test_an_unknown_state_is_refused(): void {
		$this->assertFalse( ConfigManager::set_state( 'bogus' ) );
	}

	public function test_a_configured_but_unshipped_provider_yields_no_client(): void {
		$GLOBALS['_test_wp_options']['diluxone_offload_config'] = array( 'cloud_provider' => 'aws', 'provider_config' => array( 'x' => 1 ) );
		$this->assertNull( ConfigManager::get_cloud_client() );
	}

	public function test_saving_an_unsupported_provider_is_refused(): void {
		$this->assertFalse( ConfigManager::save_config( array( 'cloud_provider' => 'dropbox', 'provider_config' => array() ) ) );
		$this->assertFalse( ConfigManager::save_provider_config( array( 'cloud_provider' => 'dropbox', 'provider_config' => array() ) ) );
	}

	public function test_a_provider_config_that_is_not_an_array_is_refused(): void {
		$this->assertFalse( ConfigManager::save_config( array( 'cloud_provider' => 'azure', 'provider_config' => 'garbage' ) ) );
		$this->assertFalse( ConfigManager::save_provider_config( array( 'cloud_provider' => 'azure', 'provider_config' => 'garbage' ) ) );
	}

	public function test_invalid_plugin_settings_are_refused(): void {
		$this->assertFalse( ConfigManager::save_plugin_settings( array( 'max_file_size' => -1 ) ) );
	}

	public function test_a_corrupt_stored_config_falls_back_to_defaults(): void {
		$GLOBALS['_test_wp_options']['diluxone_offload_config'] = array( 'cloud_provider' => 'azure', 'provider_config' => 'not-an-array', 'max_file_size' => -5 );
		$cfg = ConfigManager::get_config();
		$this->assertIsArray( $cfg );
		$this->assertSame( '', $cfg['cloud_provider'] );
	}

	public function test_removing_the_provider_drops_the_state_to_not_configured(): void {
		$this->configure();
		$GLOBALS['_test_wp_options']['diluxone_offload_plugin_state'] = PluginState::CONFIGURED;
		$this->assertTrue( ConfigManager::save_config( array( 'cloud_provider' => '', 'provider_config' => array() ) ) );
		$this->assertSame( PluginState::NOT_CONFIGURED, ConfigManager::get_state() );
	}

	public function test_failed_file_entries_without_a_path_are_dropped(): void {
		ConfigManager::add_failed_files( array( array( 'error' => 'no path' ), array( 'file' => '/a.jpg', 'error' => 'x' ) ) );
		$this->assertCount( 1, ConfigManager::get_failed_files() );
	}

	public function test_offloading_switches_follow_the_state(): void {
		$this->configure();
		$GLOBALS['_test_wp_options']['diluxone_offload_plugin_state'] = PluginState::CONFIGURED;
		$this->assertFalse( ConfigManager::enable_offloading(), 'needs a completed sync first' );
		$this->assertFalse( ConfigManager::disable_offloading(), 'nothing to disable' );
		$GLOBALS['_test_wp_options']['diluxone_offload_plugin_state'] = PluginState::SYNCED;
		$this->assertTrue( ConfigManager::enable_offloading() );
		$this->assertSame( PluginState::OFFLOADING_ACTIVE, ConfigManager::get_state() );
		$this->assertTrue( ConfigManager::disable_offloading() );
		$this->assertSame( PluginState::SYNCED, ConfigManager::get_state() );
	}

	// ── enums ───────────────────────────────────────────────

	public function test_plugin_state_predicates_and_names(): void {
		$this->assertTrue( PluginState::can_disconnect( PluginState::SYNCED ) );
		$this->assertTrue( PluginState::can_disconnect( PluginState::OFFLOADING_ACTIVE ) );
		$this->assertFalse( PluginState::can_disconnect( PluginState::CONFIGURED ) );
		$this->assertTrue( PluginState::is_offloading_active( PluginState::OFFLOADING_ACTIVE ) );
		$this->assertFalse( PluginState::is_offloading_active( PluginState::SYNCED ) );
		$this->assertSame( 'Offloading Active', PluginState::get_state_name( PluginState::OFFLOADING_ACTIVE ) );
		$this->assertSame( 'weird', PluginState::get_state_name( 'weird' ) );
		$this->assertCount( 5, PluginState::get_all_states() );
	}

	public function test_sync_status_finished(): void {
		$this->assertTrue( SyncStatus::isFinished( SyncStatus::COMPLETED ) );
		$this->assertTrue( SyncStatus::isFinished( SyncStatus::CANCELLED ) );
		$this->assertFalse( SyncStatus::isFinished( SyncStatus::STARTED ) );
	}

	// ── The error code read from a failure message ──────────

	/**
	 * @dataProvider messages
	 */
	public function test_the_error_code_is_read_from_the_message( string $message, string $expected ): void {
		$this->assertSame( $expected, ConfigManager::error_code_from_message( $message ) );
	}

	/** @return array<string, array{string, string}> */
	public static function messages(): array {
		return array(
			'a status on its own'               => array( 'Upload failed with status: 403', '403' ),
			'a status inside a sentence'        => array( 'Download failed with status: 404 - Azure BlobNotFound', '404' ),
			'a transport timeout (60000 ms)'    => array( 'Upload failed: cURL error 28: Operation timed out after 60000 milliseconds with 0 bytes received', 'timeout' ),
			'the word timeout'                  => array( 'Connection timeout while talking to the provider', 'timeout' ),
			'digits that are not a status'      => array( 'Block 1 of 120 failed: 1048576 bytes short', '' ),
			'a 2xx is not an error code'        => array( 'Unexpected status 201', '' ),
			'nothing to read'                   => array( 'Unknown upload error', '' ),
			'a status wins over a file name'    => array( 'Upload failed with status: 403 - timeout-banner.jpg', '403' ),
		);
	}
}
