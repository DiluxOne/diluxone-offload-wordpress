<?php
namespace Tests\Unit\CloudStorage;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\CloudStreamWrapper;
use DiluxOneOffload\DiluxOneOffloadDB;

/**
 * Every object key the plugin writes, lists or maps back goes through one
 * prefix. On a single site and on the main site of a network it is
 * `uploads`; on any other site of a network it is `uploads/sites/<id>`,
 * the layout WordPress uses on disk. That is what keeps two sites of a
 * network sharing one container from ever sharing a key.
 */
class KeyPrefixTest extends TestCase {

	protected function setUp(): void {
		require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';
		unset( $GLOBALS['_test_multisite'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_test_multisite'] );
	}

	public function test_a_single_site_uses_the_plain_prefix(): void {
		$this->assertSame( 'uploads', CloudStreamWrapper::key_prefix() );
		$this->assertSame( 'uploads/', DiluxOneOffloadDB::listing_prefix() );
		$this->assertSame( 'uploads/2026/09/a.jpg', DiluxOneOffloadDB::key_from_path( '/2026/09/a.jpg' ) );
		$this->assertSame( 'uploads/2026/09/a.jpg', DiluxOneOffloadDB::key_from_path( '2026/09/a.jpg' ), 'with or without the leading slash' );
		$this->assertSame( '/2026/09/a.jpg', DiluxOneOffloadDB::path_from_key( 'uploads/2026/09/a.jpg' ) );
	}

	public function test_the_main_site_of_a_network_uses_the_plain_prefix(): void {
		$GLOBALS['_test_multisite'] = array( 'enabled' => true, 'main' => true, 'blog_id' => 1 );
		$this->assertSame( 'uploads', CloudStreamWrapper::key_prefix() );
	}

	public function test_another_site_of_a_network_is_namespaced_by_its_id(): void {
		$GLOBALS['_test_multisite'] = array( 'enabled' => true, 'main' => false, 'blog_id' => 7 );
		$this->assertSame( 'uploads/sites/7', CloudStreamWrapper::key_prefix() );
		$this->assertSame( 'uploads/sites/7/', DiluxOneOffloadDB::listing_prefix() );
		$this->assertSame( 'uploads/sites/7/2026/09/a.jpg', DiluxOneOffloadDB::key_from_path( '/2026/09/a.jpg' ) );
		$this->assertSame( '/2026/09/a.jpg', DiluxOneOffloadDB::path_from_key( 'uploads/sites/7/2026/09/a.jpg' ) );
	}

	public function test_another_sites_key_never_maps_onto_this_sites_rows(): void {
		$GLOBALS['_test_multisite'] = array( 'enabled' => true, 'main' => false, 'blog_id' => 7 );
		$this->assertSame( '/uploads/2026/09/a.jpg', DiluxOneOffloadDB::path_from_key( 'uploads/2026/09/a.jpg' ), 'the main site key is not this site\'s /2026/09/a.jpg' );
		$this->assertSame( '/uploads/sites/8/2026/09/a.jpg', DiluxOneOffloadDB::path_from_key( 'uploads/sites/8/2026/09/a.jpg' ) );
	}

	public function test_key_and_path_round_trip(): void {
		foreach ( array( null, array( 'enabled' => true, 'main' => false, 'blog_id' => 3 ) ) as $layout ) {
			$GLOBALS['_test_multisite'] = $layout;
			foreach ( array( '/2026/09/a.jpg', '/2026/uploads/b.jpg', '/x.png' ) as $path ) {
				$this->assertSame( $path, DiluxOneOffloadDB::path_from_key( DiluxOneOffloadDB::key_from_path( $path ) ) );
			}
		}
	}

	public function test_a_single_site_owns_every_key_under_uploads(): void {
		$this->assertTrue( CloudStreamWrapper::owns_key( 'uploads/2026/09/a.jpg' ) );
		$this->assertFalse( CloudStreamWrapper::owns_key( 'other/2026/09/a.jpg' ) );
	}

	/** The main site's prefix is the parent of every other site's; a listing by prefix returns them too. */
	public function test_the_main_site_of_a_network_does_not_own_the_other_sites_keys(): void {
		$GLOBALS['_test_multisite'] = array( 'enabled' => true, 'main' => true, 'blog_id' => 1 );
		$this->assertTrue( CloudStreamWrapper::owns_key( 'uploads/2026/09/a.jpg' ) );
		$this->assertFalse( CloudStreamWrapper::owns_key( 'uploads/sites/2/2026/09/a.jpg' ) );
		$listing = array(
			array( 'path' => 'uploads/2026/09/mine.jpg', 'size' => 1 ),
			array( 'path' => 'uploads/sites/2/2026/09/theirs.jpg', 'size' => 1 ),
			array( 'path' => 'uploads/sites/33/x.jpg', 'size' => 1 ),
		);
		$this->assertSame( array( 'uploads/2026/09/mine.jpg' ), array_column( CloudStreamWrapper::site_files( $listing ), 'path' ) );
	}

	public function test_another_site_of_a_network_owns_only_its_own_prefix(): void {
		$GLOBALS['_test_multisite'] = array( 'enabled' => true, 'main' => false, 'blog_id' => 7 );
		$this->assertTrue( CloudStreamWrapper::owns_key( 'uploads/sites/7/2026/09/a.jpg' ) );
		$this->assertFalse( CloudStreamWrapper::owns_key( 'uploads/sites/77/2026/09/a.jpg' ), 'a prefix that merely starts the same' );
		$this->assertFalse( CloudStreamWrapper::owns_key( 'uploads/2026/09/a.jpg' ) );
	}

	public function test_only_the_leading_prefix_is_stripped(): void {
		$this->assertSame( '/2026/uploads/b.jpg', DiluxOneOffloadDB::path_from_key( 'uploads/2026/uploads/b.jpg' ) );
	}
}
