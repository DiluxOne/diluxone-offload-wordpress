<?php
namespace Tests\Unit\Admin;

use DiluxOneOffload\Admin;
use DiluxOneOffload\CloudStreamWrapper;

/**
 * What the admin says, built from what the plugin already keeps: the labels
 * of skipped files, the health pause and its banner, the rail beside every
 * screen, the strings each screen's script is handed, and the counts read
 * from the tracking table.
 */
class AdminCopyTest extends AdminTestCase {

	protected function tearDown(): void {
		CloudStreamWrapper::unregister();
		parent::tearDown();
	}

	// ── Skip reasons ────────────────────────────────────────

	/** @return array<string, array{string, string}> */
	public function skipReasons(): array {
		return array(
			'empty'            => array( 'empty_file', 'Empty files' ),
			'excluded folder'  => array( 'excluded_folder', 'In a folder Settings › Transfers leaves out' ),
			'path too long'    => array( 'path_too_long', 'Paths too long for the tracking table' ),
			'cache'            => array( 'Cache directories', 'Cache directories' ),
			'hidden'           => array( 'Hidden file (starts with .)', 'Hidden files' ),
			'system'           => array( 'System file', 'System files' ),
			'filter'           => array( 'File excluded by filter', 'Excluded by the filter' ),
			'size sentence'    => array( 'File size exceeds limit (30 MB > 20 MB)', 'Over the size limit' ),
			'pattern sentence' => array( 'Path matches exclusion pattern: private', 'Excluded paths' ),
			'type sentence'    => array( 'File extension not allowed: .exe', 'File type not allowed' ),
			'unknown'          => array( 'something new', 'something new' ),
			'prefix not at 0'  => array( 'x File size exceeds limit', 'x File size exceeds limit' ),
		);
	}

	/** @dataProvider skipReasons */
	public function test_each_skip_reason_has_one_label_and_an_unknown_one_shows_as_is( string $reason, string $label ): void {
		$this->assertSame( $label, Admin::skip_reason_label( $reason ) );
	}

	// ── Pause reason and banner ─────────────────────────────

	/** @return array<string, array{string, string}> */
	public function pauseReasons(): array {
		return array(
			'decrypt'   => array( 'decrypt_failed', 'credentials unreadable' ),
			'401'       => array( '401', 'permission denied' ),
			'403'       => array( '403', 'permission denied' ),
			'timeout'   => array( 'timeout', 'transfer timed out' ),
			'exception' => array( 'exception', 'connection error' ),
			'500'       => array( '500', 'cloud unreachable' ),
			'none'      => array( '', 'cloud unreachable' ),
		);
	}

	/** @dataProvider pauseReasons */
	public function test_the_short_pause_reason_per_error_code( string $code, string $reason ): void {
		$this->assertSame( $reason, Admin::pause_reason_short( $code ) );
	}

	public function test_a_missing_storage_is_named_as_the_provider_names_it(): void {
		$this->configure_azure();
		$this->assertSame( 'container not found', Admin::pause_reason_short( '404' ) );
		$this->configure_s3();
		$this->assertSame( 'bucket not found', Admin::pause_reason_short( '404' ) );
	}

	public function test_credential_problems_send_the_banner_to_credentials(): void {
		foreach ( array( 'decrypt_failed', '401', '403', '404', 'exception', '' ) as $code ) {
			$this->assertSame( 'credentials', self::call( 'health_banner_copy', $code, 'msg' )['cta_tab'], $code );
		}
		$this->assertSame( 'Stored Credentials Unreadable', self::call( 'health_banner_copy', 'decrypt_failed', '' )['title'] );
		$this->assertSame( 'Cloud Permission Denied', self::call( 'health_banner_copy', '401', '' )['title'] );
		$this->assertSame( 'Container or Bucket Not Found', self::call( 'health_banner_copy', '404', '' )['title'] );
	}

