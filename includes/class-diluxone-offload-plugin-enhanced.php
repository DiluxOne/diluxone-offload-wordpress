<?php
/**
 * Main Plugin Class
 *
 * Manages plugin initialization and core components.
 *
 * Direct $wpdb queries against the plugin's own table (`$wpdb->prefix .
 * 'diluxone_offload_files'`) drive the AJAX endpoints for sync progress, scan, and
 * batch delete. The table name is derived from $wpdb->prefix and never from
 * user input; values are passed through $wpdb->prepare() where applicable.
 * Cache layers don't apply: those endpoints poll real-time sync state and a
 * stale read would defeat their purpose. Filesystem ops touch local files
 * outside /wp-content/uploads/. These rules are intentionally suppressed
 * file-wide:
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
 * phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload;

use DiluxOneOffload\Enums\PluginState;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Top-level plugin orchestrator.
 *
 * Wires the WordPress lifecycle: activation, deactivation, init,
 * AJAX handler registration, custom-table maintenance, stream-wrapper
 * activation. Singleton accessed via Plugin::get_instance(). Holds
 * references to the Admin and SyncManager subsystems.
 */
class Plugin {

	/** @var Plugin|null Singleton instance */

	private static ?Plugin $instance = null;

	/** @var SyncManager|null Sync manager instance */
	private ?SyncManager $sync_manager = null;

	/**
	 * Get singleton instance
	 *
	 * @return Plugin
	 */
	public static function get_instance(): Plugin {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize plugin
	 *
	 * @return void
	 */
	public function init(): void {
		Logger::log( '[DiluxOne Offload Plugin] Initializing...', 'info' );

		// Initialize new architecture (legacy removed)
		$this->init_new_architecture();
	}

	/**
	 * Check and update database table version
	 * Auto-creates or upgrades table on version mismatch
	 */
	private function check_and_update_database(): void {
		require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';

		$current_version = get_option( DiluxOneOffloadDB::TABLE_VERSION_OPTION, '0' );

		// If version mismatch or table doesn't exist, create/update
		if ( version_compare( $current_version, DiluxOneOffloadDB::TABLE_VERSION, '<' ) || ! DiluxOneOffloadDB::table_exists() ) {
			Logger::info( '[DiluxOne Offload Plugin] Database table needs update (current: ' . $current_version . ', required: ' . DiluxOneOffloadDB::TABLE_VERSION . ')' );
			DiluxOneOffloadDB::create_files_table();
		}
	}

	/**
	 * Initialize new architecture
	 */
	private function init_new_architecture(): void {
		// Left out on purpose: it made the log unreadable.
		// Logger::log('[DiluxOne Offload Plugin] Initializing NEW architecture', 'info');

		// ⭐ Check and update database table if needed
		$this->check_and_update_database();

		// Initialize sync manager
		$this->sync_manager = new SyncManager();

		// Check if offloading should be active
		$state = ConfigManager::get_state();
		if ( PluginState::is_offloading_active( $state ) ) {
			$this->activate_stream_wrapper();
		}

		// Admin interface
		if ( is_admin() ) {
			Admin::init(); // Initialize legacy admin correctly

			// Recursion-based sync endpoints
			add_action( 'wp_ajax_diluxone_offload_start_sync', array( $this, 'ajax_cs_start_sync' ) );
			add_action( 'wp_ajax_diluxone_offload_process_batch', array( $this, 'ajax_cs_process_batch' ) );

			// ⭐ NEW: Reverse sync endpoints (disconnect)
			add_action( 'wp_ajax_diluxone_offload_start_reverse_sync', array( $this, 'ajax_cs_start_reverse_sync' ) );
			add_action( 'wp_ajax_diluxone_offload_process_reverse_batch', array( $this, 'ajax_cs_process_reverse_batch' ) );
			add_action( 'wp_ajax_diluxone_offload_scan_remote', array( $this, 'ajax_cs_scan_remote' ) );
			add_action( 'wp_ajax_diluxone_offload_calculate_download', array( $this, 'ajax_cs_calculate_download' ) );
			add_action( 'wp_ajax_diluxone_offload_calculate_sync', array( $this, 'ajax_cs_calculate_sync' ) );

			// ⭐ NEW: Delete local files endpoints
			add_action( 'wp_ajax_diluxone_offload_get_deletable_stats', array( $this, 'ajax_cs_get_deletable_stats' ) );
			add_action( 'wp_ajax_diluxone_offload_process_delete_batch', array( $this, 'ajax_cs_process_delete_batch' ) );

			// ⭐ Reset state to configured (when canceling sync)
			add_action( 'wp_ajax_diluxone_offload_reset_state_to_configured', array( $this, 'ajax_cs_reset_state_to_configured' ) );

			// ⭐ NEW: Prepare resync (clear table and calculate)
			add_action( 'wp_ajax_diluxone_offload_prepare_resync', array( $this, 'ajax_cs_prepare_resync' ) );

			add_action( 'wp_ajax_diluxone_offload_activate_offloading', array( $this, 'ajax_activate_offloading' ) );
			add_action( 'wp_ajax_diluxone_offload_deactivate_offloading', array( $this, 'ajax_deactivate_offloading' ) );

			// ⭐ NEW: Discard failed files endpoint
			add_action( 'wp_ajax_diluxone_offload_discard_failed_files', array( $this, 'ajax_cs_discard_failed_files' ) );

			// ⭐ NEW: Multi-tab coordination endpoints
			add_action( 'wp_ajax_diluxone_offload_take_control', array( $this, 'ajax_cs_take_control' ) );
			add_action( 'wp_ajax_diluxone_offload_get_sync_state', array( $this, 'ajax_cs_get_sync_state' ) );

			// ⭐ NEW: Get failed files count (for Enable Offloading validation)
			add_action( 'wp_ajax_diluxone_offload_get_failed_files_count', array( $this, 'ajax_cs_get_failed_files_count' ) );

			// ⭐ DEV MODE: Skip sync endpoints (only registered when DILUXONE_OFFLOAD_DEV_MODE is active)
			if ( defined( 'DILUXONE_OFFLOAD_DEV_MODE' ) && DILUXONE_OFFLOAD_DEV_MODE ) {
				add_action( 'wp_ajax_diluxone_offload_dev_enable_without_sync', array( $this, 'ajax_dev_enable_without_sync' ) );
				add_action( 'wp_ajax_diluxone_offload_dev_disconnect_without_sync', array( $this, 'ajax_dev_disconnect_without_sync' ) );
			}
		}
	}


	/**
	 * Activate stream wrapper for cloud offloading
	 */
	private function activate_stream_wrapper(): void {
		if ( CloudStreamWrapper::activate_offloading() ) {
			Logger::info( '[DiluxOne Offload Plugin] Stream wrapper activated' );

			// ⭐ Register custom image editors for thumbnail generation
			// Uses temp files to handle diluxoneoffload:// paths
			add_filter( 'wp_image_editors', array( $this, 'filter_image_editors' ), 9 );
		} else {
			Logger::error( '[DiluxOne Offload Plugin] Failed to activate stream wrapper' );
		}
	}

	/**
	 * Filter image editors to use our custom editors
	 *
	 * Our custom editors handle diluxoneoffload:// paths by using temp files.
	 * This allows WordPress to generate thumbnails even when offloading is active.
	 *
	 * @param array<string, mixed> $editors Array of image editor class names
	 * @return array<string, mixed> Modified array with our custom editors first
	 */
	public function filter_image_editors( array $editors ): array {
		// Remove default Imagick if present
		$position = array_search( 'WP_Image_Editor_Imagick', $editors, true );
		if ( $position !== false ) {
			unset( $editors[ $position ] );
		}

		// Add our custom Imagick editor first (highest priority)
		array_unshift( $editors, 'DiluxOneOffload\\DiluxOneOffload_Image_Editor_Imagick' );

		// Add our custom GD editor as fallback
		// Note: WordPress will try Imagick first, then GD
		$editors[] = 'DiluxOneOffload\\DiluxOneOffload_Image_Editor_GD';

		return $editors;
	}

	/**
	 * ⭐ REFACTORED: Start sync with DOUBLE VALIDATION
	 *
	 * Flow:
	 * 1. First call (confirmed=0): Pre-check validation + calculate files → show modal
	 * 2. User confirms in modal (may take minutes)
	 * 3. Second call (confirmed=1): Execution-check validation + execute
	 *
	 * This prevents race conditions when user takes time to confirm
	 */
	public function ajax_cs_start_sync(): void {
		check_ajax_referer( 'diluxone_offload_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'diluxone-offload' ) );
		}

		if ( ! $this->sync_manager ) {
			wp_send_json_error( esc_html__( 'Sync manager not available', 'diluxone-offload' ) );
		}

		// Get parameters
		$session_id   = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ?? '' ) ) : '';
		$confirmed    = isset( $_POST['confirmed'] ) ? intval( wp_unslash( $_POST['confirmed'] ?? '' ) ) : 0;
		$retry_failed = isset( $_POST['retry_failed'] ) ? intval( wp_unslash( $_POST['retry_failed'] ?? '' ) ) : 0;

