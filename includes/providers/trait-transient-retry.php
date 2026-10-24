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
 * service documents as temporary: 500, 502, 503 or 504, 429, or a connection
 * that dropped before an answer. Backblaze B2 answers 500 "internal incident"
 * to about one upload in a hundred and asks clients to retry; Amazon S3, R2,
 * Google and Azure document the same for their 5xx answers, and their own
 * SDKs retry them. A 429 says to slow down: Google allows one change a second
 * to the same object, so a delete right after WordPress writes an image (an
 * edit, a thumbnail) is refused once; its retry waits at least
 * `$throttle_pause`, or the answer's Retry-After up to `$max_retry_after`.
 * Any other 4xx answer is never retried (the request is
 * wrong, or the key is), and neither is a timeout, which already spent its
 * time: the request that waited is the owner's, in a browser.
 *
 * Only requests that are safe to repeat are sent again: a PUT of the same
 * bytes to the same key, a GET, a HEAD, a DELETE, a copy onto the same
 * destination. A POST (S3's start and commit of a multipart upload) is sent
 * once: a start that worked but answered 500 would leave an upload that is
 * kept and billed, and a commit that worked would be reported as a failure.
 * Nor is an answer that took long (`$slow_answer`, ten seconds): asking
 * again would keep the owner waiting several times the timeout, the same
 * reason a timeout is not retried.
 */
trait TransientRetry {

	/**
	 * Pauses before the second and the third attempt, in microseconds.
	 *
	 * @var int[]
	 */
	protected static $retry_pauses = array( 250000, 750000 );

	/**
	 * The shortest pause before a retry of a 429, in microseconds: a second,
	 * the window Google counts changes to one object in.
	 *
	 * @var int
	 */
	protected static $throttle_pause = 1000000;

	/**
	 * The longest a 429's Retry-After is honoured, in seconds: past it the
	 * owner would wait in the browser longer than a slow answer is allowed.
	 *
	 * @var int
	 */
	protected static $max_retry_after = 5;

	/**
	 * Seconds after which an answer, even a transient one, is not asked again.
	 *
	 * @var float
	 */
	protected static $slow_answer = 10.0;

	/**
	 * Send a request through the WordPress HTTP API, up to three times.
	 *
	 * @param string              $url  Request URL.
	 * @param array<string,mixed> $args wp_remote_request() arguments, including the method.
	 * @return array<string,mixed>|\WP_Error The last answer.
	 */
	protected function send_with_retry( string $url, array $args ) {
		$repeatable = 'POST' !== strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		$attempt    = 0;
		while ( true ) {
			$started  = \DiluxOneOffload\Clock::now();
			$response = self::send( $url, $args );
			$slow     = \DiluxOneOffload\Clock::now() - $started >= static::$slow_answer;
			if ( ! $repeatable || $slow || ! self::is_transient( $response ) || $attempt >= count( static::$retry_pauses ) ) {
				return $response;
			}
			$pause = static::$retry_pauses[ $attempt ];
			if ( ! is_wp_error( $response ) && 429 === (int) wp_remote_retrieve_response_code( $response ) ) {
				// Retry-After in seconds (the date form is not used by these
				// services), honoured up to $max_retry_after.
				$after = wp_remote_retrieve_header( $response, 'retry-after' );
				$after = is_string( $after ) && ctype_digit( $after ) ? min( (int) $after, static::$max_retry_after ) * 1000000 : 0;
				$pause = max( $pause, static::$throttle_pause, $after );
			}
			usleep( $pause );
			++$attempt;
		}
	}

	/**
	 * One request through the WordPress HTTP API, its body included even when
	 * it is the one string PHP's empty() is true of: "0". WordPress' Requests
	 * library hands a body to curl only when !empty($data), so a one-byte
	 * body "0" (a one-byte file, or the last part of a file one byte over a
	 * whole number of parts whose last byte is 0x30) left with no body and
	 * no Content-Length; the services answer 411 or wait for the byte. For
	 * that body the handle is given its bytes through `http_api_curl`, the
	 * action WordPress fires with the curl handle just before it is run.
	 *
	 * @param string              $url  Request URL.
	 * @param array<string,mixed> $args wp_remote_request() arguments.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function send( string $url, array $args ) {
		if ( '0' !== ( $args['body'] ?? '' ) ) {
			return wp_remote_request( $url, $args );
		}
		$give_body = static function ( $handle ): void {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- The WordPress HTTP API itself drops this body; this is its own hook for the handle.
			curl_setopt( $handle, CURLOPT_POSTFIELDS, '0' );
		};
		add_action( 'http_api_curl', $give_body );
		try {
			return wp_remote_request( $url, $args );
		} finally {
			remove_action( 'http_api_curl', $give_body );
		}
	}

	/**
	 * Whether a failure, by its code, is one send_with_retry() already asked
	 * again for, so a caller's own retry loop does not repeat it three more times.
	 *
	 * @param string $code '500'…'504', '429', 'network', or another code.
	 * @return bool
	 */
	private static function retried_inside( string $code ): bool {
		return in_array( $code, array( '500', '502', '503', '504', '429', 'network' ), true );
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
		return in_array( (int) wp_remote_retrieve_response_code( $response ), array( 500, 502, 503, 504, 429 ), true );
	}
}
