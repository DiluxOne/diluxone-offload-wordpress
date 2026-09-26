<?php
/**
 * The plugin's admin screen: one page with a tab bar.
 *
 * Direct $wpdb queries against the plugin's own table (`$wpdb->prefix .
 * 'diluxone_offload_files'`) are used in a few read-only spots to render real-time
 * sync progress; cache layers don't apply because the value would be stale.
 * The table name is derived from $wpdb->prefix and never from user input.
 * These rules are intentionally suppressed file-wide:
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin's admin screen.
 */
class Admin {

	/** The menu slug, written once. */
	const MENU = 'diluxone-offload';

	/**
	 * How the plugin introduces itself in the dashboard.
	 *
	 * Written once: the menu, the heading and the browser tab all read it from
	 * here. Spelled out in three places, sooner or later they say three
	 * different things — which is exactly how the heading ended up still
	 * saying "Cloud Storage" long after the plugin stopped being called that.
	 *
	 * @return string
	 */
	public static function plugin_name(): string {
		return (string) \apply_filters( 'diluxone_offload_plugin_name', \__( 'DiluxOne Offload', 'diluxone-offload' ) );
	}

	/**
	 * The tabs, in order: slug => [label, dashicon, aliases, hidden].
	 *
	 * The tab bar, the browser title and the routing all walk this list, so a
	 * tab cannot exist in one and be missing from another. A hidden tab still
	 * answers to its URL and gets its own title, it just is not offered in the
	 * bar.
	 *
	 * @return array<string, array{label: string, icon: string, aliases: string[], hidden: bool}>
	 */
	public static function tabs(): array {
		return array(
			'overview'        => array(
				'label'   => \__( 'Overview', 'diluxone-offload' ),
				'icon'    => 'dashicons-dashboard',
				'aliases' => array(),
				'hidden'  => false,
			),
			'cloud-provider'  => array(
				'label'   => \__( 'Cloud Provider', 'diluxone-offload' ),
				'icon'    => 'dashicons-cloud-upload',
				'aliases' => array(),
				'hidden'  => false,
			),
			'sync-offloading' => array(
				'label'   => \__( 'Sync & Offloading', 'diluxone-offload' ),
				'icon'    => 'dashicons-update',
				'aliases' => array( 'sync' ),
				'hidden'  => false,
			),
			'settings'        => array(
				'label'   => \__( 'Settings', 'diluxone-offload' ),
				'icon'    => 'dashicons-admin-settings',
				'aliases' => array(),
				'hidden'  => false,
			),
			'status'          => array(
				'label'   => \__( 'Status', 'diluxone-offload' ),
				'icon'    => 'dashicons-info',
				'aliases' => array( 'status-tools' ),
				'hidden'  => false,
			),
		);
	}

	/**
	 * The name of the tab currently open, resolved through its aliases.
	 *
	 * @param string $tab The raw `tab` parameter.
	 * @return string The canonical tab slug.
	 */
	public static function current_tab( string $tab ): string {
		$tabs = self::tabs();

		if ( isset( $tabs[ $tab ] ) ) {
			return $tab;
		}

		foreach ( $tabs as $slug => $meta ) {
			if ( in_array( $tab, $meta['aliases'], true ) ) {
				return $slug;
			}
		}

		return 'overview';
	}

