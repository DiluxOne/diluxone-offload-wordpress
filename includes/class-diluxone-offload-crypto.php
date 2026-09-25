<?php
/**
 * Symmetric encryption helper for sensitive credentials at rest (AES-256-GCM).
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Symmetric encryption helper for sensitive credentials at rest.
 *
 * AES-256-GCM with a 256-bit key derived from the site's WordPress salts
 * (`wp_salt('auth')` + `wp_salt('secure_auth')`). Ciphertext is base64-encoded
 * and prefixed with a version tag so future key rotations can be detected.
 *
 * Failure mode: if `decrypt()` cannot recover the plaintext (corrupted payload,
 * salts rotated, openssl unavailable), it returns null. There is no fallback to
 * plaintext storage — the caller is expected to surface the failure so the user
 * re-enters the credential.
 */
class Crypto {

	private const PREFIX = 'DILUXONEOFFLOADENC1:';


	private const CIPHER  = 'aes-256-gcm';
	private const IV_LEN  = 12;   // 96-bit IV recommended for GCM
	private const TAG_LEN = 16;  // 128-bit authentication tag

	/**
	 * @return bool True when openssl + AES-256-GCM are available.
	 */
	public static function is_available(): bool {
		return function_exists( 'openssl_encrypt' )
			&& function_exists( 'openssl_decrypt' )
			&& function_exists( 'random_bytes' )
			&& in_array( self::CIPHER, openssl_get_cipher_methods(), true );
	}

	/**
	 * @return bool True if the given value already has the encrypted prefix.
	 * @param string $value
	 */
	public static function is_encrypted( string $value ): bool {
		return self::match_prefix( $value ) !== null;
	}

	/**
	 * @return string|null The prefix this value carries, or null if it carries none.
	 * @param string $value
	 */
	private static function match_prefix( string $value ): ?string {
		return strncmp( $value, self::PREFIX, strlen( self::PREFIX ) ) === 0 ? self::PREFIX : null;
	}

	/**
	 * Encrypt a plaintext credential. Idempotent: already-encrypted values are
	 * returned unchanged. Empty strings are returned unchanged.
	 *
	 * @param string $plaintext
	 * @return string Encrypted payload (or empty string on failure).
	 */
	public static function encrypt( string $plaintext ): string {
		if ( $plaintext === '' || self::is_encrypted( $plaintext ) ) {
			return $plaintext;
		}
		if ( ! self::is_available() ) {
			Logger::error( '[DiluxOne Offload Crypto] openssl/AES-256-GCM unavailable; refusing to store credential.' );
			return '';
		}

		try {
			$key    = self::derive_key();
			$iv     = random_bytes( self::IV_LEN );
			$tag    = '';
			$cipher = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN );
			if ( $cipher === false ) {
				Logger::error( '[DiluxOne Offload Crypto] openssl_encrypt failed.' );
				return '';
			}
			return self::PREFIX . base64_encode( $iv . $tag . $cipher );
		} catch ( \Throwable $e ) {
			Logger::error( '[DiluxOne Offload Crypto] Encryption error: ' . $e->getMessage() );
			return '';
		}
	}

	/**
	 * Decrypt a previously encrypted value.
	 *
	 * @param string $ciphertext
	 * @return string|null Plaintext on success, null on any failure (caller
	 *                     should treat as "credential lost — re-enter").
	 */
	public static function decrypt( string $ciphertext ): ?string {
		$prefix = self::match_prefix( $ciphertext );
		if ( $prefix === null ) {
			return null;
		}
		if ( ! self::is_available() ) {
			return null;
		}

		$payload = base64_decode( substr( $ciphertext, strlen( $prefix ) ), true );
		if ( $payload === false || strlen( $payload ) < self::IV_LEN + self::TAG_LEN ) {
			return null;
		}

		$iv     = substr( $payload, 0, self::IV_LEN );
		$tag    = substr( $payload, self::IV_LEN, self::TAG_LEN );
		$cipher = substr( $payload, self::IV_LEN + self::TAG_LEN );

		try {
			$key   = self::derive_key();
			$plain = openssl_decrypt( $cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag );
			return $plain === false ? null : $plain;
		} catch ( \Throwable $e ) {
			Logger::error( '[DiluxOne Offload Crypto] Decryption error: ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * Derive a 32-byte key from the site's WordPress auth salts.
	 * Rotating the salts invalidates every previously-encrypted value, which
	 * is the desired behaviour: stored credentials are tied to this install.
	 */
	private static function derive_key(): string {
		$material = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		return hash_hmac( 'sha256', 'diluxone-offload-v1', $material, true );
	}
}