	public function test_a_timeout_sends_the_banner_to_transfers_and_quotes_the_setting(): void {
		$GLOBALS['_test_wp_options']['diluxone_offload_config'] = array( 'timeout' => 90 );

		$copy = self::call( 'health_banner_copy', 'timeout', '' );

		$this->assertSame( 'transfers', $copy['cta_tab'] );
		$this->assertSame( 'Cloud Transfer Timed Out', $copy['title'] );
		$this->assertStringContainsString( 'the Transfer Timeout (90 seconds)', $copy['detail'] );
	}

	public function test_an_exception_shows_its_message_or_a_generic_line(): void {
		$this->assertSame( 'socket closed', self::call( 'health_banner_copy', 'exception', 'socket closed' )['detail'] );
		$this->assertSame( 'An unexpected error occurred while talking to the cloud provider.', self::call( 'health_banner_copy', 'exception', '' )['detail'] );
		$this->assertSame( 'HTTP 502 from the gateway', self::call( 'health_banner_copy', '502', 'HTTP 502 from the gateway' )['detail'] );
	}

	/** @param array<string, mixed> $health */
	private function banner( array $health ): string {
		ob_start();
		self::call( 'render_connection_health_banner', $health + array( 'last_success' => 0 ) );
		return (string) ob_get_clean();
	}

	public function test_the_banner_escapes_the_message_and_links_to_its_tab(): void {
		$this->state( 'synced' );
		$html = $this->banner( array( 'error_code' => '500', 'error_message' => '<img src=x onerror=alert(1)>' ) );

		$this->assertStringContainsString( '<strong>Cloud Connection Error</strong>', $html );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( 'href="' . self::url( 'page=diluxone-offload-provider&tab=credentials' ) . '"', $html );
		$this->assertStringNotContainsString( 'New uploads are refused', $html, 'only said while offloading' );
		$this->assertStringNotContainsString( 'Last successful connection', $html, 'never connected' );
	}

	public function test_the_banner_while_offloading_says_uploads_are_refused(): void {
		$this->state( 'offloading_active' );
		$this->assertStringContainsString( 'New uploads are refused until the connection recovers.', $this->banner( array( 'error_code' => '403' ) ) );
	}

	/** @return array<string, array{int, string}> */
	public function lastSuccess(): array {
		return array(
			'just broke'  => array( 120, '' ),
			'minutes'     => array( 10 * 60 + 5, 'Last successful connection: 10 minutes ago' ),
			'five minutes' => array( 5 * 60, 'Last successful connection: 5 minutes ago' ),
			'hours'       => array( 3 * 3600 + 10, 'Last successful connection: 3 hours ago' ),
			'one hour'    => array( 3600, 'Last successful connection: 1 hour ago' ),
			'days'        => array( 2 * 86400 + 10, 'Last successful connection: 2 days ago' ),
		);
	}

	/** @dataProvider lastSuccess */
	public function test_the_banner_says_when_the_last_success_was_unless_it_just_broke( int $ago, string $line ): void {
		$html = $this->banner( array( 'error_code' => '500', 'last_success' => time() - $ago ) );

		if ( '' === $line ) {
			$this->assertStringNotContainsString( 'Last successful connection', $html );
		} else {
			$this->assertStringContainsString( $line, $html );
		}
	}

	// ── Script payloads ─────────────────────────────────────

	public function test_screens_without_a_script_get_no_payload(): void {
		$this->assertNull( self::call( 'tab_payload', 'settings' ) );
		$this->assertNull( self::call( 'tab_payload', 'nowhere' ) );
	}

	/** @return array<string, array{string, string, string, string}> */
	public function payloads(): array {
		return array(
			'overview'        => array( 'overview', 'DiluxOneOffloadOverview', 'diluxone-offload-admin-overview', 'stats_failed' ),
			'cloud provider'  => array( 'cloud-provider', 'DiluxOneOffloadProvider', 'diluxone-offload-admin-cloud-provider', 'connection_failed' ),
			'sync'            => array( 'sync-offloading', 'DiluxOneOffloadSync', 'diluxone-offload-admin-sync', 'sync_completed_successfully' ),
			'status'          => array( 'status', 'DiluxOneOffloadStatus', 'diluxone-offload-admin-status', 'check_now' ),
		);
	}

