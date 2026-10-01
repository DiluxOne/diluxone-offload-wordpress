<?php
namespace Tests\Unit\Sync;

use DiluxOneOffload\ConfigManager;

/**
 * What the initial sync takes from uploads/ and what it leaves out, with
 * the reason the Sync screen shows; how the concurrency setting sizes a
 * round; and which names the reverse sync refuses to write back.
 */
class SyncManagerScanTest extends SyncTestCase {

	// ── Concurrency ─────────────────────────────────────────

	public function test_a_round_is_never_under_12_mb_and_grows_5_mb_per_slot(): void {
		$m = $this->manager();
		$this->assertSame( 25 * 1048576, $m->round_bytes(), 'the default 5 slots' );

		$m->set_parallel_uploads( 3 );
		$this->assertSame( 15 * 1048576, $m->round_bytes() );

		$m->set_parallel_uploads( 40 );
		$this->assertSame( 200 * 1048576, $m->round_bytes() );
	}

	/** @return array<string, array{int, int}> */
	public function batchSizes(): array {
		return array(
			'3 slots: the floor of 25' => array( 3, 25 ),
			'10 slots: five each'      => array( 10, 50 ),
			'40 slots: 200'            => array( 40, 200 ),
		);
	}

	/** @dataProvider batchSizes */
	public function test_the_batch_size_follows_the_concurrency( int $level, int $batch ): void {
		$m = $this->manager();
		$m->set_parallel_uploads( $level );
		$this->assertSame( $level, $this->get( $m, 'parallel_uploads' ) );
		$this->assertSame( $batch, $this->get( $m, 'batch_size' ) );
	}

	/** @return array<string, array{mixed}> */
	public function invalidLevels(): array {
		return array( 'two' => array( 2 ), 'forty-one' => array( 41 ), 'text' => array( 'fast' ) );
	}

	/**
	 * @dataProvider invalidLevels
	 * @param mixed $level
	 */
	public function test_a_concurrency_out_of_range_is_ignored( $level ): void {
		$m = $this->manager();
		$m->set_parallel_uploads( $level );
		$this->assertSame( 5, $this->get( $m, 'parallel_uploads' ) );
		$this->assertSame( 100, $this->get( $m, 'batch_size' ) );
	}

	// ── should_sync_file ────────────────────────────────────

	/** @return mixed */
	private function verdict( string $path, int $size, array $config = array() ) {
		$this->config( $config );
		return $this->call( $this->manager(), 'should_sync_file', array( $path, $size ) );
	}

	/** @return array<string, array{string, string}> */
	public function scratchFolders(): array {
		return array(
			'cache' => array( '/up/cache/a.jpg', 'Cache directories' ),
			'temp'  => array( '/up/temp/a.jpg', 'Temporary directories' ),
			'tmp'   => array( '/up/tmp/a.jpg', 'Temporary directories' ),
		);
	}

	/** @dataProvider scratchFolders */
	public function test_cache_and_temp_folders_are_never_synced( string $path, string $reason ): void {
		$this->assertSame( $reason, $this->verdict( $path, 10 ) );
	}

	public function test_a_file_over_the_maximum_size_is_skipped_with_both_sizes(): void {
		$this->assertSame( 'File size exceeds limit (200 bytes > 100 bytes)', $this->verdict( '/up/a.jpg', 200, array( 'max_file_size' => 100 ) ) );
		$this->assertTrue( $this->verdict( '/up/a.jpg', 100, array( 'max_file_size' => 100 ) ), 'exactly the limit is fine' );
	}

	public function test_a_hidden_file_is_skipped(): void {
		$this->assertSame( 'Hidden file (starts with .)', $this->verdict( '/up/.DS_Store', 10 ) );
	}

	public function test_with_every_type_allowed_system_files_go_too(): void {
		$this->assertTrue( $this->verdict( '/up/index.php', 10, array( 'allowed_file_types' => '*' ) ) );
	}

	public function test_with_a_type_list_other_extensions_and_system_files_are_skipped(): void {
		$config = array( 'allowed_file_types' => 'jpg, PNG' );
		$this->assertTrue( $this->verdict( '/up/a.png', 10, $config ) );
		$this->assertSame( 'File extension not allowed: .pdf', $this->verdict( '/up/a.pdf', 10, $config ) );
		$this->assertSame( 'System file', $this->verdict( '/up/index.php', 10, $config ) );
	}

	// ── scan_files_to_sync ──────────────────────────────────

	public function test_a_missing_uploads_directory_scans_nothing_and_records_nothing(): void {
		$GLOBALS['_test_wp_upload_dir'] = $this->uploads . '/missing';
		$m                              = new \DiluxOneOffload\SyncManager();
		rmdir( $this->uploads . '/missing' ); // wp_upload_dir() made it; the scan finds it gone.

		$this->assertSame( array(), $m->scan_files_to_sync( true ) );
		$this->assertNull( $this->option( ConfigManager::SKIPPED_OPTION ) );
	}

