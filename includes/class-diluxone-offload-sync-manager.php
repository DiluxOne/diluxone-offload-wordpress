<?php
/**
 * Sync Manager
 *
 * Performs the initial bulk upload of media files. Uses cURL Multi to run
 * parallel uploads (up to 5 concurrent) — the WP HTTP API does not expose
 * multi-handle parallelism nor streamed-body uploads, so cURL is required
 * for performance on large libraries. WP HTTP API IS used everywhere else.
 * Filesystem ops touch local temporary files outside /wp-content/uploads/.
 * Direct $wpdb queries are used against the plugin's own table whose name is
 * derived from $wpdb->prefix (no user input). These rules are intentionally
 * suppressed file-wide:
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_multi_init
 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_multi_add_handle
 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_multi_exec
 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_multi_select
 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_multi_getcontent
 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_multi_remove_handle
 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_multi_close
 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init
 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_getinfo
 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_error
 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_close
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
 * phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_print_r
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload;

use DiluxOneOffload\Enums\PluginState;
use DiluxOneOffload\Enums\SyncStatus;
use DiluxOneOffload\DTOs\SyncFilter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sync Manager
 *
 * Handles initial synchronization of files to cloud storage
 *
 * PERFORMANCE OPTIMIZATIONS (Latest Iteration - v2.2 Queue Simple):
 * ==================================================================
 *
 * 1. PARALLEL UPLOADS (5 concurrent)
 *    - BALANCED: 5 simultaneous uploads using cURL Multi
 *    - Prevents MySQL/PHP-FPM saturation in Docker environments
 *    - Still 5x faster than sequential (1 at a time)
 *
 * 2. STREAMING UPLOAD (Memory Efficient)
 *    - Uses CURLOPT_INFILE instead of loading entire file in memory
 *    - 50MB file: ~8KB RAM usage vs 100MB RAM (6000x more efficient)
 *    - Eliminates PHP memory_limit issues during sync
 *
 * 3. CHUNKED UPLOAD for Large Files (>10MB)
 *    - Automatically splits files >10MB into 4MB chunks
 *    - Uses cloud provider chunked upload API (delegated to provider implementation)
 *    - Prevents timeout on slow connections
 *    - Only 4MB RAM per file regardless of size
 *
 * 4. QUEUE SIMPLE ARCHITECTURE ⭐ NEW
 *    - Full file list stored in TRANSIENT (wp_options with autoload=no)
 *    - Progress stores only INDEX (last_processed_index), not full queue
 *    - Reduces DB writes from 5MB to ~100 bytes per batch
 *    - MySQL queries reduced ~80% (from 250/sec to 50/sec)
 *    - Transient cached in object cache if available (Redis/Memcached)
 *
 * 5. OPTIMIZED TIMEOUTS
 *    - 90s per file (reduced from 300s)
 *    - Better failure detection without premature timeouts
 *
 * 6. NO MD5 CALCULATION during initial sync
 *    - Skips checksums for 80% speed gain
 *    - Only validates on incremental sync
 *
 * 7. BATCH SIZE: 20 files per batch
 *    - Balanced for throughput without overwhelming DB
 *    - 100ms delay between batches to let MySQL breathe
 *
 * 8. PERSISTENT FAILED FILES
 *    - Failed uploads stored in DB (diluxone_offload_failed_files)
 *    - Manual retry available via UI
 *    - No data loss on sync interruption
 *
 * DATA STORAGE COMPARISON:
 * ------------------------
 * BEFORE v2.2:
 *   wp_options.diluxone_offload_sync_progress = [
 *     'files_queue' => [5910 files...], // 2-5 MB serialized
 *     'processed_files' => 90
 *   ]
 *   Every batch: 2-5 MB write to MySQL
 *
 * AFTER v2.2:
 *   wp_options._transient_diluxone_offload_full_file_list = [6000 files] // Written ONCE
 *   wp_options.diluxone_offload_sync_progress = [
 *     'last_processed_index' => 90, // Just a number
 *     'processed_files' => 90
 *   ]
 *   Every batch: ~100 bytes write to MySQL
 *
 * EXPECTED PERFORMANCE:
 * - 6000 files @ 1MB avg: ~15-20 minutes
 * - Memory usage: Stable at ~50-100MB
 * - MySQL queries: ~50/second (vs 250/sec before)
 * - DB write size: ~100 bytes/batch (vs 5MB before)
 * - System stability: No MySQL crashes, no PHP-FPM saturation
 */
class SyncManager {

	/** @var mixed */
	private $cloud_client;
	/** @var mixed */
	private $upload_dir;
	/** @var int Files per batch — recalculated dynamically based on concurrency. */
	private $batch_size = 100;
	/** @var int Concurrent uploads using cURL Multi — default; configurable via UI. */
	private $parallel_uploads = 5;
	/** @var int Files larger than this threshold (bytes) use chunked upload. */
	private $chunked_threshold = 10485760;

