<?php
/**
 * AWS Signature Version 4 for the S3-compatible provider.
 *
 * A class of its own so it can be tested against the vectors AWS publishes
 * without a provider around it. Pure PHP: hash() and hash_hmac() only.
 *
 * The URL it receives is the one the request goes to, with every path
 * segment and query value already encoded once (the provider builds it that
 * way). The canonical URI is that path as is: S3 signs the path exactly as
 * sent and never normalises or re-encodes it. Presigned URLs (signing in the
 * query string) are not implemented.
 *
 * @package DiluxOneOffload\Providers
 * @since 2.0.0
 */

namespace DiluxOneOffload\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Signs a request with AWS4-HMAC-SHA256 in the Authorization header.
 */
class AwsSignatureV4 {

	const ALGORITHM = 'AWS4-HMAC-SHA256';

	/**
	 * The payload hash S3 accepts over TLS instead of the body's SHA-256,
	 * which lets a file stream from disk without being read twice.
	 */
	const UNSIGNED_PAYLOAD = 'UNSIGNED-PAYLOAD';

	/** SHA-256 of an empty body. */
	const EMPTY_PAYLOAD = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

	/** @var string */
	private $access_key_id;

	/** @var string */
	private $secret_access_key;

	/** @var string */
	private $region;

	/** @var string */
	private $service;

	/**
	 * @param string $access_key_id     Access key ID.
	 * @param string $secret_access_key Secret access key; only ever used as HMAC key material.
	 * @param string $region            Region of the credential scope (`auto` for R2).
	 * @param string $service           Service of the credential scope; `s3` for every caller in the plugin.
	 */
	public function __construct( string $access_key_id, string $secret_access_key, string $region, string $service = 's3' ) {
		$this->access_key_id     = $access_key_id;
		$this->secret_access_key = $secret_access_key;
		$this->region            = $region;
		$this->service           = $service;
	}

	/**
	 * The headers to send with an S3 request: the caller's, plus Host,
	 * x-amz-date, x-amz-content-sha256 and Authorization.
	 *
	 * Signed: host, every x-amz-* header, and content-type, content-md5 and
	 * range when present. Any other header the caller passes is sent unsigned,
	 * because a transport may rewrite it (content-length, expect).
	 *
	 * @param string               $method       HTTP method.
	 * @param string               $url          Request URL, already encoded.
	 * @param array<string,string> $headers      Headers the request carries.
	 * @param string               $payload_hash Hex SHA-256 of the body, or UNSIGNED_PAYLOAD.
	 * @param int|null             $time         Unix time to sign at; now when null.
	 * @return array<string,string>
	 */
	public function sign( string $method, string $url, array $headers = array(), string $payload_hash = self::UNSIGNED_PAYLOAD, ?int $time = null ): array {
		$headers['Host']                 = self::host( $url );
		$headers['x-amz-date']           = gmdate( 'Ymd\THis\Z', $time ?? time() );
		$headers['x-amz-content-sha256'] = $payload_hash;

		$signed = array();
		foreach ( $headers as $name => $value ) {
			$lower = strtolower( (string) $name );
			if ( 'host' === $lower || 0 === strpos( $lower, 'x-amz-' ) || in_array( $lower, array( 'content-type', 'content-md5', 'range' ), true ) ) {
				$signed[ $name ] = (string) $value;
			}
		}

		$headers['Authorization'] = $this->authorization( $method, $url, $signed, $payload_hash );
		return $headers;
	}

	/**
	 * The Authorization header value for a request that carries exactly
	 * `$headers`, all of them signed. They must include Host and x-amz-date.
	 *
	 * @param string               $method       HTTP method.
	 * @param string               $url          Request URL, already encoded.
	 * @param array<string,string> $headers      Headers to sign.
	 * @param string               $payload_hash Hex SHA-256 of the body, or UNSIGNED_PAYLOAD.
	 * @return string
	 */
	public function authorization( string $method, string $url, array $headers, string $payload_hash ): string {
		$canonical = self::canonical_headers( $headers );
		$date      = $canonical['x-amz-date'] ?? '';
		$scope     = substr( $date, 0, 8 ) . '/' . $this->region . '/' . $this->service . '/aws4_request';

		return self::ALGORITHM
			. ' Credential=' . $this->access_key_id . '/' . $scope
			. ', SignedHeaders=' . implode( ';', array_keys( $canonical ) )
			. ', Signature=' . $this->signature( $this->string_to_sign( $this->canonical_request( $method, $url, $headers, $payload_hash ), $date ), $date );
	}

