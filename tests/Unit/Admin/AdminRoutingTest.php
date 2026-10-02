<?php
namespace Tests\Unit\Admin;

use DiluxOneOffload\Admin;
use Mockery;

/**
 * The screens and how a request reaches one: the submenu slugs, the tabs,
 * the old `&tab=` URLs, the URLs the scripts are handed, the browser title,
 * and the hooks and menu entries that make the screens exist at all.
 */
class AdminRoutingTest extends AdminTestCase {

	// ── The screen list ─────────────────────────────────────

	public function test_the_screens_in_walking_order_with_their_pages_and_tabs(): void {
		$screens = Admin::screens();

		$this->assertSame( array( 'overview', 'cloud-provider', 'sync-offloading', 'settings', 'status' ), array_keys( $screens ) );
		$this->assertSame(
			array(
				'overview'        => 'diluxone-offload',
				'cloud-provider'  => 'diluxone-offload-provider',
				'sync-offloading' => 'diluxone-offload-sync',
				'settings'        => 'diluxone-offload-settings',
				'status'          => 'diluxone-offload-status',
			),
			array_map( fn( $s ) => $s['page'], $screens )
		);
		$this->assertSame(
			array(
				'overview'        => array(),
				'cloud-provider'  => array( 'connection', 'credentials' ),
				'sync-offloading' => array( 'sync', 'offloading', 'disconnect' ),
				'settings'        => array( 'transfers', 'serving', 'logging' ),
				'status'          => array( 'health', 'system' ),
			),
			array_map( fn( $s ) => array_keys( $s['tabs'] ), $screens )
		);
		$this->assertSame( 'Sync & Offloading', $screens['sync-offloading']['label'] );
	}

	public function test_the_plugin_introduces_itself_by_its_name(): void {
		$this->assertSame( 'DiluxOne Offload', Admin::plugin_name() );
	}

	/** @return array<string, array{string, string}> */
	public function pages(): array {
		return array(
			'top level'     => array( 'diluxone-offload', 'overview' ),
			'provider'      => array( 'diluxone-offload-provider', 'cloud-provider' ),
			'sync'          => array( 'diluxone-offload-sync', 'sync-offloading' ),
			'settings'      => array( 'diluxone-offload-settings', 'settings' ),
			'status'        => array( 'diluxone-offload-status', 'status' ),
			'unknown'       => array( 'diluxone-offload-tools', 'overview' ),
			'someone else'  => array( 'woocommerce', 'overview' ),
		);
	}

	/** @dataProvider pages */
	public function test_a_submenu_slug_maps_to_its_screen( string $page, string $screen ): void {
		$this->assertSame( $screen, Admin::screen_for_page( $page ) );
	}

	/** @return array<string, array{string, string, string}> */
	public function tabs(): array {
		return array(
			'a tab the screen has'       => array( 'settings', 'serving', 'serving' ),
			'a tab of another screen'    => array( 'settings', 'health', 'transfers' ),
			'no tab asked'               => array( 'status', '', 'health' ),
			'a screen without tabs'      => array( 'overview', 'sync', '' ),
			'an unknown screen'          => array( 'nowhere', 'sync', '' ),
		);
	}

	/** @dataProvider tabs */
	public function test_the_tab_shown_is_the_requested_one_or_the_screens_first( string $screen, string $tab, string $expected ): void {
		$this->assertSame( $expected, Admin::tab_for( $screen, $tab ) );
	}

	/** @return array<string, array{string, string, string}> */
	public function legacyTabs(): array {
		return array(
			'cloud-provider'  => array( 'cloud-provider', 'cloud-provider', 'connection' ),
			'sync-offloading' => array( 'sync-offloading', 'sync-offloading', 'sync' ),
			'sync (older)'    => array( 'sync', 'sync-offloading', 'sync' ),
			'settings'        => array( 'settings', 'settings', 'transfers' ),
			'status'          => array( 'status', 'status', 'health' ),
			'status-tools'    => array( 'status-tools', 'status', 'health' ),
			'tools (gone)'    => array( 'tools', 'overview', '' ),
			'empty'           => array( '', 'overview', '' ),
		);
	}

