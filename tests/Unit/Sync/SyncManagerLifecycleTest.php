<?php
namespace Tests\Unit\Sync;

use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\Enums\PluginState;
use Tests\Unit\Support\ScriptedCloudClient;

/**
 * The sync's life around the uploads: starting it (fresh, continued or as a
 * retry of the failed files), its progress, pausing, resuming and
 * cancelling; the reverse sync of a Disconnect, from cataloguing the cloud
 * to each download batch; and inspecting or emptying a reused target.
 */
class SyncManagerLifecycleTest extends SyncTestCase {

	private const META = 'diluxone_offload_sync_meta';

	// ── start_sync ──────────────────────────────────────────

	/** Configured or synced (files added since the last sync); never while syncing or offloading. */
	public function test_a_sync_starts_only_from_configured_or_synced(): void {
		$this->state( PluginState::SYNCING );
		$this->assertSame( array( 'success' => false, 'message' => 'Cannot start sync in current state: syncing' ), $this->manager()->start_sync() );

		$this->state( PluginState::OFFLOADING_ACTIVE );
		$this->assertSame( array( 'success' => false, 'message' => 'Cannot start sync in current state: offloading_active' ), $this->manager()->start_sync() );
		$this->assertSame( array(), $this->db->calls, 'refused before the table is touched' );
	}

	public function test_a_sync_without_a_provider_is_refused(): void {
		$this->state( PluginState::CONFIGURED );
		$this->assertSame( array( 'success' => false, 'message' => 'Cloud client not configured' ), $this->manager( false )->start_sync() );
	}

	public function test_a_retry_with_nothing_failed_settles_back_on_synced(): void {
		$this->state( PluginState::SYNCED );
		$this->db->on( 'get_var', '/synced = 0 AND deleted = 0/', '0' );

		$result = $this->manager()->start_sync( true );

		$this->assertSame( array( 'success' => false, 'message' => 'No failed files to retry', 'total_files' => 0 ), $result );
		$this->assertSame( PluginState::SYNCED, ConfigManager::get_state() );
		$this->assertNull( $this->option( self::META ) );
	}

	public function test_a_retry_from_synced_runs_the_failed_files_under_the_tabs_session(): void {
		$this->state( PluginState::SYNCED );
		$this->db->on( 'get_var', '/synced = 0 AND deleted = 0/', '4' );

		$result = $this->manager()->start_sync( true, 'tab-a' );

		$this->assertSame( array( 'success' => true, 'message' => 'Sync started', 'total_files' => 4 ), $result );
		$meta = $this->option( self::META );
		$this->assertSame( 'started', $meta['status'] );
		$this->assertSame( 4, $meta['total_files'] );
		$this->assertSame( 'tab-a', $meta['sync_session_id'] );
		$this->assertSame( 5, $meta['concurrency'] );
		$this->assertEqualsWithDelta( time(), $meta['last_heartbeat'], 5 );
		$this->assertSame( PluginState::SYNCING, ConfigManager::get_state() );
	}

	public function test_a_continued_sync_uses_the_table_as_it_is(): void {
		$this->state( PluginState::CONFIGURED );
		$this->stats( 10, 7, 0, 3 );
		$this->file( 'never-scanned.jpg' );

		$result = $this->manager()->start_sync();

		$this->assertSame( 3, $result['total_files'], 'only the pending files' );
		$this->assertSame( array(), $this->db->queries(), 'no rescan, no truncate' );
		$this->assertStringStartsWith( 'sync_', $this->option( self::META )['sync_session_id'], 'a session of its own without a tab' );
	}

	public function test_a_continued_sync_with_nothing_pending_is_done(): void {
		$this->state( PluginState::CONFIGURED );
		$this->stats( 10, 10, 0, 0 );

		$this->assertSame( array( 'success' => true, 'message' => 'All files already synced', 'total_files' => 0 ), $this->manager()->start_sync() );
		$this->assertSame( PluginState::SYNCED, ConfigManager::get_state() );
	}

	public function test_a_fresh_sync_of_an_empty_library_is_done(): void {
		$this->state( PluginState::CONFIGURED );
		$this->assertSame( array( 'success' => true, 'message' => 'No files to sync', 'total_files' => 0 ), $this->manager()->start_sync() );
		$this->assertSame( PluginState::SYNCED, ConfigManager::get_state() );
	}

