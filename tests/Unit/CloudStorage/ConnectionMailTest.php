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
		$GLOBALS['_test_wp_options']    = array( 'admin_email' => 'owner@example.com', 'blogname' => 'Demo &amp; Co' );
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
}