	/** @dataProvider legacyTabs */
	public function test_an_old_tab_url_maps_to_a_screen_and_tab( string $old, string $screen, string $tab ): void {
		$this->assertSame( array( $screen, $tab ), Admin::legacy_tab( $old ) );
	}

	// ── URLs ────────────────────────────────────────────────

	public function test_a_screen_url_carries_its_page_and_a_known_tab_only(): void {
		$this->assertSame( self::url( 'page=diluxone-offload-settings&tab=serving' ), Admin::screen_url( 'settings', 'serving' ) );
		$this->assertSame( self::url( 'page=diluxone-offload-settings' ), Admin::screen_url( 'settings', 'health' ), 'another screen\'s tab is dropped' );
		$this->assertSame( self::url( 'page=diluxone-offload-settings' ), Admin::screen_url( 'settings' ) );
	}

	public function test_an_unknown_screen_url_is_overview_and_extra_arguments_travel_along(): void {
		$this->assertSame( self::url( 'page=diluxone-offload' ), Admin::screen_url( 'nowhere', 'sync' ) );
		$this->assertSame(
			self::url( 'page=diluxone-offload-sync&tab=sync&auto-start=1' ),
			Admin::screen_url( 'sync-offloading', 'sync', array( 'auto-start' => '1' ) )
		);
	}

	public function test_the_scripts_get_one_url_per_tab_and_overview(): void {
		$urls = Admin::screen_urls();

		$this->assertSame(
			array( 'overview', 'connection', 'credentials', 'sync', 'offloading', 'disconnect', 'transfers', 'serving', 'logging', 'health', 'system' ),
			array_keys( $urls )
		);
		$this->assertSame( self::url( 'page=diluxone-offload' ), $urls['overview'] );
		$this->assertSame( self::url( 'page=diluxone-offload-provider&tab=credentials' ), $urls['credentials'] );
		$this->assertSame( self::url( 'page=diluxone-offload-status&tab=system' ), $urls['system'] );
	}

	// ── Titles ──────────────────────────────────────────────

	public function test_a_screen_title_is_the_plugin_name_a_bar_and_the_screen(): void {
		$this->assertSame( 'DiluxOne Offload | Status', Admin::screen_title( 'status' ) );
		$this->assertSame( 'DiluxOne Offload | Overview', Admin::screen_title( 'nowhere' ) );
	}

	public function test_the_browser_title_of_a_plugin_screen_names_the_plugin(): void {
		$screen     = Mockery::mock( 'WP_Screen' );
		$screen->id = 'diluxone-offload_page_diluxone-offload-status';
		$this->returns( 'get_current_screen', $screen );
		$_GET['page'] = 'diluxone-offload-status';

		$this->assertSame(
			'DiluxOne Offload | Status &lsaquo; Site &#8212; WordPress',
			Admin::admin_title( 'Status &lsaquo; Site &#8212; WordPress', 'Status' )
		);
	}

	public function test_the_browser_title_of_another_screen_is_left_alone(): void {
		$screen     = Mockery::mock( 'WP_Screen' );
		$screen->id = 'edit-post';
		$this->returns( 'get_current_screen', $screen );

		$this->assertSame( 'Posts &lsaquo; Site', Admin::admin_title( 'Posts &lsaquo; Site', 'Posts' ) );
	}

	public function test_the_browser_title_without_a_screen_is_left_alone(): void {
		$this->returns( 'get_current_screen', null );
		$this->assertSame( 'Dashboard', Admin::admin_title( 'Dashboard', 'Dashboard' ) );
	}

	public function test_an_unknown_page_reads_as_overview_in_the_title(): void {
		$screen     = Mockery::mock( 'WP_Screen' );
		$screen->id = 'toplevel_page_diluxone-offload';
		$this->returns( 'get_current_screen', $screen );
		$_GET['page'] = 'diluxone-offload<script>';

		$this->assertSame( 'DiluxOne Offload | Overview', Admin::admin_title( 'DiluxOne Offload', 'DiluxOne Offload' ) );
	}

