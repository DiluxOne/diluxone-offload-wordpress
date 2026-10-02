<?php
namespace Tests\Unit\CloudStorage;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\Crypto;

/**
 * Crypto on a host that cannot do it: no AES-256-GCM, no source of random
 * bytes, an openssl call that fails, salts that cannot be read.
 *
 * The rule under test is the one the class documents: there is no fallback
 * to storing a credential in clear. encrypt() answers '' and logs why,
 * decrypt() answers null (the user re-enters the key), and ConfigManager
 * stores nothing rather than the plaintext.
 *
 * Each test runs in its own process, because the faults are namespaced
 * stand-ins for PHP functions (tests/Unit/Support/crypto-faults.php) that
 * must never reach the rest of the suite.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CryptoFailureTest extends TestCase {

	private string $sink = '';

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__ ) . '/Support/crypto-faults.php';
		$GLOBALS['_dlx_crypto_fault']   = array();
		$GLOBALS['_test_wp_options']    = array();
		$GLOBALS['_test_wp_transients'] = array();
		$this->sink                     = (string) tempnam( sys_get_temp_dir(), 'dlxlog' );
		ini_set( 'error_log', $this->sink );
	}

	protected function tearDown(): void {
		@unlink( $this->sink );
		unset( $GLOBALS['_dlx_crypto_fault'], $GLOBALS['_test_wp_options'], $GLOBALS['_test_wp_transients'] );
		parent::tearDown();
	}

	private function fault( string $name ): void {
		$GLOBALS['_dlx_crypto_fault'][ $name ] = true;
	}

	private function logged(): string {
		return (string) file_get_contents( $this->sink );
	}

	public function test_without_aes_gcm_nothing_is_encrypted_and_the_reason_is_logged(): void {
		$this->fault( 'no_gcm' );

		$this->assertFalse( Crypto::is_available() );
		$this->assertSame( '', Crypto::encrypt( 'placeholder-credential' ) );
		$this->assertStringContainsString( 'AES-256-GCM unavailable; refusing to store credential', $this->logged() );
	}

	public function test_without_aes_gcm_a_stored_value_cannot_be_read_back(): void {
		$cipher = Crypto::encrypt( 'placeholder-credential' );
		$this->assertTrue( Crypto::is_encrypted( $cipher ) );

		$this->fault( 'no_gcm' );

		$this->assertNull( Crypto::decrypt( $cipher ), 'the caller asks for the key again' );
	}

	public function test_an_openssl_encrypt_failure_stores_nothing(): void {
		$this->fault( 'encrypt_fails' );

		$this->assertSame( '', Crypto::encrypt( 'placeholder-credential' ) );
		$this->assertStringContainsString( 'openssl_encrypt failed', $this->logged() );
	}

	public function test_no_random_bytes_for_the_iv_stores_nothing_and_says_why(): void {
		$this->fault( 'no_entropy' );

		$this->assertSame( '', Crypto::encrypt( 'placeholder-credential' ) );
		$this->assertStringContainsString( 'Encryption error: Could not gather sufficient random data', $this->logged() );
	}

	public function test_salts_that_cannot_be_read_make_decrypt_answer_null_not_throw(): void {
		$cipher = Crypto::encrypt( 'placeholder-credential' );

		$this->fault( 'salt_unavailable' );

		$this->assertNull( Crypto::decrypt( $cipher ) );
		$this->assertStringContainsString( 'Decryption error: salt store unavailable', $this->logged() );
	}

	public function test_config_manager_never_stores_a_key_it_could_not_encrypt(): void {
		$this->fault( 'no_gcm' );
		$key = base64_encode( str_repeat( 'k', 32 ) );

		ConfigManager::save_provider_config(
			array(
				'cloud_provider'  => 'azure',
				'provider_config' => array(
					'storage_account' => 'acct1',
					'container_name'  => 'media',
					'access_key'      => $key,
				),
			)
		);

		$stored = serialize( $GLOBALS['_test_wp_options'][ ConfigManager::CONFIG_OPTION ] ?? array() );
		$this->assertStringNotContainsString( $key, $stored, 'the key never reaches the option in clear' );
		$this->assertSame( '', $GLOBALS['_test_wp_options'][ ConfigManager::CONFIG_OPTION ]['provider_config']['access_key'] ?? null );
		$this->assertStringContainsString( 'Refusing to persist credential "access_key"', $this->logged() );
	}
}