	/**
	 * The canonical request: method, URI, query, headers, signed headers, payload hash.
	 *
	 * @param string               $method       HTTP method.
	 * @param string               $url          Request URL, already encoded.
	 * @param array<string,string> $headers      Headers to sign.
	 * @param string               $payload_hash Hex SHA-256 of the body, or UNSIGNED_PAYLOAD.
	 * @return string
	 */
	public function canonical_request( string $method, string $url, array $headers, string $payload_hash ): string {
		$path      = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query     = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$canonical = self::canonical_headers( $headers );

		$lines = '';
		foreach ( $canonical as $name => $value ) {
			$lines .= $name . ':' . $value . "\n";
		}

		return strtoupper( $method ) . "\n"
			. ( '' === $path ? '/' : $path ) . "\n"
			. self::canonical_query( $query ) . "\n"
			. $lines . "\n"
			. implode( ';', array_keys( $canonical ) ) . "\n"
			. $payload_hash;
	}

	/**
	 * The string to sign for a canonical request made at `$amz_date`.
	 *
	 * @param string $canonical_request The canonical request.
	 * @param string $amz_date          The x-amz-date value, `YYYYMMDDTHHMMSSZ`.
	 * @return string
	 */
	public function string_to_sign( string $canonical_request, string $amz_date ): string {
		return self::ALGORITHM . "\n"
			. $amz_date . "\n"
			. substr( $amz_date, 0, 8 ) . '/' . $this->region . '/' . $this->service . "/aws4_request\n"
			. hash( 'sha256', $canonical_request );
	}

	/**
	 * The hex signature of a string to sign.
	 *
	 * @param string $string_to_sign The string to sign.
	 * @param string $amz_date       The x-amz-date value it was built with.
	 * @return string
	 */
	public function signature( string $string_to_sign, string $amz_date ): string {
		$key = hash_hmac( 'sha256', substr( $amz_date, 0, 8 ), 'AWS4' . $this->secret_access_key, true );
		$key = hash_hmac( 'sha256', $this->region, $key, true );
		$key = hash_hmac( 'sha256', $this->service, $key, true );
		$key = hash_hmac( 'sha256', 'aws4_request', $key, true );
		return hash_hmac( 'sha256', $string_to_sign, $key );
	}

	/**
	 * The Host header for a URL: the host, plus the port when it is not the
	 * scheme's default (MinIO on `:9000`).
	 *
	 * @param string $url Request URL.
	 * @return string
	 */
	public static function host( string $url ): string {
		$parts = wp_parse_url( $url );
		$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
		$port  = isset( $parts['port'] ) ? (int) $parts['port'] : null;
		$https = 'https' === strtolower( (string) ( $parts['scheme'] ?? 'https' ) );
		if ( null !== $port && ! ( $https && 443 === $port ) && ! ( ! $https && 80 === $port ) ) {
			$host .= ':' . $port;
		}
		return $host;
	}

	/**
	 * Lower-case names, sorted; values trimmed with inner runs of spaces
	 * collapsed; a name given twice (in different case) joined with commas.
	 *
	 * @param array<string,string> $headers Headers to sign.
	 * @return array<string,string>
	 */
	private static function canonical_headers( array $headers ): array {
		$out = array();
		foreach ( $headers as $name => $value ) {
			$lower         = strtolower( trim( (string) $name ) );
			$value         = trim( (string) preg_replace( '/\s+/', ' ', (string) $value ) );
			$out[ $lower ] = isset( $out[ $lower ] ) ? $out[ $lower ] . ',' . $value : $value;
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * Each name and value decoded and re-encoded per RFC 3986, sorted by name
	 * then value, and `=` kept for an empty value (`uploads=`).
	 *
	 * @param string $query Raw query string.
	 * @return string
	 */
	private static function canonical_query( string $query ): string {
		if ( '' === $query ) {
			return '';
		}
		$pairs = array();
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$kv      = explode( '=', $pair, 2 );
			$pairs[] = array( rawurlencode( rawurldecode( $kv[0] ) ), rawurlencode( rawurldecode( $kv[1] ?? '' ) ) );
		}
		usort(
			$pairs,
			static function ( array $a, array $b ): int {
				$by_name = strcmp( $a[0], $b[0] );
				return 0 !== $by_name ? $by_name : strcmp( $a[1], $b[1] );
			}
		);
		return implode(
			'&',
			array_map(
				static function ( array $p ): string {
					return $p[0] . '=' . $p[1];
				},
				$pairs
			)
		);
	}
}