	public function test_the_scan_maps_each_file_to_its_key_and_skips_checksums_on_the_initial_sync(): void {
		$path = $this->file( '2026/09/photo.jpg', 'abcdef' );

		$files = $this->manager()->scan_files_to_sync( true );

		$this->assertSame(
			array( array( 'local_path' => $path, 'remote_path' => 'uploads/2026/09/photo.jpg', 'size' => 6, 'checksum' => null, 'is_initial_sync' => true ) ),
			$files
		);
		$skipped = $this->option( ConfigManager::SKIPPED_OPTION );
		$this->assertSame( 0, $skipped['total'] );
		$this->assertSame( array(), $skipped['reasons'] );
	}

	public function test_a_later_scan_carries_each_files_md5(): void {
		$this->file( 'a.txt', 'hello' );
		$files = $this->manager()->scan_files_to_sync( false );
		$this->assertSame( md5( 'hello' ), $files[0]['checksum'] );
		$this->assertFalse( $files[0]['is_initial_sync'] );
	}

	public function test_a_subsite_keys_its_files_under_its_own_prefix(): void {
		$GLOBALS['_test_multisite'] = array( 'enabled' => true, 'main' => false, 'blog_id' => 7 );
		$this->file( 'a.jpg' );
		$this->assertSame( 'uploads/sites/7/a.jpg', $this->manager()->scan_files_to_sync( true )[0]['remote_path'] );
	}

	public function test_what_is_left_out_is_counted_per_reason_with_its_paths(): void {
		$this->config( array( 'max_file_size' => 10, 'excluded_folders' => array( 'backups' ) ) );
		$this->file( 'keep.jpg', 'small' );
		$this->file( 'empty.txt', '' );
		$this->file( 'backups/db.sql', 'dump' );
		$this->file( 'big1.mov', str_repeat( 'b', 11 ) );
		$this->file( 'big2.mov', str_repeat( 'b', 50 ) );
		$this->file( 'cache/page.html', 'c' );
		$this->file( '.hidden', 'h' );

		$files = $this->manager()->scan_files_to_sync( true );

		$this->assertSame( array( 'uploads/keep.jpg' ), array_column( $files, 'remote_path' ) );
		$skipped = $this->option( ConfigManager::SKIPPED_OPTION );
		$this->assertSame( 6, $skipped['total'] );
		$this->assertSame( array( 'count' => 1, 'paths' => array( '/empty.txt' ) ), $skipped['reasons']['empty_file'] );
		$this->assertSame( array( 'count' => 1, 'paths' => array( '/backups/db.sql' ) ), $skipped['reasons']['excluded_folder'] );
		$this->assertSame( 2, $skipped['reasons']['File size exceeds limit']['count'], 'one group, not one per size' );
		$this->assertSame( 1, $skipped['reasons']['Cache directories']['count'] );
		$this->assertSame( 1, $skipped['reasons']['Hidden file (starts with .)']['count'] );
		$this->assertEqualsWithDelta( time(), $skipped['time'], 5 );
	}

	public function test_a_path_too_long_for_the_table_is_skipped_and_said(): void {
		$deep = implode( '/', array_fill( 0, 4, str_repeat( 'd', 200 ) ) ) . '/f.jpg';
		$this->file( $deep );

		$this->assertSame( array(), $this->manager()->scan_files_to_sync( true ) );
		$this->assertSame( 1, $this->option( ConfigManager::SKIPPED_OPTION )['reasons']['path_too_long']['count'] );
	}

	public function test_the_skipped_paths_stop_at_the_cap_and_the_count_does_not(): void {
		for ( $i = 0; $i <= \DiluxOneOffload\SyncManager::SKIPPED_PATHS_CAP; $i++ ) {
			$this->file( 'e/' . $i . '.txt', '' );
		}

		$this->manager()->scan_files_to_sync( true );

		$empty = $this->option( ConfigManager::SKIPPED_OPTION )['reasons']['empty_file'];
		$this->assertSame( 501, $empty['count'] );
		$this->assertCount( 500, $empty['paths'] );
	}

	public function test_the_main_site_of_a_network_leaves_the_other_sites_folders_alone(): void {
		$GLOBALS['_test_multisite'] = array( 'enabled' => true, 'main' => true, 'blog_id' => 1 );
		$this->file( 'own.jpg' );
		$this->file( 'sites/2/theirs.jpg' );
		$this->file( 'blogs.dir/3/old.jpg' );
		$this->file( '2026/sites/mine.jpg' );

		$keys = array_column( $this->manager()->scan_files_to_sync( true ), 'remote_path' );
		sort( $keys );

		$this->assertSame( array( 'uploads/2026/sites/mine.jpg', 'uploads/own.jpg' ), $keys, 'only the top-level sites/ and blogs.dir/ are other sites\'' );
		$this->assertSame( 0, $this->option( ConfigManager::SKIPPED_OPTION )['total'], 'they are not "skipped" either: they are not this site\'s' );
	}