	/** @dataProvider payloads */
	public function test_each_script_gets_its_object_handle_strings_and_urls( string $tab, string $object, string $handle, string $string_key ): void {
		$bag = self::call( 'tab_payload', $tab );

		$this->assertSame( $object, $bag['object'] );
		$this->assertSame( $handle, $bag['handle'] );
		$this->assertArrayHasKey( $string_key, $bag['payload']['i18n'] );
		$this->assertSame( Admin::screen_urls(), $bag['payload']['data']['urls'] );
		foreach ( $bag['payload']['i18n'] as $key => $text ) {
			$this->assertIsString( $text, $key );
			$this->assertNotSame( '', $text, $key );
		}
	}

	public function test_the_provider_script_gets_the_saved_provider_and_the_presets(): void {
		$bag = self::call( 'tab_payload', 'cloud-provider', array( 'config' => array( 'cloud_provider' => 's3' ) ) );

		$this->assertSame( 's3', $bag['payload']['data']['config_cloud_provider'] );
		$this->assertSame( \DiluxOneOffload\Providers\S3Presets::all(), $bag['payload']['data']['s3_presets'] );
		$this->assertSame( '', self::call( 'tab_payload', 'cloud-provider' )['payload']['data']['config_cloud_provider'] );
	}

	public function test_the_sync_script_gets_the_current_state(): void {
		$this->assertSame( 'synced', self::call( 'tab_payload', 'sync-offloading', array( 'current_state' => 'synced' ) )['payload']['data']['current_state'] );
		$this->assertNull( self::call( 'tab_payload', 'sync-offloading' )['payload']['data']['current_state'] );
	}

	// ── Tracking counts ─────────────────────────────────────

	public function test_tracking_counts_are_typed_and_read_once_per_request(): void {
		$this->tracking_row = array( 'total' => '10', 'synced' => '7', 'pending' => '3', 'errored' => '1', 'local' => '5', 'local_size' => '2048', 'cloud_only' => '2', 'cloud_only_size' => '512' );

		$expected = array( 'total' => 10, 'synced' => 7, 'pending' => 3, 'errored' => 1, 'local' => 5, 'local_size' => 2048, 'cloud_only' => 2, 'cloud_only_size' => 512 );
		$this->assertSame( $expected, Admin::tracking_counts() );
		$this->assertStringContainsString( 'FROM wp_diluxone_offload_files', $this->queries[0] );

		$this->tracking_row = array( 'total' => '99' );
		$this->assertSame( $expected, Admin::tracking_counts(), 'cached' );
		$this->assertCount( 1, $this->queries );

		$this->assertSame( 99, Admin::tracking_counts( true )['total'], 'fresh reads again' );
		$this->assertSame( 0, Admin::tracking_counts()['synced'], 'missing columns read as zero' );
	}

	public function test_a_missing_table_counts_as_zero(): void {
		$this->tracking_row = null;
		$this->assertSame( array_fill_keys( array( 'total', 'synced', 'pending', 'errored', 'local', 'local_size', 'cloud_only', 'cloud_only_size' ), 0 ), Admin::tracking_counts() );
	}

	public function test_basic_stats_report_what_can_be_deleted_locally(): void {
		$this->tracking_row = array( 'total_files' => '4', 'total_size' => '4096' );
		$stats              = self::call( 'get_basic_stats' );
		$this->assertSame( 4, $stats['deletable_files'] );
		$this->assertSame( 4096, $stats['deletable_size'] );
		$this->assertStringContainsString( 'WHERE synced = 1 AND deleted = 0', end( $this->queries ) );

		$this->tracking_row = null;
		$stats              = self::call( 'get_basic_stats' );
		$this->assertSame( 0, $stats['deletable_files'] );
		$this->assertSame( 0, $stats['deletable_size'] );
	}