	public function test_a_fresh_sync_scans_fills_the_table_and_starts(): void {
		$this->state( PluginState::CONFIGURED );
		$this->file( 'a.jpg', 'aa' );
		$this->file( '2026/b.jpg', 'bbb' );
		$m = $this->manager();
		$m->set_parallel_uploads( 8 );

		$result = $m->start_sync( false, 'tab-x' );

		$this->assertSame( array( 'success' => true, 'message' => 'Sync started', 'total_files' => 2 ), $result );
		$this->assertStringStartsWith( 'TRUNCATE', $this->db->queries()[0] );
		$this->assertCount( 4, $this->db->prepared[0]['args'], 'both files in one insert' );
		$this->assertSame( 8, $this->option( self::META )['concurrency'] );
		$this->assertSame( PluginState::SYNCING, ConfigManager::get_state() );
	}

	// ── Progress, pause, resume, cancel ─────────────────────

	public function test_no_progress_without_a_sync(): void {
		$this->assertNull( $this->manager()->get_progress() );
	}

	public function test_progress_hands_numbers_to_the_browser_not_strings(): void {
		$this->meta( array( 'status' => 'started', 'start_time' => time() - 30 ) );
		$this->stats( 4, 3, 1, 1 );

		$progress = $this->manager()->get_progress();

		$this->assertSame( 'started', $progress['status'] );
		$this->assertSame( 4, $progress['total_files'] );
		$this->assertSame( 3, $progress['processed_files'] );
		$this->assertSame( 3, $progress['successful_uploads'] );
		$this->assertSame( 1, $progress['failed_uploads'] );
		$this->assertSame( 75.0, $progress['percentage'] );
		$this->assertSame( 1, $progress['pending_files'] );
		$this->assertSame( 1, $progress['remaining_files'] );
		$this->assertEqualsWithDelta( 30, $progress['elapsed_time'], 5 );
	}

	public function test_a_running_sync_pauses_and_resumes(): void {
		$this->meta( array( 'status' => 'started', 'last_update' => 1 ) );
		$m = $this->manager();

		$this->assertFalse( $m->resume_sync(), 'not paused' );
		$this->assertTrue( $m->pause_sync() );
		$this->assertSame( 'paused', $this->option( self::META )['status'] );
		$this->assertGreaterThan( 1, $this->option( self::META )['last_update'] );
		$this->assertFalse( $m->pause_sync(), 'already paused' );

		$this->assertTrue( $m->resume_sync() );
		$this->assertSame( 'started', $this->option( self::META )['status'] );
		$this->assertSame( PluginState::SYNCING, ConfigManager::get_state() );
	}

	public function test_nothing_to_pause_or_resume_without_a_sync(): void {
		$this->assertFalse( $this->manager()->pause_sync() );
		$this->assertFalse( $this->manager()->resume_sync() );
	}

	public function test_cancelling_a_running_sync_empties_the_table_and_goes_back_to_configured(): void {
		$this->state( PluginState::SYNCING );
		$this->meta( array( 'status' => 'started' ) );

		$this->assertTrue( $this->manager()->cancel_sync() );

		$this->assertSame( array( 'TRUNCATE TABLE wp_diluxone_offload_files' ), $this->db->queries() );
		$this->assertNull( $this->option( self::META ) );
		$this->assertSame( PluginState::CONFIGURED, ConfigManager::get_state() );
	}

	public function test_cancelling_a_paused_sync_clears_it_without_touching_the_table(): void {
		$this->state( PluginState::SYNCING );
		$this->meta( array( 'status' => 'paused' ) );
		$GLOBALS['_test_wp_transients']['diluxone_offload_full_file_list'] = array( 'x' );

		$this->assertTrue( $this->manager()->cancel_sync() );

		$this->assertSame( array(), $this->db->queries() );
		$this->assertNull( $this->option( self::META ) );
		$this->assertArrayNotHasKey( 'diluxone_offload_full_file_list', $GLOBALS['_test_wp_transients'] );
		$this->assertSame( PluginState::CONFIGURED, ConfigManager::get_state() );
	}

	public function test_nothing_to_cancel_without_a_running_or_paused_sync(): void {
		$this->assertFalse( $this->manager()->cancel_sync() );
		$this->meta( array( 'status' => 'completed' ) );
		$this->assertFalse( $this->manager()->cancel_sync() );
	}

	// ── start_reverse_sync ──────────────────────────────────

