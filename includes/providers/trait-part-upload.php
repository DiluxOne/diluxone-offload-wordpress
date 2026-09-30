<?php
/**
 * The body of one part of a chunked upload, shared by the providers.
 *
 * @package DiluxOne_Offload
 */

namespace DiluxOneOffload\Providers;

use DiluxOneOffload\DTOs\ChunkedUpload;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One part's bytes, streamed from the file into a cURL handle: the file is
 * opened at the part's offset and cURL reads no further than its length, so
 * a part never sits in memory and several parts of one file can be in flight
 * at once, each with its own file handle. The file is the one under
 * uploads/ being synced, and cURL is the sync's curl_multi transport, for
 * the same reasons as in the providers that use this trait.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
 */
trait PartUpload {

	/**
	 * Make a cURL handle send one part of the file as its body.
	 *
	 * @param resource      $ch     The handle (PHPStan reads the PHP 7.4 stubs; PHP 8 hands a CurlHandle, which curl_setopt_array() accepts).
	 * @param ChunkedUpload $upload The upload.
	 * @param int           $part   Part number, from 1.
	 * @return resource|null The file handle to close once the transfer ends; null when the file cannot be read there.
	 */
	private static function stream_part( $ch, ChunkedUpload $upload, int $part ) {
		$file_handle = fopen( $upload->localPath(), 'rb' );
		if ( ! $file_handle ) {
			return null;
		}
		if ( 0 !== fseek( $file_handle, $upload->offset( $part ) ) ) {
			fclose( $file_handle );
			return null;
		}

		$left = $upload->length( $part );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_UPLOAD       => true,
				CURLOPT_INFILESIZE   => $left,
				// A file that got shorter since ends the body early, and cURL
				// fails the part instead of sending the next part's bytes.
				CURLOPT_READFUNCTION => static function ( $handle, $stream, int $max ) use ( $file_handle, &$left ): string {
					if ( $left <= 0 ) {
						return '';
					}
					$bytes = fread( $file_handle, max( 1, min( $max, $left ) ) );
					if ( false === $bytes ) {
						return '';
					}
					$left -= strlen( $bytes );
					return $bytes;
				},
			)
		);
		return $file_handle;
	}
}