	// ── Free disk ───────────────────────────────────────────

	public function test_free_disk_is_read_from_the_servers_uploads_never_the_cloud_path(): void {
		$dir                             = sys_get_temp_dir() . '/dlx-admin-free-' . uniqid();
		$GLOBALS['_test_wp_upload_dir']  = $dir;
		mkdir( $dir );
		add_filter( 'upload_dir', array( CloudStreamWrapper::class, 'filter_upload_dir' ), 10 );

		try {
			$free = Admin::free_disk();
			$this->assertSame( (int) disk_free_space( $dir ), $free );
			$this->assertSame( 10, has_filter( 'upload_dir', array( CloudStreamWrapper::class, 'filter_upload_dir' ) ), 'the filter is put back' );
		} finally {
			rmdir( $dir );
			unset( $GLOBALS['_test_wp_upload_dir'] );
		}
	}

	public function test_free_disk_of_a_directory_that_does_not_exist_is_not_available(): void {
		$GLOBALS['_test_wp_upload_dir'] = '/nonexistent/dlx-' . uniqid();
		// wp_upload_dir() is asked not to create it; disk_free_space() then fails.
		try {
			$this->assertNull( Admin::free_disk() );
		} finally {
			unset( $GLOBALS['_test_wp_upload_dir'] );
		}
	}

	// ── The rail ────────────────────────────────────────────

	/** @return array<string, mixed> */
	private function rail( string $screen, string $tab, array $health = array( 'status' => 'healthy' ) ): array {
		return self::call( 'rail_content', $screen, $tab, $health );
	}

	public function test_with_nothing_configured_the_rail_says_media_is_local_and_reads_no_table(): void {
		$state = $this->rail( 'overview', '' )['state'];

		$this->assertSame( 'off', $state['pill'] );
		$this->assertSame( 'Off', $state['label'] );
		$this->assertSame( 'No cloud provider is connected. Media is served from this server.', $state['line'] );
		$this->assertSame( array(), $this->queries );
	}

	public function test_a_pause_overrides_the_state_and_says_why(): void {
		$this->configure_azure();
		$this->state( 'offloading_active' );
		$this->tracking_row = array( 'total' => 1 );

		$state = $this->rail( 'overview', '', array( 'status' => 'unhealthy', 'error_code' => '403', 'consecutive_failures' => 4 ) )['state'];

		$this->assertSame( 'pending', $state['pill'] );
		$this->assertSame( 'permission denied', $state['why'] );
		$this->assertSame( 'Paused (permission denied): 4 consecutive failures. Uploads are refused until the next successful connection.', $state['line'] );
	}

	public function test_offloading_names_the_azure_container_and_the_local_copies(): void {
		$this->configure_azure( 'media' );
		$this->state( 'offloading_active' );
		$this->tracking_row = array( 'synced' => 1234, 'local' => 5 );

		$state = $this->rail( 'overview', '' )['state'];

		$this->assertSame( 'active', $state['pill'] );
		$this->assertSame( 'Active', $state['label'] );
		$this->assertSame( '1,234 files synced to the Azure Blob Storage container media; 5 still have a copy on this server.', $state['line'] );
	}

	public function test_synced_names_the_s3_service_and_bucket(): void {
		$this->configure_s3( 'r2' );
		$this->state( 'synced' );
		$this->tracking_row = array( 'synced' => 3 );

		$state = $this->rail( 'overview', '' )['state'];

		$this->assertSame( 'Synced', $state['label'] );
		$this->assertSame( 'offloading off', $state['why'] );
		$this->assertSame( '3 files synced to the Cloudflare R2 bucket photos; still served from this server until offloading is enabled.', $state['line'] );
	}

	public function test_syncing_counts_done_and_pending(): void {
		$this->configure_azure();
		$this->state( 'syncing' );
		$this->tracking_row = array( 'synced' => 2, 'pending' => 8 );

		$state = $this->rail( 'overview', '' )['state'];

		$this->assertSame( 'Syncing', $state['label'] );
		$this->assertSame( 'A sync is in progress: 2 files done, 8 pending.', $state['line'] );
	}

