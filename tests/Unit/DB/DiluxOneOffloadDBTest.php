<?php
namespace Tests\Unit\DB;

use DiluxOneOffload\DiluxOneOffloadDB;
use DiluxOneOffload\DTOs\ChunkedUpload;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\FakeWpdb;

// The plugin loads the DB class with require_once where it needs it.
require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';

/**
 * The PHP around the tracking table's queries: which rows a round takes,
 * how the statistics come out of what the database hands back, which
 * values are written, what is refused before it reaches the database, and
 * where the long upload names live. The queries themselves are answered by
 * a scripted $wpdb; whether MySQL agrees with them is the integration
 * suite's business.
 */
class DiluxOneOffloadDBTest extends TestCase {

	private FakeWpdb $db;

	/** @var mixed */
	private $previous_wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->previous_wpdb            = $GLOBALS['wpdb'] ?? null;
		$this->db                       = new FakeWpdb();
		$GLOBALS['wpdb']                = $this->db;
		$GLOBALS['_test_wp_options']    = array();
		$GLOBALS['_test_wp_http_log']   = array();
		$GLOBALS['_test_wp_transients'] = array();
		unset( $GLOBALS['_test_wp_http'] );
	}

	protected function tearDown(): void {
		if ( null === $this->previous_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		}
		unset( $GLOBALS['_test_wp_options'], $GLOBALS['_test_wp_http'], $GLOBALS['_test_wp_http_log'], $GLOBALS['_test_wp_transients'] );
		parent::tearDown();
	}

	private const TABLE = 'wp_diluxone_offload_files';

	// ── Table name and key width ────────────────────────────

	public function test_the_table_carries_the_site_prefix(): void {
		$this->db->prefix = 'wp_3_';
		$this->assertSame( 'wp_3_diluxone_offload_files', DiluxOneOffloadDB::get_table_name() );
	}

	public function test_a_path_fits_the_key_up_to_767_bytes_and_not_one_more(): void {
		$this->assertTrue( DiluxOneOffloadDB::fits_key( '/' . str_repeat( 'a', 766 ) ) );
		$this->assertFalse( DiluxOneOffloadDB::fits_key( '/' . str_repeat( 'a', 767 ) ) );
		// Bytes, not characters: 384 two-byte characters are 768 bytes.
		$this->assertFalse( DiluxOneOffloadDB::fits_key( str_repeat( 'ñ', 384 ) ) );
	}

	// ── table_exists / create_files_table's migration ───────

	public function test_table_exists_compares_the_name_case_insensitively(): void {
		$this->db->prefix = 'WP_';
		$this->db->on( 'get_var', '/SHOW TABLES/', 'wp_diluxone_offload_files' );
		$this->assertTrue( DiluxOneOffloadDB::table_exists(), 'lower_case_table_names=1 reports it in lowercase' );
		$this->assertSame( array( 'WP\_diluxone\_offload\_files' ), $this->db->prepared[0]['args'], 'the name is escaped for LIKE' );
	}

	public function test_table_exists_is_false_when_the_database_names_nothing(): void {
		$this->assertFalse( DiluxOneOffloadDB::table_exists() );
	}

	public function test_a_legacy_table_whose_long_rows_cannot_be_dropped_is_not_migrated_nor_recorded(): void {
		$this->db->on( 'get_var', '/SHOW TABLES/', self::TABLE );
		$this->db->on( 'get_row', '/SHOW COLUMNS/', (object) array( 'Type' => 'varchar(191)' ) );
		$this->db->on( 'query', '/^DELETE FROM/', false );

		$this->assertFalse( DiluxOneOffloadDB::create_files_table() );

		$this->assertCount( 1, $this->db->queries(), 'no ALTER after the DELETE failed' );
		$this->assertSame( array( 767 ), end( $this->db->prepared )['args'], 'rows longer than the new column go first' );
		$this->assertArrayNotHasKey( DiluxOneOffloadDB::TABLE_VERSION_OPTION, $GLOBALS['_test_wp_options'] );
	}

	public function test_a_legacy_table_whose_key_cannot_be_rebuilt_is_not_recorded(): void {
		$this->db->on( 'get_var', '/SHOW TABLES/', self::TABLE );
		$this->db->on( 'get_row', '/SHOW COLUMNS/', (object) array( 'Type' => 'VARCHAR(500)' ) );
		$this->db->on( 'query', '/^ALTER TABLE/', false );

		$this->assertFalse( DiluxOneOffloadDB::create_files_table() );

		$queries = $this->db->queries();
		$this->assertCount( 2, $queries );
		$this->assertStringContainsString( 'VARBINARY(767)', $queries[1] );
		$this->assertArrayNotHasKey( DiluxOneOffloadDB::TABLE_VERSION_OPTION, $GLOBALS['_test_wp_options'] );
	}

	// ── Adding rows ─────────────────────────────────────────

	public function test_add_file_writes_a_fresh_pending_row(): void {
		$this->assertTrue( DiluxOneOffloadDB::add_file( '/2026/09/a.jpg', 1234 ) );

		$replace = $this->db->callsOf( 'replace' )[0];
		$this->assertSame( self::TABLE, $replace['sql'] );
		$this->assertSame(
			array( 'file' => '/2026/09/a.jpg', 'size' => 1234, 'synced' => 0, 'deleted' => 0, 'transferred' => 0, 'errors' => 0, 'upload_id' => null ),
			$replace['data']
		);
	}

	public function test_add_file_refuses_a_path_the_key_cannot_hold_without_asking_the_database(): void {
		$this->assertFalse( DiluxOneOffloadDB::add_file( '/' . str_repeat( 'x', 800 ), 1 ) );
		$this->assertSame( array(), $this->db->calls );
	}

	public function test_add_file_reports_a_failed_write(): void {
		$this->db->on( 'replace', '/./', false );
		$this->assertFalse( DiluxOneOffloadDB::add_file( '/a.jpg', 1 ) );
	}

	public function test_add_cloud_only_file_records_a_synced_file_missing_locally(): void {
		$this->assertTrue( DiluxOneOffloadDB::add_cloud_only_file( '/b.png', 99 ) );
		$data = $this->db->callsOf( 'replace' )[0]['data'];
		$this->assertSame( 1, $data['synced'] );
		$this->assertSame( 1, $data['deleted'] );
		$this->assertSame( 99, $data['transferred'] );

		$this->assertFalse( DiluxOneOffloadDB::add_cloud_only_file( str_repeat( 'y', 768 ), 1 ) );
		$this->db->on( 'replace', '/./', false );
		$this->assertFalse( DiluxOneOffloadDB::add_cloud_only_file( '/c.png', 1 ) );
	}

	public function test_an_empty_batch_touches_nothing(): void {
		$this->assertTrue( DiluxOneOffloadDB::add_files_batch( array() ) );
		$this->assertTrue( DiluxOneOffloadDB::add_cloud_only_files_batch( array() ) );
		$this->assertSame( array(), $this->db->calls );
	}

	public function test_a_batch_leaves_out_paths_too_long_for_the_key_and_inserts_the_rest(): void {
		$long = '/' . str_repeat( 'z', 800 );
		$this->assertTrue(
			DiluxOneOffloadDB::add_files_batch(
				array(
					array( 'path' => '/a.jpg', 'size' => 10 ),
					array( 'path' => $long, 'size' => 20 ),
					array( 'path' => '/b.jpg', 'size' => 30 ),
				)
			)
		);

		$this->assertSame( array( '/a.jpg', 10, '/b.jpg', 30 ), $this->db->prepared[0]['args'] );
		$this->assertSame( 2, substr_count( $this->db->prepared[0]['query'], '(%s, %d, 0, 0, 0, 0, NULL)' ) );
		$this->assertCount( 1, $this->db->queries() );
	}

	public function test_a_batch_of_only_long_paths_runs_no_query(): void {
		$this->assertTrue( DiluxOneOffloadDB::add_files_batch( array( array( 'path' => str_repeat( 'q', 900 ), 'size' => 1 ) ) ) );
		$this->assertTrue( DiluxOneOffloadDB::add_cloud_only_files_batch( array( array( 'path' => str_repeat( 'q', 900 ), 'size' => 1 ) ) ) );
		$this->assertSame( array(), $this->db->queries() );
	}

	public function test_a_failed_batch_insert_is_reported(): void {
		$this->db->on( 'query', '/INSERT INTO/', false );
		$this->assertFalse( DiluxOneOffloadDB::add_files_batch( array( array( 'path' => '/a', 'size' => 1 ) ) ) );
		$this->assertFalse( DiluxOneOffloadDB::add_cloud_only_files_batch( array( array( 'path' => '/a', 'size' => 1 ) ) ) );
	}

	public function test_a_cloud_only_batch_counts_each_file_as_fully_transferred(): void {
		$this->assertTrue(
			DiluxOneOffloadDB::add_cloud_only_files_batch(
				array(
					array( 'path' => '/a.jpg', 'size' => 5 ),
					array( 'path' => str_repeat( 'l', 768 ), 'size' => 6 ),
				)
			)
		);
		$this->assertSame( array( '/a.jpg', 5, 5 ), $this->db->prepared[0]['args'], 'path, size, transferred = size' );
	}

	// ── Marking rows ────────────────────────────────────────

	public function test_mark_synced_records_the_whole_size_as_transferred_and_clears_the_upload(): void {
		$this->db->on( 'get_var', '/SELECT size/', '4096' );

		$this->assertSame( 1, DiluxOneOffloadDB::mark_synced( '/a.mov' ) );

		$update = $this->db->callsOf( 'update' )[0];
		$this->assertSame( array( 'synced' => 1, 'transferred' => '4096', 'errors' => 0, 'upload_id' => null ), $update['data'] );
		$this->assertSame( array( 'file' => '/a.mov' ), $update['where'] );
	}

	public function test_mark_synced_drops_the_long_upload_name_its_row_pointed_at(): void {
		$GLOBALS['_test_wp_options'][ DiluxOneOffloadDB::LONG_UPLOAD_NAMES_OPTION ] = array( 'abc' => 'LONG', 'other' => 'KEEP' );
		$this->db->on( 'get_var', '/SELECT upload_id/', 'v1|10|20|#abc' );

		DiluxOneOffloadDB::mark_synced( '/a.mov' );

		$this->assertSame( array( 'other' => 'KEEP' ), get_option( DiluxOneOffloadDB::LONG_UPLOAD_NAMES_OPTION ) );
	}

	public function test_mark_synced_hands_back_a_failed_or_empty_update(): void {
		$this->db->onSequence( 'update', '/./', array( false, 0 ) );
		$this->assertFalse( DiluxOneOffloadDB::mark_synced( '/gone' ) );
		$this->assertSame( 0, DiluxOneOffloadDB::mark_synced( '/gone' ) );
	}

	public function test_mark_downloaded_makes_the_file_local_again(): void {
		$this->db->on( 'get_var', '/SELECT size/', '77' );
		DiluxOneOffloadDB::mark_downloaded( '/x.jpg' );
		$data = $this->db->callsOf( 'update' )[0]['data'];
		$this->assertSame( 0, $data['deleted'] );
		$this->assertSame( 1, $data['synced'] );
		$this->assertSame( '77', $data['transferred'] );
	}

	public function test_increment_error_keeps_the_message_only_when_there_is_one(): void {
		DiluxOneOffloadDB::increment_error( '/a.jpg', 'HTTP 500' );
		DiluxOneOffloadDB::increment_error( '/b.jpg' );

		$this->assertSame( array( 'HTTP 500', '/a.jpg' ), $this->db->prepared[0]['args'] );
		$this->assertStringContainsString( 'error_message', $this->db->prepared[0]['query'] );
		$this->assertSame( array( '/b.jpg' ), $this->db->prepared[1]['args'] );
		$this->assertStringNotContainsString( 'error_message', $this->db->prepared[1]['query'] );
	}

	public function test_progress_and_refund_address_the_row(): void {
		DiluxOneOffloadDB::update_progress( '/a.jpg', 512 );
		DiluxOneOffloadDB::refund_attempt( '/b.jpg' );
		$this->assertSame( array( 512, '/a.jpg' ), $this->db->prepared[0]['args'] );
		$this->assertSame( array( '/b.jpg' ), $this->db->prepared[1]['args'] );
		$this->assertStringContainsString( 'GREATEST( errors - 1, 0 )', $this->db->prepared[1]['query'], 'never below zero' );
	}

	// ── Unfinished uploads ──────────────────────────────────

	public function test_remember_upload_keeps_a_short_name_in_the_row_itself(): void {
		$upload = new ChunkedUpload( '/tmp/a', 'uploads/a', 100, 50, 'UP1' );

		DiluxOneOffloadDB::remember_upload( '/a', $upload, 1700000000 );

		$update = $this->db->callsOf( 'update' )[0];
		$this->assertSame( array( 'upload_id' => 'v1|100|1700000000|UP1' ), $update['data'] );
		$this->assertArrayNotHasKey( DiluxOneOffloadDB::LONG_UPLOAD_NAMES_OPTION, $GLOBALS['_test_wp_options'] );
	}

	public function test_a_name_too_long_for_the_row_is_kept_by_its_sha1_and_found_again(): void {
		$long   = str_repeat( 'R2', 150 );
		$upload = new ChunkedUpload( '/tmp/a', 'uploads/a', 100, 50, $long );

		DiluxOneOffloadDB::remember_upload( '/a', $upload, 5 );

		$token = $this->db->callsOf( 'update' )[0]['data']['upload_id'];
		$this->assertSame( 'v1|100|5|#' . sha1( $long ), $token );
		$this->assertSame( array( sha1( $long ) => $long ), get_option( DiluxOneOffloadDB::LONG_UPLOAD_NAMES_OPTION ) );
		$this->assertSame( $long, DiluxOneOffloadDB::upload_name_of( $token ) );
		$this->assertSame( $long, DiluxOneOffloadDB::resumable_upload_name( $token, 100, 5 ) );
	}

	public function test_upload_name_of_reads_every_kind_of_token(): void {
		$this->assertNull( DiluxOneOffloadDB::upload_name_of( null ) );
		$this->assertNull( DiluxOneOffloadDB::upload_name_of( 'not a token' ) );
		$this->assertSame( 'UP1', DiluxOneOffloadDB::upload_name_of( 'v1|1|2|UP1' ) );
		$this->assertSame( '#deadbeef', DiluxOneOffloadDB::upload_name_of( 'v1|1|2|#deadbeef' ), 'a name not kept stays in its # form, for the provider to look up' );
	}

	public function test_a_file_that_changed_since_is_not_resumed(): void {
		$token = 'v1|100|5|UP1';
		$this->assertSame( 'UP1', DiluxOneOffloadDB::resumable_upload_name( $token, 100, 5 ) );
		$this->assertNull( DiluxOneOffloadDB::resumable_upload_name( $token, 101, 5 ), 'other size' );
		$this->assertNull( DiluxOneOffloadDB::resumable_upload_name( $token, 100, 6 ), 'other mtime' );
		$this->assertNull( DiluxOneOffloadDB::resumable_upload_name( null, 100, 5 ) );
	}

	public function test_forget_upload_clears_the_row_and_its_long_name(): void {
		$GLOBALS['_test_wp_options'][ DiluxOneOffloadDB::LONG_UPLOAD_NAMES_OPTION ] = array( 'h1' => 'NAME' );
		$this->db->on( 'get_var', '/SELECT upload_id/', 'v1|1|2|#h1' );

		DiluxOneOffloadDB::forget_upload( '/big.mov' );

		$this->assertSame( array(), get_option( DiluxOneOffloadDB::LONG_UPLOAD_NAMES_OPTION ) );
		$update = $this->db->callsOf( 'update' )[0];
		$this->assertSame( array( 'upload_id' => null ), $update['data'] );
		$this->assertSame( array( 'file' => '/big.mov' ), $update['where'] );
	}

	public function test_forget_upload_leaves_the_names_alone_when_the_row_has_no_token(): void {
		$GLOBALS['_test_wp_options'][ DiluxOneOffloadDB::LONG_UPLOAD_NAMES_OPTION ] = array( 'h1' => 'NAME' );
		DiluxOneOffloadDB::forget_upload( '/small.jpg' );
		$this->assertSame( array( 'h1' => 'NAME' ), get_option( DiluxOneOffloadDB::LONG_UPLOAD_NAMES_OPTION ) );
	}

	/** An S3 provider configured in the options, so get_cloud_client() builds one. */
	private function configure_s3(): void {
		$GLOBALS['_test_wp_options']['diluxone_offload_config'] = array(
			'cloud_provider'  => 's3',
			'provider_config' => array(
				'preset'            => 'custom',
				'endpoint'          => 'https://s3.example.com',
				'region'            => 'us-east-1',
				'bucket'            => 'media',
				'access_key_id'     => 'AKIDUNIT',
				'secret_access_key' => 'secret-unit',
				'public_url'        => 'https://cdn.example.com',
				'path_style'        => true,
			),
		);
		$GLOBALS['_test_wp_http'] = static fn() => array( 'response' => array( 'code' => 204, 'message' => '' ), 'body' => '', 'headers' => array() );
	}

	public function test_clearing_the_table_aborts_the_uploads_its_rows_left_half_sent(): void {
		$this->configure_s3();
		$GLOBALS['_test_wp_options'][ DiluxOneOffloadDB::LONG_UPLOAD_NAMES_OPTION ] = array( 'h' => 'KEPT' );
		$this->db->on(
			'get_results',
			'/upload_id IS NOT NULL/',
			array(
				array( 'file' => '/2026/big.mov', 'size' => '20000000', 'upload_id' => 'v1|20000000|5|U-1' ),
				array( 'file' => '/empty.bin', 'size' => '0', 'upload_id' => 'v1|0|5|U-2' ),
			)
		);

		$this->assertTrue( DiluxOneOffloadDB::clear_table() );

		$aborts = array_values( array_filter( $GLOBALS['_test_wp_http_log'], static fn( $r ) => 'DELETE' === $r['method'] ) );
		$this->assertCount( 1, $aborts, 'a row of size 0 has no upload to abort' );
		$this->assertSame( 'https://s3.example.com/media/uploads/2026/big.mov?uploadId=U-1', $aborts[0]['url'] );
		$this->assertArrayNotHasKey( DiluxOneOffloadDB::LONG_UPLOAD_NAMES_OPTION, $GLOBALS['_test_wp_options'] );
		$this->assertSame( array( 'TRUNCATE TABLE ' . self::TABLE ), $this->db->queries() );
	}

	public function test_without_a_provider_the_unfinished_uploads_are_left_to_the_lifecycle_rule(): void {
		$this->db->on( 'get_results', '/upload_id IS NOT NULL/', array( array( 'file' => '/a', 'size' => '9', 'upload_id' => 'v1|9|1|U' ) ) );
		DiluxOneOffloadDB::abandon_unfinished_uploads();
		$this->assertSame( array(), $GLOBALS['_test_wp_http_log'] );
	}

	public function test_a_failed_truncate_is_reported(): void {
		$this->db->on( 'query', '/TRUNCATE/', false );
		$this->assertFalse( DiluxOneOffloadDB::clear_table() );
	}

	public function test_discarding_unsynced_files_abandons_only_their_uploads(): void {
		$this->db->on( 'query', '/^DELETE FROM/', 4 );
		$this->assertSame( 4, DiluxOneOffloadDB::discard_unsynced_files() );
		$select = $this->db->callsOf( 'get_results' )[0]['sql'];
		$this->assertStringContainsString( 'AND synced = 0 AND deleted = 0', $select );
	}

	// ── get_pending_files: the next upload round ────────────

	/** @return array<int, array<string, mixed>> */
	private static function rows( array $sizes ): array {
		$rows = array();
		foreach ( $sizes as $name => $size ) {
			$rows[] = array( 'file' => '/' . $name, 'size' => $size, 'transferred' => 0, 'errors' => 0, 'upload_id' => null );
		}
		return $rows;
	}

	public function test_without_a_byte_cap_the_round_is_what_the_query_returned(): void {
		$rows = self::rows( array( 'a' => 5, 'b' => 3 ) );
		$this->db->on( 'get_results', '/size DESC/', $rows );
		$this->assertSame( $rows, DiluxOneOffloadDB::get_pending_files( 50 ) );
		$this->assertSame( array( 50 ), $this->db->prepared[0]['args'] );
	}

	public function test_the_round_takes_the_largest_files_up_to_the_cap(): void {
		$this->db->on( 'get_results', '/size DESC/', self::rows( array( 'a' => 60, 'b' => 30, 'c' => 20, 'd' => 5 ) ) );

		$files = DiluxOneOffloadDB::get_pending_files( 1000, 100 );

		$this->assertSame( array( '/a', '/b' ), array_column( $files, 'file' ), 'a + b = 90; adding c would pass 100' );
	}

	public function test_a_file_larger_than_the_cap_still_goes_alone(): void {
		$this->db->on( 'get_results', '/size DESC/', self::rows( array( 'huge' => 500, 'b' => 1 ) ) );
		$this->assertSame( array( '/huge' ), array_column( DiluxOneOffloadDB::get_pending_files( 1000, 100 ), 'file' ) );
	}

	public function test_a_round_short_of_files_is_filled_with_the_smallest_up_to_a_slots_share(): void {
		$this->db->on( 'get_results', '/size DESC/', self::rows( array( 'video' => 90, 'b' => 40 ) ) );
		$this->db->on( 'get_results', '/size ASC/', self::rows( array( 'tiny' => 1, 'video' => 90, 'small' => 2, 'mid' => 3 ) ) );

		$files = DiluxOneOffloadDB::get_pending_files( 1000, 100, 3 );

		$this->assertSame( array( '/video', '/tiny', '/small' ), array_column( $files, 'file' ), 'no file twice, stops at three' );
		$fill = $this->db->prepared[1]['args'];
		$this->assertSame( array( 33, 3 ), $fill, 'only files of 100/3 bytes or less, three at most' );
	}

	public function test_the_fill_never_asks_for_more_files_than_the_limit(): void {
		$this->db->on( 'get_results', '/size DESC/', self::rows( array( 'video' => 90 ) ) );
		$this->db->on( 'get_results', '/size ASC/', self::rows( array( 'a' => 1, 'b' => 1 ) ) );

		$files = DiluxOneOffloadDB::get_pending_files( 2, 100, 10 );

		$this->assertCount( 2, $files );
		$this->assertSame( array( 50, 2 ), $this->db->prepared[1]['args'] );
	}

	public function test_a_round_with_enough_files_runs_no_fill_query(): void {
		$this->db->on( 'get_results', '/size DESC/', self::rows( array( 'a' => 1, 'b' => 1, 'c' => 1 ) ) );
		DiluxOneOffloadDB::get_pending_files( 1000, 100, 3 );
		$this->assertCount( 1, $this->db->callsOf( 'get_results' ) );
	}

	public function test_nothing_pending_is_an_empty_round(): void {
		$this->assertSame( array(), DiluxOneOffloadDB::get_pending_files( 1000, 100, 5 ) );
	}

	// ── Statistics and lists ────────────────────────────────

	public function test_stats_of_a_missing_table_are_zeroes(): void {
		$this->assertSame(
			array( 'total_files' => 0, 'total_size' => 0, 'synced_files' => 0, 'synced_size' => 0, 'failed_files' => 0, 'total_transferred' => 0, 'pending_files' => 0, 'percentage' => 0 ),
			DiluxOneOffloadDB::get_stats()
		);
	}

	public function test_the_nulls_of_an_empty_table_become_zeroes(): void {
		$this->db->on( 'get_row', '/COUNT/', array( 'total_files' => '0', 'total_size' => '0', 'synced_files' => null, 'synced_size' => '0', 'failed_files' => null, 'total_transferred' => '0', 'pending_files' => null ) );
		$stats = DiluxOneOffloadDB::get_stats();
		$this->assertSame( 0, $stats['synced_files'] );
		$this->assertSame( 0, $stats['failed_files'] );
		$this->assertSame( 0, $stats['pending_files'] );
		$this->assertSame( 0, $stats['percentage'] );
	}

	public function test_the_percentage_is_synced_over_total_to_one_decimal(): void {
		$this->db->on( 'get_row', '/COUNT/', array( 'total_files' => '3', 'synced_files' => '2', 'pending_files' => '1' ) );
		$stats = DiluxOneOffloadDB::get_stats();
		$this->assertSame( 66.7, $stats['percentage'] );
		$this->assertSame( '1', $stats['pending_files'] );
		$this->assertSame( 0, $stats['total_size'], 'a column missing from the row gets its default' );
	}

	public function test_failed_files_are_all_of_them_or_the_first_page(): void {
		$rows = array( array( 'file' => '/a', 'size' => 1, 'errors' => 3, 'error_message' => 'x' ) );
		$this->db->on( 'get_results', '/synced = 0 AND deleted = 0/', $rows );

		$this->assertSame( $rows, DiluxOneOffloadDB::get_failed_files() );
		$this->assertSame( array(), $this->db->prepared, 'no LIMIT without a limit' );

		DiluxOneOffloadDB::get_failed_files( 25 );
		$this->assertSame( array( 25 ), $this->db->prepared[0]['args'] );
		$this->assertStringEndsWith( 'LIMIT 25', $this->db->callsOf( 'get_results' )[1]['sql'] );
	}

	public function test_failed_files_of_a_failed_query_are_an_empty_list(): void {
		$this->db->on( 'get_results', '/./', null );
		$this->assertSame( array(), DiluxOneOffloadDB::get_failed_files() );
	}

	public function test_counts_come_back_as_integers_and_flags_as_booleans(): void {
		$this->db->on( 'get_var', '/synced = 0 AND deleted = 0/', '7' );
		$this->db->on( 'get_var', '/synced = 0 AND errors < 3/', '0' );
		$this->db->on( 'get_var', '/synced = 1 AND deleted = 1/', '2' );
		$this->db->on( 'get_var', '/^SELECT COUNT\(\*\) FROM/', '11' );

		$this->assertSame( 7, DiluxOneOffloadDB::count_failed_files() );
		$this->assertFalse( DiluxOneOffloadDB::has_pending_files() );
		$this->assertTrue( DiluxOneOffloadDB::has_deleted_files() );
		$this->assertSame( 2, DiluxOneOffloadDB::count_deleted_files() );
		$this->assertSame( 11, DiluxOneOffloadDB::get_total_count() );
	}

	public function test_pending_files_exist_while_the_count_is_positive(): void {
		$this->db->on( 'get_var', '/synced = 0 AND errors < 3/', '4' );
		$this->assertTrue( DiluxOneOffloadDB::has_pending_files() );
	}

	public function test_the_download_list_honours_its_limit_and_the_stats_are_passed_through(): void {
		$rows = array( array( 'file' => '/a', 'size' => 1, 'errors' => 0 ) );
		$this->db->on( 'get_results', '/deleted = 1/', $rows );
		$this->db->on( 'get_row', '/deleted = 1/', array( 'files' => '1', 'size' => '1' ) );
		$this->db->on( 'get_results', '/WHERE synced = 1\s+ORDER BY file/', array( array( 'file' => '/s', 'size' => 2 ) ) );

		$this->assertSame( $rows, DiluxOneOffloadDB::get_deleted_files( 40 ) );
		$this->assertSame( array( 40 ), $this->db->prepared[0]['args'] );
		$this->assertSame( array( 'files' => '1', 'size' => '1' ), DiluxOneOffloadDB::get_deleted_stats() );
		$this->assertSame( array( array( 'file' => '/s', 'size' => 2 ) ), DiluxOneOffloadDB::get_synced_files() );
	}

	public function test_bulk_resets_report_whether_the_update_ran(): void {
		$this->db->on( 'query', '/./', 3 );
		$this->assertTrue( DiluxOneOffloadDB::reset_all_files_to_pending() );
		$this->assertTrue( DiluxOneOffloadDB::reset_failed_files_to_pending() );
		$this->assertSame( 3, DiluxOneOffloadDB::reset_errors() );
		$this->assertSame( 3, DiluxOneOffloadDB::delete_synced_files() );

		$failing = new FakeWpdb();
		$failing->on( 'query', '/./', false );
		$GLOBALS['wpdb'] = $failing;
		$this->assertFalse( DiluxOneOffloadDB::reset_all_files_to_pending() );
		$this->assertFalse( DiluxOneOffloadDB::reset_failed_files_to_pending() );
	}

	public function test_retrying_failed_files_leaves_synced_and_cloud_only_rows_alone(): void {
		DiluxOneOffloadDB::reset_failed_files_to_pending();
		$this->assertStringContainsString( 'WHERE synced = 0 AND deleted = 0', $this->db->queries()[0] );
		DiluxOneOffloadDB::reset_all_files_to_pending();
		$this->assertStringNotContainsString( 'WHERE', $this->db->queries()[1], 'from scratch: every row' );
	}

	// ── What the stream wrapper records ─────────────────────

	public function test_without_a_database_the_wrapper_records_nothing_and_says_so(): void {
		$GLOBALS['wpdb'] = null;
		$this->assertFalse( DiluxOneOffloadDB::forget_file( '/a' ) );
		$this->assertFalse( DiluxOneOffloadDB::rename_file( '/a', '/b' ) );
		$this->assertFalse( DiluxOneOffloadDB::record_live_upload( '/a', 1 ) );
	}

	public function test_forget_file_deletes_the_row(): void {
		$this->assertTrue( DiluxOneOffloadDB::forget_file( '/a.jpg' ) );
		$this->assertSame( array( 'file' => '/a.jpg' ), $this->db->callsOf( 'delete' )[0]['where'] );

		$this->db->on( 'delete', '/./', false );
		$this->assertFalse( DiluxOneOffloadDB::forget_file( '/a.jpg' ) );
	}

	public function test_a_rename_without_a_source_row_changes_nothing(): void {
		$this->db->on( 'get_var', '/COUNT/', '0' );
		$this->assertFalse( DiluxOneOffloadDB::rename_file( '/old.jpg', '/new.jpg' ) );
		$this->assertSame( array(), $this->db->callsOf( 'delete' ), 'a row the destination has is left as it is' );
		$this->assertSame( array(), $this->db->callsOf( 'update' ) );
	}

	public function test_a_rename_replaces_the_destination_row_with_the_source(): void {
		$this->db->on( 'get_var', '/COUNT/', '1' );

		$this->assertTrue( DiluxOneOffloadDB::rename_file( '/old.jpg', '/new.jpg' ) );

		$this->assertSame( array( 'file' => '/new.jpg' ), $this->db->callsOf( 'delete' )[0]['where'] );
		$update = $this->db->callsOf( 'update' )[0];
		$this->assertSame( array( 'file' => '/new.jpg' ), $update['data'] );
		$this->assertSame( array( 'file' => '/old.jpg' ), $update['where'] );
		$this->assertSame( array( '/old.jpg' ), $this->db->prepared[0]['args'] );
	}

	public function test_a_rename_to_a_path_too_long_is_refused(): void {
		$this->assertFalse( DiluxOneOffloadDB::rename_file( '/a', '/' . str_repeat( 'n', 800 ) ) );
		$this->assertSame( array(), $this->db->calls );
	}

	public function test_a_live_upload_inserts_a_new_row_as_synced_with_no_local_copy(): void {
		$this->assertTrue( DiluxOneOffloadDB::record_live_upload( '/2026/a.jpg', 321 ) );

		$insert = $this->db->callsOf( 'insert' )[0];
		$this->assertSame(
			array( 'file' => '/2026/a.jpg', 'size' => 321, 'synced' => 1, 'deleted' => 1, 'transferred' => 321, 'errors' => 0, 'error_message' => null, 'upload_id' => null ),
			$insert['data']
		);
		$this->assertSame( array(), $this->db->callsOf( 'update' ) );
	}

	public function test_a_live_upload_over_an_existing_row_updates_it_in_place(): void {
		$this->db->on( 'get_var', '/SELECT file/', '/2026/a.jpg' );

		$this->assertTrue( DiluxOneOffloadDB::record_live_upload( '/2026/a.jpg', 10 ) );

		$this->assertSame( array(), $this->db->callsOf( 'insert' ) );
		$update = $this->db->callsOf( 'update' )[0];
		$this->assertArrayNotHasKey( 'file', $update['data'], 'the row keeps its key and its created_at' );
		$this->assertSame( 10, $update['data']['size'] );
	}

	public function test_a_live_upload_the_database_refuses_is_reported(): void {
		$this->db->on( 'insert', '/./', false );
		$this->assertFalse( DiluxOneOffloadDB::record_live_upload( '/a.jpg', 1 ) );
		$this->assertFalse( DiluxOneOffloadDB::record_live_upload( str_repeat( 'p', 800 ), 1 ) );
	}

	public function test_set_upload_id_writes_the_token_as_given(): void {
		DiluxOneOffloadDB::set_upload_id( '/a', 'v1|1|2|X' );
		$this->assertSame( array( 'upload_id' => 'v1|1|2|X' ), $this->db->callsOf( 'update' )[0]['data'] );
	}
}
