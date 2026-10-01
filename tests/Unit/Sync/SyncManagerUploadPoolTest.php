<?php
namespace Tests\Unit\Sync;

use DiluxOneOffload\Clock;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\DTOs\ChunkedUpload;
use DiluxOneOffload\SyncManager;
use Tests\Unit\Support\ScriptedCloudClient;

/**
 * The forward sync's upload pool and the batch around it, on real curl_multi
 * transfers of file:// URLs: what each finished transfer means for its file,
 * how a large file's parts and commit move through the pool, what a
 * deadline leaves for the next request, and what the batch writes to the
 * tracking table. The verdict on a transfer is the scripted provider's, as
 * it would be the real provider's reading of the status and body.
 */
class SyncManagerUploadPoolTest extends SyncTestCase {

	/** @return array<string, mixed> One file of a round, as run_batch() builds it. */
	private function entry( string $relative, string $content = 'data', ?string $token = null ): array {
		$local = $this->file( $relative, $content );
		return array(
			'path'        => '/' . $relative,
			'local_path'  => $local,
			'remote_path' => 'uploads/' . $relative,
			'size'        => strlen( $content ),
			'upload_id'   => $token,
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $batch
	 * @return array<int, array<string, mixed>>
	 */
	private function pool( SyncManager $m, array $batch, float $seconds_left = 100.0 ): array {
		try {
			return $this->call( $m, 'sync_files_parallel', array( $batch, Clock::now() + $seconds_left ) );
		} finally {
			$this->call( $m, 'release_transport' );
		}
	}

	/** A manager that sends anything above 10 bytes in parts of 10. */
	private function chunking_manager(): SyncManager {
		$m = $this->manager();
		$this->set( $m, 'chunked_threshold', 10 );
		return $m;
	}

	/** The provider starts every large file as an upload in parts of 10 bytes named $id (or the one to resume). */
	private function begins_uploads( string $id = 'U1' ): void {
		$this->client->begin = static function ( array $file_info, ?string $resume ) use ( $id ): array {
			return array(
				'success' => true,
				'upload'  => new ChunkedUpload( (string) $file_info['local_path'], (string) $file_info['remote_path'], (int) $file_info['size'], 10, $resume ?? $id ),
			);
		};
	}

	/** @return array<int, mixed> The upload_id each update of $path wrote, in order. */
	private function tokens_written( string $path ): array {
		$tokens = array();
		foreach ( $this->db->callsOf( 'update' ) as $u ) {
			if ( array( 'file' => $path ) === $u['where'] && array_key_exists( 'upload_id', $u['data'] ) && 1 === count( $u['data'] ) ) {
				$tokens[] = $u['data']['upload_id'];
			}
		}
		return $tokens;
	}

	// ── Single PUTs ─────────────────────────────────────────

	public function test_every_file_is_counted_as_an_attempt_before_it_goes_and_succeeds_on_the_providers_word(): void {
		$batch = array( $this->entry( 'a.jpg' ), $this->entry( 'b.jpg' ) );

		$results = $this->pool( $this->manager(), $batch );

		$this->assertSame( array( array( 'success' => true ), array( 'success' => true ) ), $results );
		$this->assertSame( array( '/a.jpg', '/b.jpg' ), $this->attempts() );
		$this->assertSame( array( 'uploads/a.jpg', 'uploads/b.jpg' ), array_column( $this->client->batch_prepared, 'remote_path' ) );
	}

	public function test_a_refused_upload_fails_with_the_providers_line_and_runs_its_cleanup(): void {
		$this->client->verdict = static fn( int $status, string $body ) => 'BAD' === $body ? 'HTTP 403 - AuthorizationFailure' : null;
		$batch                 = array( $this->entry( 'ok.jpg', 'GOOD' ), $this->entry( 'no.jpg', 'BAD' ) );

		$results = $this->pool( $this->manager(), $batch );

		$this->assertSame( array( 'success' => true ), $results[0] );
		$this->assertSame( array( 'success' => false, 'error' => 'HTTP 403 - AuthorizationFailure' ), $results[1] );
		$this->assertSame( 1, $this->client->failure_callbacks, 'on_failure ran for the refused file only' );
	}

	public function test_a_transport_error_is_reported_rather_than_the_status_line(): void {
		$this->client->verdict      = static fn() => 'HTTP 0';
		$this->client->batch_handle = static fn( array $fi ) => array(
			'success' => true,
			'handle'  => ScriptedCloudClient::file_handle( $fi['local_path'] . '.gone' ),
		);

		$results = $this->pool( $this->manager(), array( $this->entry( 'a.jpg' ) ) );

		$this->assertSame( array( 'success' => false, 'error' => curl_strerror( 37 ) ), $results[0] );
	}

	public function test_a_file_gone_from_disk_fails_without_reaching_the_provider(): void {
		$entry = $this->entry( 'a.jpg' );
		unlink( $entry['local_path'] );

		$results = $this->pool( $this->manager(), array( $entry ) );

		$this->assertSame( array( 'success' => false, 'error' => 'File not found: ' . $entry['local_path'] ), $results[0] );
		$this->assertSame( array(), $this->client->batch_prepared );
	}

	public function test_a_handle_the_provider_cannot_prepare_fails_its_file_only(): void {
		$this->client->batch_handle = static fn( array $fi ) => '/b.jpg' === $fi['path']
			? array( 'success' => false, 'error' => 'no handle' )
			: array( 'success' => true, 'handle' => ScriptedCloudClient::file_handle( $fi['local_path'] ) );

		$results = $this->pool( $this->manager(), array( $this->entry( 'a.jpg' ), $this->entry( 'b.jpg' ) ) );

		$this->assertTrue( $results[0]['success'] );
		$this->assertSame( array( 'success' => false, 'error' => 'no handle' ), $results[1] );
	}

	public function test_past_the_deadline_only_the_first_fill_starts_and_the_rest_stay_untouched(): void {
		$m = $this->manager();
		$m->set_parallel_uploads( 3 );
		$batch = array();
		foreach ( array( 'a', 'b', 'c', 'd', 'e' ) as $name ) {
			$batch[] = $this->entry( $name . '.jpg' );
		}

		$results = $this->pool( $m, $batch, -1.0 );

		$this->assertSame( array( 0, 1, 2 ), array_keys( $results ), 'every request uploads something' );
		$this->assertSame( array( '/a.jpg', '/b.jpg', '/c.jpg' ), $this->attempts(), 'd and e are not charged an attempt' );
	}

	// ── Large files in parts ────────────────────────────────

	public function test_a_large_file_sends_every_part_then_its_commit_and_remembers_its_upload(): void {
		$this->begins_uploads();
		$entry = $this->entry( 'big.mov', str_repeat( 'v', 25 ) );

		$results = $this->pool( $this->chunking_manager(), array( $entry ) );

		$this->assertSame( array( array( 'success' => true ) ), $results );
		$this->assertSame( array( 1, 2, 3 ), $this->client->parts_prepared );
		$this->assertSame( 1, $this->client->commits );
		$this->assertSame( array(), $this->client->batch_prepared, 'no single PUT for it' );
		$this->assertSame( array( 'v1|25|' . filemtime( $entry['local_path'] ) . '|U1' ), $this->tokens_written( '/big.mov' ), 'kept for a later request; not forgotten on success' );
		$this->assertSame( array( '/big.mov' ), $this->attempts() );
	}

	public function test_a_resumed_upload_sends_only_the_parts_the_service_does_not_hold(): void {
		$entry = $this->entry( 'big.mov', str_repeat( 'v', 25 ) );
		$token = 'v1|25|' . filemtime( $entry['local_path'] ) . '|U9';
		$entry['upload_id'] = $token;
		$this->client->begin = static function ( array $fi, ?string $resume ): array {
			$upload = new ChunkedUpload( (string) $fi['local_path'], (string) $fi['remote_path'], 25, 10, (string) $resume );
			$upload->recordTag( 1, 'held' );
			$upload->recordTag( 2, 'held' );
			return array( 'success' => true, 'upload' => $upload );
		};

		$results = $this->pool( $this->chunking_manager(), array( $entry ) );

		$this->assertTrue( $results[0]['success'] );
		$this->assertSame( array( array( 'uploads/big.mov', 'U9' ) ), $this->client->begun, 'the row\'s upload is taken up' );
		$this->assertSame( array( 3 ), $this->client->parts_prepared );
		$this->assertSame( array( $token ), $this->tokens_written( '/big.mov' ) );
	}

	public function test_an_upload_whose_parts_all_landed_goes_straight_to_its_commit(): void {
		$this->client->begin = static function ( array $fi ): array {
			$upload = new ChunkedUpload( (string) $fi['local_path'], (string) $fi['remote_path'], 15, 10, 'U1' );
			$upload->recordTag( 1, 't' );
			$upload->recordTag( 2, 't' );
			return array( 'success' => true, 'upload' => $upload );
		};

		$results = $this->pool( $this->chunking_manager(), array( $this->entry( 'big.mov', str_repeat( 'v', 15 ) ) ) );

		$this->assertTrue( $results[0]['success'] );
		$this->assertSame( array(), $this->client->parts_prepared );
		$this->assertSame( 1, $this->client->commits );
	}

	public function test_an_upload_left_by_a_file_that_changed_is_aborted_before_a_new_one_starts(): void {
		$this->begins_uploads( 'NEW' );
		$entry              = $this->entry( 'big.mov', str_repeat( 'v', 25 ) );
		$entry['upload_id'] = 'v1|999|1|OLD';
		$this->db->on( 'get_var', '/SELECT upload_id/', 'v1|999|1|OLD' );

		$this->pool( $this->chunking_manager(), array( $entry ) );

		$this->assertCount( 1, $this->client->aborted );
		$this->assertSame( 'OLD', $this->client->aborted[0]->uploadId() );
		$this->assertSame( 'uploads/big.mov', $this->client->aborted[0]->remotePath() );
		$this->assertSame( array( array( 'uploads/big.mov', null ) ), $this->client->begun, 'started over' );
		$tokens = $this->tokens_written( '/big.mov' );
		$this->assertNull( $tokens[0], 'the stale upload is forgotten' );
		$this->assertStringEndsWith( '|NEW', (string) end( $tokens ), 'the new one is what the row keeps' );
	}

	public function test_an_upload_the_service_no_longer_knows_is_forgotten_and_the_new_one_kept(): void {
		$entry              = $this->entry( 'big.mov', str_repeat( 'v', 25 ) );
		$entry['upload_id'] = 'v1|25|' . filemtime( $entry['local_path'] ) . '|LOST';
		$this->client->begin = static fn( array $fi ) => array(
			'success' => true,
			'upload'  => new ChunkedUpload( (string) $fi['local_path'], (string) $fi['remote_path'], 25, 10, 'FRESH' ),
		);

		$this->pool( $this->chunking_manager(), array( $entry ) );

		$this->assertSame( array(), $this->client->aborted, 'nothing to abort: the service lost it' );
		$tokens = $this->tokens_written( '/big.mov' );
		$this->assertNull( $tokens[0] );
		$this->assertStringEndsWith( '|FRESH', (string) $tokens[1] );
	}

	public function test_a_large_file_the_provider_cannot_start_fails_without_an_abort(): void {
		$results = $this->pool( $this->chunking_manager(), array( $this->entry( 'big.mov', str_repeat( 'v', 25 ) ) ) );

		$this->assertSame( array( 'success' => false, 'error' => 'no chunked uploads scripted' ), $results[0] );
		$this->assertSame( array(), $this->client->aborted );
	}

	public function test_a_part_dropped_by_the_connection_is_sent_again_and_the_file_completes(): void {
		$this->begins_uploads();
		$failures                  = 2;
		$this->client->part_handle = static function ( ChunkedUpload $u, int $part ) use ( &$failures ): array {
			$path = $u->localPath();
			if ( 2 === $part && $failures-- > 0 ) {
				$path .= '.gone';
			}
			return array( 'success' => true, 'handle' => ScriptedCloudClient::file_handle( $path ) );
		};

		$results = $this->pool( $this->chunking_manager(), array( $this->entry( 'big.mov', str_repeat( 'v', 25 ) ) ) );

		$this->assertSame( array( array( 'success' => true ) ), $results, 'three tries in all for a part' );
		$this->assertSame( 3, count( array_keys( $this->client->parts_prepared, 2, true ) ) );
		$this->assertSame( array(), $this->client->aborted );
	}

	public function test_a_part_that_keeps_failing_fails_its_file_aborts_and_forgets_the_upload(): void {
		$this->begins_uploads();
		$this->client->part_handle = static fn( ChunkedUpload $u, int $part ) => array(
			'success' => true,
			'handle'  => ScriptedCloudClient::file_handle( $u->localPath() . ( 2 === $part ? '.gone' : '' ) ),
		);

		$results = $this->pool( $this->chunking_manager(), array( $this->entry( 'big.mov', str_repeat( 'v', 25 ) ) ) );

		$this->assertSame( array( 'success' => false, 'error' => 'Upload failed on part 2: ' . curl_strerror( 37 ) ), $results[0] );
		$this->assertSame( 3, count( array_keys( $this->client->parts_prepared, 2, true ) ), 'not a fourth time' );
		$this->assertCount( 1, $this->client->aborted );
		$this->assertSame( 0, $this->client->commits );
		$updates = $this->db->callsOf( 'update' );
		$this->assertNull( end( $updates )['data']['upload_id'], 'the dead upload is forgotten' );
	}

	public function test_a_part_the_service_refuses_fails_at_once(): void {
		$this->begins_uploads();
		$this->client->part_verdict = static fn( ChunkedUpload $u, int $part ) => 1 === $part ? 'HTTP 400 - InvalidPart' : null;

		$results = $this->pool( $this->chunking_manager(), array( $this->entry( 'big.mov', str_repeat( 'v', 25 ) ) ) );

		$this->assertSame( array( 'success' => false, 'error' => 'HTTP 400 - InvalidPart' ), $results[0] );
		$this->assertSame( 1, count( array_keys( $this->client->parts_prepared, 1, true ) ), 'a 4xx is not retried' );
		$this->assertCount( 1, $this->client->aborted, 'aborted once, however many parts were in flight' );
	}

	public function test_a_part_handle_that_cannot_be_prepared_aborts_the_upload(): void {
		$this->begins_uploads();
		$this->client->part_handle = static fn() => array( 'success' => false, 'error' => 'cannot read part' );

		$results = $this->pool( $this->chunking_manager(), array( $this->entry( 'big.mov', str_repeat( 'v', 25 ) ) ) );

		$this->assertSame( array( 'success' => false, 'error' => 'cannot read part' ), $results[0] );
		$this->assertCount( 1, $this->client->aborted );
		$this->assertSame( array( 1 ), $this->client->parts_prepared, 'the queued parts of a failed file are dropped' );
	}

	public function test_a_refused_commit_fails_the_file_and_forgets_its_upload(): void {
		$this->begins_uploads();
		$this->client->commit_handle = static fn() => array( 'success' => false, 'error' => 'commit refused' );

		$results = $this->pool( $this->chunking_manager(), array( $this->entry( 'big.mov', str_repeat( 'v', 25 ) ) ) );

		$this->assertSame( array( 'success' => false, 'error' => 'commit refused' ), $results[0] );
		$this->assertCount( 1, $this->client->aborted );
	}

	public function test_a_large_file_left_half_sent_at_the_deadline_gets_its_attempt_back(): void {
		$this->begins_uploads();
		$m = $this->chunking_manager();
		$m->set_parallel_uploads( 3 );

		$results = $this->pool( $m, array( $this->entry( 'big.mov', str_repeat( 'v', 55 ) ) ), -1.0 );

		$this->assertSame( array(), $results, 'no verdict: it is still under way' );
		$this->assertSame( array( 1, 2, 3 ), $this->client->parts_prepared, 'the first fill only' );
		$this->assertSame( array(), $this->client->aborted );
		$refunds = array_filter( $this->db->prepared, static fn( $p ) => false !== strpos( $p['query'], 'GREATEST' ) );
		$this->assertSame( array( array( '/big.mov' ) ), array_values( array_column( $refunds, 'args' ) ) );
	}

	// ── process_batch ───────────────────────────────────────

	public function test_a_batch_without_a_running_sync_is_an_error(): void {
		$this->assertSame( array( 'status' => 'error', 'message' => 'No active sync found' ), $this->manager()->process_batch() );
		$this->meta( array( 'status' => 'paused' ) );
		$this->assertSame( 'error', $this->manager()->process_batch()['status'] );
	}

	public function test_a_tab_that_lost_the_sync_runs_nothing(): void {
		$this->meta( array( 'status' => 'started', 'sync_session_id' => 'tab-a', 'last_heartbeat' => 1 ) );

		$result = $this->manager()->process_batch( 8.0, 'tab-b' );

		$this->assertSame( array( 'status' => 'session_lost', 'message' => 'Another tab has taken control of the sync', 'active_session_id' => 'tab-a' ), $result );
		$this->assertSame( 1, $this->option( 'diluxone_offload_sync_meta' )['last_heartbeat'], 'no heartbeat for it' );
		$this->assertSame( array(), $this->db->calls );
	}

	public function test_a_batch_uploads_marks_and_records_then_reports_completion(): void {
		$this->meta( array( 'status' => 'started', 'sync_session_id' => 'tab-a', 'last_heartbeat' => 1 ) );
		$this->file( 'ok.jpg', 'GOOD' );
		$this->file( 'no.jpg', 'BAD' );
		$this->client->verdict = static fn( int $s, string $body ) => 'BAD' === $body ? 'HTTP 403' : null;
		$this->db->onSequence(
			'get_results',
			'/size DESC/',
			array(
				array(
					array( 'file' => '/ok.jpg', 'size' => 4, 'upload_id' => null ),
					array( 'file' => '/../../wp-config.php', 'size' => 1, 'upload_id' => null ),
					array( 'file' => '/no.jpg', 'size' => 3, 'upload_id' => null ),
				),
				array(),
			)
		);
		$this->stats( 2, 1, 1, 0 );

		$result = $this->manager()->process_batch( 30.0, 'tab-a' );

		$this->assertSame(
			array( 'status' => 'completed', 'total_files' => '2', 'processed_files' => '1', 'successful_uploads' => '1', 'failed_uploads' => '1', 'percentage' => 100, 'pending_files' => 0 ),
			$result
		);
		$this->assertEqualsWithDelta( time(), $this->option( 'diluxone_offload_sync_meta' )['last_heartbeat'], 5 );
		$this->assertSame( array( 'uploads/ok.jpg', 'uploads/no.jpg' ), array_column( $this->client->batch_prepared, 'remote_path' ), 'the traversal row never reaches the provider' );
		$this->assertSame( array( '/../../wp-config.php' => 'Path traversal rejected', '/no.jpg' => 'HTTP 403' ), $this->stored_errors() );
		$marked = array_column( array_filter( $this->db->callsOf( 'update' ), static fn( $u ) => isset( $u['data']['synced'] ) ), 'where' );
		$this->assertSame( array( array( 'file' => '/ok.jpg' ) ), $marked );
		$this->assertSame( array( 1000 ), $this->db->prepared[0]['args'] );
	}

	public function test_a_batch_out_of_time_reports_progress(): void {
		$this->meta( array( 'status' => 'started', 'sync_session_id' => 'tab-a', 'last_heartbeat' => time() ) );
		$this->file( 'a.jpg' );
		$this->db->on( 'get_results', '/size DESC/', array( array( 'file' => '/a.jpg', 'size' => 1, 'upload_id' => null ) ) );
		$this->stats( 4, 1, 0, 3 );

		$result = $this->manager()->process_batch( 0.0 );

		$this->assertSame( 'processing', $result['status'] );
		$this->assertSame( 1, $result['uploaded_this_batch'] );
		$this->assertSame( 25.0, $result['percentage'] );
		$this->assertSame( '3', $result['pending_files'] );
		$rounds = array_filter( $this->db->callsOf( 'get_results' ), static fn( $c ) => false !== strpos( $c['sql'], 'size DESC' ) );
		$this->assertCount( 1, $rounds, 'one round only' );
	}

	public function test_a_working_upload_ends_a_recorded_pause(): void {
		$this->meta( array( 'status' => 'started', 'sync_session_id' => 's', 'last_heartbeat' => time() ) );
		$GLOBALS['_test_wp_options'][ ConfigManager::HEALTH_OPTION ] = array( 'status' => 'unhealthy', 'consecutive_failures' => 4, 'error_code' => '403', 'last_check' => time() );
		$this->file( 'a.jpg' );
		$this->db->on( 'get_results', '/size DESC/', array( array( 'file' => '/a.jpg', 'size' => 1, 'upload_id' => null ) ) );

		$this->manager()->process_batch( 0.0 );

		$health = $this->option( ConfigManager::HEALTH_OPTION );
		$this->assertSame( 'healthy', $health['status'] );
		$this->assertSame( 0, $health['consecutive_failures'] );
	}

	public function test_a_round_with_no_success_leaves_a_recorded_pause_alone(): void {
		$this->meta( array( 'status' => 'started', 'sync_session_id' => 's', 'last_heartbeat' => time() ) );
		$GLOBALS['_test_wp_options'][ ConfigManager::HEALTH_OPTION ] = array( 'status' => 'unhealthy', 'consecutive_failures' => 4, 'error_code' => '403', 'last_check' => time() );
		$this->client->verdict = static fn() => 'HTTP 403';
		$this->file( 'a.jpg' );
		$this->db->on( 'get_results', '/size DESC/', array( array( 'file' => '/a.jpg', 'size' => 1, 'upload_id' => null ) ) );

		$this->manager()->process_batch( 0.0 );

		$this->assertSame( 'unhealthy', $this->option( ConfigManager::HEALTH_OPTION )['status'] );
	}

	public function test_a_round_takes_no_more_files_than_the_batch_size(): void {
		$this->meta( array( 'status' => 'started', 'sync_session_id' => 's', 'last_heartbeat' => time() ) );
		$rows = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$this->file( $i . '.jpg' );
			$rows[] = array( 'file' => '/' . $i . '.jpg', 'size' => 1, 'upload_id' => null );
		}
		$this->db->onSequence( 'get_results', '/size DESC/', array( $rows, array() ) );
		$m = $this->manager();
		$m->set_parallel_uploads( 3 ); // batch size 25

		$this->assertSame( 'completed', $m->process_batch( 30.0 )['status'] );

		$this->assertCount( 25, $this->client->batch_prepared );
		$this->assertSame( '/24.jpg', end( $this->client->batch_prepared )['path'] );
	}

	// ── What the pool closes and logs ───────────────────────

	public function test_the_pool_closes_the_file_handle_the_provider_opened_for_a_transfer(): void {
		$entry  = $this->entry( 'a.jpg' );
		$opened = null;
		$this->client->batch_handle = static function ( array $fi ) use ( &$opened ): array {
			$opened = fopen( $fi['local_path'], 'rb' );
			return array( 'success' => true, 'handle' => ScriptedCloudClient::file_handle( $fi['local_path'] ), 'file_handle' => $opened );
		};

		$this->pool( $this->manager(), array( $entry ) );

		$this->assertFalse( is_resource( $opened ), 'closed once its transfer finished' );
	}

	/**
	 * Run $fn with error_log() captured and the Logger reading the options
	 * as they are now (its verbose flag and its dedupe window are static);
	 * returns what was logged.
	 */
	private function logged( callable $fn ): string {
		$sink     = (string) tempnam( sys_get_temp_dir(), 'dlx-log' );
		$previous = (string) ini_get( 'error_log' );
		$verbose  = \DiluxOneOffload\Logger::is_verbose_logging();
		$cache    = new \ReflectionProperty( \DiluxOneOffload\Logger::class, 'log_cache' );
		if ( PHP_VERSION_ID < 80100 ) {
			$cache->setAccessible( true );
		}
		$cache->setValue( null, array() );
		\DiluxOneOffload\Logger::refresh();
		ini_set( 'error_log', $sink );
		try {
			$fn();
		} finally {
			ini_set( 'error_log', $previous );
			\DiluxOneOffload\Logger::set_verbose_logging( $verbose );
		}
		$log = (string) file_get_contents( $sink );
		unlink( $sink );
		return $log;
	}

	public function test_with_debug_logging_each_upload_is_logged_with_its_verdict(): void {
		$this->config( array( 'debug_enabled' => true ) );
		$this->client->verdict = static fn( int $s, string $body ) => 'BAD' === $body ? 'HTTP 403' : null;
		$batch                 = array( $this->entry( 'ok.jpg', 'GOOD' ), $this->entry( 'no.jpg', 'BAD' ) );
		$m                     = $this->manager();

		$log = $this->logged( fn() => $this->pool( $m, $batch ) );

		$this->assertStringContainsString( '[DiluxOne Offload Debug] ✓ UPLOADED: /ok.jpg', $log );
		$this->assertStringContainsString( '[DiluxOne Offload Debug] ✗ FAILED: /no.jpg - Error: HTTP 403', $log );
	}

	public function test_without_debug_logging_the_uploads_are_not_logged_one_by_one(): void {
		$batch = array( $this->entry( 'ok.jpg' ) );
		$m     = $this->manager();

		$log = $this->logged( fn() => $this->pool( $m, $batch ) );

		$this->assertStringNotContainsString( 'UPLOADED', $log );
	}

	public function test_an_upload_whose_row_cannot_be_marked_is_still_counted_and_said(): void {
		$this->config( array( 'debug_enabled' => true ) );
		$this->meta( array( 'status' => 'started', 'sync_session_id' => 's', 'last_heartbeat' => time() ) );
		$this->file( 'a.jpg' );
		$this->db->on( 'get_results', '/size DESC/', array( array( 'file' => '/a.jpg', 'size' => 1, 'upload_id' => null ) ) );
		$this->db->on( 'update', '/./', 0 );
		$m = $this->manager();

		$result = null;
		$log    = $this->logged(
			function () use ( $m, &$result ) {
				$result = $m->process_batch( 0.0 );
			}
		);

		$this->assertSame( 1, $result['uploaded_this_batch'], 'the file is in the cloud' );
		$this->assertStringContainsString( 'Upload succeeded but DB update failed for: /a.jpg', $log );
	}
}
