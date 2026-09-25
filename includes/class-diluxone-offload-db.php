<?php
/**
 * DiluxOne Offload Database Manager
 *
 * Manages a custom database table for file tracking. Every query targets the
 * plugin's own table whose name is `$wpdb->prefix . 'diluxone_offload_files'` — never
 * derived from user input. WordPress's $wpdb->prepare() does not support
 * identifier placeholders on all currently supported WP versions, so the table
 * name is interpolated directly. Cache layers don't apply because the data is
 * a per-request progress queue; stale reads would defeat the purpose. These
 * rules are intentionally suppressed file-wide:
 *
 * phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
 * phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom-table data-access layer for sync state.
 *
 * Owns the wp_diluxone_offload_files table — a per-file record of sync status
 * (synced, deleted, errors, error_message, upload_id, timestamps).
 * Created on plugin init via DiluxOneOffloadDB::create_files_table() and migrated
 * via TABLE_VERSION when the schema changes. All queries route through
 * $wpdb->prepare() inside the methods of this class.
 */
class DiluxOneOffloadDB {

	const TABLE_VERSION        = '1.4';
	const TABLE_VERSION_OPTION = 'diluxone_offload_db_version';

	/** Longest path the tracking table holds, in bytes: the width of its key column. */
	const FILE_MAX_BYTES = 767;

	/**
	 * Get table name
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'diluxone_offload_files';
	}

	/**
	 * The object key of a tracking-row path.
	 *
	 * Rows are keyed by the path below this site's uploads directory with a
	 * leading slash; objects are keyed by the same path under the site's
	 * prefix (see CloudStreamWrapper::key_prefix()).
	 *
	 * @param string $path Tracking-row path, e.g. `/2026/09/photo.jpg`.
	 * @return string Object key, e.g. `uploads/2026/09/photo.jpg` or `uploads/sites/2/2026/09/photo.jpg`.
	 */
	public static function key_from_path( string $path ): string {
		return CloudStreamWrapper::key_prefix() . '/' . ltrim( $path, '/' );
	}

	/**
	 * The tracking-row path of an object key: the inverse of key_from_path().
	 *
	 * Only this site's leading prefix is removed: a folder that happens to
	 * be named `uploads` deeper in the tree is part of the path and stays.
	 *
	 * @param string $key Object key, e.g. `uploads/2026/09/photo.jpg`.
	 * @return string Tracking-row path, e.g. `/2026/09/photo.jpg`.
	 */
	public static function path_from_key( string $key ): string {
		$prefix = CloudStreamWrapper::key_prefix() . '/';
		if ( 0 === strpos( $key, $prefix ) ) {
			return '/' . substr( $key, strlen( $prefix ) );
		}

		return '/' . ltrim( $key, '/' );
	}

	/**
	 * The listing prefix that returns only this site's objects.
	 *
	 * @return string e.g. `uploads/` or `uploads/sites/2/`.
	 */
	public static function listing_prefix(): string {
		return CloudStreamWrapper::key_prefix() . '/';
	}

