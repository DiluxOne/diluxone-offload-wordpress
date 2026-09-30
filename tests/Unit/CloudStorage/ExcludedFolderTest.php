<?php
namespace Tests\Unit\CloudStorage;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\SyncManager;

/**
 * A folder the initial sync leaves out is a prefix of the path under
 * uploads/: that folder and what is inside it, never a namesake deeper down.
 */
class ExcludedFolderTest extends TestCase {

	public function test_a_file_inside_an_excluded_folder_is_left_out(): void {
		$folders = array( 'backups/', 'cache/tmp/' );
		$this->assertTrue( SyncManager::in_excluded_folder( 'backups/db.sql.gz', $folders ) );
		$this->assertTrue( SyncManager::in_excluded_folder( '/backups/2026/x.zip', $folders ) );
		$this->assertTrue( SyncManager::in_excluded_folder( 'cache/tmp/a.css', $folders ) );
	}

	public function test_a_namesake_deeper_in_the_tree_or_a_longer_name_is_not(): void {
		$folders = array( 'backups/', 'cache/tmp/' );
		$this->assertFalse( SyncManager::in_excluded_folder( '2026/09/backups/photo.jpg', $folders ) );
		$this->assertFalse( SyncManager::in_excluded_folder( 'backups-old/photo.jpg', $folders ) );
		$this->assertFalse( SyncManager::in_excluded_folder( 'cache/other.css', $folders ) );
		$this->assertFalse( SyncManager::in_excluded_folder( 'backups/x', array() ) );
	}
}