	/**
	 * The browser tab: the plugin's name, then the screen's.
	 *
	 * In a dashboard with twenty plugins, "Status" says nothing about whose
	 * screen it is. "DiluxOne Offload | Status" does.
	 *
	 * @param string $admin_title The title WordPress built.
	 * @param string $title       The screen's own title.
	 * @return string
	 */
	public static function admin_title( string $admin_title, string $title ): string {
		$screen = \function_exists( 'get_current_screen' ) ? \get_current_screen() : null;

		if ( ! $screen instanceof \WP_Screen || false === strpos( (string) $screen->id, self::MENU ) ) {
			return $admin_title;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing parameter, no state change.
		$tab     = isset( $_GET['tab'] ) ? \sanitize_text_field( \wp_unslash( $_GET['tab'] ) ) : 'overview';
		$tabs    = self::tabs();
		$abierta = $tabs[ self::current_tab( $tab ) ]['label'];

		$nuevo = sprintf(
			/* translators: 1: plugin name, 2: name of the screen */
			\_x( '%1$s | %2$s', 'a dashboard screen title', 'diluxone-offload' ),
			self::plugin_name(),
			$abierta
		);

		return str_replace( $title, $nuevo, $admin_title );
	}

	/**
	 * Initialize admin hooks
	 */
	public static function init(): void {
		Logger::debug( '[DiluxOne Offload] Admin::init() called - registering hooks' );
		\add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
		\add_filter( 'admin_title', array( __CLASS__, 'admin_title' ), 10, 2 );
		\add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		\add_action( 'admin_post_diluxone_offload_save_config', array( __CLASS__, 'save_config' ) );

		// Register AJAX handlers
		\add_action( 'wp_ajax_diluxone_offload_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
		\add_action( 'wp_ajax_diluxone_offload_save_updated_credentials', array( __CLASS__, 'ajax_save_updated_credentials' ) );
		// DISABLED: ajax_diluxone_offload_start_sync now handled by Plugin::ajax_cs_start_sync in class-diluxone-offload-plugin-enhanced.php (legacy handler removed).
		\add_action( 'wp_ajax_diluxone_offload_cancel_sync', array( __CLASS__, 'ajax_cancel_sync' ) );
		\add_action( 'wp_ajax_diluxone_offload_mark_sync_complete', array( __CLASS__, 'ajax_mark_sync_complete' ) );
		\add_action( 'wp_ajax_diluxone_offload_clear_failed', array( __CLASS__, 'ajax_clear_failed' ) );
		\add_action( 'wp_ajax_diluxone_offload_ajax_remove_provider', array( __CLASS__, 'ajax_remove_provider' ) );
		\add_action( 'wp_ajax_diluxone_offload_refresh_stats', array( __CLASS__, 'ajax_refresh_stats' ) );
		Logger::debug( '[DiluxOne Offload] Admin hooks registered successfully' );
	}

	/**
	 * Register the top-level admin menu.
	 *
	 * Standalone menu — no shared "DiluxOne" parent.
	 *
	 * @return void
	 */
	public static function add_admin_menu() {
		\add_menu_page(
			self::plugin_name(),                     // Page title.
			self::plugin_name(),                     // Menu label.
			'manage_options',                        // Capability.
			self::MENU,                              // Menu slug.
			array( __CLASS__, 'render_admin_page' ), // Callback.
			'dashicons-cloud',                       // Icon.
			81                                       // Position (below the Settings block).
		);
	}

	/**
	 * Render the admin page.
	 */
	public static function render_admin_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing parameter, no state change.
		$requested   = isset( $_GET['tab'] ) ? \sanitize_text_field( \wp_unslash( $_GET['tab'] ) ) : 'overview';
		$current_tab = self::current_tab( $requested );

		// Check configuration states
		$is_configured         = ConfigManager::is_configured();
		$current_state         = ConfigManager::get_state();
		$is_synced             = ( $current_state === 'synced' || $current_state === 'offloading_active' );
		$is_offloading_enabled = ( $current_state === 'offloading_active' );

		?>
		<div class="wrap diluxone-offload-admin">
			<h1>
				<span class="dashicons dashicons-cloud"></span>
				<?php echo \esc_html( self::plugin_name() ); ?>
			</h1>

			<!-- Tabs Navigation -->
			<nav class="nav-tab-wrapper">
				<?php foreach ( self::tabs() as $slug => $meta ) : ?>
					<?php
					if ( $meta['hidden'] ) {
						continue; }
					?>
					<a href="
					<?php
					echo \esc_url(
						\add_query_arg(
							array(
								'page' => self::MENU,
								'tab'  => $slug,
							),
							\admin_url( 'admin.php' )
						)
					);
					?>
								"
						class="nav-tab <?php echo esc_attr( $current_tab === $slug ? 'nav-tab-active' : '' ); ?>">
						<span class="dashicons <?php echo \esc_attr( $meta['icon'] ); ?>"></span>
						<?php echo \esc_html( $meta['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<!-- Tab Content -->
			<div class="tab-content" style="margin-top: 20px;">
				<?php
				self::render_flash_notice();

				// Connection health check (5-min TTL)
				$health = ConfigManager::check_connection_health();
				if ( $health['status'] === 'unhealthy' ) {
					self::render_connection_health_banner( $health );
				}
				?>
				<?php self::render_tab_content( $current_tab ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Queue a one-time notice for the current user, shown on their next
	 * plugin admin page.
	 *
	 * Handlers that redirect used to put the text in the URL; anyone could
	 * then craft a link that showed an admin an arbitrary "success" message.
	 * The text now travels in a short-lived, per-user transient instead, so
	 * a plugin page only ever shows what this plugin's own code queued.
	 *
	 * @param string $type    'success' or 'error'.
	 * @param string $message Plain text; escaped on output.
	 */
	public static function flash_notice( string $type, string $message ): void {
		set_transient(
			self::flash_notice_key(),
			array(
				'type'    => 'error' === $type ? 'error' : 'success',
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Print and discard the queued notice, if any.
	 */
	public static function render_flash_notice(): void {
		$key    = self::flash_notice_key();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		delete_transient( $key );

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( (string) $notice['type'] ),
			esc_html( (string) $notice['message'] )
		);
	}

	/**
	 * @return string Transient name for the current user's queued notice.
	 */
	private static function flash_notice_key(): string {
		return 'diluxone_offload_notice_' . get_current_user_id();
	}

	/**
	 * The named fields of the current request, and nothing else.
	 *
	 * A DTO factory must never be handed $_POST itself: the whole superglobal
	 * carries whatever else was posted, and no sniff — and no reader — can tell
	 * what the callee touches. This returns a slice containing only the keys
	 * asked for, each one unslashed and sanitized as text here; the factory
	 * then validates every field for its own type (integer ranges, format
	 * regexes). A crafted `field[]=` arrives as '' rather than an array, so
	 * nothing downstream can be handed a type it does not expect.
	 *
	 * The nonce and capability for the request are verified by the caller
	 * before this runs.
	 *
	 * @param string[] $keys Field names to take.
	 * @return array<string, string>
	 */
	private static function posted_fields( array $keys ): array {
		$fields = array();

		foreach ( $keys as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the caller.
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only checked for being a scalar; sanitized on the next line.
			if ( ! is_scalar( $_POST[ $key ] ) ) {
				$fields[ $key ] = '';
				continue;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the caller.
			$fields[ $key ] = sanitize_text_field( (string) wp_unslash( $_POST[ $key ] ) );
		}

		return $fields;
	}

	/**
	 * Redirect back to a plugin tab after an admin_post handler.
	 *
	 * @param string $tab Tab slug.
	 */
	private static function redirect_to_tab( string $tab ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => self::MENU,
					'tab'  => $tab,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Single source of truth for the plugin version: the `Version:` line of
	 * the main plugin file. Cached per request.
	 */
	public static function get_plugin_version(): string {
		static $version = null;
		if ( $version !== null ) {
			return $version;
		}
		if ( ! \function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data    = \get_plugin_data( DILUXONE_OFFLOAD_FILE, false, false );
		$version = $data['Version'] !== '' ? (string) $data['Version'] : ( defined( 'DILUXONE_OFFLOAD_VERSION' ) ? DILUXONE_OFFLOAD_VERSION : '' );
		return $version;
	}

	/**
	 * Enqueue admin assets (CSS/JS)
	 *
	 * @param mixed $hook_suffix
	 */
	public static function enqueue_admin_assets( $hook_suffix ): void {
		// Only load on our plugin pages
		if ( strpos( $hook_suffix, 'diluxone-offload' ) === false ) {
			return;
		}

		// Enqueue CSS
		wp_enqueue_style(
			'diluxone-offload-admin',
			DILUXONE_OFFLOAD_URL . 'assets/css/admin.css',
			array(),
			self::asset_version( 'assets/css/admin.css' )
		);

		// Enqueue JS
		wp_enqueue_script(
			'diluxone-offload-admin',
			DILUXONE_OFFLOAD_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			self::asset_version( 'assets/js/admin.js' ),
			true
		);

		self::enqueue_tab_assets();

		// Localize script with AJAX data
		wp_localize_script(
			'diluxone-offload-admin',
			'diluxOneOffloadAdmin',
			array(
				'nonce'           => wp_create_nonce( 'diluxone_offload_admin' ),
				// Offloading activate/deactivate verify a different action; see
				// Plugin::ajax_activate_offloading().
				'offloadingNonce' => wp_create_nonce( 'diluxone_offload_admin_nonce' ),
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'autoRefresh'     => true,
			)
		);
	}

	/**
	 * Cache-busting version for a plugin asset.
	 *
	 * In production the plugin version is right: assets only change when a new
	 * version ships. Anywhere else it is actively misleading — the version
	 * stays put across edits, so browsers keep serving the previous CSS and JS
	 * and the change looks like it simply did not work. Off production, fall
	 * back to the file's mtime.
	 *
	 * Keyed on the environment type rather than SCRIPT_DEBUG: a dev stack does
	 * not necessarily set SCRIPT_DEBUG, and that is exactly where stale assets
	 * cost the most time.
	 *
	 * @param string $relative Path under the plugin root, e.g. 'assets/js/admin.js'.
	 * @return string Version string for wp_enqueue_*.
	 */
	private static function asset_version( string $relative ): string {
		$path = DILUXONE_OFFLOAD_DIR . $relative;

		$is_production = ! function_exists( 'wp_get_environment_type' ) || wp_get_environment_type() === 'production';

		if ( ! $is_production && file_exists( $path ) ) {
			$mtime = filemtime( $path );
			if ( $mtime !== false ) {
				return (string) $mtime;
			}
		}

		return DILUXONE_OFFLOAD_VERSION;
	}

	/**
	 * Per-tab stylesheet and script, by tab slug.
	 *
	 * Each admin tab used to carry its own <style>/<script> block inline in its
	 * template. They now live in assets/ and are registered here so WordPress
	 * can cache, version, defer and dequeue them like any other asset — and so
	 * a page only pays for the tab it is showing.
	 *
	 * @return array<string, string> Tab slug => asset basename in assets/{css,js}/.
	 */
	private static function tab_assets(): array {
		return array(
			'overview'        => 'admin-overview',
			'cloud-provider'  => 'admin-cloud-provider',
			'sync-offloading' => 'admin-sync',
			'settings'        => 'admin-settings',
			'status'          => 'admin-status',
		);
	}

	/**
	 * Build the strings and data the current tab's script needs.
	 *
	 * Templates used to interpolate both directly into an inline <script>.
	 * Keeping them here means the JS files are static and cacheable, the
	 * strings stay in the .pot, and nothing is echoed into a script tag.
	 *
	 * @param string               $tab           Resolved tab slug.
	 * @param array<string, mixed> $template_data Data the tab's template renders with, if known yet.
	 * @return array{payload: array<string, mixed>, object: string, handle: string}|null
	 */
	private static function tab_payload( string $tab, array $template_data = array() ): ?array {
		$payload = null;
		$object  = '';
		$handle  = '';

		switch ( $tab ) {
			case 'cloud-provider':
				$payload = array(
					'i18n' => array(
						'connection_failed'               => __( 'Connection Failed', 'diluxone-offload' ),
						'please_fill_in_all_required_fields' => __( 'Please fill in all required fields.', 'diluxone-offload' ),
						'testing'                         => __( 'Testing...', 'diluxone-offload' ),
						'test_connection'                 => __( 'Test Connection', 'diluxone-offload' ),
						'connection_successful'           => __( 'Connection Successful', 'diluxone-offload' ),
						'storage_account_name_must_be_3'  => __( 'Storage Account Name must be 3-24 characters long and contain only lowercase letters and numbers.', 'diluxone-offload' ),
						'container_name_must_contain_only_lowercase' => __( 'Container Name must contain only lowercase letters, numbers, and hyphens.', 'diluxone-offload' ),
						'deleting_configuration'          => __( 'Deleting configuration...', 'diluxone-offload' ),
						'yes_delete_configuration'        => __( 'Yes, Delete Configuration', 'diluxone-offload' ),
						'please_enter_the_new_access_key' => __( 'Please enter the new access key.', 'diluxone-offload' ),
						'saving'                          => __( 'Saving...', 'diluxone-offload' ),
						'save'                            => __( 'Save', 'diluxone-offload' ),
						'error_deleting_configuration'    => __( 'Error deleting configuration:', 'diluxone-offload' ),
						'error_saving_credentials'        => __( 'Error saving credentials:', 'diluxone-offload' ),
					),
					'data' => array(
						'config_cloud_provider' => $template_data['config']['cloud_provider'] ?? '',
					),
				);
				$object  = 'DiluxOneOffloadProvider';
				$handle  = 'diluxone-offload-admin-cloud-provider';
				break;

			case 'overview':
				$payload = array(
					'i18n' => array(
						'not_available' => __( 'Not available', 'diluxone-offload' ),
						'images'        => __( 'Images', 'diluxone-offload' ),
						'videos'        => __( 'Videos', 'diluxone-offload' ),
						'audio'         => __( 'Audio', 'diluxone-offload' ),
						'other'         => __( 'Other', 'diluxone-offload' ),
						'last_updated'  => __( 'Last updated:', 'diluxone-offload' ),
						'just_now'      => __( 'just now', 'diluxone-offload' ),
						'storage'       => __( 'Storage', 'diluxone-offload' ),
						'total_files'   => __( 'Total Files', 'diluxone-offload' ),
						'request_timed_out_try_again_later' => __( 'Request timed out. Try again later.', 'diluxone-offload' ),
					),
					'data' => array(),
				);
				$object  = 'DiluxOneOffloadOverview';
				$handle  = 'diluxone-offload-admin-overview';
				break;

			case 'sync-offloading':
				$payload = array(
					'i18n' => array(
						'cancelling_sync'                  => __( 'Cancelling sync...', 'diluxone-offload' ),
						'close'                            => __( 'Close', 'diluxone-offload' ),
						'upload_summary'                   => __( 'Upload Summary', 'diluxone-offload' ),
						'total_files'                      => __( 'Total files:', 'diluxone-offload' ),
						'already_uploaded'                 => __( 'Already uploaded:', 'diluxone-offload' ),
						'new_files'                        => __( 'New files:', 'diluxone-offload' ),
						'pending'                          => __( 'Pending:', 'diluxone-offload' ),
						'upload_performance'               => __( 'Upload Performance:', 'diluxone-offload' ),
						'balanced_5_parallel'              => __( 'Balanced (5 parallel)', 'diluxone-offload' ),
						'fast_20_parallel'                 => __( 'Fast (20 parallel)', 'diluxone-offload' ),
						'intensive_40_parallel'            => __( 'Intensive (40 parallel)', 'diluxone-offload' ),
						'continue_upload'                  => __( 'Continue Upload', 'diluxone-offload' ),
						'upload_from_scratch'              => __( 'Upload from Scratch', 'diluxone-offload' ),
						'scan_and_complete_sync'           => __( 'Scan and Complete Sync', 'diluxone-offload' ),
						'sync_files_to_cloud'              => __( 'Sync Files to Cloud', 'diluxone-offload' ),
						'error_processing_batch'           => __( 'Error processing batch:', 'diluxone-offload' ),
						'max_retries_exceeded_sync_stopped_please' => __( '⚠️ Max retries exceeded. Sync stopped. Please check logs and try again.', 'diluxone-offload' ),
						'sync_completed_successfully'      => __( 'Sync Completed Successfully!', 'diluxone-offload' ),
						'all_files_have_been_synced_to'    => __( 'All files have been synced to cloud storage.', 'diluxone-offload' ),
						'sync_completed_with_errors'       => __( 'Sync Completed with Errors', 'diluxone-offload' ),
						'some_files_could_not_be_synced'   => __( 'Some files could not be synced.', 'diluxone-offload' ),
						'sync_failed'                      => __( 'Sync Failed', 'diluxone-offload' ),
						'unknown_error'                    => __( 'Unknown error', 'diluxone-offload' ),
						'successful'                       => __( 'Successful:', 'diluxone-offload' ),
						'failed'                           => __( 'Failed:', 'diluxone-offload' ),
						'enable_offloading'                => __( 'Enable Offloading', 'diluxone-offload' ),
						'later'                            => __( 'Later', 'diluxone-offload' ),
						'accept'                           => __( 'Accept', 'diluxone-offload' ),
						'cannot_enable_offloading'         => __( 'Cannot enable offloading: ', 'diluxone-offload' ),
						'failed_files'                     => __( 'failed files', 'diluxone-offload' ),
						'and'                              => __( 'and', 'diluxone-offload' ),
						'pending_files'                    => __( 'pending files', 'diluxone-offload' ),
						'please_resolve_errors_first_using_clear' => __( 'Please resolve errors first using "Clear Failed & Enable" or retry failed files.', 'diluxone-offload' ),
						'enabling_cloud_storage_offloading' => __( 'Enabling cloud storage offloading...', 'diluxone-offload' ),
						'enabling'                         => __( 'Enabling...', 'diluxone-offload' ),
						'offloading_enabled_successfully'  => __( 'Offloading enabled successfully!', 'diluxone-offload' ),
						'connection_error'                 => __( 'Connection error', 'diluxone-offload' ),
						'connection_error_try_again'       => __( 'Connection error. Please try again.', 'diluxone-offload' ),
						'failed_to_take_control'           => __( 'Failed to take control:', 'diluxone-offload' ),
						'connection_error_taking_control'  => __( 'Connection error while taking control.', 'diluxone-offload' ),
						'cancelling'                       => __( 'Cancelling...', 'diluxone-offload' ),
						'cancelling_sync_please_wait'      => __( 'Cancelling sync... Please wait.', 'diluxone-offload' ),
						'sync_cancelled_refreshing'        => __( 'Sync cancelled. Refreshing...', 'diluxone-offload' ),
						'retry_failed_files'               => __( 'Retry Failed Files', 'diluxone-offload' ),
						'failed_files_to_retry'            => __( 'Failed files to retry:', 'diluxone-offload' ),
						'previously_failed'                => __( 'Previously failed:', 'diluxone-offload' ),
						'new_files_found'                  => __( 'New files found:', 'diluxone-offload' ),
						'performance_level'                => __( 'Performance Level:', 'diluxone-offload' ),
						'balanced_5_parallel_recommended'  => __( 'Balanced (5 parallel - Recommended)', 'diluxone-offload' ),
						'fast_20_parallel_more_resources'  => __( 'Fast (20 parallel - More resources)', 'diluxone-offload' ),
						'intensive_40_parallel_maximum_speed' => __( 'Intensive (40 parallel - Maximum speed)', 'diluxone-offload' ),
						'higher_values_faster_upload_but_more' => __( 'Higher values = faster upload but more server resources. Start with Balanced if unsure.', 'diluxone-offload' ),
						'retry_upload'                     => __( 'Retry Upload', 'diluxone-offload' ),
						'cancel'                           => __( 'Cancel', 'diluxone-offload' ),
						'error_calculating_failed_files'   => __( 'Error calculating failed files', 'diluxone-offload' ),
						'confirm_complete_resync'          => __( 'Confirm Complete Resync', 'diluxone-offload' ),
						'are_you_sure_you_want_to'         => __( 'Are you sure you want to resynchronize all files?', 'diluxone-offload' ),
						'all_sync_history_will_be_cleared' => __( 'All sync history will be cleared', 'diluxone-offload' ),
						'files_will_be_scanned_from_scratch' => __( 'Files will be scanned from scratch', 'diluxone-offload' ),
						'already_synced_files_will_be_detected' => __( 'Already synced files will be detected and skipped', 'diluxone-offload' ),
						'this_action_cannot_be_undone'     => __( 'This action cannot be undone.', 'diluxone-offload' ),
						'yes_resync_all_files'             => __( 'Yes, Resync All Files', 'diluxone-offload' ),
						'processing'                       => __( 'Processing...', 'diluxone-offload' ),
						'clearing_sync_data'               => __( 'Clearing sync data...', 'diluxone-offload' ),
						'sync_data_cleared'                => __( 'Sync Data Cleared', 'diluxone-offload' ),
						'reloading_page'                   => __( 'Reloading page...', 'diluxone-offload' ),
						'error_preparing_resync'           => __( 'Error preparing resync', 'diluxone-offload' ),
						'are_you_sure_you_want_to_2'       => __( 'Are you sure you want to clear the failed files list?', 'diluxone-offload' ),
						'clearing'                         => __( 'Clearing...', 'diluxone-offload' ),
						'clear_list'                       => __( 'Clear List', 'diluxone-offload' ),
						'files_discarded_but_failed_to_enable' => __( 'Files discarded but failed to enable offloading', 'diluxone-offload' ),
						'connection_error_while_enabling_offloading' => __( 'Connection error while enabling offloading', 'diluxone-offload' ),
						'failed_to_discard_files'          => __( 'Failed to discard files', 'diluxone-offload' ),
						'sync_cancelled_and_reset_to_configured' => __( 'Sync cancelled and reset to configured state', 'diluxone-offload' ),
						'failed_to_cancel_sync'            => __( 'Failed to cancel sync', 'diluxone-offload' ),
						'connection_error_while_cancelling_sync' => __( 'Connection error while cancelling sync', 'diluxone-offload' ),
						'connection_error_deletion_interrupted' => __( 'Connection error. Deletion interrupted.', 'diluxone-offload' ),
						'deletion_completed_successfully'  => __( 'Deletion Completed Successfully!', 'diluxone-offload' ),
						'all_local_files_have_been_deleted' => __( 'All local files have been deleted.', 'diluxone-offload' ),
						'deletion_completed_with_errors'   => __( 'Deletion Completed with Errors', 'diluxone-offload' ),
						'some_files_could_not_be_deleted'  => __( 'Some files could not be deleted.', 'diluxone-offload' ),
						'deleted'                          => __( 'Deleted:', 'diluxone-offload' ),
						'deactivating_offloading'          => __( 'Deactivating Offloading...', 'diluxone-offload' ),
						'files_already_exist_locally'      => __( 'files already exist locally.', 'diluxone-offload' ),
						'files_are_already_local_but_failed' => __( 'Files are already local but failed to disable offloading. Please disable manually.', 'diluxone-offload' ),
						'total_files_in_cloud'             => __( 'Total files in cloud:', 'diluxone-offload' ),
						'already_local'                    => __( 'Already local:', 'diluxone-offload' ),
						'pending_download'                 => __( 'Pending download:', 'diluxone-offload' ),
						'disconnecting'                    => __( 'Disconnecting...', 'diluxone-offload' ),
						'disconnected_reloading'           => __( 'Disconnected. Reloading...', 'diluxone-offload' ),
						'force_disconnect_without_sync'    => __( 'Force Disconnect Without Sync', 'diluxone-offload' ),
						'failed_to_disconnect'             => __( 'Failed to disconnect', 'diluxone-offload' ),
						'cancelling_download'              => __( 'Cancelling download...', 'diluxone-offload' ),
						'download_completed_with_errors'   => __( 'Download Completed with Errors', 'diluxone-offload' ),
						'some_files_could_not_be_downloaded' => __( 'Some files could not be downloaded.', 'diluxone-offload' ),
						'downloaded'                       => __( 'Downloaded:', 'diluxone-offload' ),
						'skipped'                          => __( 'Skipped:', 'diluxone-offload' ),
						'files_downloaded_but_failed_to_disable' => __( 'Files downloaded but failed to disable offloading. Please disable manually.', 'diluxone-offload' ),
						'connection_error_please_try_again' => __( 'Connection error. Please try again.', 'diluxone-offload' ),
						'max_retries_exceeded_please_try_again' => __( 'Max retries exceeded. Please try again later.', 'diluxone-offload' ),
						'dev_mode_enable_offloading_without_syncing' => __( 'DEV MODE: Enable offloading without syncing files? This assumes cloud already has all files.', 'diluxone-offload' ),
						'dev_mode_offloading_enabled_without_sync' => __( 'DEV MODE: Offloading enabled without sync!', 'diluxone-offload' ),
						'dev_mode_disconnect_without_downloading_files' => __( 'DEV MODE: Disconnect without downloading files? This assumes local already has all files.', 'diluxone-offload' ),
						'dev_mode_offloading_disabled_without_sync' => __( 'DEV MODE: Offloading disabled without sync!', 'diluxone-offload' ),
					),
					'data' => array(
						'current_state' => $template_data['current_state'] ?? null,
					),
				);
				$object  = 'DiluxOneOffloadSync';
				$handle  = 'diluxone-offload-admin-sync';
				break;
		}

		if ( $payload === null ) {
			return null;
		}

		return array(
			'payload' => $payload,
			'object'  => $object,
			'handle'  => $handle,
		);
	}

	/**
	 * Enqueue the stylesheet and script belonging to the tab being rendered.
	 *
	 * @return void
	 */
	private static function enqueue_tab_assets(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing parameter, no state change.
		$requested = isset( $_GET['tab'] ) ? \sanitize_text_field( \wp_unslash( $_GET['tab'] ) ) : 'overview';
		$tab       = self::current_tab( $requested );

		$assets = self::tab_assets();
		if ( ! isset( $assets[ $tab ] ) ) {
			return;
		}

		$base   = $assets[ $tab ];
		$handle = 'diluxone-offload-' . $base;

		if ( file_exists( DILUXONE_OFFLOAD_DIR . 'assets/css/' . $base . '.css' ) ) {
			wp_enqueue_style(
				$handle,
				DILUXONE_OFFLOAD_URL . 'assets/css/' . $base . '.css',
				array( 'diluxone-offload-admin' ),
				self::asset_version( 'assets/css/' . $base . '.css' )
			);
		}

		if ( file_exists( DILUXONE_OFFLOAD_DIR . 'assets/js/' . $base . '.js' ) ) {
			wp_enqueue_script(
				$handle,
				DILUXONE_OFFLOAD_URL . 'assets/js/' . $base . '.js',
				array( 'jquery', 'diluxone-offload-admin' ),
				self::asset_version( 'assets/js/' . $base . '.js' ),
				true
			);

			// Localize the strings here, not at render time: some tabs return
			// early before rendering their template, and the script is enqueued
			// either way. Localizing here guarantees the object always exists.
			$bag = self::tab_payload( $tab );
			if ( $bag !== null ) {
				wp_localize_script( $bag['handle'], $bag['object'], $bag['payload'] );
			}
		}
	}

	/**
	 * Merge the tab's render-time data into its already-localized object.
	 *
	 * @param string               $tab           Resolved tab slug.
	 * @param array<string, mixed> $template_data Data the tab's template renders with.
	 * @return void
	 */
	private static function merge_tab_data( string $tab, array $template_data ): void {
		$bag = self::tab_payload( $tab, $template_data );

		if ( $bag === null || empty( $bag['payload']['data'] ) || ! wp_script_is( $bag['handle'], 'enqueued' ) ) {
			return;
		}

		wp_add_inline_script(
			$bag['handle'],
			sprintf(
				'Object.assign( %s.data, %s );',
				$bag['object'],
				wp_json_encode( $bag['payload']['data'] )
			),
			'before'
		);
	}

	/**
	 * Render tab content based on current tab
	 *
	 * @param mixed $current_tab
	 */
	private static function render_tab_content( $current_tab ): void {
		$template_path = '';
		$template_data = array();

		switch ( $current_tab ) {
			case 'overview':
				$template_path           = 'admin-overview.php';
				$config                  = ConfigManager::get_config();
				$config['is_configured'] = ConfigManager::is_configured();

				// Cached stats only — never fetch here. See
				// ConfigManager::get_cached_cloud_stats() for why. On a cold
				// cache this is null and the template paints a skeleton that
				// the tab's script fills in.
				$current_state_ov = ConfigManager::get_state();
				$is_configured_ov = ! in_array( $current_state_ov, array( 'not_configured', '' ), true );
				$cloud_stats_ov   = $is_configured_ov ? ConfigManager::get_cached_cloud_stats() : null;

				$template_data = array(
					'config'      => $config,
					'cloud_stats' => $cloud_stats_ov,
					'stats'       => self::get_basic_stats(),
				);
				break;

			case 'settings':
				$template_path           = 'admin-settings.php';
				$config                  = ConfigManager::get_config();
				$config['is_configured'] = ConfigManager::is_configured();
				$template_data           = array(
					'config' => $config,
				);
				break;

			case 'cloud-provider':
				$template_path           = 'admin-cloud-provider.php';
				$config                  = ConfigManager::get_config();
				$config['is_configured'] = ConfigManager::is_configured();

				// Prepare cloud stats and DB data for template (no business logic in templates)
				$current_state_cp   = ConfigManager::get_state();
				$is_configured_cp   = ! in_array( $current_state_cp, array( 'not_configured', '' ), true );
				$has_files_in_db_cp = false;

				if ( $is_configured_cp ) {
					// Check files in DB (table name from trusted source, no user input)
					require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';
					global $wpdb;
					$table_name_cp = DiluxOneOffloadDB::get_table_name();
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted DiluxOneOffloadDB::get_table_name()
					$has_files_in_db_cp = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name_cp}" ) > 0;
				}

				$template_data = array(
					'config'          => $config,
					'current_state'   => $current_state_cp,
					'is_configured'   => $is_configured_cp,
					'has_files_in_db' => $has_files_in_db_cp,
				);
				break;

			case 'sync':
			case 'sync-offloading':
				// Check if sync tab is accessible
				$is_configured = ConfigManager::is_configured();
				if ( ! $is_configured ) {
					// Distinguish "credentials cannot be decrypted" from
					// "never configured": the recovery path is different
					// (re-enter creds vs initial setup) and the existing
					// "Steps to Enable Sync" copy misleads the user when the
					// real problem is unreadable credentials.
					$health             = ConfigManager::get_connection_health();
					$is_decrypt_failure = $health['status'] === 'unhealthy'
						&& $health['error_code'] === 'decrypt_failed';

					if ( $is_decrypt_failure ) {
						?>
						<div class="wrap">
							<div class="notice notice-error">
								<h3><?php \esc_html_e( 'Stored Credentials Unreadable', 'diluxone-offload' ); ?></h3>
								<p><?php \esc_html_e( 'Sync is paused because the saved cloud credentials cannot be decrypted. This is not the same as "never configured" — the cloud provider details are still in the database, but the WordPress salts changed since they were saved (commonly after restoring a database from a different environment).', 'diluxone-offload' ); ?></p>
								<p><?php \esc_html_e( 'Re-enter the credentials in the Cloud Provider tab. Everything else (provider selection, container name, sync state) is preserved.', 'diluxone-offload' ); ?></p>
								<p>
									<a href="<?php echo \esc_url( \admin_url( 'admin.php?page=diluxone-offload&tab=cloud-provider' ) ); ?>" class="button button-primary">
										<?php \esc_html_e( 'Re-enter Credentials', 'diluxone-offload' ); ?>
									</a>
								</p>
							</div>
						</div>
						<?php
						return;
					}
					?>
					<div class="wrap">
						<div class="notice notice-warning is-dismissible">
							<h3><?php esc_html_e( 'Sync & Offloading Not Available', 'diluxone-offload' ); ?></h3>
							<p><?php esc_html_e( 'Please configure a cloud provider in the Cloud Provider tab first.', 'diluxone-offload' ); ?></p>
							<p>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=diluxone-offload&tab=cloud-provider' ) ); ?>" class="button button-primary">
									<?php esc_html_e( 'Go to Cloud Provider', 'diluxone-offload' ); ?>
								</a>
							</p>
						</div>

						<div class="card" style="max-width: 600px; margin-top: 20px;">
							<h2><?php esc_html_e( 'Steps to Enable Sync', 'diluxone-offload' ); ?></h2>
							<ol>
								<li><?php esc_html_e( 'Go to the Cloud Provider tab', 'diluxone-offload' ); ?></li>
								<li><?php esc_html_e( 'Configure your cloud storage provider', 'diluxone-offload' ); ?></li>
								<li><?php esc_html_e( 'Test the connection', 'diluxone-offload' ); ?></li>
								<li><?php esc_html_e( 'Save your configuration', 'diluxone-offload' ); ?></li>
								<li><?php esc_html_e( 'Return to this tab to sync files', 'diluxone-offload' ); ?></li>
							</ol>
						</div>
					</div>
					<?php
					return;
				}

				$template_path           = 'admin-sync.php';
				$config                  = ConfigManager::get_config();
				$config['is_configured'] = ConfigManager::is_configured();

				$current_state_sync = ConfigManager::get_state();

				// Smart cleanup: reset stale syncing state (no heartbeat for >90s)
				if ( $current_state_sync === 'syncing' ) {
					$sync_meta            = get_option( 'diluxone_offload_sync_meta', array() );
					$last_heartbeat       = $sync_meta['last_heartbeat'] ?? 0;
					$time_since_heartbeat = time() - $last_heartbeat;

					if ( $time_since_heartbeat > 90 ) {
						ConfigManager::set_state( \DiluxOneOffload\Enums\PluginState::SYNCED );
						ConfigManager::clear_sync_progress();
						$current_state_sync = 'synced';
						Logger::info( '[DiluxOne Offload Admin] Auto-reset state from syncing to SYNCED (inactive for ' . $time_since_heartbeat . 's)' );
					}
				}

				// Get failed files from DB
				require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';
				$failed_files_sync = DiluxOneOffloadDB::get_failed_files();

				// Get file counts from DB for continuation detection (table name from trusted source)
				global $wpdb;
				$table_name_sync = DiluxOneOffloadDB::get_table_name();
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted DiluxOneOffloadDB::get_table_name()
				$has_files_in_db_sync = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name_sync}" ) > 0;
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$synced_count_sync = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name_sync} WHERE synced=1" );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$pending_count_sync = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name_sync} WHERE synced=0 AND deleted=0" );

				$template_data = array(
					'config'          => $config,
					'current_state'   => $current_state_sync,
					'sync_progress'   => ConfigManager::get_sync_progress(),
					'stats'           => self::get_basic_stats(),
					'failed_files'    => $failed_files_sync,
					'failed_count'    => count( $failed_files_sync ),
					'has_files_in_db' => $has_files_in_db_sync,
					'synced_count'    => $synced_count_sync,
					'pending_count'   => $pending_count_sync,
				);
				break;

			case 'status':
			case 'status-tools':  // legacy alias — keep for old bookmarked URLs
				$template_path           = 'admin-status.php';
				$config                  = ConfigManager::get_config();
				$config['is_configured'] = ConfigManager::is_configured();
				$template_data           = array(
					'config'        => $config,
					'storage_stats' => self::get_basic_stats(),
				);
				break;

			default:
				$template_path           = 'admin-overview.php';
				$config                  = ConfigManager::get_config();
				$config['is_configured'] = ConfigManager::is_configured();
				$template_data           = array(
					'config' => $config,
					'stats'  => self::get_basic_stats(),
				);
		}

		$full_template_path = DILUXONE_OFFLOAD_DIR . 'templates/' . $template_path;

		// The strings were localized when the script was enqueued, so the object
		// exists even on the early-return branches below. Only the data depends
		// on $template_data, so merge that in now. Footer scripts have not been
		// printed yet at this point, so this still reaches the browser.
		self::merge_tab_data( $current_tab, $template_data );

		if ( file_exists( $full_template_path ) ) {
			// Templates expect each value of $template_data to be available as
			// a local variable. The keys are static (set in this method) and
			// never derived from user input, so the documented extract() risk
			// (variable shadowing from untrusted keys) does not apply here.
			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Keys are static and trusted; templates depend on this contract.
			extract( $template_data );
			include $full_template_path;
		} else {
			echo '<p>' . \esc_html(
				/* translators: %s: relative template file path */
				\sprintf( \__( 'Template not found: %s', 'diluxone-offload' ), $template_path )
			) . '</p>';
		}
	}

	/**
	 * Short, single-line reason for a health pause. Used inline in the
	 * Status tab cards as a sub-label so each card explains *why* it is
	 * showing "paused" instead of its normal state.
	 *
	 * Mirrors the error_code branches in health_banner_copy() — keep them
	 * in sync if a new error_code is added.
	 *
	 * @param string $error_code Connection-health error_code (e.g. 'decrypt_failed', '403')
	 * @return string Short human label (already translated)
	 */
	public static function pause_reason_short( string $error_code ): string {
		switch ( $error_code ) {
			case 'decrypt_failed':
				return __( 'credentials unreadable', 'diluxone-offload' );
			case '401':
			case '403':
				return __( 'permission denied', 'diluxone-offload' );
			case '404':
				return __( 'container not found', 'diluxone-offload' );
			case 'timeout':
				return __( 'transfer timed out', 'diluxone-offload' );
			case 'exception':
				return __( 'connection error', 'diluxone-offload' );
			default:
				return __( 'cloud unreachable', 'diluxone-offload' );
		}
	}

	/**
	 * Build the title / detail / CTA copy for the health banner based on the
	 * recorded `error_code`. Each branch maps a known failure mode to a
	 * tailored message so the user knows exactly what to do.
	 *
	 * Returns an array with keys: title, detail, cta_label and cta_tab, the
	 * plugin tab the call to action opens.
	 *
	 * @param string $error_code    Code from connection_health (e.g. 'decrypt_failed', '403', 'exception')
	 * @param string $error_message Human-readable message from the failure source
	 * @return array{title:string,detail:string,cta_label:string,cta_tab:string}
	 */
	private static function health_banner_copy( string $error_code, string $error_message ): array {
		$copy = self::health_banner_copy_for( $error_code, $error_message );
		return $copy + array( 'cta_tab' => 'cloud-provider' );
	}

	/**
	 * The copy per error code; the caller adds the default tab.
	 *
	 * @param string $error_code    Code from connection_health.
	 * @param string $error_message Human-readable message from the failure source.
	 * @return array{title:string,detail:string,cta_label:string,cta_tab?:string}
	 */
	private static function health_banner_copy_for( string $error_code, string $error_message ): array {
		switch ( $error_code ) {
			case 'decrypt_failed':
				return array(
					'title'     => __( 'Stored Credentials Unreadable', 'diluxone-offload' ),
					'detail'    => __( 'Your saved cloud credentials cannot be decrypted. This usually means the WordPress salts (AUTH_KEY / SECURE_AUTH_KEY) changed since these credentials were saved — for example after restoring a database from a different environment. Re-enter your credentials to fix this.', 'diluxone-offload' ),
					'cta_label' => __( 'Re-enter Credentials', 'diluxone-offload' ),
				);

			case '401':
			case '403':
				return array(
					'title'     => __( 'Cloud Permission Denied', 'diluxone-offload' ),
					'detail'    => __( 'The cloud provider rejected the credentials. The access key may have been rotated, the SAS token may have expired, or the role assignment is missing. Verify the credentials and re-enter them.', 'diluxone-offload' ),
					'cta_label' => __( 'Update Credentials', 'diluxone-offload' ),
				);

			case '404':
				return array(
					'title'     => __( 'Container Not Found', 'diluxone-offload' ),
					'detail'    => __( 'The configured container or bucket does not exist on the cloud provider. Check that the name is spelled correctly and that it has been created.', 'diluxone-offload' ),
					'cta_label' => __( 'Open Cloud Provider Settings', 'diluxone-offload' ),
				);

			case 'timeout':
				return array(
					'title'     => __( 'Cloud Transfer Timed Out', 'diluxone-offload' ),
					'detail'    => sprintf(
						/* translators: %d: the Transfer Timeout setting, in seconds */
						__( 'A transfer to the cloud took longer than allowed: the Transfer Timeout (%d seconds) for an upload, at least 300 seconds for a download. Raise the setting if this host or its connection is slow; if transfers used to work at this value, check the connection to the provider.', 'diluxone-offload' ),
						(int) ( ConfigManager::get_config()['timeout'] ?? 60 )
					),
					'cta_label' => __( 'Open Settings', 'diluxone-offload' ),
					'cta_tab'   => 'settings',
				);

			case 'exception':
				return array(
					'title'     => __( 'Cloud Connection Error', 'diluxone-offload' ),
					'detail'    => $error_message !== ''
						? $error_message
						: __( 'An unexpected error occurred while talking to the cloud provider.', 'diluxone-offload' ),
					'cta_label' => __( 'Update your credentials in the Cloud Provider tab', 'diluxone-offload' ),
				);

			default:
				// Unknown / generic — preserve the existing copy.
				return array(
					'title'     => __( 'Cloud Connection Error', 'diluxone-offload' ),
					'detail'    => $error_message,
					'cta_label' => __( 'Update your credentials in the Cloud Provider tab', 'diluxone-offload' ),
				);
		}
	}

	/**
	 * Render the connection health error banner.
	 *
	 * @param array<string, mixed> $health Connection health data from ConfigManager
	 */
	private static function render_connection_health_banner( array $health ): void {
		$current_state = ConfigManager::get_state();
		$is_offloading = ( $current_state === 'offloading_active' );
		$copy          = self::health_banner_copy(
			(string) ( $health['error_code'] ?? '' ),
			(string) ( $health['error_message'] ?? '' )
		);

		// Calculate time since last success (only show if > 5 min to avoid
		// confusing "2 minutes ago" when the break just happened)
		$last_success_text = '';
		if ( $health['last_success'] > 0 ) {
			$diff = time() - $health['last_success'];
			if ( $diff < 300 ) {
				// Too recent — skip showing it (just broke, not useful context)
				$last_success_text = '';
			} elseif ( $diff < 3600 ) {
				$minutes = (int) ( $diff / 60 );
				/* translators: %d: number of minutes */
				$last_success_text = sprintf( \_n( '%d minute ago', '%d minutes ago', $minutes, 'diluxone-offload' ), $minutes );
			} elseif ( $diff < 86400 ) {
				$hours = (int) ( $diff / 3600 );
				/* translators: %d: number of hours */
				$last_success_text = sprintf( \_n( '%d hour ago', '%d hours ago', $hours, 'diluxone-offload' ), $hours );
			} else {
				$days = (int) ( $diff / 86400 );
				/* translators: %d: number of days */
				$last_success_text = sprintf( \_n( '%d day ago', '%d days ago', $days, 'diluxone-offload' ), $days );
			}
		}
		?>
		<div class="diluxone-offload-health-banner" style="background: #fef0f0; border-left: 4px solid #d63638; padding: 16px 20px; margin-bottom: 20px; border-radius: 4px;">
			<div style="display: flex; align-items: flex-start; gap: 12px;">
				<span class="dashicons dashicons-warning" style="color: #d63638; font-size: 24px; flex-shrink: 0; margin-top: 2px;"></span>
				<div>
					<strong style="color: #721c24; font-size: 15px;">
						<?php echo \esc_html( $copy['title'] ); ?>
					</strong>
					<p style="margin: 8px 0 0; color: #721c24;">
						<?php echo \esc_html( $copy['detail'] ); ?>
					</p>
					<?php if ( $last_success_text ) : ?>
					<p style="margin: 4px 0 0; color: #856404; font-size: 13px;">
						<?php
						/* translators: %s: human-readable time, e.g. "5 minutes ago" */
						printf( \esc_html__( 'Last successful connection: %s', 'diluxone-offload' ), \esc_html( $last_success_text ) );
						?>
					</p>
					<?php endif; ?>
					<?php if ( $is_offloading ) : ?>
					<p style="margin: 8px 0 0; color: #721c24; font-weight: 600;">
						<?php \esc_html_e( 'New uploads are refused until the connection recovers.', 'diluxone-offload' ); ?>
					</p>
					<?php endif; ?>
					<p style="margin: 8px 0 0; font-size: 13px;">
						<a href="<?php echo \esc_url( \admin_url( 'admin.php?page=diluxone-offload&tab=' . $copy['cta_tab'] ) ); ?>" style="color: #721c24; text-decoration: underline;">
							<?php echo \esc_html( $copy['cta_label'] ); ?>
						</a>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Get basic stats for templates
	 *
	 * @return array<string, mixed>
	 */
	private static function get_basic_stats(): array {
		global $wpdb;
		$table_name = $wpdb->prefix . 'diluxone_offload_files';

		// Get deletable files count (synced but not deleted locally)
		$deletable_stats = $wpdb->get_row(
			"SELECT
                COUNT(*) as total_files,
                COALESCE(SUM(size), 0) as total_size
             FROM {$table_name}
             WHERE synced = 1 AND deleted = 0",
			ARRAY_A
		);

		$deletable_files = $deletable_stats ? (int) $deletable_stats['total_files'] : 0;
		$deletable_size  = $deletable_stats ? (int) $deletable_stats['total_size'] : 0;

		return array(
			'total_files'     => 0,
			'total_size'      => 0,
			'cloud_files'     => 0,
			'cloud_size'      => 0,
			'local_files'     => 0,
			'local_size'      => 0,
			'files_today'     => 0,
			'size_today'      => 0,
			'deletable_files' => $deletable_files,
			'deletable_size'  => $deletable_size,
		);
	}

	/**
	 * Handle configuration save
	 *
	 * @throws \Exception When PluginSettings/ProviderConfig validation fails inside
	 *                   the inner try blocks (caught and converted to error notices).
	 */
	public static function save_config(): void {
		// Check nonce for security
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'diluxone_offload_save_config' ) ) {
			wp_die( esc_html__( 'Security check failed', 'diluxone-offload' ) );
		}

		// Check user permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'diluxone-offload' ) );
		}

		// Get redirect tab from form (cloud-provider or settings)
		$redirect_tab = sanitize_text_field( wp_unslash( $_POST['redirect_tab'] ?? 'settings' ) );

		// ========================================================================
		// NEW ARCHITECTURE: Detect what's being saved based on tab + fields
		// ========================================================================
		// Key insight: disabled fields are NOT sent in POST, so we use redirect_tab
		// to determine intent, then check which fields were actually sent

		$is_from_settings_tab       = ( $redirect_tab === 'settings' );
		$is_from_cloud_provider_tab = ( $redirect_tab === 'cloud-provider' );

		if ( $is_from_settings_tab ) {
			// Settings-only save: Use PluginSettings::fromPost()
			try {
				// Only the five fields of the Settings form ever reach the DTO,
				// already unslashed and sanitized by posted_fields(); fromPost()
				// validates each one for its type. The superglobal itself is
				// never handed to another function.
				$settings = \DiluxOneOffload\DTOs\PluginSettings::fromPost(
					self::posted_fields(
						array(
							'enable_debug_logging',
							'force_https_on_cloud',
							'timeout',
							'max_file_size',
							'allowed_file_types',
						)
					)
				);

				if ( ! ConfigManager::save_plugin_settings( $settings ) ) {
					throw new \Exception( 'Failed to save settings to database' );
				}

				self::flash_notice( 'success', 'Settings saved successfully!' );
			} catch ( \Exception $e ) {
				self::flash_notice( 'error', 'Failed to save settings: ' . $e->getMessage() );
			}

			self::redirect_to_tab( $redirect_tab );

		} elseif ( $is_from_cloud_provider_tab ) {
			// Cloud Provider tab: Could be full provider save OR custom domain only

			// Check if provider credentials were sent (not disabled)
			$has_provider_credentials = isset( $_POST['cloud_provider'] ) && isset( $_POST['account_name'] );

			if ( $has_provider_credentials ) {
				// Full provider save: credentials + custom domain
				try {
					// Only the four fields of the provider form ever reach the
					// DTO, already unslashed and sanitized by posted_fields();
					// fromPost() validates each one (format regexes). The
					// superglobal itself is never handed to another function.
					$provider = \DiluxOneOffload\DTOs\ProviderConfig::fromPost(
						self::posted_fields(
							array(
								'cloud_provider',
								'account_name',
								'account_key',
								'container_name',
							)
						)
					);

					if ( ! ConfigManager::save_provider_config( $provider ) ) {
						throw new \Exception( 'Failed to save provider configuration to database' );
					}

					self::flash_notice( 'success', 'Provider configuration saved successfully!' );
				} catch ( \InvalidArgumentException $e ) {
					// Validation error from ProviderConfig::fromPost()
					self::flash_notice( 'error', $e->getMessage() );
				} catch ( \Exception $e ) {
					self::flash_notice( 'error', 'Failed to save configuration: ' . $e->getMessage() );
				}
			}
			// No credentials sent (fields were disabled) — nothing to save.

			self::redirect_to_tab( $redirect_tab );

		} else {
			// Unknown tab - shouldn't happen
			self::flash_notice( 'error', 'Invalid save request' );
			self::redirect_to_tab( 'overview' );
		}
	}

	/**
	 * AJAX handler for testing connection
	 */
	public static function ajax_test_connection(): void {

		// Check nonce for security - try different nonce field names
		$nonce_verified = false;
		if ( isset( $_POST['nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'diluxone_offload_admin' ) ) {
			$nonce_verified = true;
		} elseif ( isset( $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'diluxone_offload_admin' ) ) {
			$nonce_verified = true;
		}

		if ( ! $nonce_verified ) {
			Logger::error( '[DiluxOne Offload] ajax_test_connection: nonce verification failed.' );
			wp_send_json_error( array( 'message' => esc_html__( 'Security check failed', 'diluxone-offload' ) ) );
		}

		// Check user permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Insufficient permissions', 'diluxone-offload' ) ) );
		}

		$provider = sanitize_text_field( wp_unslash( $_POST['provider'] ?? 'azure' ) );

		// The factory lowercases its argument, so anything but the exact
		// provider name is refused here, before the validation below could be
		// skipped for a spelling the factory would still accept.
		if ( ! \DiluxOneOffload\Factories\CloudStorageFactory::is_provider_supported( $provider ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Unsupported cloud provider', 'diluxone-offload' ) ) );
		}

		$account_name   = '';
		$container_name = '';

		try {
			$account_name   = sanitize_text_field( wp_unslash( $_POST['account_name'] ?? '' ) );
			$account_key    = sanitize_text_field( wp_unslash( $_POST['account_key'] ?? '' ) );
			$container_name = sanitize_text_field( wp_unslash( $_POST['container_name'] ?? '' ) );

			if ( empty( $account_name ) || empty( $account_key ) || empty( $container_name ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Missing required fields', 'diluxone-offload' ) ) );
			}

			// Same rules as saving: the account name becomes the hostname the
			// signed request goes to, so it's checked before any request is built.
			\DiluxOneOffload\DTOs\ProviderConfig::validate_azure_config(
				array(
					'storage_account' => $account_name,
					'access_key'      => $account_key,
					'container_name'  => $container_name,
				)
			);

			$client = \DiluxOneOffload\Factories\CloudStorageFactory::create(
				$provider,
				array(
					'storage_account' => $account_name,
					'access_key'      => $account_key,
					'container_name'  => $container_name,
				)
			);

			if ( $client === null ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Could not instantiate cloud client for the selected provider.', 'diluxone-offload' ) ) );
			}

			$result = $client->test_connection();

			if ( $result['success'] ) {
				Logger::info( '[DiluxOne Offload] Connection successful for provider: ' . $provider );
				ConfigManager::record_connection_success();

				// Remembered so the save can verify it is the tested account.
				$transient_data = array(
					'provider'       => 'azure',
					'account_name'   => $account_name,
					'container_name' => $container_name,
					'timestamp'      => time(),
				);

				set_transient(
					'diluxone_offload_connection_test_passed_' . get_current_user_id(),
					$transient_data,
					300
				);

				\wp_send_json_success(
					array(
						'message'     => esc_html( $result['message'] ?? 'Connection successful! You can now save.' ),
						'test_passed' => true,
					)
				);
			} else {
				Logger::info( '[DiluxOne Offload] Connection failed: ' . $result['message'] );
				\wp_send_json_error(
					array(
						'message' => esc_html( $result['message'] ?? 'Connection failed' ),
					)
				);
			}
		} catch ( \InvalidArgumentException $e ) {
			// A field that fails validation is not a connection problem; say
			// which field, without the "Connection error" prefix.
			\wp_send_json_error(
				array(
					'message' => esc_html( $e->getMessage() ),
				)
			);
		} catch ( \Exception $e ) {
			Logger::info( '[DiluxOne Offload] Connection error: ' . $e->getMessage() );
			\wp_send_json_error(
				array(
					'message' => 'Connection error: ' . esc_html( $e->getMessage() ),
				)
			);
		}
	}

	/**
	 * AJAX handler for refreshing cloud storage statistics
	 *
	 * Calls the provider's stats method with force_refresh=true. Uses
	 * instanceof because get_container_stats() is Azure-specific and not
	 * part of the client interface.
	 */
	public static function ajax_refresh_stats(): void {
		check_ajax_referer( 'diluxone_offload_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Insufficient permissions', 'diluxone-offload' ) ) );
		}

		$client = ConfigManager::get_cloud_client();
		if ( ! $client ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Cloud client not available', 'diluxone-offload' ) ) );
		}

		try {
			$stats = null;
			if ( $client instanceof \DiluxOneOffload\Providers\AzureProvider ) {
				$stats = $client->get_container_stats( true );
			} else {
				wp_send_json_error( array( 'message' => esc_html__( 'Unknown provider type', 'diluxone-offload' ) ) );
			}

			if ( $stats['success'] ) {
				ConfigManager::record_connection_success();
				wp_send_json_success( $stats['data'] ?? array() );
			} else {
				wp_send_json_error( array( 'message' => esc_html( $stats['message'] ?? 'Failed to fetch stats' ) ) );
			}
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => esc_html( $e->getMessage() ) ) );
		}
	}

	/**
	 * AJAX handler for saving updated credentials (Update Credentials modal)
	 */
	public static function ajax_save_updated_credentials(): void {
		// Check nonce
		check_ajax_referer( 'diluxone_offload_admin', 'nonce' );

		// Check user permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Unauthorized', 'diluxone-offload' ) ) );
		}

		// Validate that connection test passed
		$test_data = get_transient( 'diluxone_offload_connection_test_passed_' . get_current_user_id() );
		if ( ! $test_data ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must test the connection first', 'diluxone-offload' ) ) );
		}

		$provider = sanitize_text_field( wp_unslash( $_POST['provider'] ?? '' ) );

		$account_name   = sanitize_text_field( wp_unslash( $_POST['account_name'] ?? '' ) );
		$account_key    = sanitize_text_field( wp_unslash( $_POST['account_key'] ?? '' ) );
		$container_name = sanitize_text_field( wp_unslash( $_POST['container_name'] ?? '' ) );

		// The credentials being saved must be the ones that were tested.
		if ( ( $test_data['account_name'] ?? '' ) !== $account_name ||
			( $test_data['container_name'] ?? '' ) !== $container_name ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Credentials do not match tested values. Please test again.', 'diluxone-offload' ) ) );
		}

		$provider_data = array(
			'cloud_provider'  => $provider,
			'provider_config' => array(
				'storage_account' => $account_name,
				'access_key'      => $account_key,
				'container_name'  => $container_name,
			),
		);

		try {
			// $provider_data['provider_config'] is fresh off this request, not
			// storage — fromArray() itself stays validation-free (see its
			// docblock), so the same check fromPost() runs is applied here too.
			if ( 'azure' === $provider ) {
				\DiluxOneOffload\DTOs\ProviderConfig::validate_azure_config( $provider_data['provider_config'] );
			}

			$provider_config = \DiluxOneOffload\DTOs\ProviderConfig::fromArray( $provider_data );
			if ( ! ConfigManager::save_provider_config( $provider_config ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Credentials were not saved: the provider configuration is invalid.', 'diluxone-offload' ) ) );
			}

			// Clear transient
			delete_transient( 'diluxone_offload_connection_test_passed_' . get_current_user_id() );

			// Log for audit
			Logger::info( '[DiluxOne Offload] Credentials updated by user ID: ' . get_current_user_id() );

			// The page reloads after this; the notice waits for it there.
			self::flash_notice( 'success', 'Credentials updated successfully' );

			wp_send_json_success(
				array(
					'message' => 'Credentials updated successfully',
				)
			);
		} catch ( \Exception $e ) {
			wp_send_json_error(
				array(
					'message' => 'Error saving: ' . esc_html( $e->getMessage() ),
				)
			);
		}
	}

	/**
	 * AJAX: Cancel sync process OR reset plugin to configured state
	 *
	 * This endpoint handles two scenarios:
	 * 1. Active sync: Cancel the sync and reset to CONFIGURED
	 * 2. Synced state: Reset everything (DB + metadata + state) back to CONFIGURED
	 */
	public static function ajax_cancel_sync(): void {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'diluxone_offload_admin' ) ) {
			wp_die( esc_html__( 'Invalid nonce', 'diluxone-offload' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'diluxone-offload' ) );
		}

		// ⭐ DOUBLE VALIDATION: Validate that this operation is safe to execute
		$session_id = sanitize_text_field( wp_unslash( $_POST['session_id'] ?? '' ) );
		$validation = \DiluxOneOffload\ValidationHelper::validate_sync_operation(
			$session_id,
			'cancel_sync' // Validate that no other tab is syncing
		);

		if ( ! $validation['passed'] ) {
			Logger::warning( '[DiluxOne Offload] Cancel sync BLOCKED by validation: ' . $validation['reason'] );
			wp_send_json_error(
				array(
					'validation_failed' => true,
					'reason'            => $validation['reason'],
					'details'           => $validation['details'],
					'message'           => esc_html__( 'Cannot cancel sync: Another tab is currently syncing', 'diluxone-offload' ),
				)
			);
		}

		try {
			$current_state = ConfigManager::get_state();

			// Try to cancel sync using SyncManager
			$sync_manager = new \DiluxOneOffload\SyncManager();
			$cancelled    = $sync_manager->cancel_sync();

			if ( $cancelled ) {
				// Sync was active and got cancelled
				wp_send_json_success(
					array(
						'message' => esc_html__( 'Sync cancelled successfully', 'diluxone-offload' ),
					)
				);
			} else {
				// No active sync - perform FULL RESET (for "Cancel Sync & Reset" button)
				// This allows user to discard a completed sync and start fresh

				Logger::info( '[DiluxOne Offload] No active sync - performing full reset to CONFIGURED state' );

				// Clear DB table
				require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';
				\DiluxOneOffload\DiluxOneOffloadDB::clear_table();

				// Clear sync metadata
				delete_option( 'diluxone_offload_sync_meta' );
				delete_option( 'diluxone_offload_failed_files' );

				// Reset state to CONFIGURED (preserves credentials)
				ConfigManager::set_state( \DiluxOneOffload\Enums\PluginState::CONFIGURED );

				wp_send_json_success(
					array(
						'message' => esc_html__( 'Plugin reset to configured state successfully', 'diluxone-offload' ),
					)
				);
			}
		} catch ( \Exception $e ) {
			Logger::info( '[DiluxOne Offload] Error in cancel_sync: ' . $e->getMessage() );
			/* translators: %s: error message */
			wp_send_json_error( sprintf( esc_html__( 'Error: %s', 'diluxone-offload' ), esc_html( $e->getMessage() ) ) );
		}
	}