	/**
	 * Set parallel uploads level (concurrency)
	 * ⭐ OPTIMIZED: Increased max from 12 to 40, batch_size adjusts dynamically
	 *
	 * @param int $level Number of parallel uploads (3-40)
	 */
	public function set_parallel_uploads( $level ): void {
		$level = intval( $level );
		if ( $level >= 3 && $level <= 40 ) {
			$this->parallel_uploads = $level;

			// ⭐ DYNAMIC BATCH SIZE: concurrency * 5 (min 25, max 200)
			// Reduced from *10 to *5 for more frequent progress updates
			$this->batch_size = max( 25, min( 200, $level * 5 ) );

			Logger::info( '[DiluxOne Offload Sync] Concurrency set to: ' . $level . ', batch_size: ' . $this->batch_size );
		}
	}

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->cloud_client = ConfigManager::get_cloud_client();
		$this->upload_dir   = wp_upload_dir();
	}

	/**
	 * Check if debug logging is enabled
	 *
	 * @return bool
	 */
	private function is_debug_enabled() {
		$config = ConfigManager::get_config();
		return ! empty( $config['debug_enabled'] );
	}

	/**
	 * Get sync filter from current configuration
	 *
	 * @return SyncFilter
	 */
	private function get_sync_filter(): SyncFilter {
		$config = ConfigManager::get_config();

		return SyncFilter::fromConfig(
			array(
				'allowed_file_types'   => $config['allowed_file_types'] ?? '*',
				'max_file_size'        => $config['max_file_size'] ?? 0,
				'excluded_paths'       => array(), // Could be extended to support excluded paths in config
				'include_hidden_files' => false,
				'include_system_files' => $config['allowed_file_types'] === '*',
			)
		);
	}

	/**
	 * Log debug message (only if debug is enabled)
	 *
	 * @param string $message
	 */
	private function debug_log( $message ): void {
		if ( $this->is_debug_enabled() ) {
			Logger::info( '[DiluxOne Offload Debug] ' . $message );
		}
	}

	/**
	 * Start initial synchronization
	 * Only an index is kept in the database; the queue itself is not stored.
	 *
	 * @return array ['success' => bool, 'message' => string, 'total_files' => int]
	 */
	/**
	 * Scan local files and populate DB (without starting sync)
	 * Used for calculating files before showing confirmation popup
	 *
	 * @return array<string, mixed> Result with success status and total_files count
	 */
	public function scan_local_files() {
		if ( ! $this->cloud_client ) {
			return array(
				'success' => false,
				'message' => 'Cloud client not configured',
			);
		}

		// Scan for files to sync (initial sync - optimized without MD5)
		$files_to_sync = $this->scan_files_to_sync( true );

		if ( empty( $files_to_sync ) ) {
			return array(
				'success'     => true,
				'message'     => 'No files to sync',
				'total_files' => 0,
			);
		}

		// Populate custom DB table
		require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';

		// Clear previous scan data
		DiluxOneOffloadDB::clear_table();

		// Add files to tracking table in batches
		$batch_files = array();
		foreach ( $files_to_sync as $file_info ) {
			$relative_path = str_replace( $this->upload_dir['basedir'], '', $file_info['local_path'] );

			$batch_files[] = array(
				'path' => $relative_path,
				'size' => $file_info['size'],
			);

			if ( count( $batch_files ) >= 500 ) {
				DiluxOneOffloadDB::add_files_batch( $batch_files );
				$batch_files = array();
			}
		}

		if ( ! empty( $batch_files ) ) {
			DiluxOneOffloadDB::add_files_batch( $batch_files );
		}

		Logger::info( '[DiluxOne Offload SyncManager] Scanned ' . count( $files_to_sync ) . ' files (stored in DB, ready for sync)' );

		return array(
			'success'     => true,
			'message'     => 'Files scanned',
			'total_files' => count( $files_to_sync ),
		);
	}

	/**
	 * Start the initial synchronization (or retry the failed-files subset).
	 *
	 * Reads progress from ConfigManager and processes files in batches via
	 * the configured cloud client. Does NOT block on completion — returns
	 * after kicking off the first batch; subsequent batches are driven by
	 * the AJAX progress endpoint.
	 *
	 * @param bool $retry_failed When true, only re-uploads files marked
	 *                           failed in DiluxOneOffloadDB; the regular sync queue
	 *                           is skipped.
	 * @return array{success:bool,message:string,total_files?:int} Status envelope.
	 */
	public function start_sync( $retry_failed = false ) {
		Logger::info( '[DiluxOne Offload SyncManager] start_sync() called with retry_failed=' . ( $retry_failed ? 'true' : 'false' ) );

		$current_state = ConfigManager::get_state();
		Logger::info( '[DiluxOne Offload SyncManager] Current state: ' . $current_state );

		// ⭐ SPECIAL: Allow retry from 'synced' state
		$can_start = $retry_failed
			? ( $current_state === 'synced' || PluginState::can_start_sync( $current_state ) )
			: PluginState::can_start_sync( $current_state );

		if ( ! $can_start ) {
			Logger::error( '[DiluxOne Offload SyncManager] Cannot start from state: ' . $current_state );
			return array(
				'success' => false,
				'message' => 'Cannot start sync in current state: ' . $current_state,
			);
		}

		Logger::info( '[DiluxOne Offload SyncManager] State check passed!' );

		if ( ! $this->cloud_client ) {
			Logger::info( '[DiluxOne Offload SyncManager] Cloud client not configured' );
			return array(
				'success' => false,
				'message' => 'Cloud client not configured',
			);
		}

		Logger::info( '[DiluxOne Offload SyncManager] Checks passed, loading DiluxOneOffloadDB...' );

		// ⭐ NEW: Populate custom DB table instead of wp_options
		require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';

		$total_files = 0;

		// ⭐ SPECIAL CASE: Retry failed mode
		// After reset_failed_files_to_pending(), files have errors=2, synced=0
		// We need to count them directly instead of using get_stats()
		if ( $retry_failed ) {
			global $wpdb;
			$table_name = DiluxOneOffloadDB::get_table_name();

			// Count ALL failed files (synced=0, regardless of error count after reset)
			$retry_count = (int) $wpdb->get_var(
				"
                SELECT COUNT(*) FROM $table_name
                WHERE synced = 0 AND deleted = 0
            "
			);

			Logger::info( '[DiluxOne Offload SyncManager] RETRY mode: Found ' . $retry_count . ' files to retry (synced=0)' );

			if ( $retry_count === 0 ) {
				ConfigManager::set_state( PluginState::SYNCED );
				return array(
					'success'     => false,
					'message'     => 'No failed files to retry',
					'total_files' => 0,
				);
			}

			$total_files = $retry_count;
		} else {
			// NORMAL MODE: Check if DB already has files (for "continue upload" scenario)
			$db_stats     = DiluxOneOffloadDB::get_stats();
			$db_has_files = ( $db_stats['total_files'] ?? 0 ) > 0;

			if ( $db_has_files ) {
				// DB already populated - this is a "continue upload"
				// Use existing DB data, don't rescan or clear
				$pending_files = (int) ( $db_stats['pending_files'] ?? 0 );
				$total_files   = $pending_files; // Only pending files for this sync session

				Logger::info( '[DiluxOne Offload SyncManager] DB stats: ' . print_r( $db_stats, true ) );
				Logger::info( '[DiluxOne Offload SyncManager] Pending files count: ' . $pending_files );

				if ( $pending_files === 0 ) {
					// All files already synced
					ConfigManager::set_state( PluginState::SYNCED );
					Logger::info( '[DiluxOne Offload SyncManager] No pending files found, marking as SYNCED' );
					return array(
						'success'     => true,
						'message'     => 'All files already synced',
						'total_files' => 0,
					);
				}

				Logger::info( '[DiluxOne Offload SyncManager] Continue upload: ' . $pending_files . ' pending files from existing DB' );
			} else {
				// DB is empty - this is a fresh sync, need to scan files
				$files_to_sync = $this->scan_files_to_sync( true );

				if ( empty( $files_to_sync ) ) {
					// No files to sync, mark as completed
					ConfigManager::set_state( PluginState::SYNCED );
					return array(
						'success'     => true,
						'message'     => 'No files to sync',
						'total_files' => 0,
					);
				}

				// Clear previous sync data
				DiluxOneOffloadDB::clear_table();

				// Add files to tracking table in batches for better performance
				$batch_files = array();
				foreach ( $files_to_sync as $file_info ) {
					// Transform to DB format (using relative path from local_path)
					$relative_path = str_replace( $this->upload_dir['basedir'], '', $file_info['local_path'] );

					$batch_files[] = array(
						'path' => $relative_path,
						'size' => $file_info['size'],
					);

					// Insert in batches of 500
					if ( count( $batch_files ) >= 500 ) {
						DiluxOneOffloadDB::add_files_batch( $batch_files );
						$batch_files = array();
					}
				}

				// Insert remaining files
				if ( ! empty( $batch_files ) ) {
					DiluxOneOffloadDB::add_files_batch( $batch_files );
				}

				$total_files = count( $files_to_sync );
				Logger::info( '[DiluxOne Offload SyncManager] Fresh sync: scanned and stored ' . $total_files . ' files in DB' );
			}
		}

		// ⭐ NEW: Get session_id from POST (will be sent from JavaScript).
		// This method is invoked by AJAX handlers that already verified the nonce
		// via check_ajax_referer(); the session_id is a client-generated correlator.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by calling AJAX handler.
		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ?? '' ) ) : uniqid( 'sync_', true );

		// Initialize sync metadata in wp_options (MINIMAL - only metadata, no file list)
		$sync_meta = array(
			'status'          => 'started',
			'total_files'     => $total_files,
			'start_time'      => time(),
			'last_update'     => time(),
			'concurrency'     => $this->parallel_uploads, // ⭐ FIXED: Save concurrency level
			// ⭐ NEW: Session control fields
			'sync_session_id' => $session_id,  // Id of the tab driving this sync.
			'last_heartbeat'  => time(),          // Refreshed on every batch.
		);

		// Save metadata (autoload = false for performance)
		update_option( 'diluxone_offload_sync_meta', $sync_meta, false );
		ConfigManager::set_state( PluginState::SYNCING );

		Logger::info( '[DiluxOne Offload SyncManager] Sync started with ' . $total_files . ' files' );

		return array(
			'success'     => true,
			'message'     => 'Sync started',
			'total_files' => $total_files,
		);
	}

	/**
	 * Process batch of files with time-based batching
	 * Uses the tracking table and a time budget per request
	 *
	 * @param float $time_limit Time limit in seconds (default 8s for responsive UI)
	 * @return array<string, mixed> Progress information
	 */
	public function process_batch( $time_limit = 8.0 ) {
		require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';

		$start_time          = microtime( true );
		$uploaded_this_batch = 0;
		$max_batch_size      = 12 * 1024 * 1024; // 12 MB per batch

		// Check if sync is active
		$sync_meta = get_option( 'diluxone_offload_sync_meta', array() );

		if ( empty( $sync_meta ) || $sync_meta['status'] !== 'started' ) {
			Logger::info( '[DiluxOne Offload SyncManager] process_batch() called but no active sync found' );
			return array(
				'status'  => 'error',
				'message' => 'No active sync found',
			);
		}

		// ⭐ NEW: Validate session ownership.
		// Invoked from AJAX handlers that already ran check_ajax_referer().
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by calling AJAX handler.
		$requesting_session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ?? '' ) ) : '';
		$current_session_id    = $sync_meta['sync_session_id'] ?? '';

		if ( ! empty( $requesting_session_id ) && $requesting_session_id !== $current_session_id ) {
			// This tab has lost control of the sync
			Logger::info( '[DiluxOne Offload Multi-Tab] Session mismatch: requesting=' . $requesting_session_id . ', current=' . $current_session_id );
			return array(
				'status'            => 'session_lost',
				'message'           => 'Another tab has taken control of the sync',
				'active_session_id' => $current_session_id,
			);
		}

		// ⭐ NEW: Update heartbeat at the beginning of batch
		$sync_meta['last_heartbeat'] = time();
		update_option( 'diluxone_offload_sync_meta', $sync_meta, false );

		// Process files until time limit or no more files
		while ( true ) {
			// Get pending files from DB (up to 1000 files or 12MB)
			$files = DiluxOneOffloadDB::get_pending_files( 1000, $max_batch_size );

			if ( empty( $files ) ) {
				// ⭐ FIX: Don't auto-complete here, let JavaScript handle it
				// This prevents race condition when cancelling
				$stats = DiluxOneOffloadDB::get_stats();
				return array(
					'status'             => 'completed',
					'total_files'        => $stats['total_files'],
					'processed_files'    => $stats['synced_files'],
					'successful_uploads' => $stats['synced_files'],
					'failed_uploads'     => $stats['failed_files'],
					'percentage'         => 100,
					'pending_files'      => 0,
				);
			}

			// Process files in parallel
			$batch_to_upload = array();
			foreach ( $files as $file ) {
				$relative_path = $file['file'];

				// The file to upload must live inside uploads/: rows can carry a
				// name the storage account reported, so reject anything that
				// would resolve outside it before it ever reaches fopen().
				if ( strpos( str_replace( '\\', '/', $relative_path ), '..' ) !== false ) {
					Logger::error( '[DiluxOne Offload SyncManager] Rejected sync path outside uploads/: ' . $relative_path );
					DiluxOneOffloadDB::increment_error( $relative_path, 'Path traversal rejected' );
					continue;
				}

				$local_path = $this->upload_dir['basedir'] . $relative_path;

				$remote_path = DiluxOneOffloadDB::key_from_path( $relative_path );

				$batch_to_upload[] = array(
					'path'        => $relative_path,
					'local_path'  => $local_path,
					'remote_path' => $remote_path,
					'size'        => $file['size'],
				);

				// Don't exceed batch size
				if ( count( $batch_to_upload ) >= $this->batch_size ) {
					break;
				}
			}

			// ⭐ Increment error count BEFORE upload (in case of timeout)
			foreach ( $batch_to_upload as $file_info ) {
				DiluxOneOffloadDB::increment_error( $file_info['path'] );
			}

			// ⭐ DEBUG: Log batch start
			$this->debug_log(
				sprintf(
					'Processing batch of %d files (batch size limit: %d)',
					count( $batch_to_upload ),
					$this->batch_size
				)
			);

			// Upload batch
			$results = $this->sync_files_parallel( $batch_to_upload );

			// Update DB based on results
			foreach ( $results as $i => $result ) {
				$file_info = $batch_to_upload[ $i ];

				if ( $result['success'] ) {
					$mark_result = DiluxOneOffloadDB::mark_synced( $file_info['path'] );
					if ( $mark_result === false || $mark_result === 0 ) {
						Logger::info( '[DiluxOne Offload SyncManager] ⚠️ Upload succeeded but DB update failed for: ' . $file_info['path'] );
					}
					++$uploaded_this_batch;
				} else {
					// Store error message in DB
					$error_msg = $result['error'] ?? 'Unknown error';
					DiluxOneOffloadDB::increment_error( $file_info['path'], $error_msg );
					Logger::error( '[DiluxOne Offload SyncManager] Failed: ' . $file_info['path'] . ' - ' . $error_msg );
				}
			}

			// Check time limit
			$elapsed = microtime( true ) - $start_time;
			if ( $elapsed >= $time_limit ) {
				Logger::info( '[DiluxOne Offload SyncManager] Time limit reached (' . round( $elapsed, 2 ) . 's), ending batch' );
				break;
			}
		}

		// Get updated stats from DB
		$stats = DiluxOneOffloadDB::get_stats();

		Logger::info( '[DiluxOne Offload SyncManager] Batch completed: ' . $uploaded_this_batch . ' uploaded this round. Total: ' . $stats['synced_files'] . '/' . $stats['total_files'] );

		return array(
			'status'              => 'processing',
			'total_files'         => $stats['total_files'],
			'processed_files'     => $stats['synced_files'],
			'successful_uploads'  => $stats['synced_files'],
			'failed_uploads'      => $stats['failed_files'],
			'percentage'          => $stats['percentage'],
			'uploaded_this_batch' => $uploaded_this_batch,
			'pending_files'       => $stats['pending_files'],
		);
	}

	/**
	 * Get current sync progress
	 * ⭐ NEW: Uses custom DB table for stats
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_progress() {
		require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';

		$sync_meta = get_option( 'diluxone_offload_sync_meta', array() );

		if ( empty( $sync_meta ) ) {
			return null;
		}

		// Get real-time stats from custom table
		$stats = DiluxOneOffloadDB::get_stats();

		$elapsed_time = time() - ( $sync_meta['start_time'] ?? time() );

		// $wpdb hands aggregates back as strings. Cast here so the JSON the
		// browser polls carries numbers, not "2": the progress bar does
		// arithmetic on these and the tests compare them strictly.
		return array(
			'status'             => $sync_meta['status'] ?? 'idle',
			'total_files'        => (int) $stats['total_files'],
			'processed_files'    => (int) $stats['synced_files'],
			'successful_uploads' => (int) $stats['synced_files'],
			'failed_uploads'     => (int) $stats['failed_files'],
			'percentage'         => (float) $stats['percentage'],
			'elapsed_time'       => (int) $elapsed_time,
			'pending_files'      => (int) $stats['pending_files'],
			'remaining_files'    => (int) $stats['pending_files'],
		);
	}

	/**
	 * Pause current sync
	 *
	 * @return bool
	 */
	public function pause_sync() {
		$progress = ConfigManager::get_sync_progress();

		if ( $progress && $progress['status'] === 'started' ) {
			$progress['status']      = 'paused';
			$progress['last_update'] = time();

			ConfigManager::save_sync_progress( $progress );
			Logger::info( '[DiluxOne Offload SyncManager] Sync paused' );

			return true;
		}

		return false;
	}

	/**
	 * Resume paused sync
	 *
	 * @return bool
	 */
	public function resume_sync() {
		$progress = ConfigManager::get_sync_progress();

		if ( $progress && $progress['status'] === 'paused' ) {
			$progress['status']      = 'started';
			$progress['last_update'] = time();

			ConfigManager::save_sync_progress( $progress );
			ConfigManager::set_state( PluginState::SYNCING );

			Logger::info( '[DiluxOne Offload SyncManager] Sync resumed' );

			return true;
		}

		return false;
	}

	/**
	 * Cancel current sync
	 *
	 * @return bool
	 */
	/**
	 * Cancel current sync
	 * ⭐ FIXED: Uses new DB architecture
	 *
	 * @return bool
	 */
	public function cancel_sync() {
		// Check if sync is active using new metadata structure
		$sync_meta = get_option( 'diluxone_offload_sync_meta', array() );

		if ( ! empty( $sync_meta ) && $sync_meta['status'] === 'started' ) {
			require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';

			// 1. Clear DB table
			DiluxOneOffloadDB::clear_table();

			// 2. Clear sync metadata
			delete_option( 'diluxone_offload_sync_meta' );

			// 3. Reset state to CONFIGURED
			ConfigManager::set_state( PluginState::CONFIGURED );

			Logger::info( '[DiluxOne Offload SyncManager] Sync cancelled - DB cleared and state reset to CONFIGURED' );

			return true;
		}

		// Fallback: Also check old architecture (for compatibility)
		$progress = ConfigManager::get_sync_progress();
		if ( $progress && in_array( $progress['status'], array( 'started', 'paused' ), true ) ) {
			ConfigManager::clear_sync_progress();
			delete_transient( 'diluxone_offload_full_file_list' );
			ConfigManager::set_state( PluginState::CONFIGURED );
			Logger::info( '[DiluxOne Offload SyncManager] Sync cancelled (legacy architecture)' );
			return true;
		}

		return false;
	}

	/**
	 * Scan uploads directory for files to sync
	 *
	 * @param bool $initial_sync Whether this is an initial sync (optimizes MD5 calculation)
	 * @return array<int, array<string, mixed>> Array of file information
	 */
	public function scan_files_to_sync( $initial_sync = false ) {
		$files          = array();
		$upload_basedir = $this->upload_dir['basedir'];

		Logger::debug( '[DiluxOne Offload SyncManager] Scanning directory: ' . $upload_basedir );
		Logger::info( '[DiluxOne Offload SyncManager] Directory exists: ' . ( is_dir( $upload_basedir ) ? 'YES' : 'NO' ) );

		if ( ! is_dir( $upload_basedir ) ) {
			Logger::error( '[DiluxOne Offload SyncManager] Directory does not exist, returning empty array' );
			return $files;
		}

		// On the main site of a network the other sites' uploads live inside
		// this very directory (uploads/sites/<id>, or blogs.dir on an older
		// network). They are theirs to sync, under their own prefix; the main
		// site never lists, uploads or deletes them.
		$own_files = new \RecursiveCallbackFilterIterator(
			new \RecursiveDirectoryIterator( $upload_basedir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			static function ( \SplFileInfo $entry ) use ( $upload_basedir ): bool {
				if ( ! $entry->isDir() || ! ( is_multisite() && is_main_site() ) ) {
					return true;
				}
				$relative = ltrim( substr( $entry->getPathname(), strlen( $upload_basedir ) ), '/' );
				return ! in_array( $relative, array( 'sites', 'blogs.dir' ), true );
			}
		);
		$iterator  = new \RecursiveIteratorIterator( $own_files, \RecursiveIteratorIterator::LEAVES_ONLY );

		$total_files_found   = 0;
		$files_passed_filter = 0;
		$skipped_files       = array();
		$skip_reasons        = array();

		foreach ( $iterator as $file ) {
			++$total_files_found;
			if ( $file->isFile() ) {
				$local_path    = $file->getPathname();
				$relative_path = str_replace( $upload_basedir . '/', '', $local_path );
				$remote_path   = DiluxOneOffloadDB::key_from_path( $relative_path );

				// Debug: Log first few files to verify correct path structure
				if ( $files_passed_filter < 3 ) {
					Logger::info( '[DiluxOne Offload SyncManager] Path mapping: ' . $local_path . ' → ' . $remote_path );
				}

				// Get file size first
				$file_size = $file->getSize();

				// Skip files with size 0 (empty files) - checked before filter
				if ( $file_size === 0 ) {
					$skipped_files[] = basename( $local_path );
					if ( ! isset( $skip_reasons['empty_file'] ) ) {
						$skip_reasons['empty_file'] = 0;
					}
					++$skip_reasons['empty_file'];
					continue;
				}

				// A path the tracking table cannot hold is skipped here, and
				// counted, rather than dropped later without a trace.
				if ( ! DiluxOneOffloadDB::fits_key( $relative_path ) ) {
					$skipped_files[] = basename( $local_path );
					if ( ! isset( $skip_reasons['path_too_long'] ) ) {
						$skip_reasons['path_too_long'] = 0;
					}
					++$skip_reasons['path_too_long'];
					continue;
				}

				// Apply filtering (including file type, max size, hidden files)
				$skip_reason = $this->should_sync_file( $local_path, $file_size );
				if ( $skip_reason !== true ) {
					$skipped_files[] = basename( $local_path );
					if ( ! isset( $skip_reasons[ $skip_reason ] ) ) {
						$skip_reasons[ $skip_reason ] = 0;
					}
					++$skip_reasons[ $skip_reason ];
					continue;
				}

				++$files_passed_filter;

				// One line every 1000 files: enough to follow, not enough to flood.
				if ( $files_passed_filter % 1000 === 0 ) {
					Logger::info( '[DiluxOne Offload SyncManager] Processed ' . $total_files_found . ' files, accepted ' . $files_passed_filter );
				}

				$files[] = array(
					'local_path'      => $local_path,
					'remote_path'     => $remote_path,
					'size'            => $file_size,
					'checksum'        => $initial_sync ? null : md5_file( $local_path ), // OPTIMIZED: Skip MD5 for initial sync
					'is_initial_sync' => $initial_sync,
				);
			}
		}

		Logger::info( '[DiluxOne Offload SyncManager] Total files found by iterator: ' . $total_files_found );
		Logger::info( '[DiluxOne Offload SyncManager] Files passed filter: ' . $files_passed_filter );

		// Report what the filter left out.
		if ( ! empty( $skip_reasons ) ) {
			Logger::info( '[DiluxOne Offload SyncManager] Files skipped by reason:' );
			foreach ( $skip_reasons as $reason => $count ) {
				Logger::info( '[DiluxOne Offload SyncManager]   ' . $reason . ': ' . $count . ' files' );
			}
		}

		Logger::info( '[DiluxOne Offload SyncManager] Found ' . count( $files ) . ' files to sync' );

		return $files;
	}

	/**
	 * Check if file should be synced
	 * REFACTORED: Now uses SyncFilter DTO
	 *
	 * @param string $file_path
	 * @param int    $file_size File size in bytes
	 * @return bool|string True if should sync, string with reason if should skip
	 */
	private function should_sync_file( $file_path, $file_size = 0 ) {
		// Skip known cache/temp directories (always) - not in SyncFilter yet
		$skip_patterns = array(
			'/cache/' => 'Cache directories',
			'/temp/'  => 'Temporary directories',
			'/tmp/'   => 'Temporary directories',
		);

		foreach ( $skip_patterns as $pattern => $reason ) {
			if ( strpos( $file_path, $pattern ) !== false ) {
				return $reason;
			}
		}

		// Use SyncFilter DTO for remaining filtering logic
		$filter = $this->get_sync_filter();

		if ( ! $filter->shouldIncludeFile( $file_path, $file_size ) ) {
			$exclusion_reason = $filter->getExclusionReason( $file_path, $file_size );
			return $exclusion_reason ?? 'File excluded by filter';
		}

		return true;
	}

	/**
	 * Sync multiple files in parallel using cURL Multi
	 *
	 * PERFORMANCE BOOST: 10x faster than sequential uploads
	 *
	 * @param array<int, array<string, mixed>> $batch Array of file_info arrays
	 * @return array<int, array<string, mixed>> Array of per-file result envelopes
	 */
	private function sync_files_parallel( $batch ) {
		$results    = array();
		$chunk_size = max( 1, $this->parallel_uploads );
		$chunks     = array_chunk( $batch, $chunk_size );

		foreach ( $chunks as $chunk ) {
			$chunk_results = $this->upload_chunk_parallel( $chunk );
			$results       = array_merge( $results, $chunk_results );
		}

		return $results;
	}


	/**
	 * Run a curl_multi stack to completion and collect each transfer's error.
	 *
	 * curl_error() is empty for a handle that ran inside a multi stack: the
	 * transport's verdict is only handed out through curl_multi_info_read().
	 * Without this a timed-out or unreachable transfer was recorded as a bare
	 * "HTTP 0", which told the user nothing.
	 *
	 * @param \CurlMultiHandle|resource $mh The multi handle, with every handle added.
	 * @return array<string, string> Transport error message per handle id ('' when none).
	 */
	private function run_multi( $mh ): array {
		$running = null;
		$errors  = array();
		do {
			curl_multi_exec( $mh, $running );
			curl_multi_select( $mh );
			while ( false !== ( $info = curl_multi_info_read( $mh ) ) ) {
				$id = $this->handle_id( $info['handle'] );
				$errors[ $id ] = 0 === (int) $info['result'] ? '' : curl_strerror( (int) $info['result'] );
			}
		} while ( $running > 0 );
		return $errors;
	}

	/**
	 * @param \CurlHandle|resource $ch
	 */
	private function handle_id( $ch ): string {
		return is_object( $ch ) ? (string) spl_object_id( $ch ) : (string) (int) $ch;
	}

	/**
	 * Upload a chunk of files in parallel using cURL Multi
	 * OPTIMIZED: Properly manages file handles for streaming
	 *
	 * @param array<int, array<string, mixed>> $files Array of file_info arrays (max $parallel_uploads files)
	 * @return array<int, array<string, mixed>> Array of results
	 */
	private function upload_chunk_parallel( $files ) {
		$mh           = curl_multi_init();
		$handles      = array();
		$file_handles = array(); // Track file handles for cleanup
		$results      = array();

		// Prepare all cURL handles
		foreach ( $files as $i => $file_info ) {
			$handle_data = $this->prepare_upload_handle( $file_info );

			if ( $handle_data['success'] ) {
				$handles[ $i ]      = $handle_data['handle'];
				$file_handles[ $i ] = $handle_data['file_handle']; // Store file handle
				curl_multi_add_handle( $mh, $handle_data['handle'] );
			} else {
				// Failed to prepare handle
				$results[ $i ] = array(
					'success' => false,
					'error'   => $handle_data['error'],
				);
			}
		}

		// Execute all handles in parallel
		$transport = $this->run_multi( $mh );

		// Collect results and cleanup
		foreach ( $handles as $i => $ch ) {
			$response_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$response_body = curl_multi_getcontent( $ch );
			$error         = $transport[ $this->handle_id( $ch ) ] ?? curl_error( $ch );
			$file_info     = $files[ $i ];

			if ( $response_code === 201 || $response_code === 200 ) {
				$results[ $i ] = array( 'success' => true );

				// ⭐ DEBUG: Log upload result (cloud providers typically return 201 on PUT success)
				$this->debug_log(
					sprintf(
						'✓ UPLOADED: %s (%.2f KB) [HTTP %d]',
						$file_info['path'],
						$file_info['size'] / 1024,
						$response_code
					)
				);
			} else {
				// Only the error code and message from the response: on a 403
				// the full body also carries the request's signature, which
				// has no place in a log line or the tracking table.
				$error_details = $error ? $error : 'HTTP ' . $response_code;
				if ( ! empty( $response_body ) && $response_code >= 400 && $this->cloud_client instanceof \DiluxOneOffload\Providers\AzureProvider ) {
					$error_details .= $this->cloud_client->describe_error_body( (string) $response_body );
				}

				$results[ $i ] = array(
					'success' => false,
					'error'   => $error_details,
				);

				// ⭐ DEBUG: Log failed upload with error details
				$this->debug_log(
					sprintf(
						'✗ FAILED: %s - Error: %s [HTTP %d]',
						$file_info['path'],
						$error ? $error : 'Unknown error',
						$response_code
					)
				);
			}

			curl_multi_remove_handle( $mh, $ch );
			// CRITICAL: Close file handle to free resources
			if ( isset( $file_handles[ $i ] ) && is_resource( $file_handles[ $i ] ) ) {
				fclose( $file_handles[ $i ] );
			}
		}

		curl_multi_close( $mh );

		// Fill missing results (for files that failed to prepare)
		foreach ( $files as $i => $file_info ) {
			if ( ! isset( $results[ $i ] ) ) {
				$results[ $i ] = array(
					'success' => false,
					'error'   => 'Unknown error',
				);
			}
		}

		// Results land in completion order, and the caller merges chunks by
		// position: put them back in the order of the files they belong to,
		// or a file that failed to open is credited with its neighbour's
		// upload.
		ksort( $results );

		return $results;
	}

	/**
	 * Prepare a cURL handle for uploading a file to cloud storage
	 * OPTIMIZED: Uses streaming for memory efficiency + chunked upload for large files
	 *
	 * @param array $file_info
	 * @return array ['success' => bool, 'handle' => resource|null, 'error' => string, 'file_handle' => resource|null]
	 */
	/**
	 * Prepare upload handle (delegates to provider)
	 * Provider-agnostic method that delegates to cloud storage provider
	 *
	 * @param array<string, mixed> $file_info File information
	 * @return array<string, mixed> Upload handle data
	 */
	private function prepare_upload_handle( $file_info ) {
		$local_path = $file_info['local_path'];

		if ( ! file_exists( $local_path ) ) {
			return array(
				'success'     => false,
				'error'       => 'File not found: ' . $local_path,
				'file_handle' => null,
			);
		}

		$file_size = filesize( $local_path );

		// OPTIMIZATION: Use chunked upload for files > 10MB
		if ( $file_size > $this->chunked_threshold ) {
			return $this->cloud_client->prepare_chunked_upload_handle( $file_info );
		}

		// Regular upload for files < 10MB
		return $this->cloud_client->prepare_batch_upload_handle( $file_info );
	}

	/**
	 * ⭐ NEW: Download files in parallel (same pattern as upload)
	 *
	 * @param array<int, array<string, mixed>> $batch Array of file_info arrays
	 * @return array<int, array<string, mixed>> Array of per-file result envelopes
	 */
	private function download_files_parallel( $batch ) {
		$results    = array();
		$chunk_size = max( 1, $this->parallel_uploads );
		$chunks     = array_chunk( $batch, $chunk_size );

		foreach ( $chunks as $chunk ) {
			$chunk_results = $this->download_chunk_parallel( $chunk );
			$results       = array_merge( $results, $chunk_results );
		}

		return $results;
	}

	/**
	 * ⭐ NEW: Download a chunk of files in parallel using cURL Multi
	 *
	 * @param array<int, array<string, mixed>> $files Array of file_info arrays (max $parallel_uploads files)
	 * @return array<int, array<string, mixed>> Array of results
	 */
	private function download_chunk_parallel( $files ) {
		Logger::info( '[DiluxOne Offload SyncManager] 🚀 Downloading chunk of ' . count( $files ) . ' files in parallel (concurrency=' . $this->parallel_uploads . ')' );

		$mh           = curl_multi_init();
		$handles      = array();
		$file_handles = array(); // Track file handles for writing
		$part_paths   = array(); // Where each download lands before it replaces the attachment
		$results      = array();

		// Prepare all cURL handles
		foreach ( $files as $i => $file_info ) {
			$handle_data = $this->prepare_download_handle( $file_info );

			if ( $handle_data['success'] ) {
				$handles[ $i ]      = $handle_data['handle'];
				$file_handles[ $i ] = $handle_data['file_handle'];
				$part_paths[ $i ]   = (string) ( $handle_data['part_path'] ?? '' );
				curl_multi_add_handle( $mh, $handle_data['handle'] );
			} else {
				// Failed to prepare handle
				$results[ $i ] = array(
					'success' => false,
					'error'   => $handle_data['error'],
				);
			}
		}

		// Execute all handles in parallel
		$transport = $this->run_multi( $mh );

		// Collect results and cleanup
		foreach ( $handles as $i => $ch ) {
			$response_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$error         = $transport[ $this->handle_id( $ch ) ] ?? curl_error( $ch );

			curl_multi_remove_handle( $mh, $ch );
			// Close file handle before measuring what actually landed on disk.
			if ( isset( $file_handles[ $i ] ) && is_resource( $file_handles[ $i ] ) ) {
				fclose( $file_handles[ $i ] );
			}

			// A timeout mid-body still reports HTTP 200: the status line arrived
			// before cURL gave up. Only a transfer with no error, and with as many
			// bytes on disk as the response itself declared, is a download;
			// anything else is a partial file that must not be marked as present
			// locally. The comparison is against this response's Content-Length,
			// not the size in the tracking table: that one was recorded when the
			// file was first seen, and a blob rewritten since (a regenerated
			// stylesheet, a log that grew) is a different size now.
			$declared_size = (float) curl_getinfo( $ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD );
			$local_path    = (string) ( $files[ $i ]['local_path'] ?? '' );
			$part_path     = $part_paths[ $i ] ?? '';
			$landed        = '' !== $part_path ? $part_path : $local_path;
			clearstatcache( true, $landed );
			$actual_size = ( '' !== $landed && file_exists( $landed ) ) ? (int) filesize( $landed ) : -1;
			$size_ok     = $declared_size < 0 || $actual_size === (int) $declared_size;

			// The part file replaces the attachment only now, with the status
			// and the byte count known; a copy that was already there is never
			// touched by a download that did not complete.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- the attachment's own path, resolved at runtime; see prepare_download_handle().
			if ( $response_code === 200 && '' === $error && $size_ok && '' !== $part_path && ! rename( $part_path, $local_path ) ) {
				$error = 'Could not move the downloaded file into place';
			}

			if ( $response_code === 200 && '' === $error && $size_ok ) {
				$results[ $i ] = array( 'success' => true );
			} else {
				if ( '' === $error && $response_code === 200 ) {
					$error = 'Incomplete download: expected ' . (int) $declared_size . ' bytes, got ' . $actual_size;
				}
				// Whatever landed is not the file: a truncated body, or Azure's
				// XML error document. Only the part file goes; the attachment's
				// path, if a copy was there, is untouched.
				if ( '' !== $landed && file_exists( $landed ) ) {
					wp_delete_file( $landed );
				}
				$results[ $i ] = array(
					'success' => false,
					'error'   => $error ? $error : 'HTTP ' . $response_code,
				);
			}
		}

		curl_multi_close( $mh );

		// Fill missing results
		foreach ( $files as $i => $file_info ) {
			if ( ! isset( $results[ $i ] ) ) {
				$results[ $i ] = array(
					'success' => false,
					'error'   => 'Unknown error',
				);
			}
		}

		// Same as the upload chunk: the caller merges by position.
		ksort( $results );

		return $results;
	}

	/**
	 * Prepare a cURL handle for downloading a file from cloud storage
	 * Delegates to cloud provider implementation
	 *
	 * @param array<string, mixed> $file_info File information ['local_path' => string, 'remote_path' => string]
	 * @return array<string, mixed> ['success' => bool, 'handle' => resource|null, 'error' => string, 'file_handle' => resource|null]
	 */
	private function prepare_download_handle( $file_info ) {
		// Delegate to cloud provider (Azure, AWS, GCP, etc.)
		return $this->cloud_client->prepare_download_handle( $file_info );
	}

	/**
	 * Complete sync process
	 *
	 * @param array $progress
	 * @return array
	 */
	/**
	 * Reverse sync (Cloud → Local)
	 * Downloads files from cloud storage to local storage
	 * Used when disconnecting from cloud provider
	 *
	 * @param string $mode 'continue' (smart, only missing) or 'scratch' (re-download all)
	 * @return array<string, mixed> ['success' => bool, 'message' => string, 'total_files' => int]
	 */
	public function start_reverse_sync( $mode = 'continue' ) {
		$current_state = ConfigManager::get_state();

		if ( $current_state !== PluginState::OFFLOADING_ACTIVE ) {
			return array(
				'success' => false,
				'message' => 'Reverse sync only available when offloading is active',
			);
		}

		if ( ! $this->cloud_client ) {
			return array(
				'success' => false,
				'message' => 'Cloud client not configured',
			);
		}

		// Test connection before listing files
		Logger::info( '[DiluxOne Offload SyncManager] Testing connection before reverse sync (mode: ' . $mode . ')' );
		$connection_test = $this->cloud_client->test_connection();

		if ( ! $connection_test['success'] ) {
			Logger::info( '[DiluxOne Offload SyncManager] Connection test failed: ' . $connection_test['message'] );
			return array(
				'success' => false,
				'message' => 'Connection to cloud storage failed: ' . $connection_test['message'],
			);
		}

		require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';

		// ⭐ NEW MODE LOGIC
		if ( $mode === 'scratch' ) {
			// SCRATCH MODE: Clear table and re-download EVERYTHING
			Logger::info( '[DiluxOne Offload SyncManager] SCRATCH mode: Clearing table and cataloging all cloud files...' );

			DiluxOneOffloadDB::clear_table();

			// List this site's files in cloud storage
			$cloud_files = CloudStreamWrapper::site_files( $this->cloud_client->list_files( DiluxOneOffloadDB::listing_prefix() ) );

			if ( empty( $cloud_files ) ) {
				return array(
					'success' => false,
					'message' => 'No files found in cloud storage.',
				);
			}

			// Mark ALL as deleted (pending download)
			$batch_files = array();
			foreach ( $cloud_files as $cloud_file ) {
				$relative_path = DiluxOneOffloadDB::path_from_key( (string) $cloud_file['path'] );
				$batch_files[] = array(
					'path' => $relative_path,
					'size' => $cloud_file['size'],
				);

				if ( count( $batch_files ) >= 500 ) {
					DiluxOneOffloadDB::add_cloud_only_files_batch( $batch_files );
					$batch_files = array();
				}
			}

			if ( ! empty( $batch_files ) ) {
				DiluxOneOffloadDB::add_cloud_only_files_batch( $batch_files );
			}

			$deleted_stats = DiluxOneOffloadDB::get_deleted_stats();
			Logger::info( '[DiluxOne Offload SyncManager] SCRATCH mode: ' . count( $cloud_files ) . ' files marked for download' );

		} else {
			// CONTINUE MODE (default): Resume existing download
			Logger::info( '[DiluxOne Offload SyncManager] CONTINUE mode: Resuming existing download from DB...' );

			// Check if there are already deleted files in DB
			$deleted_stats = DiluxOneOffloadDB::get_deleted_stats();

			if ( ! empty( $deleted_stats ) && $deleted_stats['files'] > 0 ) {
				// Already have files marked for download, just resume
				Logger::info( '[DiluxOne Offload SyncManager] CONTINUE mode: Found ' . $deleted_stats['files'] . ' files already marked for download, resuming...' );
			} else {
				// No files marked yet, need to catalog from cloud storage (smart mode)
				Logger::info( '[DiluxOne Offload SyncManager] CONTINUE mode: No files marked yet, cataloging missing files from cloud storage...' );

				global $wpdb;
				$table_name = $wpdb->prefix . 'diluxone_offload_files';

				// List this site's files in cloud storage
				$cloud_files = CloudStreamWrapper::site_files( $this->cloud_client->list_files( DiluxOneOffloadDB::listing_prefix() ) );

				if ( empty( $cloud_files ) ) {
					return array(
						'success' => false,
						'message' => 'No files found in cloud storage.',
					);
				}

				// Get files already in DB (synced files)
				$synced_files_in_db = array();
				$db_files           = $wpdb->get_results(
					"
                    SELECT file
                    FROM {$table_name}
                    WHERE synced = 1
                ",
					ARRAY_A
				);

				foreach ( $db_files as $row ) {
					$synced_files_in_db[ $row['file'] ] = true;
				}

				// Mark only missing ones as deleted (pending download)
				$missing_count = 0;
				$batch_files   = array();

				foreach ( $cloud_files as $cloud_file ) {
					$relative_path = DiluxOneOffloadDB::path_from_key( (string) $cloud_file['path'] );

					// Only mark as deleted if NOT in synced DB files
					if ( ! isset( $synced_files_in_db[ $relative_path ] ) ) {
						$batch_files[] = array(
							'path' => $relative_path,
							'size' => $cloud_file['size'],
						);
						++$missing_count;

						// Batch insert every 500 files
						if ( count( $batch_files ) >= 500 ) {
							DiluxOneOffloadDB::add_cloud_only_files_batch( $batch_files );
							$batch_files = array();
						}
					}
				}

				// Insert remaining files
				if ( ! empty( $batch_files ) ) {
					DiluxOneOffloadDB::add_cloud_only_files_batch( $batch_files );
				}

				$deleted_stats = DiluxOneOffloadDB::get_deleted_stats();
				Logger::info( '[DiluxOne Offload SyncManager] CONTINUE mode: ' . $missing_count . ' missing files marked for download' );
			}
		}

		// ⭐ Get TOTAL files in cloud (synced=1), not just pending
		$all_stats          = DiluxOneOffloadDB::get_stats();
		$total_in_cloud     = $all_stats['synced_files']; // All files with synced=1
		$already_downloaded = $total_in_cloud - $deleted_stats['files'];

		// Initialize sync metadata for reverse sync
		$sync_meta = array(
			'status'             => 'started',
			'total_files'        => $total_in_cloud, // ⭐ TOTAL files in cloud (not just pending)
			'already_downloaded' => $already_downloaded, // ⭐ Files already local
			'start_time'         => time(),
			'last_update'        => time(),
			'is_reverse_sync'    => true,
			'reverse_mode'       => $mode, // ⭐ Save mode (continue or scratch)
			'concurrency'        => $this->parallel_uploads, // ⭐ Save concurrency for reverse sync
		);

		update_option( 'diluxone_offload_sync_meta', $sync_meta, false );

		Logger::info(
			'[DiluxOne Offload SyncManager] Reverse sync started: ' . $total_in_cloud . ' total in cloud, ' .
				$already_downloaded . ' already downloaded, ' .
				$deleted_stats['files'] . ' pending (' . size_format( $deleted_stats['size'] ) . ')'
		);

		return array(
			'success'     => true,
			'message'     => 'Reverse sync started',
			'total_files' => $total_in_cloud,
		);
	}

	/**
	 * Whether a file may be written back to uploads/ by the reverse sync.
	 *
	 * Anything that was under uploads/ when it was synced may; anything the
	 * web server or a browser could execute may not, regardless of how it
	 * got into the container: PHP in every spelling, other
	 * server-side scripts, shell and Windows executables, HTML, JavaScript,
	 * and Apache control files. A double extension such as shell.php.jpg is
	 * caught by looking at every extension in the name, not only the last.
	 *
	 * @param string $relative_path Path relative to the uploads directory.
	 * @return bool
	 */
	private static function is_restorable_file( string $relative_path ): bool {
		$name = strtolower( basename( str_replace( '\\', '/', $relative_path ) ) );

		if ( '' === $name || '.htaccess' === $name || '.htpasswd' === $name || '.user.ini' === $name ) {
			return false;
		}

		$blocked = array(
			'php',
			'php3',
			'php4',
			'php5',
			'php7',
			'php8',
			'phps',
			'phtml',
			'phar',
			'pht',
			'shtml',
			'shtm',
			'cgi',
			'pl',
			'py',
			'rb',
			'sh',
			'bash',
			'exe',
			'bat',
			'cmd',
			'js',
			'mjs',
			'html',
			'htm',
			'xhtml',
		);

		$parts = explode( '.', $name );
		array_shift( $parts ); // the base name is not an extension
		foreach ( $parts as $ext ) {
			if ( in_array( $ext, $blocked, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Process one batch of the reverse sync (cloud → local).
	 *
	 * This is "Disconnect from Cloud": the site's own attachments are copied
	 * back from the container to the uploads-directory paths WordPress has on
	 * record, resolved at runtime with wp_upload_dir(), so the Media Library
	 * keeps working without the plugin. Nothing here is plugin data and no
	 * other location is possible: any other folder would leave every URL in
	 * wp_posts pointing at a file that is not there. It runs only when the
	 * user starts it, restores only what sits under the uploads/ prefix of
	 * the site's own container, and never a script or executable file name —
	 * see is_restorable_file().
	 *
	 * Downloads run in parallel with a size comparison (no MD5, for speed) and
	 * use the same dynamic batch_size as the forward sync.
	 *
	 * @param float $time_limit Time limit in seconds (default 8s for responsive UI)
	 * @return array<string, mixed> Progress information
	 */
	public function process_reverse_batch( $time_limit = 8.0 ) {
		require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';

		$start_time            = microtime( true );
		$downloaded_this_batch = 0;
		$max_batch_size        = 12 * 1024 * 1024; // 12 MB (same as normal sync)

		$sync_meta = get_option( 'diluxone_offload_sync_meta', array() );

		if ( empty( $sync_meta ) || $sync_meta['status'] !== 'started' || ! ( $sync_meta['is_reverse_sync'] ?? false ) ) {
			return array(
				'status'  => 'error',
				'message' => 'No active reverse sync found',
			);
		}

		// Process files until time limit
		while ( true ) {
			// ⭐ NEW: Get deleted files only (synced=1, deleted=1)
			$files = DiluxOneOffloadDB::get_deleted_files( $this->batch_size * 2 );

			if ( empty( $files ) ) {
				// ⭐ FIX: Don't auto-complete here, let JavaScript handle it
				$stats = DiluxOneOffloadDB::get_stats();
				return array(
					'status'               => 'completed',
					'total_files'          => $stats['total_files'],
					'processed_files'      => $stats['synced_files'],
					'successful_downloads' => $stats['synced_files'],
					'failed_downloads'     => $stats['failed_files'],
					'percentage'           => 100,
					'pending_files'        => 0,      // ⭐ Legacy name (for SYNC compatibility)
					'remaining_files'      => 0,     // ⭐ Standard name (for DISCONNECT)
				);
			}

			// ⭐ OPTIMIZED: Process downloads in batches (similar to upload logic)
			$batch_to_download = array();

			foreach ( $files as $file ) {
				$relative_path = $file['file'];

				// The download target must stay inside uploads/: reject anything
				// that would resolve outside it before it ever reaches fopen().
				if ( strpos( str_replace( '\\', '/', $relative_path ), '..' ) !== false ) {
					Logger::error( '[DiluxOne Offload SyncManager] Rejected reverse-sync path outside uploads/: ' . $relative_path );
					DiluxOneOffloadDB::increment_error( $relative_path, 'Path traversal rejected' );
					continue;
				}

				// What comes back is whatever was under uploads/ when it was
				// synced. A blob the container holds under a script or
				// server-executable name is never written to disk, whatever
				// put it there.
				if ( ! self::is_restorable_file( $relative_path ) ) {
					Logger::error( '[DiluxOne Offload SyncManager] Reverse-sync refused a script or executable file name: ' . $relative_path );
					DiluxOneOffloadDB::increment_error( $relative_path, 'Executable or script file refused' );
					continue;
				}

				$local_path  = $this->upload_dir['basedir'] . $relative_path;
				$remote_path = DiluxOneOffloadDB::key_from_path( $relative_path );
				$size        = (int) $file['size'];

				// ⭐ Check mode: only skip if 'continue' mode AND file exists with same size
				$reverse_mode = $sync_meta['reverse_mode'] ?? 'continue';

				if ( $reverse_mode === 'continue' ) {
					// CONTINUE mode: Skip if file exists locally with same size
					if ( file_exists( $local_path ) && filesize( $local_path ) === $size ) {
						DiluxOneOffloadDB::mark_downloaded( $relative_path );
						continue;
					}
				}
				// SCRATCH mode: Always download (don't skip even if exists)

				// Add to download batch
				$batch_to_download[] = array(
					'path'        => $relative_path,
					'local_path'  => $local_path,
					'remote_path' => $remote_path,
					'size'        => $size,
				);

				// Don't exceed batch size
				if ( count( $batch_to_download ) >= $this->batch_size ) {
					break;
				}
			}

			// ⭐ Pre-increment error count BEFORE download (in case of timeout)
			foreach ( $batch_to_download as $file_info ) {
				DiluxOneOffloadDB::increment_error( $file_info['path'] );
			}

			// ⭐ Download batch in PARALLEL (same as upload)
			$results = $this->download_files_parallel( $batch_to_download );

			// Update DB based on results
			foreach ( $results as $i => $result ) {
				$file_info = $batch_to_download[ $i ];

				if ( $result['success'] ) {
					$mark_result = DiluxOneOffloadDB::mark_downloaded( $file_info['path'] ); // ⭐ Use mark_downloaded() not mark_synced()
					Logger::info( '[DiluxOne Offload SyncManager] ✅ Downloaded and marked: ' . $file_info['path'] . ' (mark_result=' . ( $mark_result ? 'true' : 'false' ) . ')' );
					++$downloaded_this_batch;
				} else {
					// Store error message in DB
					$error_msg = $result['error'] ?? 'Unknown error';
					DiluxOneOffloadDB::increment_error( $file_info['path'], $error_msg );
					Logger::info( '[DiluxOne Offload SyncManager] Download failed: ' . $file_info['path'] . ' - ' . $error_msg );
				}
			}

			// Check time limit
			$elapsed = microtime( true ) - $start_time;
			if ( $elapsed >= $time_limit ) {
				Logger::info( '[DiluxOne Offload SyncManager] Time limit reached (' . round( $elapsed, 2 ) . 's), ending batch' );
				break;
			}
		}

		// ⭐ Calculate progress including already downloaded files
		$total_in_cloud        = $sync_meta['total_files']; // Total files in cloud (synced=1)
		$already_downloaded    = $sync_meta['already_downloaded'] ?? 0; // Files already local when started
		$remaining_deleted     = DiluxOneOffloadDB::count_deleted_files(); // Files still pending
		$downloaded_in_session = ( $total_in_cloud - $already_downloaded ) - $remaining_deleted; // Downloaded in THIS session
		$total_downloaded      = $already_downloaded + $downloaded_in_session; // Total including previous
		$percentage            = $total_in_cloud > 0 ? round( ( $total_downloaded / $total_in_cloud ) * 100, 1 ) : 100;

		Logger::debug(
			'[DiluxOne Offload SyncManager] 🔍 Progress: total=' . $total_in_cloud .
				', already=' . $already_downloaded .
				', session=' . $downloaded_in_session .
				', total_downloaded=' . $total_downloaded .
				', pending=' . $remaining_deleted .
				', percentage=' . $percentage . '%'
		);

		return array(
			'status'                => 'processing',
			'total_files'           => $total_in_cloud,
			'processed_files'       => $total_downloaded, // ⭐ Total including already downloaded
			'successful_downloads'  => $total_downloaded,
			'failed_downloads'      => 0, // TODO: track failed downloads separately
			'percentage'            => $percentage,
			'downloaded_this_batch' => $downloaded_this_batch,
			'pending_files'         => $remaining_deleted,    // ⭐ Legacy name (for SYNC compatibility)
			'remaining_files'       => $remaining_deleted,   // ⭐ Standard name (for DISCONNECT)
		);
	}
}
