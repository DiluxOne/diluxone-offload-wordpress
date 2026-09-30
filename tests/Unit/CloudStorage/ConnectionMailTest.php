<?php
namespace Tests\Unit\CloudStorage;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\ConfigManager;

/**
 * The administrator hears once when uploads pause (the third failure in a
 * row) and once when they resume, never once per failure, and not at all
 * when Settings › Logging turns it off.
 */
class ConnectionMailTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_wp_options']    = array( 'admin_email' => 'owner@example.com', 'blogname' => 'Demo &amp; Co', 'diluxone_offload_plugin_state' => 'offloading_active' );
		$GLOBALS['_test_wp_transients'] = array();
		$GLOBALS['_test_wp_mail']       = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_test_wp_options'], $GLOBALS['_test_wp_transients'], $GLOBALS['_test_wp_mail'] );
		parent::tearDown();
	}

	/** @return array<int, array{to: string, subject: string, message: string}> */
	private function mail(): array {
		return $GLOBALS['_test_wp_mail'];
	}

	public function test_one_message_when_uploads_pause_and_one_when_they_resume(): void {
		ConfigManager::record_connection_failure( '403', 'the keys cannot write to this bucket', 'upload' );
		ConfigManager::record_connection_failure( '403', 'the keys cannot write to this bucket', 'upload' );
		$this->assertSame( array(), $this->mail(), 'a blip or two says nothing' );

		ConfigManager::record_connection_failure( '403', 'the keys cannot write to this bucket', 'upload' );
		$this->assertCount( 1, $this->mail() );
		$this->assertSame( 'owner@example.com', $this->mail()[0]['to'] );
		$this->assertSame( '[Demo & Co] Media uploads to the cloud are paused', $this->mail()[0]['subject'] );
		$this->assertStringContainsString( '403 the keys cannot write to this bucket', $this->mail()[0]['message'] );
		$this->assertStringContainsString( 'page=diluxone-offload-status&tab=health', $this->mail()[0]['message'] );

		ConfigManager::record_connection_failure( '403', 'the keys cannot write to this bucket', 'health' );
		ConfigManager::record_connection_failure( '403', 'the keys cannot write to this bucket', 'health' );
		$this->assertCount( 1, $this->mail(), 'never one per failure' );

		ConfigManager::record_connection_success();
		$this->assertCount( 2, $this->mail() );
		$this->assertSame( '[Demo & Co] Media uploads to the cloud resumed', $this->mail()[1]['subject'] );

		ConfigManager::record_connection_success();
		$this->assertCount( 2, $this->mail(), 'the recovery is told once' );
	}

	public function test_a_success_that_follows_no_announced_pause_says_nothing(): void {
		ConfigManager::record_connection_failure( '500', 'Upload failed', 'upload' );
		ConfigManager::record_connection_success();
		$this->assertSame( array(), $this->mail() );
	}

	public function test_turned_off_nothing_is_sent_either_way(): void {
		ConfigManager::save_plugin_settings( array( 'notify_email' => false ) );
		for ( $i = 0; $i < 3; $i++ ) {
			ConfigManager::record_connection_failure( '403', 'denied', 'upload' );
		}
		ConfigManager::record_connection_success();
		$this->assertSame( array(), $this->mail() );
	}

	public function test_nothing_is_announced_while_offloading_is_off_since_no_upload_is_refused(): void {
		$GLOBALS['_test_wp_options']['diluxone_offload_plugin_state'] = 'synced';
		for ( $i = 0; $i < 4; $i++ ) {
			ConfigManager::record_connection_failure( '403', 'denied', 'health' );
		}
		$this->assertSame( array(), $this->mail() );
	}

	public function test_a_pause_not_announced_at_the_third_failure_is_announced_at_the_next(): void {
		ConfigManager::save_plugin_settings( array( 'notify_email' => false ) );
		for ( $i = 0; $i < 3; $i++ ) {
			ConfigManager::record_connection_failure( '403', 'denied', 'upload' );
		}
		$this->assertSame( array(), $this->mail() );
		ConfigManager::save_plugin_settings( array( 'notify_email' => true ) );
		ConfigManager::record_connection_failure( '403', 'denied', 'upload' );
		$this->assertCount( 1, $this->mail(), 'turned on later, the next failure announces the pause' );
	}

	public function test_credentials_that_stop_decrypting_at_the_pause_announce_it_once_without_looping(): void {
		ConfigManager::record_connection_failure( '403', 'denied', 'upload' );
		ConfigManager::record_connection_failure( '403', 'denied', 'upload' );
		// Salts changed: the stored key no longer decrypts, and reading the
		// configuration records that as the failure that pauses uploads.
		$GLOBALS['_test_wp_options']['diluxone_offload_config'] = array(
			'cloud_provider'  => 'azure',
			'provider_config' => array( 'storage_account' => 'acct', 'container_name' => 'media', 'access_key' => 'DILUXONEOFFLOADENC1:not-decryptable' ),
		);
		ConfigManager::get_config();
		$health = ConfigManager::get_connection_health();
		$this->assertSame( 'decrypt_failed', $health['error_code'] );
		$this->assertSame( 3, $health['consecutive_failures'], 'recorded once, not again and again' );
		$this->assertCount( 1, $this->mail() );
	}
}
