<?php
/**
 * DiluxOne Offload custom Image Editor for GD
 *
 * Extends WP_Image_Editor_GD to handle diluxoneoffload:// stream wrapper paths.
 * Uses temp files to avoid stream wrapper limitations with GD library. Temp
 * file cleanup uses native unlink() because the temp paths live outside
 * /wp-content/uploads/. The image_make_intermediate_size filter is a WordPress
 * core hook (we don't define it). These rules are intentionally suppressed
 * file-wide:
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
 * phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
 *
 * Why this is needed:
 * - GD can't handle diluxoneoffload:// paths directly for saving images
 * - WordPress needs local files to generate thumbnails
 * - Solution: Save to temp file, copy to diluxoneoffload://, clean up temp
 *
 * @package DiluxOneOffload
 * @since 1.0.0
 */

namespace DiluxOneOffload;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GD image editor that handles diluxoneoffload:// paths via temp files.
 *
 * Fallback used when Imagick is unavailable on the host. Extends the
 * WordPress core GD editor so standard image processing works against
 * cloud-hosted media.
 */
class DiluxOneOffload_Image_Editor_GD extends \WP_Image_Editor_GD {

	/**
	 * Remote filename (diluxoneoffload:// path)
	 *
	 * @var string
	 */

	protected $remote_filename = null;

	/**
	 * Temporary files to cleanup on destruct
	 *
	 * @var array<int, string>
	 */
	protected $temp_files_to_cleanup = array();

	/**
	 * Load image into GD resource
	 *
	 * If file is in diluxoneoffload://, download to temp first, then load.
	 *
	 * @return true|\WP_Error True if loaded; \WP_Error on failure.
	 */
	public function load() {
		if ( is_resource( $this->image ) ) {
			return true;
		}

		if ( ! is_file( $this->file ) && ! preg_match( '|^https?://|', $this->file ) ) {
			return new \WP_Error( 'error_loading_image', __( 'File doesn&#8217;t exist?', 'diluxone-offload' ), $this->file );
		}

		$upload_dir = wp_upload_dir();

		// If file is NOT in our stream wrapper, use parent load
		if ( strpos( $this->file, $upload_dir['basedir'] ) !== 0 ) {
			return parent::load();
		}

		// ⭐ File is diluxoneoffload:// - download to temp for GD processing
		$temp_filename = tempnam( get_temp_dir(), 'diluxone-offload' );
		if ( $temp_filename === false ) {
			return new \WP_Error( 'error_loading_image', __( 'Could not create temp file for cloud download.', 'diluxone-offload' ) );
		}
		$this->temp_files_to_cleanup[] = $temp_filename;

		// Copy from diluxoneoffload:// to local temp
		copy( $this->file, $temp_filename );

		// Store remote path and switch to temp
		$this->remote_filename = $this->file;
		$this->file            = $temp_filename;

		// Load from temp file
		$result = parent::load();

		// Restore remote path
		$this->file = $this->remote_filename;

		return $result;
	}

	/**
	 * Save image to diluxoneoffload:// path
	 *
	 * GD can't save directly to diluxoneoffload://, so:
	 * 1. Save to temp file
	 * 2. Copy temp to diluxoneoffload:// (triggers stream wrapper upload to Azure)
	 * 3. Delete temp
	 *
	 * @param resource|object $image GD image (resource on PHP 7, \GdImage on PHP 8+)
	 * @param string          $filename Output filename
	 * @param string          $mime_type Output mime type
	 * @return array<string, mixed>|\WP_Error Saved file info or error
	 */
	protected function _save( $image, $filename = null, $mime_type = null ) {
		list($filename, $extension, $mime_type) = $this->get_output_format( $filename, $mime_type );

		if ( ! $filename ) {
			$filename = $this->generate_filename( null, null, $extension );
		}

		$upload_dir = wp_upload_dir();

		// Only use temp file if saving to our stream wrapper
		if ( strpos( $filename, $upload_dir['basedir'] ) === 0 ) {
			$temp_filename = tempnam( get_temp_dir(), 'diluxone-offload' );
		} else {
			// Not our stream wrapper, use parent directly.
			// PHPStan can't resolve \GdImage because the GD extension may not
			// be loaded in CI; the parent declares GdImage|resource which our
			// $image satisfies at runtime.
			// @phpstan-ignore-next-line argument.type
			return parent::_save( $image, $filename, $mime_type );
		}

		// Save to temp file first
		// @phpstan-ignore-next-line argument.type
		$save = parent::_save( $image, $temp_filename, $mime_type );

		if ( is_wp_error( $save ) ) {
			wp_delete_file( $temp_filename );
			return $save;
		}

		// Copy temp to diluxoneoffload:// (triggers upload to Azure)
		$copy_result = copy( $save['path'], $filename );

		// Clean up temp files
		wp_delete_file( $save['path'] );
		wp_delete_file( $temp_filename );

		if ( ! $copy_result ) {
			return new \WP_Error(
				'unable-to-copy-to-cloud',
				__( 'Unable to copy the temp image to the cloud', 'diluxone-offload' )
			);
		}

		return array(
			'path'      => $filename,
			'file'      => wp_basename( apply_filters( 'image_make_intermediate_size', $filename ) ),
			'width'     => $this->size['width'],
			'height'    => $this->size['height'],
			'mime-type' => $mime_type,
		);
	}

	/**
	 * Cleanup temp files on destruct
	 */
	public function __destruct() {
		// Clean up all temp files
		foreach ( $this->temp_files_to_cleanup as $temp_file ) {
			if ( file_exists( $temp_file ) ) {
				wp_delete_file( $temp_file );
			}
		}

		parent::__destruct();
	}
}
