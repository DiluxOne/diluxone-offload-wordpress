<?php
namespace Tests\Unit\CloudStorage;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\Crypto;

/**
 * Unit tests for Crypto — AES-256-GCM encryption / decryption with a key
 * derived from the WordPress salts.
 *
 * The bootstrap registers wp_salt() stubs that return deterministic
 * values per scheme, so encrypt/decrypt is reproducible across runs but
 * still validates the actual round-trip through openssl.
 *
 * Pure logic, no WordPress runtime needed.
 */
class CryptoTest extends TestCase {

    public function test_is_available_returns_true_in_test_environment(): void {
        // Test environment has openssl + AES-256-GCM. If this assertion fails,
        // the host is missing crypto extensions and no other Crypto test can
        // run — so this is the canary for the whole suite.
        $this->assertTrue(Crypto::is_available());
    }

    public function test_encrypt_then_decrypt_roundtrip(): void {
        // Plaintext is intentionally a generic fake string, NOT shaped like
        // any real API key format (Stripe sk_..., AWS AKIA..., GitHub
        // ghp_..., etc.) — GitHub's secret-scanning push protection rejects
        // even fake-looking secrets in test fixtures.
        $plaintext = 'placeholder-credential-value-32-bytes-long';

        $cipher = Crypto::encrypt($plaintext);
        $this->assertNotSame('', $cipher);
        $this->assertNotSame($plaintext, $cipher);
        $this->assertTrue(Crypto::is_encrypted($cipher));

        $recovered = Crypto::decrypt($cipher);
        $this->assertSame($plaintext, $recovered);
    }

    public function test_encrypt_empty_string_returns_empty_string(): void {
        $this->assertSame('', Crypto::encrypt(''));
    }

    public function test_encrypt_is_idempotent_on_already_encrypted_values(): void {
        $cipher = Crypto::encrypt('original');
        $this->assertTrue(Crypto::is_encrypted($cipher));

        // Re-encrypting an already-encrypted value must return it unchanged.
        // Without this guarantee, save_config() would double-encrypt every
        // time the user saves the form.
        $cipher_again = Crypto::encrypt($cipher);
        $this->assertSame($cipher, $cipher_again);
    }

    public function test_encrypt_uses_a_random_iv_so_same_plaintext_yields_different_ciphertexts(): void {
        $plaintext = 'identical-input';

        $a = Crypto::encrypt($plaintext);
        $b = Crypto::encrypt($plaintext);

        $this->assertNotSame($a, $b, 'IV randomness check: same plaintext encrypted twice must produce different ciphertexts.');

        // ...but both decrypt back to the original plaintext.
        $this->assertSame($plaintext, Crypto::decrypt($a));
        $this->assertSame($plaintext, Crypto::decrypt($b));
    }

    public function test_decrypt_returns_null_for_unencrypted_string(): void {
        // A value without the DILUXONEOFFLOADENC1: prefix is treated as plaintext —
        // decrypt() returns null so the caller can distinguish "not
        // encrypted" from "decryption failed".
        $this->assertNull(Crypto::decrypt('plain-value-not-encrypted'));
    }

    public function test_decrypt_returns_null_for_tampered_ciphertext(): void {
        $cipher = Crypto::encrypt('secret-payload');

        // Flip the last byte of the base64 portion. AES-GCM's authentication
        // tag must catch this — decrypt() returns null rather than producing
        // wrong plaintext.
        $tampered = substr($cipher, 0, -1) . (substr($cipher, -1) === 'A' ? 'B' : 'A');

        $this->assertNull(Crypto::decrypt($tampered));
    }

    public function test_decrypt_returns_null_for_truncated_ciphertext(): void {
        $cipher = Crypto::encrypt('secret-payload');

        // A ciphertext shorter than IV (12) + tag (16) bytes cannot
        // possibly be valid; decrypt() should reject without throwing.
        $truncated = substr($cipher, 0, strlen('DILUXONEOFFLOADENC1:') + 4);

        $this->assertNull(Crypto::decrypt($truncated));
    }

    public function test_decrypt_returns_null_for_invalid_base64(): void {
        // DILUXONEOFFLOADENC1: prefix is present but the body is not valid base64.
        $invalid = 'DILUXONEOFFLOADENC1:!!!not-valid-base64!!!';

        $this->assertNull(Crypto::decrypt($invalid));
    }

    public function test_is_encrypted_recognises_only_the_official_prefix(): void {
        $this->assertTrue(Crypto::is_encrypted('DILUXONEOFFLOADENC1:anything'));
        $this->assertFalse(Crypto::is_encrypted(''));
        $this->assertFalse(Crypto::is_encrypted('plain'));
        $this->assertFalse(Crypto::is_encrypted('diluxone-offload-enc-1:wrong'));
    }
}