	public function test_a_reverse_sync_needs_offloading_on_a_provider_and_a_connection(): void {
		$this->state( PluginState::SYNCED );
		$this->assertSame( 'Reverse sync only available when offloading is active', $this->manager()->start_reverse_sync()['message'] );

		$this->state( PluginState::OFFLOADING_ACTIVE );
		$this->assertSame( 'Cloud client not configured', $this->manager( false )->start_reverse_sync()['message'] );

		$this->client->connection = array( 'success' => false, 'message' => 'HTTP 403' );
		$this->assertSame( array( 'success' => false, 'message' => 'Connection to cloud storage failed: HTTP 403' ), $this->manager()->start_reverse_sync() );
		$this->assertSame( array(), $this->client->listed, 'nothing is listed over a broken connection' );
	}

	/** @return array<int, array<string, mixed>> */
	private static function cloud( array $keys ): array {
		$files = array();
		foreach ( $keys as $key => $size ) {
			$files[] = array( 'path' => $key, 'size' => $size );
		}
		return $files;
	}

	public function test_a_scratch_reverse_sync_marks_every_one_of_this_sites_files_for_download(): void {
		$GLOBALS['_test_multisite'] = array( 'enabled' => true, 'main' => true, 'blog_id' => 1 );
		$this->state( PluginState::OFFLOADING_ACTIVE );
		$this->client->files = self::cloud( array( 'uploads/a.jpg' => 5, 'uploads/2026/b.jpg' => 7, 'uploads/sites/2/theirs.jpg' => 9 ) );
		$this->db->on( 'get_row', '/as files/', array( 'files' => 2, 'size' => 12 ) );
		$this->stats( 5, 5 );

		$result = $this->manager()->start_reverse_sync( 'scratch' );

		$this->assertSame( array( 'success' => true, 'message' => 'Reverse sync started', 'total_files' => '5' ), $result );
		$this->assertSame( array( 'uploads/' ), $this->client->listed );
		$this->assertStringStartsWith( 'TRUNCATE', $this->db->queries()[0] );
		$this->assertSame( array( '/a.jpg', 5, 5, '/2026/b.jpg', 7, 7 ), $this->db->prepared[0]['args'], 'another site\'s files are not this site\'s to restore' );
		$meta = $this->option( self::META );
		$this->assertTrue( $meta['is_reverse_sync'] );
		$this->assertSame( 'scratch', $meta['reverse_mode'] );
		$this->assertSame( 3, $meta['already_downloaded'], '5 in the cloud, 2 to download' );
	}

	public function test_a_scratch_reverse_sync_of_an_empty_container_is_refused(): void {
		$this->state( PluginState::OFFLOADING_ACTIVE );
		$this->assertSame( array( 'success' => false, 'message' => 'No files found in cloud storage.' ), $this->manager()->start_reverse_sync( 'scratch' ) );
	}

	public function test_a_continued_reverse_sync_catalogues_only_what_the_table_does_not_hold_as_synced(): void {
		$this->state( PluginState::OFFLOADING_ACTIVE );
		$this->client->files = self::cloud( array( 'uploads/known.jpg' => 1, 'uploads/new.jpg' => 2 ) );
		$this->db->on( 'get_results', '/WHERE synced = 1/', array( array( 'file' => '/known.jpg' ) ) );
		$this->db->onSequence( 'get_row', '/as files/', array( array( 'files' => 0, 'size' => 0 ), array( 'files' => 1, 'size' => 2 ) ) );
		$this->stats( 2, 2 );

		$this->assertTrue( $this->manager()->start_reverse_sync()['success'] );

		$this->assertSame( array(), array_filter( $this->db->queries(), static fn( $q ) => 0 === strpos( $q, 'TRUNCATE' ) ), 'nothing is thrown away' );
		$this->assertSame( array( '/new.jpg', 2, 2 ), $this->db->prepared[0]['args'] );
		$meta = $this->option( self::META );
		$this->assertSame( 'continue', $meta['reverse_mode'] );
		$this->assertSame( 1, $meta['already_downloaded'] );
	}

	public function test_a_continued_reverse_sync_with_nothing_anywhere_is_refused(): void {
		$this->state( PluginState::OFFLOADING_ACTIVE );
		$this->db->on( 'get_row', '/as files/', array( 'files' => '0', 'size' => '0' ) );
		$this->assertSame( 'No files found in cloud storage.', $this->manager()->start_reverse_sync( 'continue' )['message'] );
	}

