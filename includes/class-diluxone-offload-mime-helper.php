<?php
/**
 * Extension-to-MIME-type mapping helper.
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MIME Type Helper - Centralized MIME type detection
 *
 * WHY THIS EXISTS:
 * PHP's mime_content_type() is UNRELIABLE for CSS/JS files.
 * Returns 'text/plain' for CSS/JS instead of correct MIME types.
 * This class uses extension-based detection for accuracy.
 *
 * USED BY:
 * - AzureProvider: Upload operations
 * - SyncManager: Batch sync operations
 *
 * @package DiluxOneOffload
 */
class MimeHelper {

	/**
	 * Get MIME type from file path (extension-based)
	 *
	 * @param string $file_path File path (can be remote path with extension)
	 * @return string MIME type
	 */
	public static function get_mime_type( string $file_path ): string {
		$extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

		if ( empty( $extension ) ) {
			return 'application/octet-stream';
		}

		// Extension → MIME mapping
		$mime_types = array(
			// ⭐ CRITICAL: Browsers strictly require these exact MIME types
			'css'   => 'text/css',
			'js'    => 'application/javascript',
			'mjs'   => 'application/javascript',
			'json'  => 'application/json',
			'xml'   => 'application/xml',

			// Fonts (critical for CORS)
			'woff2' => 'font/woff2',
			'woff'  => 'font/woff',
			'ttf'   => 'font/ttf',
			'otf'   => 'font/otf',
			'eot'   => 'application/vnd.ms-fontobject',

			// Images
			'jpg'   => 'image/jpeg',
			'jpeg'  => 'image/jpeg',
			'png'   => 'image/png',
			'gif'   => 'image/gif',
			'webp'  => 'image/webp',
			'svg'   => 'image/svg+xml',
			'ico'   => 'image/x-icon',
			'bmp'   => 'image/bmp',
			'tiff'  => 'image/tiff',
			'tif'   => 'image/tiff',

			// Video
			'mp4'   => 'video/mp4',
			'webm'  => 'video/webm',
			'ogv'   => 'video/ogg',
			'avi'   => 'video/x-msvideo',
			'mov'   => 'video/quicktime',
			'wmv'   => 'video/x-ms-wmv',
			'flv'   => 'video/x-flv',
			'mkv'   => 'video/x-matroska',

			// Audio
			'mp3'   => 'audio/mpeg',
			'wav'   => 'audio/wav',
			'ogg'   => 'audio/ogg',
			'flac'  => 'audio/flac',
			'm4a'   => 'audio/mp4',
			'aac'   => 'audio/aac',

			// Documents
			'pdf'   => 'application/pdf',
			'doc'   => 'application/msword',
			'docx'  => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'xls'   => 'application/vnd.ms-excel',
			'xlsx'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'ppt'   => 'application/vnd.ms-powerpoint',
			'pptx'  => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'odt'   => 'application/vnd.oasis.opendocument.text',
			'ods'   => 'application/vnd.oasis.opendocument.spreadsheet',
			'odp'   => 'application/vnd.oasis.opendocument.presentation',

			// Archives
			'zip'   => 'application/zip',
			'rar'   => 'application/x-rar-compressed',
			'7z'    => 'application/x-7z-compressed',
			'tar'   => 'application/x-tar',
			'gz'    => 'application/gzip',
			'bz2'   => 'application/x-bzip2',

			// Text
			'txt'   => 'text/plain',
			'html'  => 'text/html',
			'htm'   => 'text/html',
			'csv'   => 'text/csv',
			'rtf'   => 'application/rtf',
			'md'    => 'text/markdown',

			// Other
			'swf'   => 'application/x-shockwave-flash',
		);

		return $mime_types[ $extension ] ?? 'application/octet-stream';
	}

	/**
	 * Check if file is a stylesheet
	 *
	 * @param string $file_path File path
	 * @return bool
	 */
	public static function is_stylesheet( string $file_path ): bool {
		$ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		return $ext === 'css';
	}

	/**
	 * Check if file is a script
	 *
	 * @param string $file_path File path
	 * @return bool
	 */
	public static function is_script( string $file_path ): bool {
		$ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		return in_array( $ext, array( 'js', 'mjs' ), true );
	}
}