	public function test_a_library_whose_own_path_has_a_tmp_folder_is_still_synced(): void {
		// An uploads directory under /tmp/ (or /temp/, /cache/ anywhere in the
		// server path, e.g. /srv/cache/site/wp-content/uploads).
		$base = sys_get_temp_dir() . '/dlx-site-' . bin2hex( random_bytes( 4 ) ) . '/uploads';
		mkdir( $base . '/2026', 0777, true );
		file_put_contents( $base . '/2026/a.jpg', 'x' );
		$GLOBALS['_test_wp_upload_dir'] = $base;
		try {
			$files   = $this->manager()->scan_files_to_sync( true );
			$skipped = $this->option( ConfigManager::SKIPPED_OPTION );
		} finally {
			unlink( $base . '/2026/a.jpg' );
			rmdir( $base . '/2026' );
			rmdir( $base );
			rmdir( dirname( $base ) );
		}

		$this->assertSame( 'uploads/2026/a.jpg', $files[0]['remote_path'] );
	}

	public function test_a_single_site_syncs_a_folder_named_sites(): void {
		$this->file( 'sites/2/a.jpg' );
		$this->assertCount( 1, $this->manager()->scan_files_to_sync( true ) );
	}

	// ── scan_local_files ────────────────────────────────────

	public function test_scanning_without_a_provider_is_refused(): void {
		$this->assertSame( array( 'success' => false, 'message' => 'Cloud client not configured' ), $this->manager( false )->scan_local_files() );
	}

	public function test_scanning_an_empty_library_leaves_the_table_alone(): void {
		$this->assertSame( array( 'success' => true, 'message' => 'No files to sync', 'total_files' => 0 ), $this->manager()->scan_local_files() );
		$this->assertSame( array(), $this->db->queries() );
	}

	public function test_scanning_replaces_the_table_with_the_files_found(): void {
		$this->file( '2026/a.jpg', 'aa' );
		$this->file( 'b.png', 'bbb' );

		$result = $this->manager()->scan_local_files();

		$this->assertSame( array( 'success' => true, 'message' => 'Files scanned', 'total_files' => 2 ), $result );
		$queries = $this->db->queries();
		$this->assertStringStartsWith( 'TRUNCATE TABLE', $queries[0], 'the previous scan goes first' );
		$args = $this->db->prepared[0]['args'];
		$rows = array_combine( array( $args[0], $args[2] ), array( $args[1], $args[3] ) );
		ksort( $rows );
		$this->assertSame( array( '/2026/a.jpg' => 2, '/b.png' => 3 ), $rows, 'rows keyed by the path below uploads/, leading slash included' );
	}

	public function test_scanning_inserts_in_batches_of_500(): void {
		for ( $i = 0; $i < 501; $i++ ) {
			$this->file( 'many/' . $i . '.jpg' );
		}

		$this->assertSame( 501, $this->manager()->scan_local_files()['total_files'] );

		$inserts = array_values( array_filter( $this->db->prepared, static fn( $p ) => false !== strpos( $p['query'], 'INSERT INTO' ) ) );
		$this->assertCount( 2, $inserts );
		$this->assertCount( 1000, $inserts[0]['args'] );
		$this->assertCount( 2, $inserts[1]['args'] );
	}

	// ── is_restorable_file ──────────────────────────────────

	/** @return array<string, array{string, bool}> */
	public function restorable(): array {
		return array(
			'an image'                => array( '/2026/09/photo.jpg', true ),
			'a pdf'                   => array( '/doc.pdf', true ),
			'no extension'            => array( '/README', true ),
			'a dot file not blocked'  => array( '/.well-known', true ),
			'php'                     => array( '/a.php', false ),
			'PHP in capitals'         => array( '/A.PHP', false ),
			'phtml'                   => array( '/a.phtml', false ),
			'phar'                    => array( '/a.phar', false ),
			'a double extension'      => array( '/shell.php.jpg', false ),
			'javascript'              => array( '/app.js', false ),
			'html'                    => array( '/page.html', false ),
			'a shell script'          => array( '/run.sh', false ),
			'a windows executable'    => array( '/setup.exe', false ),
			'.htaccess'               => array( '/2026/.htaccess', false ),
			'.htpasswd'               => array( '/.htpasswd', false ),
			'.user.ini'               => array( '/.user.ini', false ),
			'a backslashed php'       => array( '\\dir\\x.php', false ),
			'an empty name'           => array( '/', false ),
		);
	}

	/** @dataProvider restorable */
	public function test_which_names_the_reverse_sync_writes_back( string $path, bool $expected ): void {
		$m = new \ReflectionMethod( \DiluxOneOffload\SyncManager::class, 'is_restorable_file' );
		if ( PHP_VERSION_ID < 80100 ) {
			$m->setAccessible( true );
		}
		$this->assertSame( $expected, $m->invoke( null, $path ) );
	}
}
