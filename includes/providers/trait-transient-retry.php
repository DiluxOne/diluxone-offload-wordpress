<?php
/**
 * Retries of transient cloud errors, shared by the providers.
 *
 * @package DiluxOne_Offload
 */

namespace DiluxOneOffload\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One request to the storage service, sent again when the answer is one the
 * service documents as temporary: 500, 502, 503 or 504, or a connection that
 * dropped before an answer. Backblaze B2 answers 500 "internal incident" to
 * about one upload in a hundred and asks clients to retry; Amazon S3, R2,
 * Google and Azure document the same for their 5xx answers, and their own
 * SDKs retry them. A 4xx answer is never retried (the request is wrong, or
 * the key is), and neither is a timeout, which already spent its time: the
 * request that waited is the owner's, in a browser.
 *
 * Every request the providers make is safe to repeat: a PUT of the same bytes
 * to the same key, a GET, a HEAD, a DELETE, a copy onto the same destination.
 */
trait TransientRetry {

	/**
	 * Pauses before the second and the third attempt, in microseconds.
	 *
	 * @var int[]
	 */
	protected static $retry_pauses = array( 250000, 750000 );

	/**
	 * Send a request through the WordPress HTTP API, up to three times.
	 *
	 * @param string              $url  Request URL.
	 * @param array<string,mixed> $args wp_remote_request() arguments, including the method.
	 * @return array<string,mixed>|\WP_Error The last answer.
	 */
	protected function send_with_retry( string $url, array $args ) {
		$attempt = 0;
		while ( true ) {
			$response = wp_remote_request( $url, $args );
			if ( ! self::is_transient( $response ) || $attempt >= count( static::$retry_pauses ) ) {
				return $response;
			}
			usleep( static::$retry_pauses[ $attempt ] );
			++$attempt;
		}
	}

	/**
	 * Whether an answer is one worth asking again for.
	 *
	 * @param array<string,mixed>|\WP_Error $response Answer or transport error.
	 * @return bool
	 */
	private static function is_transient( $response ): bool {
		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			// cURL 28 is a timeout: the time is already spent.
			return false === stripos( $message, 'cURL error 28' ) && false === stripos( $message, 'timed out' );
		}
		return in_array( (int) wp_remote_retrieve_response_code( $response ), array( 500, 502, 503, 504 ), true );
	}
}
