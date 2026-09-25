<?php
namespace Tests\Unit\DTOs;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\DTOs\OperationResult;
use DiluxOneOffload\DTOs\ConnectionResult;
use DiluxOneOffload\DTOs\UploadResult;

/**
 * Unit tests for the three result value objects.
 *
 * These cross every provider boundary in the plugin, and every one of them
 * round-trips through toArray()/fromArray() on its way to and from the admin
 * layer. The array keys are the contract — a rename there breaks callers
 * silently, because PHP just yields null for a missing key.
 *
 * Pure value objects: no WordPress runtime needed.
 */
class ResultObjectsTest extends TestCase {

	// ── OperationResult ─────────────────────────────────────

	public function test_operation_success_is_successful(): void {
		$r = OperationResult::success();
		$this->assertTrue( $r->isSuccess() );
		$this->assertFalse( $r->isFailure() );
	}

	public function test_operation_success_carries_no_error(): void {
		$this->assertSame( '', OperationResult::success()->getError() );
	}

	public function test_operation_success_accepts_a_message(): void {
		$this->assertSame( 'all good', OperationResult::success( 'all good' )->getError() );
	}

	public function test_operation_failure_is_not_successful(): void {
		$r = OperationResult::failure( 'boom' );
		$this->assertFalse( $r->isSuccess() );
		$this->assertTrue( $r->isFailure() );
		$this->assertSame( 'boom', $r->getError() );
	}

	public function test_operation_to_array_shape(): void {
		$this->assertSame(
			array(
				'success' => false,
				'error'   => 'nope',
			),
			OperationResult::failure( 'nope' )->toArray()
		);
	}

	public function test_operation_round_trips_through_array(): void {
		$original = OperationResult::failure( 'disk full' );
		$restored = OperationResult::fromArray( $original->toArray() );
		$this->assertSame( $original->toArray(), $restored->toArray() );
	}

	public function test_operation_from_empty_array_defaults_to_failure(): void {
		$r = OperationResult::fromArray( array() );
		$this->assertTrue( $r->isFailure() );
		$this->assertSame( '', $r->getError() );
	}

	// ── ConnectionResult ────────────────────────────────────

	public function test_connection_success_has_a_default_message(): void {
		$r = ConnectionResult::success();
		$this->assertTrue( $r->isSuccess() );
		$this->assertNotSame( '', $r->getMessage() );
	}

	public function test_connection_success_accepts_a_custom_message(): void {
		$this->assertSame( 'reachable', ConnectionResult::success( 'reachable' )->getMessage() );
	}

	public function test_connection_failure_keeps_its_message(): void {
		$r = ConnectionResult::failure( 'HTTP 403' );
		$this->assertTrue( $r->isFailure() );
		$this->assertFalse( $r->isSuccess() );
		$this->assertSame( 'HTTP 403', $r->getMessage() );
	}

	public function test_connection_to_array_shape(): void {
		$this->assertSame(
			array(
				'success' => true,
				'message' => 'ok',
			),
			ConnectionResult::success( 'ok' )->toArray()
		);
	}

	public function test_connection_round_trips_through_array(): void {
		$original = ConnectionResult::failure( 'timeout' );
		$restored = ConnectionResult::fromArray( $original->toArray() );
		$this->assertSame( $original->toArray(), $restored->toArray() );
	}

	public function test_connection_from_empty_array_defaults_to_failure(): void {
		$this->assertTrue( ConnectionResult::fromArray( array() )->isFailure() );
	}

	// ── UploadResult ────────────────────────────────────────

	public function test_upload_success_keeps_url_and_remote_path(): void {
		$r = UploadResult::success( 'https://cdn.example.com/a.jpg', '2026/01/a.jpg' );
		$this->assertTrue( $r->isSuccess() );
		$this->assertSame( 'https://cdn.example.com/a.jpg', $r->getUrl() );
		$this->assertSame( '2026/01/a.jpg', $r->getRemotePath() );
		$this->assertSame( '', $r->getError() );
	}

	public function test_upload_failure_has_no_url(): void {
		$r = UploadResult::failure( 'quota exceeded' );
		$this->assertTrue( $r->isFailure() );
		$this->assertSame( '', $r->getUrl() );
		$this->assertSame( '', $r->getRemotePath() );
		$this->assertSame( 'quota exceeded', $r->getError() );
	}

	/**
	 * The key is remote_path, not remotePath: the admin templates and the AJAX
	 * responses read the snake_case form.
	 */
	public function test_upload_to_array_uses_snake_case_remote_path(): void {
		$a = UploadResult::success( 'https://x/y.png', 'y.png' )->toArray();
		$this->assertArrayHasKey( 'remote_path', $a );
		$this->assertArrayNotHasKey( 'remotePath', $a );
		$this->assertSame( 'y.png', $a['remote_path'] );
	}

	public function test_upload_round_trips_through_array(): void {
		$original = UploadResult::success( 'https://x/y.png', 'y.png' );
		$restored = UploadResult::fromArray( $original->toArray() );
		$this->assertSame( $original->toArray(), $restored->toArray() );
	}

	public function test_upload_failure_round_trips_through_array(): void {
		$original = UploadResult::failure( 'bad credentials' );
		$restored = UploadResult::fromArray( $original->toArray() );
		$this->assertSame( $original->toArray(), $restored->toArray() );
	}

	public function test_upload_from_empty_array_defaults_to_failure(): void {
		$r = UploadResult::fromArray( array() );
		$this->assertTrue( $r->isFailure() );
		$this->assertSame( '', $r->getUrl() );
	}
}