	/**
	 * Create or migrate the file-tracking table.
	 *
	 * Called on activation, on a new site added to a network, and whenever the
	 * stored schema version is behind TABLE_VERSION.
	 *
	 * @return bool True when the table is in place afterwards. On false the
	 *              schema-version option is deliberately left unwritten, so the
	 *              next request tries again instead of assuming the schema is
	 *              current and failing every query against a missing table.
	 */
	public static function create_files_table(): bool {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// A table left over from an earlier build has to get its new key
		// column first. If that fails the version option is not written
		// either, so the next request tries again instead of recording a
		// schema that is not there.
		if ( ! self::migrate_legacy_key( $table_name ) ) {
			return false;
		}

		// `file` is the primary key and holds a filesystem path, so it is
		// bytes: case-sensitive like the filesystem (FOTO.PNG and foto.png
		// are two files on Linux) and long enough for a 255-byte name under
		// any uploads subdirectory. 767 bytes is the largest index InnoDB
		// accepts on every MySQL WordPress supports, and the key covers the
		// whole column, not a prefix of it.
		$sql = "CREATE TABLE {$table_name} (
            `file` VARBINARY(" . self::FILE_MAX_BYTES . ") NOT NULL COMMENT 'Relative file path',
            `size` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'File size in bytes',
            `transferred` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Bytes transferred so far',
            `synced` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = upload completed',
            `deleted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = file deleted locally but exists in cloud',
            `errors` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Error counter for retries',
            `error_message` TEXT NULL DEFAULT NULL COMMENT 'Last error message',
            `upload_id` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Azure Block Blob upload ID for resuming',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`file`),
            KEY `synced` (`synced`),
            KEY `deleted` (`deleted`),
            KEY `errors` (`errors`),
            KEY `synced_errors` (`synced`, `errors`),
            KEY `synced_deleted` (`synced`, `deleted`)
        ) $charset_collate COMMENT='DiluxOne Offload file tracking';";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// dbDelta reports nothing useful about failure, so ask the database
		// whether the table is actually there before recording the version.
		// Recording it on a failed run would mean never trying again.
		if ( ! self::table_exists() ) {
			Logger::error(
				'[DiluxOne Offload DB] Could not create ' . $table_name
				. ( '' !== $wpdb->last_error ? ': ' . $wpdb->last_error : '.' )
			);
			return false;
		}

		update_option( self::TABLE_VERSION_OPTION, self::TABLE_VERSION, false );

		Logger::info( '[DiluxOne Offload DB] Table created/updated: ' . $table_name );
		return true;
	}

	/**
	 * Move a table created by an earlier build onto the current key column.
	 *
	 * Up to 1.2 the table declared PRIMARY KEY (`file`(191)) over a
	 * VARCHAR(500) column, so two paths sharing their first 191 characters
	 * collided; 1.3 keyed a VARCHAR(191), which truncated or refused any
	 * longer path and, being a collation, treated FOTO.PNG and foto.png as
	 * one file. dbDelta never rewrites a primary key, so the change is made
	 * here: rows too long for the new column are dropped, which only costs
	 * them a rescan, and the key is rebuilt over the whole VARBINARY column.
	 *
	 * @param string $table_name Fully prefixed table name.
	 * @return bool True when the table is absent, already migrated, or was
	 *              migrated now; false when a statement failed.
	 */
	private static function migrate_legacy_key( string $table_name ): bool {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return true;
		}

		$column = $wpdb->get_row( "SHOW COLUMNS FROM {$table_name} LIKE 'file'" );
		if ( ! $column || ! isset( $column->Type ) ) {
			return true;
		}

		if ( 'varbinary(' . self::FILE_MAX_BYTES . ')' === strtolower( (string) $column->Type ) ) {
			return true;
		}

		// Rows that do not fit the new column would make the ALTER fail, so
		// they go first; losing them only costs those files a rescan.
		if ( false === $wpdb->query( $wpdb->prepare( "DELETE FROM {$table_name} WHERE LENGTH(`file`) > %d", self::FILE_MAX_BYTES ) ) ) {
			Logger::error( '[DiluxOne Offload DB] Could not prepare ' . $table_name . ' for its new primary key: ' . $wpdb->last_error );
			return false;
		}

		// dbDelta cannot rewrite a primary key, so this one schema change is
		// made by hand. It runs once, only on a table from an earlier build.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		if ( false === $wpdb->query( "ALTER TABLE {$table_name} DROP PRIMARY KEY, MODIFY `file` VARBINARY(" . self::FILE_MAX_BYTES . ") NOT NULL COMMENT 'Relative file path', ADD PRIMARY KEY (`file`)" ) ) {
			Logger::error( '[DiluxOne Offload DB] Could not rebuild the primary key of ' . $table_name . ': ' . $wpdb->last_error );
			return false;
		}

		return true;
	}

	/**
	 * Check if table exists
	 */
	public static function table_exists(): bool {
		global $wpdb;
		$table_name = self::get_table_name();

		$query = $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) );
		// Case-insensitive: with lower_case_table_names=1 the server reports
		// the name in lowercase whatever the prefix's spelling.
		return 0 === strcasecmp( (string) $wpdb->get_var( $query ), $table_name );
	}

	/**
	 * Add file to tracking table
	 * Uses REPLACE to handle duplicates
	 *
	 * @param string $file_path
	 * @param int    $size
	 */
	public static function add_file( $file_path, $size ): bool {
		global $wpdb;

		if ( ! self::fits_key( $file_path ) ) {
			return false;
		}

		$result = $wpdb->replace(
			self::get_table_name(),
			array(
				'file'        => $file_path,
				'size'        => $size,
				'synced'      => 0,
				'deleted'     => 0,
				'transferred' => 0,
				'errors'      => 0,
				'upload_id'   => null,
			),
			array( '%s', '%d', '%d', '%d', '%d', '%d', '%s' )
		);

		if ( $result === false ) {
			Logger::error( '[DiluxOne Offload DB] Error adding file: ' . $wpdb->last_error );
		}

		return $result !== false;
	}

	/**
	 * Whether a path fits the key column. A longer one is refused here with a
	 * warning rather than handed to the database, which would truncate it in
	 * MariaDB's default mode and refuse it in MySQL's strict mode, both silently
	 * as far as the caller can tell.
	 *
	 * @param mixed $file_path Path relative to the uploads directory.
	 */
	public static function fits_key( $file_path ): bool {
		if ( strlen( (string) $file_path ) <= self::FILE_MAX_BYTES ) {
			return true;
		}
		Logger::warning( '[DiluxOne Offload DB] Path longer than ' . self::FILE_MAX_BYTES . ' bytes is not tracked: ' . substr( (string) $file_path, 0, 80 ) . '…' );
		return false;
	}

	/**
	 * Add multiple files in batch (more efficient)
	 *
	 * @param mixed $files
	 */
	public static function add_files_batch( $files ): bool {
		global $wpdb;

		if ( empty( $files ) ) {
			return true;
		}

		$table_name   = self::get_table_name();
		$values       = array();
		$placeholders = array();

		foreach ( $files as $file ) {
			if ( ! self::fits_key( $file['path'] ) ) {
				continue;
			}
			$values[]       = $file['path'];
			$values[]       = $file['size'];
			$placeholders[] = '(%s, %d, 0, 0, 0, 0, NULL)';
		}

		if ( empty( $placeholders ) ) {
			return true;
		}

		$query = "INSERT INTO {$table_name}
                  (file, size, synced, deleted, transferred, errors, upload_id)
                  VALUES " . implode( ', ', $placeholders ) . '
                  ON DUPLICATE KEY UPDATE
                      size = VALUES(size),
                      synced = 0,
                      deleted = 0,
                      transferred = 0,
                      errors = 0,
                      upload_id = NULL';

		$result = $wpdb->query( $wpdb->prepare( $query, $values ) );

		if ( $result === false ) {
			Logger::error( '[DiluxOne Offload DB] Error adding files batch: ' . $wpdb->last_error );
			return false;
		}

		Logger::info( '[DiluxOne Offload DB] Added ' . count( $files ) . ' files to tracking table' );
		return true;
	}

	/**
	 * Mark file as successfully synced (uploaded to cloud)
	 *
	 * @param mixed $file_path
	 * @return int|false
	 */
	public static function mark_synced( $file_path ) {
		global $wpdb;

		$result = $wpdb->update(
			self::get_table_name(),
			array(
				'synced'      => 1,
				'transferred' => $wpdb->get_var(
					$wpdb->prepare(
						'SELECT size FROM ' . self::get_table_name() . ' WHERE file = %s',
						$file_path
					)
				),
				'errors'      => 0,
				'upload_id'   => null,
			),
			array( 'file' => $file_path ),
			array( '%d', '%d', '%d', '%s' ),
			array( '%s' )
		);

		// Debug logging when marking fails
		if ( $result === false || $result === 0 ) {
			Logger::error( '[DiluxOne Offload DB] ⚠️ mark_synced FAILED for: ' . $file_path . ' (result=' . var_export( $result, true ) . ', wpdb->last_error=' . $wpdb->last_error . ')' );
		}

		return $result;
	}

	/**
	 * Mark file as successfully downloaded (reverse sync)
	 * Sets deleted=0 since file now exists locally
	 *
	 * @param mixed $file_path
	 * @return int|false
	 */
	public static function mark_downloaded( $file_path ) {
		global $wpdb;

		return $wpdb->update(
			self::get_table_name(),
			array(
				'synced'      => 1,
				'deleted'     => 0, // ⭐ File is no longer deleted (now exists locally)
				'transferred' => $wpdb->get_var(
					$wpdb->prepare(
						'SELECT size FROM ' . self::get_table_name() . ' WHERE file = %s',
						$file_path
					)
				),
				'errors'      => 0,
				'upload_id'   => null,
			),
			array( 'file' => $file_path ),
			array( '%d', '%d', '%d', '%d', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Update transferred bytes for a file
	 *
	 * @param mixed $file_path
	 * @param mixed $bytes_transferred
	 * @return int|false
	 */
	public static function update_progress( $file_path, $bytes_transferred ) {
		global $wpdb;

		return $wpdb->query(
			$wpdb->prepare(
				'
            UPDATE ' . self::get_table_name() . '
            SET transferred = transferred + %d,
                errors = 0
            WHERE file = %s
        ',
				$bytes_transferred,
				$file_path
			)
		);
	}

	/**
	 * Increment error count for a file
	 *
	 * @param string      $file_path Relative file path
	 * @param string|null $error_message Optional error message to store
	 * @return int|false
	 */
	public static function increment_error( $file_path, $error_message = null ) {
		global $wpdb;

		if ( $error_message !== null ) {
			return $wpdb->query(
				$wpdb->prepare(
					'
                UPDATE ' . self::get_table_name() . '
                SET errors = errors + 1,
                    error_message = %s
                WHERE file = %s
            ',
					$error_message,
					$file_path
				)
			);
		} else {
			return $wpdb->query(
				$wpdb->prepare(
					'
                UPDATE ' . self::get_table_name() . '
                SET errors = errors + 1
                WHERE file = %s
            ',
					$file_path
				)
			);
		}
	}

	/**
	 * Set upload_id for multipart upload tracking
	 *
	 * @param string $file_path
	 * @param string $upload_id
	 * @return int|false
	 */
	public static function set_upload_id( $file_path, $upload_id ) {
		global $wpdb;

		return $wpdb->update(
			self::get_table_name(),
			array( 'upload_id' => $upload_id ),
			array( 'file' => $file_path ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Get pending files for upload
	 *
	 * @param int      $limit Max number of files
	 * @param int|null $max_bytes Max total bytes (for batching)
	 * @return array<int, array<string, mixed>> Files to upload
	 */
	public static function get_pending_files( $limit = 1000, $max_bytes = null ) {
		global $wpdb;

		$files = $wpdb->get_results(
			$wpdb->prepare(
				'
            SELECT file, size, transferred, errors, upload_id
            FROM ' . self::get_table_name() . '
            WHERE synced = 0 AND errors < 3
            ORDER BY errors ASC, size DESC, file ASC
            LIMIT %d
        ',
				$limit
			),
			ARRAY_A
		);

		// If max_bytes specified, filter by cumulative size
		if ( $max_bytes && ! empty( $files ) ) {
			$batch      = array();
			$total_size = 0;

			foreach ( $files as $file ) {
				// Always include at least 1 file even if it exceeds max_bytes
				if ( count( $batch ) > 0 && ( $total_size + $file['size'] ) > $max_bytes ) {
					break;
				}

				$batch[]     = $file;
				$total_size += $file['size'];
			}

			return $batch;
		}

		return $files;
	}

	/**
	 * Get sync statistics
	 *
	 * @return array<string, mixed>
	 */
	public static function get_stats() {
		global $wpdb;

		$stats = $wpdb->get_row(
			'
            SELECT
                COUNT(*) as total_files,
                COALESCE(SUM(size), 0) as total_size,
                SUM(CASE WHEN synced = 1 THEN 1 ELSE 0 END) as synced_files,
                COALESCE(SUM(CASE WHEN synced = 1 THEN size ELSE 0 END), 0) as synced_size,
                SUM(CASE WHEN synced = 0 AND deleted = 0 AND errors >= 3 THEN 1 ELSE 0 END) as failed_files,
                COALESCE(SUM(transferred), 0) as total_transferred,
                SUM(CASE WHEN synced = 0 AND deleted = 0 THEN 1 ELSE 0 END) as pending_files
            FROM ' . self::get_table_name() . '
        ',
			ARRAY_A
		);

		// A site whose table is not there yet — a blog added to a network
		// between activation and its first sync — gets zeroes rather than a
		// warning on a null offset.
		if ( ! is_array( $stats ) ) {
			$stats = array();
		}

		$stats = array_merge(
			array(
				'total_files'       => 0,
				'total_size'        => 0,
				'synced_files'      => 0,
				'synced_size'       => 0,
				'failed_files'      => 0,
				'total_transferred' => 0,
				'pending_files'     => 0,
			),
			array_filter( $stats, static fn( $value ) => null !== $value )
		);

		// Calculate percentage
		if ( $stats['total_files'] > 0 ) {
			$stats['percentage'] = round( ( $stats['synced_files'] / $stats['total_files'] ) * 100, 1 );
		} else {
			$stats['percentage'] = 0;
		}

		return $stats;
	}

	/**
	 * Get failed files (ALL files not synced, regardless of error count)
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_failed_files() {
		global $wpdb;

		return $wpdb->get_results(
			'
            SELECT file, size, errors, error_message
            FROM ' . self::get_table_name() . '
            WHERE synced = 0 AND deleted = 0
            ORDER BY errors DESC, file ASC
        ',
			ARRAY_A
		);
	}

	/**
	 * Get all synced files (for deletion)
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_synced_files() {
		global $wpdb;

		return $wpdb->get_results(
			'
            SELECT file, size
            FROM ' . self::get_table_name() . '
            WHERE synced = 1
            ORDER BY file ASC
        ',
			ARRAY_A
		);
	}

	/**
	 * Reset error counts (for retry)
	 *
	 * @return int|false
	 */
	public static function reset_errors() {
		global $wpdb;

		$count = $wpdb->query(
			'
            UPDATE ' . self::get_table_name() . '
            SET errors = 0, transferred = 0, error_message = NULL
            WHERE synced = 0 AND errors >= 3
        '
		);

		Logger::info( '[DiluxOne Offload DB] Reset errors for ' . $count . ' files' );
		return $count;
	}

	/**
	 * Clear entire table (before new sync)
	 */
	public static function clear_table(): bool {
		global $wpdb;

		$result = $wpdb->query( 'TRUNCATE TABLE ' . self::get_table_name() );

		if ( $result !== false ) {
			Logger::info( '[DiluxOne Offload DB] Table cleared' );
		}

		return $result !== false;
	}

	/**
	 * Reset all files to pending (for "from scratch" sync)
	 */
	public static function reset_all_files_to_pending(): bool {
		global $wpdb;

		$result = $wpdb->query(
			'
            UPDATE ' . self::get_table_name() . '
            SET synced = 0,
                transferred = 0,
                error_message = NULL,
                errors = 0
        '
		);

		if ( $result !== false ) {
			Logger::info( '[DiluxOne Offload DB] Reset ' . $result . ' files to pending' );
		}

		return $result !== false;
	}

	/**
	 * Reset only failed files to pending (for "retry failed" sync)
	 */
	public static function reset_failed_files_to_pending(): bool {
		global $wpdb;

		// Reset ALL failed files (synced=0) back to pending for retry
		// Reset error counter to 0 to give them fresh attempts
		$result = $wpdb->query(
			'
            UPDATE ' . self::get_table_name() . '
            SET transferred = 0,
                error_message = NULL,
                errors = 0
            WHERE synced = 0 AND deleted = 0
        '
		);

		if ( $result !== false ) {
			Logger::info( '[DiluxOne Offload DB] Reset ' . $result . ' failed files to pending for retry (errors → 0)' );
		}

		return $result !== false;
	}

	/**
	 * Delete synced files from table (cleanup after successful sync)
	 *
	 * @return int|false
	 */
	public static function delete_synced_files() {
		global $wpdb;

		$count = $wpdb->query(
			'
            DELETE FROM ' . self::get_table_name() . '
            WHERE synced = 1
        '
		);

		Logger::info( '[DiluxOne Offload DB] Deleted ' . $count . ' synced files from tracking' );
		return $count;
	}

	/**
	 * Get total count of files
	 */
	public static function get_total_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::get_table_name() );
	}

	/**
	 * Check if sync has any pending files
	 *
	 * @return bool
	 */
	public static function has_pending_files() {
		global $wpdb;

		$count = $wpdb->get_var(
			'
            SELECT COUNT(*)
            FROM ' . self::get_table_name() . '
            WHERE synced = 0 AND errors < 3
        '
		);

		return $count > 0;
	}

	/**
	 * Add cloud-only file (exists in cloud but not locally = deleted)
	 *
	 * @param string $file_path Relative file path
	 * @param int    $size File size in bytes
	 */
	public static function add_cloud_only_file( $file_path, $size ): bool {
		global $wpdb;

		if ( ! self::fits_key( $file_path ) ) {
			return false;
		}

		$result = $wpdb->replace(
			self::get_table_name(),
			array(
				'file'        => $file_path,
				'size'        => $size,
				'synced'      => 1,
				'deleted'     => 1,
				'transferred' => $size,
				'errors'      => 0,
				'upload_id'   => null,
			),
			array( '%s', '%d', '%d', '%d', '%d', '%d', '%s' )
		);

		if ( $result === false ) {
			Logger::error( '[DiluxOne Offload DB] Error adding cloud-only file: ' . $wpdb->last_error );
		}

		return $result !== false;
	}

	/**
	 * Add multiple cloud-only files in batch
	 *
	 * @param array<int, array<string, mixed>> $files Array of ['path' => string, 'size' => int]
	 */
	public static function add_cloud_only_files_batch( $files ): bool {
		global $wpdb;

		if ( empty( $files ) ) {
			return true;
		}

		$table_name   = self::get_table_name();
		$values       = array();
		$placeholders = array();

		foreach ( $files as $file ) {
			// A cloud key is whatever was put in the container; one the table
			// cannot hold is left out rather than allowed to abort the batch.
			if ( ! self::fits_key( $file['path'] ) ) {
				continue;
			}
			$values[]       = $file['path'];
			$values[]       = $file['size'];
			$values[]       = $file['size']; // transferred = size
			$placeholders[] = '(%s, %d, 1, 1, %d, 0, NULL)';
		}

		if ( empty( $placeholders ) ) {
			return true;
		}

		$query = "INSERT INTO {$table_name}
                  (file, size, synced, deleted, transferred, errors, upload_id)
                  VALUES " . implode( ', ', $placeholders ) . '
                  ON DUPLICATE KEY UPDATE
                      size = VALUES(size),
                      synced = 1,
                      deleted = 1,
                      transferred = VALUES(transferred),
                      errors = 0';

		$result = $wpdb->query( $wpdb->prepare( $query, $values ) );

		if ( $result === false ) {
			Logger::error( '[DiluxOne Offload DB] Error adding cloud-only files batch: ' . $wpdb->last_error );
			return false;
		}

		Logger::info( '[DiluxOne Offload DB] Added ' . count( $files ) . ' cloud-only files (deleted locally)' );
		return true;
	}

	/**
	 * Get deleted files (synced to cloud but deleted locally)
	 * These are files that need to be downloaded during disconnect
	 *
	 * @param int $limit Max number of files
	 * @return array<string, mixed> Files to download
	 */
	public static function get_deleted_files( $limit = 1000 ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'
            SELECT file, size, errors
            FROM ' . self::get_table_name() . '
            WHERE synced = 1 AND deleted = 1 AND errors < 3
            ORDER BY errors ASC, file ASC
            LIMIT %d
        ',
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Get stats for deleted files
	 *
	 * @return array<string, mixed>
	 */
	public static function get_deleted_stats() {
		global $wpdb;

		return $wpdb->get_row(
			'
            SELECT
                COUNT(*) as files,
                COALESCE(SUM(size), 0) as size
            FROM ' . self::get_table_name() . '
            WHERE synced = 1 AND deleted = 1
        ',
			ARRAY_A
		);
	}

	/**
	 * Check if there are deleted files to download
	 *
	 * @return bool
	 */
	public static function has_deleted_files() {
		global $wpdb;

		$count = $wpdb->get_var(
			'
            SELECT COUNT(*)
            FROM ' . self::get_table_name() . '
            WHERE synced = 1 AND deleted = 1 AND errors < 3
        '
		);

		return $count > 0;
	}

	/**
	 * Count deleted files (for progress calculation)
	 */
	public static function count_deleted_files(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			'
            SELECT COUNT(*)
            FROM ' . self::get_table_name() . '
            WHERE synced = 1 AND deleted = 1 AND errors < 3
        '
		);
	}
}