	public function test_a_continued_reverse_sync_resumes_what_is_marked_even_if_the_listing_is_empty(): void {
		$this->state( PluginState::OFFLOADING_ACTIVE );
		$this->db->on( 'get_row', '/as files/', array( 'files' => '3', 'size' => '30' ) );
		$this->stats( 3, 3 );

		$this->assertTrue( $this->manager()->start_reverse_sync()['success'] );
		$this->assertSame( array(), $this->db->prepared, 'nothing new to insert' );
	}

	// ── process_reverse_batch ───────────────────────────────

	public function test_a_reverse_batch_needs_a_running_reverse_sync(): void {
		$expected = array( 'status' => 'error', 'message' => 'No active reverse sync found' );
		$this->assertSame( $expected, $this->manager()->process_reverse_batch() );
		$this->meta( array( 'status' => 'started' ) );
		$this->assertSame( $expected, $this->manager()->process_reverse_batch(), 'a forward sync is not one' );
	}

	public function test_a_reverse_batch_with_nothing_left_reports_completion(): void {
		$this->meta( array( 'status' => 'started', 'is_reverse_sync' => true, 'total_files' => 3 ) );
		$this->stats( 3, 3 );

		$result = $this->manager()->process_reverse_batch();

		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( 0, $result['remaining_files'] );
		$this->assertSame( array( 200 ), $this->db->prepared[0]['args'], 'twice the batch size per query' );
	}

	public function test_a_reverse_batch_refuses_unsafe_names_skips_present_files_and_records_failures(): void {
		$this->meta( array( 'status' => 'started', 'is_reverse_sync' => true, 'reverse_mode' => 'continue', 'total_files' => 10, 'already_downloaded' => 4 ) );
		$this->file( 'same.jpg', 'abc' );
		$this->file( 'changed.jpg', 'abcdef' );
		$this->db->on(
			'get_results',
			'/deleted = 1/',
			array(
				array( 'file' => '/../escape.jpg', 'size' => 1 ),
				array( 'file' => '/2026/evil.php.jpg', 'size' => 1 ),
				array( 'file' => '/same.jpg', 'size' => '3' ),
				array( 'file' => '/changed.jpg', 'size' => '3' ),
			)
		);
		$this->db->on( 'get_var', '/synced = 1 AND deleted = 1/', '3' );

		$result = $this->manager()->process_reverse_batch( 0.0 );

		$this->assertSame(
			array( '/../escape.jpg' => 'Path traversal rejected', '/2026/evil.php.jpg' => 'Executable or script file refused', '/changed.jpg' => 'no downloads scripted' ),
			$this->stored_errors()
		);
		$downloaded = array_column( array_filter( $this->db->callsOf( 'update' ), static fn( $u ) => isset( $u['data']['deleted'] ) ), 'where' );
		$this->assertSame( array( array( 'file' => '/same.jpg' ) ), $downloaded, 'the copy already there with the same size is kept' );
		$this->assertContains( '/changed.jpg', $this->attempts(), 'counted before the download' );
		$this->assertSame(
			array( 'status' => 'processing', 'total_files' => 10, 'processed_files' => 7, 'successful_downloads' => 7, 'failed_downloads' => 0, 'percentage' => 70.0, 'downloaded_this_batch' => 0, 'pending_files' => 3, 'remaining_files' => 3 ),
			$result
		);
	}

	public function test_a_scratch_reverse_batch_downloads_even_a_file_already_there(): void {
		$this->meta( array( 'status' => 'started', 'is_reverse_sync' => true, 'reverse_mode' => 'scratch', 'total_files' => 1 ) );
		$this->file( 'same.jpg', 'abc' );
		$asked                         = array();
		$this->client->download_handle = static function ( array $fi ) use ( &$asked ): array {
			$asked[] = $fi['remote_path'];
			return array( 'success' => false, 'error' => 'refused' );
		};
		$this->db->on( 'get_results', '/deleted = 1/', array( array( 'file' => '/same.jpg', 'size' => 3 ) ) );

		$this->manager()->process_reverse_batch( 0.0 );

		$this->assertSame( array( 'uploads/same.jpg' ), $asked );
	}