	public function test_configured_with_rows_is_an_interrupted_sync(): void {
		$this->configure_azure();
		$this->state( 'configured' );
		$this->tracking_row = array( 'total' => 10, 'synced' => 4, 'pending' => 6 );

		$state = $this->rail( 'overview', '' )['state'];

		$this->assertSame( 'not synced', $state['why'] );
		$this->assertSame( 'Configured for the Azure Blob Storage container media. A sync was interrupted: 4 files done, 6 pending.', $state['line'] );
	}

	public function test_configured_with_nothing_tracked_has_synced_nothing_yet(): void {
		$this->configure_azure();
		$this->state( 'configured' );
		$this->tracking_row = array( 'total' => 0 );

		$this->assertSame( 'Configured for the Azure Blob Storage container media. Nothing synced yet.', $this->rail( 'overview', '' )['state']['line'] );
	}

	public function test_a_custom_s3_service_reads_as_the_family(): void {
		// Custom's preset label names examples, not a service.
		$this->configure_s3( 'custom' );
		$this->state( 'synced' );
		$this->tracking_row = array( 'synced' => 1 );

		$this->assertStringContainsString( 'the S3-compatible storage bucket photos', $this->rail( 'overview', '' )['state']['line'] );
	}

	/** @return array<string, array{string, string, string, string[]}> */
	public function railNotes(): array {
		$u = static fn( string $q ): string => 'https://example.test/wp-admin/admin.php?' . $q;
		return array(
			'overview'           => array( 'overview', '', 'What this screen is for', array( $u( 'page=diluxone-offload-provider&tab=connection' ), $u( 'page=diluxone-offload-sync&tab=sync' ), $u( 'page=diluxone-offload-status&tab=health' ), 'https://wordpress.org/support/plugin/diluxone-offload/' ) ),
			'connection'         => array( 'cloud-provider', 'connection', 'Where the keys come from', array( $u( 'page=diluxone-offload-provider&tab=credentials' ), $u( 'page=diluxone-offload-status&tab=health' ), $u( 'page=diluxone-offload-settings&tab=serving' ) ) ),
			'credentials'        => array( 'cloud-provider', 'credentials', 'What can change here', array( $u( 'page=diluxone-offload-provider&tab=connection' ), $u( 'page=diluxone-offload-status&tab=health' ), $u( 'page=diluxone-offload-sync&tab=disconnect' ) ) ),
			'sync'               => array( 'sync-offloading', 'sync', 'How the sync runs', array( $u( 'page=diluxone-offload-settings&tab=transfers' ), $u( 'page=diluxone-offload-sync&tab=offloading' ), $u( 'page=diluxone-offload-status&tab=health' ) ) ),
			'offloading'         => array( 'sync-offloading', 'offloading', 'What it changes', array( $u( 'page=diluxone-offload-sync&tab=sync' ), $u( 'page=diluxone-offload-sync&tab=disconnect' ), $u( 'page=diluxone-offload-settings&tab=serving' ) ) ),
			'disconnect'         => array( 'sync-offloading', 'disconnect', 'What a disconnect does', array( $u( 'page=diluxone-offload-status&tab=system' ), $u( 'page=diluxone-offload-provider&tab=credentials' ) ) ),
			'transfers'          => array( 'settings', 'transfers', 'What this screen is for', array( $u( 'page=diluxone-offload-sync&tab=sync' ), $u( 'page=diluxone-offload-status&tab=system' ) ) ),
			'serving'            => array( 'settings', 'serving', 'What this screen is for', array( $u( 'page=diluxone-offload-provider&tab=connection' ), $u( 'page=diluxone-offload-sync&tab=offloading' ) ) ),
			'logging'            => array( 'settings', 'logging', 'What this screen is for', array( $u( 'page=diluxone-offload-status&tab=system' ), 'https://wordpress.org/support/plugin/diluxone-offload/' ) ),
			'health'             => array( 'status', 'health', 'How health works', array( $u( 'page=diluxone-offload-provider&tab=credentials' ), 'https://example.test/wp-admin/site-health.php', 'https://wordpress.org/support/plugin/diluxone-offload/' ) ),
			'system'             => array( 'status', 'system', 'What this screen is for', array( $u( 'page=diluxone-offload-status&tab=health' ), 'https://github.com/DiluxOne/diluxone-offload-wordpress/issues/new/choose', 'https://wordpress.org/support/plugin/diluxone-offload/' ) ),
			'an unknown pair'    => array( 'status', 'nowhere', 'What this screen is for', array( $u( 'page=diluxone-offload-provider&tab=connection' ), $u( 'page=diluxone-offload-sync&tab=sync' ), $u( 'page=diluxone-offload-status&tab=health' ), 'https://wordpress.org/support/plugin/diluxone-offload/' ) ),
		);
	}