	// ── Hooks and menu ──────────────────────────────────────

	public function test_init_registers_the_menu_title_assets_save_and_every_ajax_action(): void {
		Admin::init();

		$actions = array_map( fn( $h ) => $h[0]['callback'], $GLOBALS['_test_wp_hooks']['action'] );
		$this->assertSame( array( Admin::class, 'add_admin_menu' ), $actions['admin_menu'] );
		$this->assertSame( array( Admin::class, 'enqueue_admin_assets' ), $actions['admin_enqueue_scripts'] );
		$this->assertSame( array( Admin::class, 'save_config' ), $actions['admin_post_diluxone_offload_save_config'] );
		foreach ( array( 'test_connection', 'save_updated_credentials', 'cancel_sync', 'mark_sync_complete', 'clear_failed', 'refresh_stats', 'check_health' ) as $ajax ) {
			$this->assertSame( array( Admin::class, 'ajax_' . $ajax ), $actions[ 'wp_ajax_diluxone_offload_' . $ajax ], $ajax );
			$this->assertTrue( is_callable( $actions[ 'wp_ajax_diluxone_offload_' . $ajax ] ), $ajax );
		}
		$this->assertSame( array( Admin::class, 'ajax_remove_provider' ), $actions['wp_ajax_diluxone_offload_ajax_remove_provider'] );
		$this->assertSame( array( Admin::class, 'admin_title' ), $GLOBALS['_test_wp_hooks']['filter']['admin_title'][0]['callback'] );
	}

	public function test_the_menu_is_one_entry_with_a_submenu_per_screen_for_administrators(): void {
		Admin::add_admin_menu();

		$menu    = $GLOBALS['_test_admin_calls']['add_menu_page'];
		$submenu = $GLOBALS['_test_admin_calls']['add_submenu_page'];

		$this->assertSame(
			array( array( 'DiluxOne Offload', 'DiluxOne Offload', 'manage_options', 'diluxone-offload', array( Admin::class, 'render_admin_page' ), 'dashicons-cloud' ) ),
			$menu
		);
		$this->assertSame(
			array( 'diluxone-offload', 'diluxone-offload-provider', 'diluxone-offload-sync', 'diluxone-offload-settings', 'diluxone-offload-status' ),
			array_map( fn( $s ) => $s[4], $submenu )
		);
		foreach ( $submenu as $entry ) {
			$this->assertSame( 'diluxone-offload', $entry[0], 'all under the plugin\'s own menu' );
			$this->assertSame( 'manage_options', $entry[3] );
		}
		$this->assertSame(
			array( Admin::class, 'redirect_legacy_tab' ),
			$GLOBALS['_test_wp_hooks']['action']['load-toplevel_page_diluxone-offload'][0]['callback'],
			'old &tab= URLs are redirected before output'
		);
	}

	public function test_no_redirect_hook_when_the_menu_page_was_not_added(): void {
		$this->returns( 'add_menu_page', false );
		$this->returns( 'add_submenu_page', false );

		Admin::add_admin_menu();

		$this->assertArrayNotHasKey( 'action', $GLOBALS['_test_wp_hooks'] );
	}

	public function test_a_top_level_url_without_tab_is_not_redirected(): void {
		$_GET['page'] = 'diluxone-offload';

		Admin::redirect_legacy_tab();

		$this->assertArrayNotHasKey( 'wp_safe_redirect', $GLOBALS['_test_admin_calls'] );
	}

	// ── Version and build ───────────────────────────────────

	public function test_the_version_comes_from_the_plugin_header_once_per_request(): void {
		$this->returns( 'get_plugin_data', array( 'Version' => '9.8.7' ) );
		$this->assertSame( '9.8.7', Admin::get_plugin_version() );

		$this->returns( 'get_plugin_data', array( 'Version' => '0.0.1' ) );
		$this->assertSame( '9.8.7', Admin::get_plugin_version(), 'cached for the request' );
	}