	public function test_a_download_cut_by_the_transport_removes_its_part_file_and_keeps_the_copy_there(): void {
		$this->meta( array( 'status' => 'started', 'is_reverse_sync' => true, 'reverse_mode' => 'scratch', 'total_files' => 1, 'already_downloaded' => 0 ) );
		$local = $this->file( 'photo.jpg', 'the old copy' );
		$part  = $local . '.part';
		$this->client->download_handle = static function ( array $fi ) use ( $part ): array {
			return array(
				'success'     => true,
				'handle'      => ScriptedCloudClient::file_handle( $fi['local_path'] . '.not-in-the-cloud' ),
				'file_handle' => fopen( $part, 'w' ),
				'part_path'   => $part,
			);
		};
		$this->db->on( 'get_results', '/deleted = 1/', array( array( 'file' => '/photo.jpg', 'size' => 99 ) ) );

		$this->manager()->process_reverse_batch( 0.0 );

		$this->assertFileDoesNotExist( $part );
		$this->assertSame( 'the old copy', file_get_contents( $local ) );
		$this->assertSame( array( '/photo.jpg' => curl_strerror( 37 ) ), $this->stored_errors() );
	}

	// ── A target used before ────────────────────────────────

	public function test_the_target_is_untouched_only_while_configured_with_an_empty_table(): void {
		$this->state( PluginState::CONFIGURED );
		$this->assertTrue( \DiluxOneOffload\SyncManager::target_untouched() );

		$this->stats( 2, 0 );
		$this->assertFalse( \DiluxOneOffload\SyncManager::target_untouched() );

		$this->db = new \Tests\Unit\Support\FakeWpdb();
		$GLOBALS['wpdb'] = $this->db;
		$this->state( PluginState::SYNCED );
		$this->assertFalse( \DiluxOneOffload\SyncManager::target_untouched() );
	}

	public function test_the_target_name_is_the_bucket_or_the_container(): void {
		$this->assertSame( '', \DiluxOneOffload\SyncManager::target_name() );
		$this->config( array( 'cloud_provider' => 'azure', 'provider_config' => array( 'container_name' => 'media' ) ) );
		$this->assertSame( 'media', \DiluxOneOffload\SyncManager::target_name() );
		$this->config( array( 'cloud_provider' => 's3', 'provider_config' => array( 'bucket' => 'photos' ) ) );
		$this->assertSame( 'photos', \DiluxOneOffload\SyncManager::target_name() );
	}

	public function test_inspecting_the_target_counts_only_this_sites_objects(): void {
		$GLOBALS['_test_multisite'] = array( 'enabled' => true, 'main' => true, 'blog_id' => 1 );
		$this->client->files        = self::cloud( array( 'uploads/a.jpg' => 10, 'uploads/b.jpg' => 5, 'uploads/sites/3/c.jpg' => 1000 ) );

		$this->assertSame( array( 'files' => 2, 'bytes' => 15 ), $this->manager()->inspect_target() );
	}

	public function test_inspecting_or_emptying_without_a_provider_throws(): void {
		$this->expectExceptionMessage( 'Cloud client not configured' );
		$this->manager( false )->inspect_target();
	}

	public function test_emptying_without_a_provider_throws(): void {
		$this->expectExceptionMessage( 'Cloud client not configured' );
		$this->manager( false )->empty_target( 5.0 );
	}

	public function test_emptying_walks_every_page_and_deletes_only_this_sites_objects(): void {
		$GLOBALS['_test_multisite'] = array( 'enabled' => true, 'main' => true, 'blog_id' => 1 );
		$this->state( PluginState::CONFIGURED );
		$this->client->pages = array(
			''   => array( 'files' => self::cloud( array( 'uploads/a.jpg' => 1, 'uploads/sites/2/x.jpg' => 1 ) ), 'next' => 'm2' ),
			'm2' => array( 'files' => self::cloud( array( 'uploads/b.jpg' => 1 ) ), 'next' => '' ),
		);

		$result = $this->manager()->empty_target( 60.0 );

		$this->assertSame( array( 'deleted' => 2, 'failed' => 0, 'errors' => array(), 'next' => '', 'done' => true ), $result );
		$this->assertSame( array( 'uploads/a.jpg', 'uploads/b.jpg' ), $this->client->deleted );
		$this->assertSame( array( '', 'm2' ), $this->client->page_markers );
	}

	public function test_failed_deletes_are_counted_and_the_first_five_named(): void {
		$this->state( PluginState::CONFIGURED );
		$keys = array();
		for ( $i = 1; $i <= 6; $i++ ) {
			$keys[ 'uploads/locked' . $i . '.jpg' ] = 1;
		}
		$this->client->pages       = array( '' => array( 'files' => self::cloud( $keys ), 'next' => '' ) );
		$this->client->undeletable = array_keys( $keys );

		$result = $this->manager()->empty_target( 60.0 );

		$this->assertSame( 6, $result['failed'] );
		$this->assertCount( 5, $result['errors'] );
		$this->assertSame( 'uploads/locked1.jpg: HTTP 403', $result['errors'][0] );
		$this->assertTrue( $result['done'] );
	}

