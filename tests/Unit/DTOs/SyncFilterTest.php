<?php
namespace Tests\Unit\DTOs;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\DTOs\SyncFilter;

/**
 * Unit tests for SyncFilter — decides which files a sync picks up.
 *
 * This is the one DTO with real branching, and the consequences of getting it
 * wrong are asymmetric: letting a file through that should be excluded uploads
 * something private to the cloud, while excluding one that should pass just
 * leaves it local. So the exclusion rules are tested individually and in the
 * order they are applied, not only through the happy path.
 *
 * Pure logic: no WordPress runtime needed.
 */
class SyncFilterTest extends TestCase {

	// ── Defaults ────────────────────────────────────────────

	public function test_defaults_allow_a_normal_media_file(): void {
		$f = new SyncFilter();
		$this->assertTrue( $f->shouldIncludeFile( '2026/01/photo.jpg', 1024 ) );
		$this->assertNull( $f->getExclusionReason( '2026/01/photo.jpg', 1024 ) );
	}

	public function test_defaults_allow_all_types(): void {
		$this->assertTrue( ( new SyncFilter() )->allowsAllTypes() );
	}

	public function test_defaults_have_no_size_limit(): void {
		$this->assertFalse( ( new SyncFilter() )->hasFileSizeLimit() );
	}

	// ── Size limit ──────────────────────────────────────────

	public function test_file_over_the_size_limit_is_excluded(): void {
		$f = new SyncFilter( '*', 1000 );
		$this->assertFalse( $f->shouldIncludeFile( 'big.jpg', 1001 ) );
	}

	public function test_file_exactly_at_the_size_limit_is_included(): void {
		$f = new SyncFilter( '*', 1000 );
		$this->assertTrue( $f->shouldIncludeFile( 'exact.jpg', 1000 ) );
	}

	public function test_zero_size_limit_means_unlimited(): void {
		$f = new SyncFilter( '*', 0 );
		$this->assertTrue( $f->shouldIncludeFile( 'huge.bin', PHP_INT_MAX ) );
		$this->assertFalse( $f->hasFileSizeLimit() );
	}

	public function test_size_exclusion_explains_itself(): void {
		$f = new SyncFilter( '*', 1000 );
		$this->assertStringContainsString( 'size', strtolower( (string) $f->getExclusionReason( 'big.jpg', 5000 ) ) );
	}