	/**
	 * @dataProvider railNotes
	 * @param string[] $urls
	 */
	public function test_each_screen_and_tab_has_its_note_and_related_links( string $screen, string $tab, string $title, array $urls ): void {
		$rail = $this->rail( $screen, $tab );

		$this->assertSame( $title, $rail['note']['title'] );
		$this->assertNotEmpty( $rail['note']['body'] );
		$this->assertSame( $urls, array_column( $rail['links'], 'url' ) );
		foreach ( $rail['links'] as $link ) {
			$this->assertNotSame( '', $link['label'] );
		}
	}

	// ── Sync before a provider, and the Test Connection gate ─

	public function test_sync_without_a_provider_points_to_the_connection_form(): void {
		ob_start();
		self::call( 'render_sync_unavailable' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Sync &amp; Offloading Not Available', $html );
		$this->assertStringContainsString( 'href="' . self::url( 'page=diluxone-offload-provider&tab=connection' ) . '"', $html );
		$this->assertStringContainsString( 'Steps to Enable Sync', $html );
		$this->assertStringNotContainsString( 'Stored Credentials Unreadable', $html );
	}

	public function test_sync_with_unreadable_credentials_points_to_re_entering_them(): void {
		$GLOBALS['_test_wp_options']['diluxone_offload_connection_health'] = array( 'status' => 'unhealthy', 'error_code' => 'decrypt_failed' );

		ob_start();
		self::call( 'render_sync_unavailable' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Stored Credentials Unreadable', $html );
		$this->assertStringContainsString( 'href="' . self::url( 'page=diluxone-offload-provider&tab=credentials' ) . '"', $html );
		$this->assertStringNotContainsString( 'Steps to Enable Sync', $html, 'not the "never configured" copy' );
	}

	public function test_a_save_needs_this_users_passing_test_of_exactly_this_configuration(): void {
		$tested  = \DiluxOneOffload\DTOs\ProviderConfig::fromArray( array( 'cloud_provider' => 'azure', 'provider_config' => array( 'storage_account' => 'acct1', 'container_name' => 'media', 'access_key' => 'a2V5' ) ) );
		$changed = \DiluxOneOffload\DTOs\ProviderConfig::fromArray( array( 'cloud_provider' => 'azure', 'provider_config' => array( 'storage_account' => 'acct1', 'container_name' => 'media', 'access_key' => 'b3RoZXI=' ) ) );

		$this->assertFalse( self::call( 'passed_connection_test', $tested ), 'nothing tested yet' );

		$GLOBALS['_test_wp_transients']['diluxone_offload_connection_test_passed_7'] = array( 'fingerprint' => $tested->fingerprint() );
		$this->assertTrue( self::call( 'passed_connection_test', $tested ) );
		$this->assertFalse( self::call( 'passed_connection_test', $changed ), 'another key was not the one tested' );

		$this->returns( 'get_current_user_id', 8 );
		$this->assertFalse( self::call( 'passed_connection_test', $tested ), 'another user\'s test does not count' );
	}
}
