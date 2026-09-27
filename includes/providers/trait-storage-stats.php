<?php
/**
 * Usage stats every provider computes the same way from its own listing.
 *
 * @package DiluxOneOffload\Providers
 * @since 2.0.0
 */

namespace DiluxOneOffload\Providers;

use DiluxOneOffload\CloudStreamWrapper;
use DiluxOneOffload\ConfigManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The get_storage_stats() of every provider: the using class supplies
 * list_files(), get_provider_name() and extract_error_code().
 */
trait StorageStats {

	/**
	 * Usage stats of the current site's files.
	 *
	 * Lists this site's objects and counts them, their bytes and their kinds.
	 * Cached for five minutes in the provider's transient; a failed listing
	 * clears it and records a connection failure.
	 *
	 * @param bool $force_refresh Skip the cache and list the storage again.
	 * @return array{success: bool, data?: array<string, mixed>, message?: string}
	 */
	public function get_storage_stats( bool $force_refresh = false ): array {
		$transient = ConfigManager::STATS_TRANSIENTS[ $this->get_provider_name() ] ?? '';

		if ( ! $force_refresh && '' !== $transient ) {
			$cached = get_transient( $transient );
			if ( $cached !== false ) {
				return array(
					'success' => true,
					'data'    => $cached,
				);
			}
		}

		try {
			$files         = CloudStreamWrapper::site_files( $this->list_files( CloudStreamWrapper::key_prefix() . '/' ) );
			$total_size    = 0;
			$files_by_type = array(
				'images' => 0,
				'videos' => 0,
				'audio'  => 0,
				'other'  => 0,
			);

			$image_exts = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico', 'tiff', 'tif', 'avif' );
			$video_exts = array( 'mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm', 'ogv', 'm4v' );
			$audio_exts = array( 'mp3', 'wav', 'ogg', 'flac', 'aac', 'wma', 'm4a', 'opus' );

			foreach ( $files as $file ) {
				$total_size += (int) ( $file['size'] ?? 0 );
				$ext         = strtolower( pathinfo( $file['path'] ?? '', PATHINFO_EXTENSION ) );
				if ( in_array( $ext, $image_exts, true ) ) {
					++$files_by_type['images'];
				} elseif ( in_array( $ext, $video_exts, true ) ) {
					++$files_by_type['videos'];
				} elseif ( in_array( $ext, $audio_exts, true ) ) {
					++$files_by_type['audio'];
				} else {
					++$files_by_type['other'];
				}
			}

			$data = array(
				'fileCount'          => count( $files ),
				'storageUsedBytes'   => $total_size,
				'storageLimitBytes'  => null,
				'plan'               => null,
				'bandwidthUsedBytes' => null,
				'storageCheckedAt'   => gmdate( 'c' ),
				'quotaExceeded'      => false,
				'filesByType'        => $files_by_type,
			);

			if ( '' !== $transient ) {
				set_transient( $transient, $data, 300 );
			}
			return array(
				'success' => true,
				'data'    => $data,
			);

		} catch ( \Exception $e ) {
			if ( '' !== $transient ) {
				delete_transient( $transient );
			}
			ConfigManager::record_connection_failure(
				$this->extract_error_code( $e->getMessage() ),
				$e->getMessage(),
				'stats_refresh'
			);
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
	}
}