	/**
	 * AJAX: Mark sync as complete (sets state to SYNCED)
	 */
	public static function ajax_mark_sync_complete(): void {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'diluxone_offload_admin' ) ) {
			wp_die( esc_html__( 'Invalid nonce', 'diluxone-offload' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'diluxone-offload' ) );
		}

		try {
			$plugin       = Plugin::get_instance();
			$sync_manager = $plugin->get_sync_manager();

			if ( ! $sync_manager ) {
				wp_send_json_error( esc_html__( 'Sync manager not available', 'diluxone-offload' ) );
			}

			$sync_meta = get_option( 'diluxone_offload_sync_meta', array() );

			// Offloading is already on: this request arrived after Enable
			// Offloading did its work, and must not pull the plugin back.
			if ( ConfigManager::get_state() === \DiluxOneOffload\Enums\PluginState::OFFLOADING_ACTIVE ) {
				wp_send_json_success( 'Offloading already active' );
			}

			require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';
			$stats = \DiluxOneOffload\DiluxOneOffloadDB::get_stats();
			Logger::info( '[DiluxOne Offload Admin] Final sync stats: ' . wp_json_encode( $stats ) );

			// Without sync metadata this is "Complete Sync" finding nothing to
			// upload — after a Disconnect, say, where every file is already in
			// the container. The tracking table is what proves that: something
			// synced, nothing pending.
			if ( empty( $sync_meta ) && ( (int) ( $stats['synced_files'] ?? 0 ) === 0 || (int) ( $stats['pending_files'] ?? 0 ) > 0 ) ) {
				wp_send_json_error( esc_html__( 'The sync is not complete', 'diluxone-offload' ) );
			}

			ConfigManager::set_state( \DiluxOneOffload\Enums\PluginState::SYNCED );

			if ( ! empty( $sync_meta ) ) {
				$sync_meta['status']   = 'completed';
				$sync_meta['end_time'] = time();
				update_option( 'diluxone_offload_sync_meta', $sync_meta, false );
			}

			Logger::info( '[DiluxOne Offload Admin] Sync marked as complete - state set to SYNCED' );
			wp_send_json_success( 'Sync completed' );
		} catch ( \Exception $e ) {
			Logger::info( '[DiluxOne Offload Admin] Error completing sync: ' . $e->getMessage() );
			/* translators: %s: error message */
			wp_send_json_error( sprintf( esc_html__( 'Error completing sync: %s', 'diluxone-offload' ), esc_html( $e->getMessage() ) ) );
		}
	}

	/**
	 * AJAX: Clear failed files list
	 */
	public static function ajax_clear_failed(): void {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'diluxone_offload_admin' ) ) {
			wp_die( esc_html__( 'Invalid nonce', 'diluxone-offload' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'diluxone-offload' ) );
		}

		try {
			ConfigManager::clear_failed_files();

			wp_send_json_success( 'Failed files list cleared successfully' );

		} catch ( \Exception $e ) {
			/* translators: %s: error message */
			wp_send_json_error( sprintf( esc_html__( 'Error clearing failed files: %s', 'diluxone-offload' ), esc_html( $e->getMessage() ) ) );
		}
	}

	/**
	 * AJAX handler to remove provider configuration
	 */
	public static function ajax_remove_provider(): void {
		// Check nonce
		check_ajax_referer( 'diluxone_offload_admin', 'nonce' );

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Insufficient permissions', 'diluxone-offload' ) ) );
		}

		try {
			Logger::info( '[DiluxOne Offload] AJAX: Removing provider configuration...' );

			// Deactivate stream wrapper before removing config (prevents inconsistent state)
			CloudStreamWrapper::deactivate_offloading();
			Logger::info( '[DiluxOne Offload] AJAX: Stream wrapper deactivated during provider removal' );

			// ⭐ COMPLETE DELETION: Delete all wp_options entries
			delete_option( 'diluxone_offload_config' );
			delete_option( 'diluxone_offload_plugin_state' );
			delete_option( 'diluxone_offload_sync_meta' );
			delete_option( 'diluxone_offload_sync_progress' );
			delete_option( 'diluxone_offload_failed_files' );
			delete_option( 'diluxone_offload_connection_health' );

			// Clear the MySQL table
			require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';
			DiluxOneOffloadDB::clear_table();

			// Clean up all transients
			delete_transient( 'diluxone_offload_azure_stats' );
			delete_transient( 'diluxone_offload_stats' );
			delete_transient( 'diluxone_offload_connection_test_passed_' . get_current_user_id() );

			Logger::info( '[DiluxOne Offload] AJAX: Provider configuration removed successfully' );

			wp_send_json_success(
				array(
					'message' => 'Cloud storage configuration deleted successfully. Reloading page...',
				)
			);

		} catch ( \Exception $e ) {
			Logger::info( '[DiluxOne Offload] AJAX: Error removing provider: ' . $e->getMessage() );
			wp_send_json_error(
				array(
					'message' => 'Error deleting configuration: ' . esc_html( $e->getMessage() ),
				)
			);
		}
	}
}

// Initialize admin
Admin::init();