		Logger::info( '[DiluxOne Offload AJAX] start_sync - session: ' . $session_id . ', confirmed: ' . $confirmed . ', retry_failed: ' . $retry_failed );

		// Always validate, whether this is the pre-check or the execution-check.
		require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-validation-helper.php';
		$validation = ValidationHelper::validate_sync_operation(
			$session_id,
			$retry_failed ? 'retry_failed' : 'start_sync'
		);

		if ( ! $validation['passed'] ) {
			// Validation failed: say exactly why.
			Logger::error( '[DiluxOne Offload AJAX] Validation FAILED: ' . $validation['reason'] );
			wp_send_json_success(
				array(
					'validation_failed' => true,
					'reason'            => $validation['reason'],
					'details'           => $validation['details'],
				)
			);
		}

		// Validation passed.

		if ( ! $confirmed ) {
			// First call: the pre-check passed, so count the files and ask for
			// confirmation.
			Logger::info( '[DiluxOne Offload AJAX] Pre-check PASSED, calculating files...' );

			require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';

			$stats       = DiluxOneOffloadDB::get_stats();
			$scan_result = $this->sync_manager->scan_files_to_sync( true );

			$new_files      = array();
			$new_files_size = 0;

			if ( ! empty( $scan_result ) ) {
				global $wpdb;
				$table_name = DiluxOneOffloadDB::get_table_name();

				foreach ( $scan_result as $file_info ) {
					$relative_path = str_replace( wp_upload_dir()['basedir'], '', $file_info['local_path'] );
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted DiluxOneOffloadDB::get_table_name(), value is %s placeholder
					$exists = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(*) FROM $table_name WHERE file = %s",
							$relative_path
						)
					);

					if ( (int) $exists === 0 ) {
						$new_files[]     = array(
							'path' => $relative_path,
							'size' => $file_info['size'],
						);
						$new_files_size += $file_info['size'];
					}
				}