	public function test_a_development_build_names_its_commit_and_a_release_names_none(): void {
		$this->answer(
			'get_file_data',
			function ( string $file, array $headers ) {
				$this->assertSame( DILUXONE_OFFLOAD_FILE, $file );
				$this->assertSame( array( 'build' => 'Build' ), $headers );
				return array( 'build' => 'abc1234' );
			}
		);
		$this->assertSame( 'abc1234', Admin::get_plugin_build() );

		$this->returns( 'get_file_data', array() );
		$this->assertSame( '', Admin::get_plugin_build() );
	}

	// ── Accent colour and asset versions ────────────────────

	public function test_the_accent_is_the_third_colour_of_the_users_scheme(): void {
		$this->returns( 'get_user_option', 'ocean' );
		$GLOBALS['_wp_admin_css_colors'] = array( 'ocean' => (object) array( 'colors' => array( '#aa9d88', '#9ebaa0', '#738e96', '#f2fcff' ) ) );

		$this->assertSame( '#738e96', Admin::accent_color() );
	}

	public function test_a_scheme_with_two_colours_uses_its_last(): void {
		$this->returns( 'get_user_option', 'duo' );
		$GLOBALS['_wp_admin_css_colors'] = array( 'duo' => (object) array( 'colors' => array( '#111111', '#abcdef' ) ) );

		$this->assertSame( '#abcdef', Admin::accent_color() );
	}

	/** @return array<string, array{mixed}> */
	public function badSchemes(): array {
		return array(
			'unknown scheme'      => array( null ),
			'empty palette'       => array( array() ),
			'not a colour'        => array( array( '#111', '#222', 'red;}body{display:none' ) ),
			'not a string'        => array( array( '#111', '#222', 3 ) ),
		);
	}

	/**
	 * @dataProvider badSchemes
	 * @param mixed $colors
	 */
	public function test_anything_but_a_hex_colour_falls_back_to_wordpress_blue( $colors ): void {
		$this->returns( 'get_user_option', 'odd' );
		if ( null !== $colors ) {
			$GLOBALS['_wp_admin_css_colors'] = array( 'odd' => (object) array( 'colors' => $colors ) );
		}

		$this->assertSame( '#2271b1', Admin::accent_color() );
	}

	public function test_assets_are_versioned_by_release_in_production(): void {
		$this->returns( 'wp_get_environment_type', 'production' );
		$this->assertSame( DILUXONE_OFFLOAD_VERSION, self::call( 'asset_version', 'assets/js/admin.js' ) );
	}

	public function test_assets_are_versioned_by_file_time_elsewhere(): void {
		$this->returns( 'wp_get_environment_type', 'local' );
		$file = 'assets/css/admin.css';
		$this->assertFileExists( DILUXONE_OFFLOAD_DIR . $file );

		$this->assertSame( (string) filemtime( DILUXONE_OFFLOAD_DIR . $file ), self::call( 'asset_version', $file ) );
		$this->assertSame( DILUXONE_OFFLOAD_VERSION, self::call( 'asset_version', 'assets/css/missing.css' ), 'a missing file falls back' );
	}

	public function test_every_screen_has_its_own_stylesheet_and_every_screen_with_strings_its_script(): void {
		$assets = self::call( 'tab_assets' );

		$this->assertSame( array_keys( Admin::screens() ), array_keys( $assets ) );
		foreach ( $assets as $screen => $base ) {
			$this->assertFileExists( DILUXONE_OFFLOAD_DIR . 'assets/css/' . $base . '.css' );
			if ( null !== self::call( 'tab_payload', $screen ) ) {
				// Its strings are localized onto this script: without the file they go nowhere.
				$this->assertFileExists( DILUXONE_OFFLOAD_DIR . 'assets/js/' . $base . '.js', $screen );
			}
		}
	}

	// ── Flash notices ───────────────────────────────────────