	public function test_negative_size_limit_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		new SyncFilter( '*', -1 );
	}

	// ── Excluded paths ──────────────────────────────────────

	public function test_path_matching_an_exclusion_is_excluded(): void {
		$f = new SyncFilter( '*', 0, array( 'backup/' ) );
		$this->assertFalse( $f->shouldIncludeFile( '2026/01/backup/old.jpg', 10 ) );
	}

	public function test_path_not_matching_an_exclusion_is_included(): void {
		$f = new SyncFilter( '*', 0, array( 'backup/' ) );
		$this->assertTrue( $f->shouldIncludeFile( '2026/01/photos/new.jpg', 10 ) );
	}

	public function test_path_exclusion_names_the_pattern(): void {
		$f = new SyncFilter( '*', 0, array( 'private' ) );
		$this->assertStringContainsString( 'private', (string) $f->getExclusionReason( 'a/private/b.jpg', 10 ) );
	}

	// ── Hidden files ────────────────────────────────────────

	public function test_hidden_files_are_excluded_by_default(): void {
		$f = new SyncFilter();
		$this->assertFalse( $f->shouldIncludeFile( '2026/01/.secret', 10 ) );
	}

	public function test_hidden_files_can_be_opted_in(): void {
		$f = new SyncFilter( '*', 0, array(), true );
		$this->assertTrue( $f->shouldIncludeFile( '2026/01/.secret', 10 ) );
	}

	/**
	 * Only a leading dot makes a file hidden — a dot in the middle does not.
	 */
	public function test_a_dot_inside_the_name_does_not_make_it_hidden(): void {
		$f = new SyncFilter();
		$this->assertTrue( $f->shouldIncludeFile( '2026/01/my.photo.jpg', 10 ) );
	}

	// ── System files ────────────────────────────────────────

	public function test_system_files_are_excluded_when_opted_out(): void {
		$f = new SyncFilter( '*', 0, array(), false, false );
		foreach ( array( 'index.php', 'wp-config.php', '.env', '.htaccess' ) as $name ) {
			$this->assertFalse( $f->shouldIncludeFile( 'dir/' . $name, 10 ), $name . ' should be excluded' );
		}
	}

	public function test_system_files_are_included_by_default(): void {
		$f = new SyncFilter();
		$this->assertTrue( $f->shouldIncludeFile( 'dir/index.php', 10 ) );
	}

	// ── Extension allow-list ────────────────────────────────

	public function test_extension_outside_the_allow_list_is_excluded(): void {
		$f = new SyncFilter( 'jpg,png' );
		$this->assertFalse( $f->shouldIncludeFile( 'doc.pdf', 10 ) );
	}

	public function test_extension_inside_the_allow_list_is_included(): void {
		$f = new SyncFilter( 'jpg,png' );
		$this->assertTrue( $f->shouldIncludeFile( 'photo.png', 10 ) );
	}

	public function test_extension_matching_is_case_insensitive(): void {
		$f = new SyncFilter( 'jpg,png' );
		$this->assertTrue( $f->shouldIncludeFile( 'PHOTO.PNG', 10 ) );
	}

	public function test_allow_list_tolerates_spaces(): void {
		$f = new SyncFilter( 'jpg, png , gif' );
		$this->assertTrue( $f->shouldIncludeFile( 'a.gif', 10 ) );
	}

	public function test_star_allows_any_extension(): void {
		$f = new SyncFilter( '*' );
		$this->assertTrue( $f->shouldIncludeFile( 'weird.xyz', 10 ) );
	}

	public function test_extension_allow_list_is_not_allows_all_types(): void {
		$this->assertFalse( ( new SyncFilter( 'jpg' ) )->allowsAllTypes() );
	}

	// ── Precedence ──────────────────────────────────────────

	/**
	 * Size is checked before everything else, so an oversized file reports the
	 * size reason even when it would also fail the extension check. The order
	 * matters for the message the user sees in the failed-files list.
	 */
	public function test_size_takes_precedence_over_extension(): void {
		$f = new SyncFilter( 'jpg', 100 );
		$this->assertStringContainsString( 'size', strtolower( (string) $f->getExclusionReason( 'doc.pdf', 5000 ) ) );
	}

	// ── Named constructors ──────────────────────────────────

	public function test_allow_all_includes_everything_normal(): void {
		$f = SyncFilter::allowAll();
		$this->assertTrue( $f->allowsAllTypes() );
		$this->assertFalse( $f->hasFileSizeLimit() );
	}

	public function test_media_only_rejects_a_non_media_extension(): void {
		$this->assertFalse( SyncFilter::mediaOnly()->shouldIncludeFile( 'script.php', 10 ) );
	}

	public function test_media_only_accepts_an_image(): void {
		$this->assertTrue( SyncFilter::mediaOnly()->shouldIncludeFile( 'photo.jpg', 10 ) );
	}

	public function test_from_config_reads_the_plugin_keys(): void {
		$f = SyncFilter::fromConfig(
			array(
				'allowed_file_types' => 'jpg',
				'max_file_size'      => 500,
			)
		);
		$this->assertTrue( $f->hasFileSizeLimit() );
		$this->assertSame( 500, $f->getMaxFileSize() );
		$this->assertSame( 'jpg', $f->getAllowedFileTypes() );
	}

	public function test_from_empty_config_falls_back_to_defaults(): void {
		$f = SyncFilter::fromConfig( array() );
		$this->assertTrue( $f->allowsAllTypes() );
	}

	// ── Serialisation ───────────────────────────────────────

	public function test_to_array_exposes_the_settings(): void {
		$a = ( new SyncFilter( 'jpg', 10, array( 'x/' ) ) )->toArray();
		$this->assertSame( 'jpg', $a['allowed_file_types'] );
		$this->assertSame( 10, $a['max_file_size'] );
		$this->assertSame( array( 'x/' ), $a['excluded_paths'] );
	}

	// ── From the Settings screen to the filter ──────────────

	/** The Maximum File Size the screen saves is the byte limit the initial sync filters on. */
	public function test_the_saved_maximum_file_size_is_the_limit_the_sync_filters_on(): void {
		$settings = \DiluxOneOffload\DTOs\PluginSettings::fromPost( array( 'max_file_size' => '2' ) );
		$f        = SyncFilter::fromConfig( array( 'max_file_size' => $settings->getMaxFileSize() ) );
		$this->assertTrue( $f->shouldIncludeFile( 'under.jpg', 2 * 1048576 ) );
		$this->assertFalse( $f->shouldIncludeFile( 'over.jpg', 2 * 1048576 + 1 ) );
		$this->assertStringContainsString( 'size', strtolower( (string) $f->getExclusionReason( 'over.jpg', 2 * 1048576 + 1 ) ) );
	}

	public function test_the_screen_clamps_the_maximum_file_size_to_one_and_five_hundred_megabytes(): void {
		$this->assertSame( 1048576, \DiluxOneOffload\DTOs\PluginSettings::fromPost( array( 'max_file_size' => '0' ) )->getMaxFileSize() );
		$this->assertSame( 500 * 1048576, \DiluxOneOffload\DTOs\PluginSettings::fromPost( array( 'max_file_size' => '9999' ) )->getMaxFileSize() );
	}
}