				if ( ! empty( $new_files ) ) {
					DiluxOneOffloadDB::add_files_batch( $new_files );
					$stats = DiluxOneOffloadDB::get_stats();
				}
			}

			$total_files      = (int) ( $stats['total_files'] ?? 0 );
			$synced_files     = (int) ( $stats['synced_files'] ?? 0 );
			$pending_files    = (int) ( $stats['pending_files'] ?? 0 );
			$total_size       = (int) ( $stats['total_size'] ?? 0 );
			$transferred_size = (int) ( $stats['total_transferred'] ?? 0 );

			wp_send_json_success(
				array(
					'validation_passed'     => true,
					'requires_confirmation' => true,
					'data'                  => array(
						'total_files'              => $total_files,
						'synced_files'             => $synced_files,
						'pending_files'            => $pending_files - count( $new_files ),
						'new_files'                => count( $new_files ),
						'total_size'               => $total_size,
						'synced_size'              => $transferred_size,
						'pending_size'             => max( 0, $total_size - $transferred_size - $new_files_size ),
						'new_files_size'           => $new_files_size,
						'total_size_formatted'     => size_format( $total_size ),
						'synced_size_formatted'    => size_format( $transferred_size ),
						'pending_size_formatted'   => size_format( (int) max( 0, $total_size - $transferred_size - $new_files_size ) ),
						'new_files_size_formatted' => size_format( $new_files_size ),
					),
				)
			);
		}

		// Second call: the user confirmed and the execution-check above passed,
		// so run it.
		Logger::info( '[DiluxOne Offload AJAX] Execution-check PASSED, starting sync...' );

		$concurrency  = isset( $_POST['concurrency'] ) ? intval( wp_unslash( $_POST['concurrency'] ?? '' ) ) : 5;
		$from_scratch = isset( $_POST['from_scratch'] ) ? intval( wp_unslash( $_POST['from_scratch'] ?? '' ) ) : 0;

		// Validate concurrency (3-40 range)
		if ( $concurrency < 3 ) {
			$concurrency = 3;
		} elseif ( $concurrency > 40 ) {
			$concurrency = 40;
		}

		require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';

		// Handle different modes
		if ( $from_scratch ) {
			DiluxOneOffloadDB::reset_all_files_to_pending();
			Logger::info( '[DiluxOne Offload Sync] Reset all files to pending (from scratch)' );
		} elseif ( $retry_failed ) {
			DiluxOneOffloadDB::reset_failed_files_to_pending();
			Logger::info( '[DiluxOne Offload Sync] Reset only failed files to pending (retry)' );
		}

		// Set concurrency and start sync
		$this->sync_manager->set_parallel_uploads( $concurrency );
		$result = $this->sync_manager->start_sync( (bool) $retry_failed );

		if ( $result['success'] ) {
			wp_send_json_success(
				array(
					'validation_passed' => true,
					'action_executed'   => true,
					'result'            => $result,
				)
			);
		} else {
			wp_send_json_error( esc_html( $result['message'] ) );
		}
	}

	/**
	 * ⭐ NEW AJAX: Process batch (time-based batching)
	 * For recursion-based approach
	 */
	public function ajax_cs_process_batch(): void {
		check_ajax_referer( 'diluxone_offload_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'diluxone-offload' ) );
		}

		if ( ! $this->sync_manager ) {
			wp_send_json_error( esc_html__( 'Sync manager not available', 'diluxone-offload' ) );
		}

		// ⭐ FIXED: Restore concurrency level from metadata
		$sync_meta   = get_option( 'diluxone_offload_sync_meta', array() );
		$concurrency = $sync_meta['concurrency'] ?? 5;
		$this->sync_manager->set_parallel_uploads( $concurrency );

		// Process batch (8 second time limit for responsive UI)
		$result = $this->sync_manager->process_batch( 8.0 );

		wp_send_json_success( $result );
	}

	/**
	 * ⭐ NEW AJAX: Start reverse sync (for disconnect)
	 * Supports 'continue' and 'scratch' modes
	 */
	public function ajax_cs_start_reverse_sync(): void {
		check_ajax_referer( 'diluxone_offload_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'diluxone-offload' ) );
		}

		if ( ! $this->sync_manager ) {
			wp_send_json_error( esc_html__( 'Sync manager not available', 'diluxone-offload' ) );
		}

		// Get parameters
		$concurrency = isset( $_POST['concurrency'] ) ? intval( wp_unslash( $_POST['concurrency'] ?? '' ) ) : 5;
		$mode        = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ?? '' ) ) : 'continue';

		Logger::info( '[DiluxOne Offload Plugin] Starting reverse sync - mode: ' . $mode . ', concurrency: ' . $concurrency );

		// Set concurrency level in sync manager
		$this->sync_manager->set_parallel_uploads( $concurrency );

		// Start reverse sync with mode (continue or scratch)
		$result = $this->sync_manager->start_reverse_sync( $mode );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( esc_html( $result['message'] ) );
		}
	}

	/**
	 * ⭐ NEW AJAX: Process reverse sync batch
	 * ⭐ OPTIMIZED: Restore concurrency level from metadata
	 */
	public function ajax_cs_process_reverse_batch(): void {
		check_ajax_referer( 'diluxone_offload_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'diluxone-offload' ) );
		}

		if ( ! $this->sync_manager ) {
			wp_send_json_error( esc_html__( 'Sync manager not available', 'diluxone-offload' ) );
		}

		// ⭐ OPTIMIZED: Restore concurrency level from metadata (same as normal sync)
		$sync_meta   = get_option( 'diluxone_offload_sync_meta', array() );
		$concurrency = $sync_meta['concurrency'] ?? 5;
		$this->sync_manager->set_parallel_uploads( $concurrency );

		// Process reverse batch (8 second time limit for responsive UI)
		$result = $this->sync_manager->process_reverse_batch( 8.0 );

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Scan remote files and register them in DB — runs before disconnect.
	 * Finds files in Azure that are not in DB and marks them as deleted=1
	 */
	public function ajax_cs_scan_remote(): void {
		check_ajax_referer( 'diluxone_offload_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'diluxone-offload' ) );
		}

		if ( ! $this->sync_manager ) {
			wp_send_json_error( esc_html__( 'Sync manager not available', 'diluxone-offload' ) );
		}

		try {
			require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';

			// Get cloud client
			$cloud_client = ConfigManager::get_cloud_client();

			if ( ! $cloud_client ) {
				wp_send_json_error( esc_html__( 'Cloud client not configured', 'diluxone-offload' ) );
			}

			// List this site's files from Azure
			Logger::info( '[DiluxOne Offload Plugin] Starting remote scan for disconnect...' );
			$azure_files = CloudStreamWrapper::site_files( $cloud_client->list_files( DiluxOneOffloadDB::listing_prefix() ) );

			if ( empty( $azure_files ) ) {
				Logger::info( '[DiluxOne Offload Plugin] No files found in cloud storage' );
				wp_send_json_success(
					array(
						'scanned'   => 0,
						'new_files' => 0,
						'message'   => 'No files in cloud',
					)
				);
			}

			Logger::info( '[DiluxOne Offload Plugin] Found ' . count( $azure_files ) . ' files in Azure, checking against DB...' );

			global $wpdb;
			$table_name = DiluxOneOffloadDB::get_table_name();

			$cloud_only_files = array();
			$already_in_db    = 0;

			foreach ( $azure_files as $file ) {
				$relative_path = DiluxOneOffloadDB::path_from_key( (string) $file['path'] );

				// Check if file exists in DB
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted DiluxOneOffloadDB::get_table_name(), value is %s placeholder
				$db_file = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM {$table_name} WHERE file = %s",
						$relative_path
					),
					ARRAY_A
				);

				if ( $db_file ) {
					// File already in DB
					++$already_in_db;

					// If file was not synced but same size, mark as synced
					if ( ! $db_file['synced'] && (int) $db_file['size'] === (int) $file['size'] ) {
						Logger::info( '[DiluxOne Offload Plugin] Already synced file found: ' . $relative_path );
						$wpdb->update(
							$table_name,
							array(
								'synced'      => 1,
								'transferred' => $file['size'],
							),
							array( 'file' => $relative_path )
						);
					}
				} else {
					// File NOT in DB - it's a cloud-only file
					Logger::info( '[DiluxOne Offload Plugin] Cloud only file found: ' . $relative_path . ' (' . size_format( $file['size'] ) . ')' );
					$cloud_only_files[] = array(
						'path' => $relative_path,
						'size' => $file['size'],
					);
				}
			}

			// Register the cloud-only files as synced but not present locally,
			// 500 rows per statement so a large container never builds one
			// INSERT bigger than the server accepts.
			foreach ( array_chunk( $cloud_only_files, 500 ) as $chunk ) {
				if ( ! DiluxOneOffloadDB::add_cloud_only_files_batch( $chunk ) ) {
					wp_send_json_error( esc_html__( 'Could not record the files found in the cloud. Check the database error log and try again.', 'diluxone-offload' ) );
				}
			}

			if ( count( $cloud_only_files ) > 0 ) {
				Logger::info( '[DiluxOne Offload Plugin] Inserted ' . count( $cloud_only_files ) . ' cloud-only files into DB' );
			}

			wp_send_json_success(
				array(
					'scanned'       => count( $azure_files ),
					'already_in_db' => $already_in_db,
					'new_files'     => count( $cloud_only_files ),
					'message'       => 'Remote scan complete',
				)
			);

		} catch ( \Exception $e ) {
			Logger::info( '[DiluxOne Offload Plugin] Remote scan error: ' . $e->getMessage() );
			/* translators: %s: error message */
			wp_send_json_error( sprintf( esc_html__( 'Error scanning remote files: %s', 'diluxone-offload' ), esc_html( $e->getMessage() ) ) );
		}
	}

	/**
	 * ⭐ NEW AJAX: Calculate download requirements from DB
	 * Uses the tracking table as the source of truth (after the remote scan)
	 * Shows what needs to be downloaded before disconnect
	 */
	public function ajax_cs_calculate_download(): void {
		check_ajax_referer( 'diluxone_offload_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'diluxone-offload' ) );
		}

		if ( ! $this->sync_manager ) {
			wp_send_json_error( esc_html__( 'Sync manager not available', 'diluxone-offload' ) );
		}

		try {
			require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';

			global $wpdb;
			$table_name = DiluxOneOffloadDB::get_table_name();

			// Query the tracking table for the download stats
			// synced = 1 (exists in cloud), deleted = 1 (needs download)
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted DiluxOneOffloadDB::get_table_name(), no user input
			$pending_download = $wpdb->get_row(
				"
                SELECT
                    COUNT(*) as count,
                    COALESCE(SUM(size), 0) as total_size
                FROM {$table_name}
                WHERE synced = 1 AND deleted = 1
            ",
				ARRAY_A
			);

			// Files already local (synced = 1, deleted = 0)
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted DiluxOneOffloadDB::get_table_name(), no user input
			$already_local = $wpdb->get_row(
				"
                SELECT
                    COUNT(*) as count,
                    COALESCE(SUM(size), 0) as total_size
                FROM {$table_name}
                WHERE synced = 1 AND deleted = 0
            ",
				ARRAY_A
			);

			// Total files in DB with synced = 1
			$total_cloud = (int) $pending_download['count'] + (int) $already_local['count'];
			$total_size  = (int) $pending_download['total_size'] + (int) $already_local['total_size'];

			Logger::debug(
				'[DiluxOne Offload Plugin] Download calculation from DB: ' .
					$total_cloud . ' total, ' .
					$already_local['count'] . ' local, ' .
					$pending_download['count'] . ' pending'
			);

			wp_send_json_success(
				array(
					'total_cloud'            => $total_cloud,
					'total_size'             => $total_size,
					'total_size_formatted'   => size_format( $total_size ),
					'already_local'          => (int) $already_local['count'],
					'local_size'             => (int) $already_local['total_size'],
					'local_size_formatted'   => size_format( $already_local['total_size'] ),
					'pending'                => (int) $pending_download['count'],
					'pending_size'           => (int) $pending_download['total_size'],
					'pending_size_formatted' => size_format( $pending_download['total_size'] ),
				)
			);

		} catch ( \Exception $e ) {
			Logger::info( '[DiluxOne Offload Plugin] Calculate download error: ' . $e->getMessage() );
			/* translators: %s: error message */
			wp_send_json_error( sprintf( esc_html__( 'Error calculating download: %s', 'diluxone-offload' ), esc_html( $e->getMessage() ) ) );
		}
	}

	/**
	 * ⭐ NEW AJAX: Calculate files to sync (upload)
	 * This ONLY counts files - it does NOT start the sync
	 */
	public function ajax_cs_calculate_sync(): void {
		check_ajax_referer( 'diluxone_offload_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'diluxone-offload' ) );
		}

		if ( ! $this->sync_manager ) {
			wp_send_json_error( esc_html__( 'Sync manager not available', 'diluxone-offload' ) );
		}

		try {
			require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';

			// ⭐ Check if this is a retry_failed request
			$retry_failed = isset( $_POST['retry_failed'] ) ? intval( wp_unslash( $_POST['retry_failed'] ?? '' ) ) : 0;

			$current_state = ConfigManager::get_state();

			// Get stats from DB (current state)
			$stats = DiluxOneOffloadDB::get_stats();

			// ⭐ ALWAYS scan filesystem to detect new files (even in retry mode)
			// This compares filesystem vs DB to find new files not yet tracked
			$scan_result = $this->sync_manager->scan_files_to_sync( true );

			$new_files      = array();
			$new_files_size = 0;

			if ( ! empty( $scan_result ) ) {
				// Compare scanned files with DB to find new ones
				global $wpdb;
				$table_name = DiluxOneOffloadDB::get_table_name();

				foreach ( $scan_result as $file_info ) {
					$relative_path = str_replace( wp_upload_dir()['basedir'], '', $file_info['local_path'] );

					// Check if file exists in DB
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted DiluxOneOffloadDB::get_table_name(), value is %s placeholder
					$exists = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(*) FROM $table_name WHERE file = %s",
							$relative_path
						)
					);

					if ( (int) $exists === 0 ) {
						// File not in DB = new file
						Logger::info( '[DiluxOne Offload Calculate Sync] NEW FILE FOUND: ' . $relative_path . ' (size: ' . size_format( $file_info['size'] ) . ')' );
						$new_files[]     = array(
							'path' => $relative_path,
							'size' => $file_info['size'],
						);
						$new_files_size += $file_info['size'];
					}
				}

				// Add new files to DB (if any)
				if ( ! empty( $new_files ) ) {
					DiluxOneOffloadDB::add_files_batch( $new_files );
					Logger::info( '[DiluxOne Offload Plugin] Found and added ' . count( $new_files ) . ' new files to DB (' . size_format( $new_files_size ) . ')' );

					// Refresh stats after adding new files
					$stats = DiluxOneOffloadDB::get_stats();
				}
			}

			// Get file statistics
			$total_files      = (int) ( $stats['total_files'] ?? 0 );
			$synced_files     = (int) ( $stats['synced_files'] ?? 0 );
			$pending_files    = (int) ( $stats['pending_files'] ?? 0 );
			$total_size       = (int) ( $stats['total_size'] ?? 0 );
			$transferred_size = (int) ( $stats['total_transferred'] ?? 0 );
			$pending_size     = $total_size - $transferred_size;

			// Calculate sizes for each category
			$new_files_count   = count( $new_files );
			$old_pending_files = $pending_files - $new_files_count; // Pending from previous sync
			$old_pending_size  = $pending_size - $new_files_size;

			Logger::info( '[DiluxOne Offload Plugin] Calculate sync: ' . $total_files . ' total, ' . $synced_files . ' synced, ' . $old_pending_files . ' pending (old), ' . $new_files_count . ' new' );

			wp_send_json_success(
				array(
					'total_files'              => $total_files,
					'synced_files'             => $synced_files,
					'pending_files'            => $old_pending_files, // Old pending files
					'new_files'                => $new_files_count, // New files not in DB before
					'total_size'               => $total_size,
					'synced_size'              => $transferred_size,
					'pending_size'             => max( 0, $old_pending_size ), // Old pending size
					'new_files_size'           => $new_files_size, // New files size
					'total_size_formatted'     => size_format( $total_size ),
					'synced_size_formatted'    => size_format( $transferred_size ),
					'pending_size_formatted'   => size_format( (int) max( 0, $old_pending_size ) ),
					'new_files_size_formatted' => size_format( $new_files_size ),
				)
			);

		} catch ( \Exception $e ) {
			Logger::info( '[DiluxOne Offload Plugin] Calculate sync error: ' . $e->getMessage() );
			/* translators: %s: error message */
			wp_send_json_error( sprintf( esc_html__( 'Error calculating sync: %s', 'diluxone-offload' ), esc_html( $e->getMessage() ) ) );
		}
	}

	/**
	 * AJAX: Activate offloading
	 */
	public function ajax_activate_offloading(): void {
		check_ajax_referer( 'diluxone_offload_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'diluxone-offload' ) );
		}

		if ( ! $this->sync_manager ) {
			wp_send_json_error( esc_html__( 'Sync manager not available', 'diluxone-offload' ) );
		}

		if ( CloudStreamWrapper::activate_offloading() ) {
			wp_send_json_success( 'Offloading activated' );
		} else {
			wp_send_json_error( esc_html__( 'Failed to activate offloading', 'diluxone-offload' ) );
		}
	}

	/**
	 * AJAX: Deactivate offloading
	 */
	public function ajax_deactivate_offloading(): void {
		check_ajax_referer( 'diluxone_offload_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'diluxone-offload' ) );
		}

		if ( ! $this->sync_manager ) {
			wp_send_json_error( esc_html__( 'Sync manager not available', 'diluxone-offload' ) );
		}

		if ( CloudStreamWrapper::deactivate_offloading() ) {
			// ⭐ Set state to CONFIGURED (not SYNCED)
			// User must re-sync to enable offloading again
			ConfigManager::set_state( PluginState::CONFIGURED );

			Logger::info( '[DiluxOne Offload Plugin] Offloading deactivated, state set to CONFIGURED' );
			wp_send_json_success( 'Offloading deactivated' );
		} else {
			wp_send_json_error( esc_html__( 'Failed to deactivate offloading', 'diluxone-offload' ) );
		}
	}

	/**
	 * DEV MODE: Enable offloading without sync.
	 * Jumps from CONFIGURED directly to OFFLOADING_ACTIVE.
	 * Assumes cloud storage already has all files.
	 *
	 * @return void
	 */
	public function ajax_dev_enable_without_sync(): void {
		check_ajax_referer( 'diluxone_offload_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'diluxone-offload' ) );
		}

		if ( ! defined( 'DILUXONE_OFFLOAD_DEV_MODE' ) || ! DILUXONE_OFFLOAD_DEV_MODE ) {
			wp_send_json_error( esc_html__( 'DILUXONE_OFFLOAD_DEV_MODE is not enabled', 'diluxone-offload' ) );
		}

		$current_state = ConfigManager::get_state();

		if ( $current_state !== PluginState::CONFIGURED ) {
			wp_send_json_error(
				sprintf(
				/* translators: %1$s is current state, %2$s is expected state. */
					esc_html__( 'Invalid state: %1$s. Expected: %2$s', 'diluxone-offload' ),
					esc_html( $current_state ),
					'configured'
				)
			);
		}

		// Set state to SYNCED first (required by CloudStreamWrapper::activate_offloading)
		ConfigManager::set_state( PluginState::SYNCED );

		if ( CloudStreamWrapper::activate_offloading() ) {
			Logger::info( '[DiluxOne Offload DEV MODE] Offloading enabled without sync (skip sync)' );
			wp_send_json_success( 'Offloading enabled without sync' );
		} else {
			// Rollback state on failure
			ConfigManager::set_state( PluginState::CONFIGURED );
			Logger::error( '[DiluxOne Offload DEV MODE] Failed to activate offloading without sync' );
			wp_send_json_error( esc_html__( 'Failed to activate offloading', 'diluxone-offload' ) );
		}
	}

	/**
	 * DEV MODE: Disconnect offloading without reverse sync.
	 * Jumps from OFFLOADING_ACTIVE directly to CONFIGURED.
	 * Assumes local storage already has all files.
	 *
	 * @return void
	 */
	public function ajax_dev_disconnect_without_sync(): void {
		check_ajax_referer( 'diluxone_offload_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'diluxone-offload' ) );
		}

		if ( ! defined( 'DILUXONE_OFFLOAD_DEV_MODE' ) || ! DILUXONE_OFFLOAD_DEV_MODE ) {
			wp_send_json_error( esc_html__( 'DILUXONE_OFFLOAD_DEV_MODE is not enabled', 'diluxone-offload' ) );
		}

		$current_state = ConfigManager::get_state();

		if ( $current_state !== PluginState::OFFLOADING_ACTIVE ) {
			wp_send_json_error(
				sprintf(
				/* translators: %1$s is current state, %2$s is expected state. */
					esc_html__( 'Invalid state: %1$s. Expected: %2$s', 'diluxone-offload' ),
					esc_html( $current_state ),
					'offloading_active'
				)
			);
		}

		// Deactivate stream wrapper
		CloudStreamWrapper::deactivate_offloading();

		// Override to CONFIGURED (deactivate_offloading sets SYNCED)
		ConfigManager::set_state( PluginState::CONFIGURED );

		// Clear stale sync tracking data
		ConfigManager::clear_sync_progress();
		DiluxOneOffloadDB::clear_table();

		Logger::info( '[DiluxOne Offload DEV MODE] Offloading disabled without reverse sync (skip sync)' );
		wp_send_json_success( 'Offloading disabled without sync' );
	}

	/**
	 * ⭐ Reset state to configured (when canceling sync)
	 */
	public function ajax_cs_reset_state_to_configured(): void {
		check_ajax_referer( 'diluxone_offload_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'diluxone-offload' ) );
		}

		if ( ! $this->sync_manager ) {
			wp_send_json_error( esc_html__( 'Sync manager not available', 'diluxone-offload' ) );
		}

		ConfigManager::set_state( PluginState::CONFIGURED );
		ConfigManager::clear_sync_progress(); // Clear sync metadata
		Logger::info( '[DiluxOne Offload Plugin] State reset to CONFIGURED after cancel (metadata cleared)' );
		wp_send_json_success( 'State reset to configured' );
	}

	/**
	 * ⭐ AJAX: Prepare complete resync (clear DB and set state to CONFIGURED)
	 * The scan + populate will happen automatically when user clicks "Start Sync" (same flow as first sync)
	 */
	public function ajax_cs_prepare_resync(): void {
		check_ajax_referer( 'diluxone_offload_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'diluxone-offload' ) );
		}

		try {
			// Step 1: Clear the database table
			DiluxOneOffloadDB::clear_table();
			Logger::info( '[DiluxOne Offload Plugin] Resync All: Database table cleared' );

			// Step 2: Set state to CONFIGURED (ready for fresh sync)
			ConfigManager::set_state( PluginState::CONFIGURED );
			Logger::info( '[DiluxOne Offload Plugin] Resync All: State set to CONFIGURED' );

			// Step 3: Return success (frontend will reload page to show CONFIGURED state)
			wp_send_json_success( array( 'cleared' => true ) );

		} catch ( \Exception $e ) {
			Logger::info( '[DiluxOne Offload Plugin] Resync All prepare error: ' . $e->getMessage() );
			/* translators: %s: error message */
			wp_send_json_error( sprintf( esc_html__( 'Failed to prepare resync: %s', 'diluxone-offload' ), esc_html( $e->getMessage() ) ) );
		}
	}

	/**
	 * ⭐ NEW AJAX: Discard failed files (remove from database)
	 * Removes ALL files with synced=0 (failed files, regardless of error count)
	 */
	public function ajax_cs_discard_failed_files(): void {
		check_ajax_referer( 'diluxone_offload_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'diluxone-offload' ) );
		}

		try {
			global $wpdb;
			$table_name = DiluxOneOffloadDB::get_table_name();

			// Delete ALL files with synced=0 (all failed files, regardless of error count)
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted DiluxOneOffloadDB::get_table_name(), no user input
			$result = $wpdb->query(
				"
                DELETE FROM $table_name
                WHERE synced = 0 AND deleted = 0
            "
			);

			if ( $result !== false ) {
				Logger::info( '[DiluxOne Offload Plugin] Discarded ' . $result . ' failed files from database' );

				// Every row left in the table now has `synced = 1` or `deleted = 1`,
				// so nothing is blocking the sync any more. If we were sitting in
				// SYNCING because of the rows just discarded, promote to SYNCED:
				// PluginState::can_activate_offloading() accepts only SYNCED, so
				// without this the `diluxone_offload_activate_offloading` call that
				// the "Clear Failed & Enable" flow fires right after (assets/js/
				// admin-sync.js) returns false and the user is told the files were
				// discarded but offloading could not be enabled.
				if ( PluginState::SYNCING === ConfigManager::get_state() ) {
					ConfigManager::set_state( PluginState::SYNCED );
					Logger::info( '[DiluxOne Offload Plugin] State transitioned SYNCING -> SYNCED after discarding all failed files' );
				}

				wp_send_json_success(
					array(
						'message'       => 'Failed files discarded',
						'deleted_count' => $result,
					)
				);
			} else {
				Logger::error( '[DiluxOne Offload Plugin] Failed to discard failed files' );
				wp_send_json_error( esc_html__( 'Failed to discard failed files', 'diluxone-offload' ) );
			}
		} catch ( \Exception $e ) {
			Logger::info( '[DiluxOne Offload Plugin] Discard failed files error: ' . $e->getMessage() );
			/* translators: %s: error message */
			wp_send_json_error( sprintf( esc_html__( 'Error discarding failed files: %s', 'diluxone-offload' ), esc_html( $e->getMessage() ) ) );
		}
	}

	/**
	 * ⭐ NEW AJAX: Get deletable files stats
	 * DB-first: no filesystem scan
	 */
	public function ajax_cs_get_deletable_stats(): void {
		check_ajax_referer( 'diluxone_offload_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'diluxone-offload' ) );
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'diluxone_offload_files';

		// Query the tracking table instead of scanning the filesystem
		// Get files that are synced to cloud but not deleted locally
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix, no user input
		$stats = $wpdb->get_row(
			"SELECT
                COUNT(*) as total_files,
                COALESCE(SUM(size), 0) as total_size
             FROM {$table_name}
             WHERE synced = 1 AND deleted = 0",
			ARRAY_A
		);

		$total_files = (int) $stats['total_files'];
		$total_size  = (int) $stats['total_size'];

		Logger::info( '[DiluxOne Offload Delete] Found ' . $total_files . ' deletable files (' . size_format( $total_size ) . ') in DB' );

		wp_send_json_success(
			array(
				'files'          => $total_files,
				'size'           => $total_size,
				'size_formatted' => size_format( $total_size ),
			)
		);
	}

	/**
	 * ⭐ NEW AJAX: Process delete batch (delete ALL local files in batches)
	 * DB-first: no filesystem scan
	 */
	public function ajax_cs_process_delete_batch(): void {
		check_ajax_referer( 'diluxone_offload_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'diluxone-offload' ) );
		}

		// Deleting the local copies means reaching the real files, so this asks
		// for the uploads directory with the offloading filter stepped aside.
		// wp_upload_dir() on its own answers with the cloud protocol while
		// offloading is on, and hardcoding wp-content/uploads instead would
		// miss every site that keeps its media somewhere else.
		$base_path = CloudStreamWrapper::native_upload_basedir();

		if ( '' === $base_path ) {
			wp_send_json_error( array( 'message' => __( 'WordPress reports no uploads directory, so there is nothing to delete from.', 'diluxone-offload' ) ) );
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'diluxone_offload_files';

		$deleted_total = 0;
		$failed_total  = 0;
		$start_time    = microtime( true );
		$time_limit    = 20; // 20 seconds per Ajax request
		$batch_size    = 500; // Process 500 files per DB query
		$break         = false;

		Logger::info( '[DiluxOne Offload Delete] Starting delete process from: ' . $base_path );

		// Runs until the time budget is spent or the work is done
		while ( ! $break ) {
			// 1. Get files from DB that need deletion (synced=1 AND deleted=0)
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix, value is %d placeholder
			$to_delete = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT file, size FROM {$table_name}
                 WHERE synced = 1 AND deleted = 0
                 LIMIT %d",
					$batch_size
				),
				ARRAY_A
			);

			// If no files to delete, we're done
			if ( empty( $to_delete ) ) {
				$break   = true;
				$is_done = true;
				break;
			}

			// 2. Delete each file from filesystem
			foreach ( $to_delete as $file_data ) {
				$file_path = $base_path . $file_data['file'];

				// The delete target must stay inside uploads/: reject anything
				// that would resolve outside it before it ever reaches the filesystem.
				if ( strpos( str_replace( '\\', '/', $file_data['file'] ), '..' ) !== false ) {
					Logger::error( '[DiluxOne Offload Delete] Rejected path outside uploads/: ' . $file_data['file'] );
					++$failed_total;
				} elseif ( file_exists( $file_path ) ) {
					// wp_delete_file() returns void; we re-check existence to detect success.
					wp_delete_file( $file_path );
					clearstatcache( true, $file_path );
					if ( ! file_exists( $file_path ) ) {
						++$deleted_total;
					} else {
						++$failed_total;
						Logger::error( '[DiluxOne Offload Delete] Failed to delete: ' . $file_path );
					}
				} else {
					// Already gone; treat as success.
					++$deleted_total;
				}

				// 3. Mark as deleted in DB (even if file didn't exist)
				$wpdb->update(
					$table_name,
					array( 'deleted' => 1 ),
					array( 'file' => $file_data['file'] ),
					array( '%d' ),
					array( '%s' )
				);
			}

			// 4. Check if done or timeout
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix, no user input
			$is_done = ! (bool) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$table_name} WHERE synced = 1 AND deleted = 0"
			);

			$elapsed = microtime( true ) - $start_time;

			if ( $is_done || $elapsed >= $time_limit ) {
				$break = true;
			}
		}

		// Get remaining count for progress
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix, no user input
		$remaining_count = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table_name} WHERE synced = 1 AND deleted = 0"
		);

		Logger::info( '[DiluxOne Offload Delete] Batch completed: deleted=' . $deleted_total . ', failed=' . $failed_total . ', remaining=' . $remaining_count );

		if ( $is_done ) {
			// ⭐ OPTIMIZATION: Clear table after delete completes
			// Table has no value once all files are deleted (all records would be deleted=1)
			// Keeps DB lean and allows fresh catalog on next operation
			DiluxOneOffloadDB::clear_table();
			Logger::info( '[DiluxOne Offload Delete] All files deleted - table cleared (no longer needed)' );

			wp_send_json_success(
				array(
					'status'             => 'completed',
					'message'            => 'All local files deleted successfully',
					'deleted_this_batch' => $deleted_total,
					'failed_this_batch'  => $failed_total,
					'pending_files'      => 0,
					'pending_size'       => 0,
				)
			);
		} else {
			wp_send_json_success(
				array(
					'status'             => 'processing',
					'deleted_this_batch' => $deleted_total,
					'failed_this_batch'  => $failed_total,
					'pending_files'      => $remaining_count,
					'pending_size'       => 0, // We don't calculate size to save queries
				)
			);
		}
	}

	/**
	 * ⭐ NEW AJAX: Take control of sync (for multi-tab coordination)
	 * Allows an inactive tab to take over the sync from another tab
	 */
	public function ajax_cs_take_control(): void {
		check_ajax_referer( 'diluxone_offload_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'diluxone-offload' ) );
		}

		try {
			// Get new session_id from POST
			$new_session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ?? '' ) ) : '';

			if ( empty( $new_session_id ) ) {
				wp_send_json_error( esc_html__( 'Session ID is required', 'diluxone-offload' ) );
			}

			// Get current sync metadata
			$sync_meta = get_option( 'diluxone_offload_sync_meta', array() );

			if ( empty( $sync_meta ) ) {
				wp_send_json_error( esc_html__( 'No active sync found', 'diluxone-offload' ) );
			}

			$old_session_id = $sync_meta['sync_session_id'] ?? 'unknown';

			// Update session control fields
			$sync_meta['sync_session_id'] = $new_session_id;
			$sync_meta['last_heartbeat']  = time();

			update_option( 'diluxone_offload_sync_meta', $sync_meta, false );

			Logger::info( '[DiluxOne Offload Multi-Tab] Control transferred from ' . $old_session_id . ' to ' . $new_session_id );

			wp_send_json_success(
				array(
					'message'        => 'Control transferred successfully',
					'old_session_id' => $old_session_id,
					'new_session_id' => $new_session_id,
				)
			);

		} catch ( \Exception $e ) {
			Logger::info( '[DiluxOne Offload Multi-Tab] Take control error: ' . $e->getMessage() );
			/* translators: %s: error message */
			wp_send_json_error( sprintf( esc_html__( 'Error taking control: %s', 'diluxone-offload' ), esc_html( $e->getMessage() ) ) );
		}
	}

	/**
	 * ⭐ NEW AJAX: Get sync state (for multi-tab coordination)
	 * Returns the current sync state including session info and progress
	 * Used by inactive tabs to detect if sync is active, inactive, or terminated
	 */
	public function ajax_cs_get_sync_state(): void {
		check_ajax_referer( 'diluxone_offload_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'diluxone-offload' ) );
		}

		try {
			// Get requesting tab's session_id
			$requesting_session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ?? '' ) ) : '';

			// Get current sync metadata
			$sync_meta = get_option( 'diluxone_offload_sync_meta', array() );

			// A reverse sync (Disconnect) is driven by the tab that started it
			// and carries no session or heartbeat, so the multi-tab rules below
			// do not apply to it. Without this, the expiry branch would read
			// its missing heartbeat as "abandoned" and drop the plugin to
			// CONFIGURED in the middle of a download.
			if ( ! empty( $sync_meta['is_reverse_sync'] ) ) {
				wp_send_json_success(
					array(
						'state'        => 'no_sync',
						'message'      => 'A download from the cloud is in progress',
						'plugin_state' => ConfigManager::get_state(),
					)
				);
			}

			if ( empty( $sync_meta ) ) {
				// Check plugin state - might be SYNCING even without metadata
				$plugin_state = ConfigManager::get_state();

				if ( $plugin_state === PluginState::SYNCING ) {
					// There SHOULD be sync metadata but there isn't - this is an error state
					Logger::info( '[DiluxOne Offload Multi-Tab] WARNING: Plugin state is SYNCING but no sync metadata found!' );
					wp_send_json_success(
						array(
							'state'        => 'no_sync',
							'message'      => 'Sync state inconsistent - no metadata',
							'plugin_state' => $plugin_state,
						)
					);
				} else {
					// No sync active
					wp_send_json_success(
						array(
							'state'        => 'no_sync',
							'message'      => 'No sync in progress',
							'plugin_state' => $plugin_state,
						)
					);
				}
			}

			$current_session_id = $sync_meta['sync_session_id'] ?? '';
			$last_heartbeat     = $sync_meta['last_heartbeat'] ?? 0;
			$status             = $sync_meta['status'] ?? 'unknown';

			// ⭐ NEW: Check for heartbeat timeout (90 seconds)
			$heartbeat_timeout = 90; // 90 seconds without heartbeat = expired session
			if ( time() - $last_heartbeat > $heartbeat_timeout ) {
				// Timeout: no tab is actively controlling the sync
				Logger::warning( '[DiluxOne Offload Multi-Tab] Sync session expired due to inactivity (no heartbeat for ' . ( time() - $last_heartbeat ) . ' seconds)' );

				// Reset state to CONFIGURED (sync was abandoned)
				ConfigManager::set_state( PluginState::CONFIGURED );
				ConfigManager::clear_sync_progress();

				wp_send_json_success(
					array(
						'state'              => 'expired',
						'message'            => 'Sync session expired due to inactivity',
						'last_heartbeat_age' => time() - $last_heartbeat,
					)
				);
			}

			// Check if sync is terminated (completed, completed_with_errors, or failed)
			$is_terminated = in_array( $status, array( 'completed', 'completed_with_errors', 'failed' ), true );

			// Determine state from this tab's perspective
			if ( $is_terminated ) {
				// State 3: Terminated
				// ⭐ FIX: Enrich sync_meta with upload stats from DB for Tab Inactivo
				// Tab Activo gets these from process_batch response, but Tab Inactivo needs them too
				require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';
				$stats = \DiluxOneOffload\DiluxOneOffloadDB::get_stats();

				$sync_meta['successful_uploads'] = $stats['synced_files'] ?? 0;
				$sync_meta['failed_uploads']     = $stats['failed_files'] ?? 0;
				$sync_meta['processed_files']    = $stats['synced_files'] ?? 0;
				$sync_meta['total_files']        = $stats['total_files'] ?? $sync_meta['total_files']; // ⭐ FIX: Also update total_files from DB

				wp_send_json_success(
					array(
						'state'     => 'terminated',
						'status'    => $status,
						'sync_meta' => $sync_meta,
						'message'   => 'Sync has terminated',
					)
				);
			} elseif ( $requesting_session_id === $current_session_id ) {
				// State 1: This tab is active (owns the sync)
				// ⭐ FIX: Enrich sync_meta with current progress from DB for Tab Activo
				// This ensures the failed_uploads counter updates in real-time
				require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';
				$stats = \DiluxOneOffload\DiluxOneOffloadDB::get_stats();

				$sync_meta['processed_files']    = $stats['synced_files'] ?? 0;
				$sync_meta['successful_uploads'] = $stats['synced_files'] ?? 0;
				$sync_meta['failed_uploads']     = $stats['failed_files'] ?? 0;
				$sync_meta['percentage']         = $stats['percentage'] ?? 0;
				$sync_meta['total_files']        = $stats['total_files'] ?? $sync_meta['total_files'];

				wp_send_json_success(
					array(
						'state'          => 'active',
						'session_id'     => $current_session_id,
						'last_heartbeat' => $last_heartbeat,
						'sync_meta'      => $sync_meta,
					)
				);
			} else {
				// State 2: This tab is inactive (another tab owns the sync)
				// ⭐ FIX: Enrich sync_meta with current progress from DB for Tab Inactivo
				// So it shows current progress instead of 0%
				require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';
				$stats = \DiluxOneOffload\DiluxOneOffloadDB::get_stats();

				$sync_meta['processed_files']    = $stats['synced_files'] ?? 0;
				$sync_meta['successful_uploads'] = $stats['synced_files'] ?? 0;
				$sync_meta['failed_uploads']     = $stats['failed_files'] ?? 0;
				$sync_meta['percentage']         = $stats['percentage'] ?? 0;
				$sync_meta['total_files']        = $stats['total_files'] ?? $sync_meta['total_files'];

				wp_send_json_success(
					array(
						'state'             => 'inactive',
						'active_session_id' => $current_session_id,
						'last_heartbeat'    => $last_heartbeat,
						'sync_meta'         => $sync_meta,
					)
				);
			}
		} catch ( \Exception $e ) {
			Logger::info( '[DiluxOne Offload Multi-Tab] Get sync state error: ' . $e->getMessage() );
			/* translators: %s: error message */
			wp_send_json_error( sprintf( esc_html__( 'Error getting sync state: %s', 'diluxone-offload' ), esc_html( $e->getMessage() ) ) );
		}
	}

	/**
	 * ⭐ NEW AJAX: Get failed files count (for Enable Offloading validation)
	 * Returns count of failed and pending files to validate before enabling offloading
	 */
	public function ajax_cs_get_failed_files_count(): void {
		check_ajax_referer( 'diluxone_offload_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'diluxone-offload' ) );
		}

		try {
			require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';

			$stats = \DiluxOneOffload\DiluxOneOffloadDB::get_stats();

			$failed_count  = (int) ( $stats['failed_files'] ?? 0 );
			$pending_count = (int) ( $stats['pending_files'] ?? 0 );

			wp_send_json_success(
				array(
					'failed_count'  => $failed_count,
					'pending_count' => $pending_count,
				)
			);

		} catch ( \Exception $e ) {
			Logger::info( '[DiluxOne Offload Plugin] Get failed files count error: ' . $e->getMessage() );
			/* translators: %s: error message */
			wp_send_json_error( sprintf( esc_html__( 'Error getting failed files count: %s', 'diluxone-offload' ), esc_html( $e->getMessage() ) ) );
		}
	}

	/**
	 * Get sync manager instance
	 *
	 * @return SyncManager|null
	 */
	public function get_sync_manager(): ?SyncManager {
		return $this->sync_manager;
	}

	/**
	 * Plugin activation hook.
	 *
	 * @param bool $network_wide True when activated across a whole multisite network.
	 * @return void
	 */
	public static function activate( bool $network_wide = false ): void {
		Logger::info( '[DiluxOne Offload Plugin] Activation hook called' );

		require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';

		// The file-tracking table is per site: on multisite every blog has its
		// own $wpdb->prefix, so a network activation that only ran here would
		// leave every other site in the network without a table, and its first
		// sync would fail on a missing table.
		if ( $network_wide && is_multisite() ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				DiluxOneOffloadDB::create_files_table();
				restore_current_blog();
			}

			Logger::info( '[DiluxOne Offload Plugin] Tables created network-wide.' );
			return;
		}

		DiluxOneOffloadDB::create_files_table();
	}

	/**
	 * Create this plugin's table on a site added after network activation.
	 *
	 * Without this, a blog created later never gets the table: activation
	 * already ran, so nothing else would create it and the site's first sync
	 * would fail.
	 *
	 * @param \WP_Site $site The newly created site.
	 * @return void
	 */
	public static function on_new_site( $site ): void {
		// wp_initialize_site can fire outside the admin, where this helper is
		// not loaded yet.
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active_for_network( plugin_basename( DILUXONE_OFFLOAD_FILE ) ) ) {
			return;
		}

		require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';

		switch_to_blog( (int) $site->blog_id );
		DiluxOneOffloadDB::create_files_table();
		restore_current_blog();

		Logger::info( '[DiluxOne Offload Plugin] Table created for new site ' . (int) $site->blog_id );
	}

	/**
	 * Plugin deactivation hook
	 */
	public static function deactivate(): void {
		Logger::info( '[DiluxOne Offload Plugin] Deactivation hook called' );

		// Deactivate stream wrapper if active
		CloudStreamWrapper::deactivate_offloading();
	}
}