	public function test_a_notice_is_kept_per_user_shown_once_and_escaped(): void {
		Admin::flash_notice( 'error', 'Bad <b>key</b>' );
		$this->assertSame( array( 'type' => 'error', 'message' => 'Bad <b>key</b>' ), $GLOBALS['_test_wp_transients']['diluxone_offload_notice_7'] );

		ob_start();
		Admin::render_flash_notice();
		Admin::render_flash_notice();
		$html = (string) ob_get_clean();

		$this->assertSame( '<div class="notice notice-error is-dismissible"><p>Bad &lt;b&gt;key&lt;/b&gt;</p></div>', $html, 'printed once, escaped' );
		$this->assertArrayNotHasKey( 'diluxone_offload_notice_7', $GLOBALS['_test_wp_transients'] );
	}

	public function test_any_type_but_error_is_a_success_notice(): void {
		Admin::flash_notice( 'warning"><script>', 'Saved.' );
		$this->assertSame( 'success', $GLOBALS['_test_wp_transients']['diluxone_offload_notice_7']['type'] );
	}

	public function test_another_users_notice_is_not_shown(): void {
		Admin::flash_notice( 'success', 'For user 7' );
		$this->returns( 'get_current_user_id', 8 );

		$this->expectOutputString( '' );
		Admin::render_flash_notice();
	}

	public function test_an_empty_or_broken_notice_prints_nothing(): void {
		$GLOBALS['_test_wp_transients']['diluxone_offload_notice_7'] = array( 'type' => 'success', 'message' => '' );
		$this->expectOutputString( '' );
		Admin::render_flash_notice();
		$GLOBALS['_test_wp_transients']['diluxone_offload_notice_7'] = 'garbage';
		Admin::render_flash_notice();
	}

	// ── posted_fields ───────────────────────────────────────

	private function allow( bool $nonce, bool $capability ): void {
		$this->answer(
			'wp_verify_nonce',
			static function ( string $nonce_value, string $action ) use ( $nonce ) {
				return $nonce && 'good' === $nonce_value ? 1 : false;
			}
		);
		$this->returns( 'current_user_can', $capability );
	}

	public function test_posted_fields_returns_only_the_asked_keys_unslashed_and_sanitized(): void {
		$this->allow( true, true );
		$_POST = array(
			'_wpnonce'         => 'good',
			'timeout'          => " 120<script> ",
			'cache_control'    => 'max-age=60, \\"public\\"',
			'excluded_folders' => "backups\ncache/<b>x</b>",
			'not_asked'        => 'leak',
		);

		$fields = self::call( 'posted_fields', array( 'timeout', 'cache_control', 'excluded_folders', 'absent' ) );

		$this->assertSame(
			array(
				'timeout'          => '120',
				'cache_control'    => 'max-age=60, "public"',
				'excluded_folders' => "backups\ncache/x",
			),
			$fields
		);
	}

	public function test_an_array_where_a_field_is_expected_arrives_empty(): void {
		$this->allow( true, true );
		$_POST = array( 'nonce' => 'good', 'bucket' => array( 'a', 'b' ) );

		$this->assertSame( array( 'bucket' => '' ), self::call( 'posted_fields', array( 'bucket' ) ) );
	}

	public function test_without_a_valid_nonce_no_field_is_read(): void {
		$this->allow( false, true );
		$_POST = array( '_wpnonce' => 'forged', 'bucket' => 'x' );

		$this->assertSame( array(), self::call( 'posted_fields', array( 'bucket' ) ) );
	}

	public function test_without_the_capability_no_field_is_read(): void {
		$this->allow( true, false );
		$_POST = array( '_wpnonce' => 'good', 'bucket' => 'x' );

		$this->assertSame( array(), self::call( 'posted_fields', array( 'bucket' ) ) );
	}

	// ── Provider removal ────────────────────────────────────

	public function test_the_provider_can_be_deleted_only_while_the_cloud_is_not_the_only_copy(): void {
		$this->assertTrue( Admin::can_delete_provider( 'configured' ) );
		$this->assertTrue( Admin::can_delete_provider( 'syncing' ) );
		$this->assertTrue( Admin::can_delete_provider( 'synced' ) );
		$this->assertFalse( Admin::can_delete_provider( 'offloading_active' ), 'disconnect first' );
		$this->assertFalse( Admin::can_delete_provider( 'not_configured' ) );
		$this->assertFalse( Admin::can_delete_provider( '' ) );
	}
}
