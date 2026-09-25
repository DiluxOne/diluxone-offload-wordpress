<?php
/**
 * DiluxOne Offload custom Image Editor for Imagick
 *
 * Extends WP_Image_Editor_Imagick to handle diluxoneoffload:// stream wrapper paths.
 * Uses temp files to avoid stream wrapper
 * limitations. Temp file cleanup uses native unlink() because the temp paths
 * live outside /wp-content/uploads/. The image_make_intermediate_size filter
 * is a WordPress core hook (we don't define it). These rules are intentionally
 * suppressed file-wide:
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
 * phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
 *
 * Why this is needed:
 * - Imagick can't handle diluxoneoffload:// paths directly for saving images
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
 * Imagick image editor that handles diluxoneoffload:// paths via temp files.
 *
 * Extends the WordPress core Imagick editor so the standard image-resize
 * pipeline keeps working when offloading is active. WordPress core will
 * try Imagick first then fall back to GD; both are wired through this
 * pair (DiluxOneOffload_Image_Editor_Imagick + DiluxOneOffload_Image_Editor_GD).
 */
class DiluxOneOffload_Image_Editor_Imagick extends \WP_Image_Editor_Imagick {

	/**
	 * Remote filename (diluxoneoffload:// path)
	 *
	 * @var string
	 */

	protected $remote_filename = null;

	/**
	 * Temporary files to cleanup on destruct
	 *
	 * @var array<string, mixed>
	 */
	protected $temp_files_to_cleanup = array();

	/**
	 * Load image into Imagick object
	 *
	 * If file is in diluxoneoffload://, download to temp first, then load.
	 *
	 * @return true|\WP_Error True if loaded; \WP_Error on failure.
	 */
	public function load() {
		// Idempotency guard: parent::load() sets \$this->image to an \Imagick
		// instance. Skip re-loading if already done. The PHPStan WP stub
		// types \$image as `\Imagick` (always), so it considers the
		// instanceof always true and the rest of the body unreachable —
		// silence both at once with the union ignore.
		/** @phpstan-ignore-next-line booleanNot.alwaysFalse */
		if ( ! empty( $this->image ) && $this->image instanceof \Imagick ) {
			return true;
		}

		// @phpstan-ignore-next-line deadCode.unreachable
		Logger::info( '[DiluxOne Offload Image Editor] load() called for: ' . $this->file );

		if ( ! is_file( $this->file ) && ! preg_match( '|^https?://|', $this->file ) ) {
			Logger::error( '[DiluxOne Offload Image Editor] File does not exist: ' . $this->file );
			return new \WP_Error( 'error_loading_image', __( 'File doesn&#8217;t exist?', 'diluxone-offload' ), $this->file );
		}

		$upload_dir = wp_upload_dir();

		// If file is NOT in our stream wrapper, use parent load
		if ( strpos( $this->file, $upload_dir['basedir'] ) !== 0 ) {
			Logger::info( '[DiluxOne Offload Image Editor] Not our stream wrapper, using parent load' );
			return parent::load();
		}

		// ⭐ File is diluxoneoffload:// - download to temp for Imagick processing
		Logger::info( '[DiluxOne Offload Image Editor] File is in diluxoneoffload://, creating temp' );
		$temp_filename                 = tempnam( get_temp_dir(), 'diluxone-offload' );
		$this->temp_files_to_cleanup[] = $temp_filename;

		// Copy from diluxoneoffload:// to local temp
		Logger::info( '[DiluxOne Offload Image Editor] Copying from ' . $this->file . ' to ' . $temp_filename );
		$copy_result = copy( $this->file, $temp_filename );

		if ( ! $copy_result ) {
			Logger::error( '[DiluxOne Offload Image Editor] FAILED to copy file!' );
			wp_delete_file( $temp_filename );
			return new \WP_Error( 'unable-to-copy-from-cloud', __( 'Unable to copy file from cloud', 'diluxone-offload' ) );
		}

		Logger::info( '[DiluxOne Offload Image Editor] Copy successful, temp size: ' . filesize( $temp_filename ) );

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
	 * Imagick can't save directly to diluxoneoffload://, so:
	 * 1. Save to temp file
	 * 2. Copy temp to diluxoneoffload:// (triggers stream wrapper upload to Azure)
	 * 3. Delete temp
	 *
	 * @param \Imagick $image Imagick object
	 * @param string   $filename Output filename
	 * @param string   $mime_type Output mime type
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
			// Not our stream wrapper, use parent directly
			return parent::_save( $image, $filename, $mime_type );
		}

		// Save to temp file first
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
