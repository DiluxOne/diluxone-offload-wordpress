<?php
namespace Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\Enums\SyncStatus;

class SyncStatusTest extends TestCase {

	public function test_status_validity(): void {
		$this->assertTrue( SyncStatus::isValid( SyncStatus::COMPLETED ) );
		$this->assertFalse( SyncStatus::isValid( 'nonsense' ) );
		$this->assertFalse( SyncStatus::isValid( '' ) );
	}

	public function test_in_progress_only_for_running_states(): void {
		$this->assertTrue( SyncStatus::isInProgress( SyncStatus::STARTED ) );
		$this->assertFalse( SyncStatus::isInProgress( SyncStatus::COMPLETED ) );
		$this->assertFalse( SyncStatus::isInProgress( SyncStatus::IDLE ) );
	}
}