	public function test_out_of_time_the_page_is_taken_up_again_from_the_same_marker(): void {
		$this->state( PluginState::CONFIGURED );
		$this->client->pages = array(
			''   => array( 'files' => array(), 'next' => 'm2' ),
			'm2' => array( 'files' => self::cloud( array( 'uploads/a.jpg' => 1, 'uploads/b.jpg' => 1 ) ), 'next' => 'm3' ),
		);
		$m = $this->manager();

		$first = $m->empty_target( 0.0 );
		$this->assertSame( array( 'deleted' => 0, 'failed' => 0, 'errors' => array(), 'next' => 'm2', 'done' => false ), $first, 'out of time after the first page' );

		$second = $m->empty_target( 0.0, 'm2' );
		$this->assertSame( 1, $second['deleted'], 'at least one delete per request' );
		$this->assertSame( 'm2', $second['next'], 'the rest of that page comes back from its own marker' );
		$this->assertFalse( $second['done'] );
	}

	public function test_emptying_stops_as_soon_as_the_target_is_no_longer_untouched(): void {
		$this->state( PluginState::SYNCING );
		$this->client->pages = array( '' => array( 'files' => self::cloud( array( 'uploads/a.jpg' => 1 ) ), 'next' => '' ) );

		$result = $this->manager()->empty_target( 60.0 );

		$this->assertSame( array(), $this->client->deleted );
		$this->assertFalse( $result['done'] );
	}

	// ── Large libraries go to the table 500 rows at a time ──

	/** @return array<int, array<int, mixed>> The arguments of each multi-row INSERT, in order. */
	private function inserts(): array {
		$inserts = array_filter( $this->db->prepared, static fn( $p ) => false !== strpos( $p['query'], 'INSERT INTO' ) );
		return array_values( array_column( $inserts, 'args' ) );
	}

	public function test_a_fresh_sync_of_a_large_library_inserts_500_rows_at_a_time(): void {
		$this->state( PluginState::CONFIGURED );
		for ( $i = 0; $i < 501; $i++ ) {
			$this->file( 'lib/' . $i . '.jpg' );
		}

		$this->assertSame( 501, $this->manager()->start_sync()['total_files'] );

		$inserts = $this->inserts();
		$this->assertCount( 2, $inserts );
		$this->assertCount( 1000, $inserts[0] );
		$this->assertCount( 2, $inserts[1] );
	}

	/** @return array<string, array{string}> */
	public function reverseModes(): array {
		return array( 'scratch' => array( 'scratch' ), 'continue' => array( 'continue' ) );
	}

	/** @dataProvider reverseModes */
	public function test_a_reverse_sync_of_a_large_container_catalogues_500_rows_at_a_time( string $mode ): void {
		$this->state( PluginState::OFFLOADING_ACTIVE );
		$keys = array();
		for ( $i = 0; $i < 501; $i++ ) {
			$keys[ 'uploads/' . $i . '.jpg' ] = 1;
		}
		$this->client->files = self::cloud( $keys );
		$this->db->on( 'get_row', '/as files/', array( 'files' => '501', 'size' => '501' ) );
		$this->stats( 501, 501 );

		$this->assertTrue( $this->manager()->start_reverse_sync( $mode )['success'] );

		$inserts = $this->inserts();
		$this->assertCount( 2, $inserts );
		$this->assertCount( 1500, $inserts[0], 'path, size, transferred per row' );
		$this->assertCount( 3, $inserts[1] );
	}

	public function test_a_reverse_round_takes_no_more_files_than_the_batch_size(): void {
		$this->meta( array( 'status' => 'started', 'is_reverse_sync' => true, 'reverse_mode' => 'scratch', 'total_files' => 30 ) );
		$rows = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$rows[] = array( 'file' => '/' . $i . '.jpg', 'size' => 1 );
		}
		$this->db->on( 'get_results', '/deleted = 1/', $rows );
		$asked                         = 0;
		$this->client->download_handle = static function () use ( &$asked ): array {
			++$asked;
			return array( 'success' => false, 'error' => 'refused' );
		};
		$m = $this->manager();
		$m->set_parallel_uploads( 3 ); // batch size 25

		$m->process_reverse_batch( 0.0 );

		$this->assertSame( 25, $asked );
		$this->assertSame( array( 50 ), $this->db->prepared[0]['args'] );
	}
}
