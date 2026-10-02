<?php
/**
 * Namespaced stand-ins for the PHP functions Crypto calls, so a test can
 * take away what a stripped-down host lacks (AES-256-GCM, a CSPRNG) or make
 * a call fail. An unqualified call inside DiluxOneOffload resolves to these
 * first; each one passes through to PHP's own unless its fault is set in
 * $GLOBALS['_dlx_crypto_fault'].
 *
 * Required only inside tests that run in their own process: once PHP has
 * resolved a call to the global function it keeps doing so, and these must
 * never leak into the rest of the suite.
 */

namespace DiluxOneOffload;

function _dlx_crypto_fault( string $name ): bool {
	return ! empty( $GLOBALS['_dlx_crypto_fault'][ $name ] );
}

/** @return string[] */
function openssl_get_cipher_methods( bool $aliases = false ): array {
	return _dlx_crypto_fault( 'no_gcm' ) ? array( 'aes-128-cbc' ) : \openssl_get_cipher_methods( $aliases );
}

/**
 * @param string|null $tag
 * @return string|false
 */
function openssl_encrypt( string $data, string $cipher_algo, string $passphrase, int $options = 0, string $iv = '', &$tag = null, string $aad = '', int $tag_length = 16 ) {
	if ( _dlx_crypto_fault( 'encrypt_fails' ) ) {
		return false;
	}
	return \openssl_encrypt( $data, $cipher_algo, $passphrase, $options, $iv, $tag, $aad, $tag_length );
}

function random_bytes( int $length ): string {
	if ( _dlx_crypto_fault( 'no_entropy' ) ) {
		throw new \Exception( 'Could not gather sufficient random data' );
	}
	return \random_bytes( $length );
}

function wp_salt( string $scheme = 'auth' ): string {
	if ( _dlx_crypto_fault( 'salt_unavailable' ) ) {
		throw new \RuntimeException( 'salt store unavailable' );
	}
	return \wp_salt( $scheme );
}
