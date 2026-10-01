<?php
namespace Tests\Unit\Support;

use DiluxOneOffload\DTOs\ChunkedUpload;
use DiluxOneOffload\Interfaces\CloudStorageClientInterface;

/**
 * A provider stand-in for the sync engine's unit tests.
 *
 * Every answer the engine reads is a public property or closure a test sets,
 * and every call is recorded. The parallel paths get real cURL handles on
 * file:// URLs: curl_multi runs them for real, offline, a missing file is a
 * real transport error, and the verdict on each finished transfer is the
 * closure's (verify_upload_response, finish_part), which is where the
 * provider would read its status and body.
 */
class ScriptedCloudClient implements CloudStorageClientInterface {

	/** @var array{success: bool, message: string} */
	public array $connection = array( 'success' => true, 'message' => 'ok' );

	/** @var array<int, array<string, mixed>> What list_files() returns. */
	public array $files = array();

	/** @var string[] Prefixes list_files() was asked for. */
	public array $listed = array();

	/** @var array<string, array{files: array<int, array<string, mixed>>, next: string}> list_page() answers by marker. */
	public array $pages = array();

	/** @var string[] Markers list_page() was asked for. */
	public array $page_markers = array();

	/** @var string[] Paths delete_file() refuses. */
	public array $undeletable = array();

	/** @var string[] Paths deleted. */
	public array $deleted = array();

	/** @var \Closure|null fn(int $status, string $body): ?string */
	public ?\Closure $verdict = null;

	/** @var \Closure|null fn(array $file_info): array — otherwise a file:// handle on the local file. */
	public ?\Closure $batch_handle = null;

	/** @var \Closure|null fn(array $file_info, ?string $resume): array */
	public ?\Closure $begin = null;

	/** @var \Closure|null fn(ChunkedUpload $u, int $part): array — otherwise a file:// handle. */
	public ?\Closure $part_handle = null;

	/** @var \Closure|null fn(ChunkedUpload $u, int $part, int $status, string $body): ?string */
	public ?\Closure $part_verdict = null;

	/** @var \Closure|null fn(ChunkedUpload $u): array — otherwise a file:// handle. */
	public ?\Closure $commit_handle = null;

	/** @var \Closure|null fn(array $file_info): array */
	public ?\Closure $download_handle = null;

	/** @var array<int, array<string, mixed>> */
	public array $batch_prepared = array();

	/** @var array<int, array{0: string, 1: ?string}> remote path and resume id of each begin. */
	public array $begun = array();

	/** @var array<int, int> Parts prepared, in order. */
	public array $parts_prepared = array();

	/** @var int */
	public int $commits = 0;

	/** @var ChunkedUpload[] */
	public array $aborted = array();

	/** @var int How many times an on_failure callback ran. */
	public int $failure_callbacks = 0;

	/**
	 * A file:// handle reading $path. A path that is not there makes curl
	 * report a transport error, as a dropped connection would.
	 *
	 * @return resource|\CurlHandle
	 */
	public static function file_handle( string $path ) {
		$ch = curl_init( 'file://' . $path );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		return $ch;
	}

	/** @return array<string, mixed> */
	private static function handle_on( string $path ): array {
		return array(
			'success'     => true,
			'handle'      => self::file_handle( $path ),
			'file_handle' => null,
		);
	}

	public function test_connection(): array {
		return $this->connection;
	}

	public function upload_file( string $local_path, string $remote_path, array $options = array() ): array {
		return array( 'success' => true );
	}

	public function download_file( string $remote_path, string $local_path ): array {
		return array( 'success' => true );
	}

	public function file_exists( string $remote_path ): bool {
		return false;
	}

	public function get_file_checksum( string $remote_path ) {
		return false;
	}

	public function get_file_info( string $remote_path ) {
		return false;
	}

	public function delete_file( string $remote_path ): array {
		if ( in_array( $remote_path, $this->undeletable, true ) ) {
			return array(
				'success' => false,
				'error'   => 'HTTP 403',
			);
		}
		$this->deleted[] = $remote_path;
		return array( 'success' => true );
	}

	public function copy_blob( string $source_path, string $dest_path ): array {
		return array( 'success' => true );
	}

	public function list_files( string $remote_path = '' ): array {
		$this->listed[] = $remote_path;
		return $this->files;
	}

	public function list_page( string $prefix, string $marker = '' ): array {
		$this->page_markers[] = $marker;
		return $this->pages[ $marker ] ?? array(
			'files' => array(),
			'next'  => '',
		);
	}

	public function get_file_url( string $remote_path ): string {
		return 'https://cloud.test/' . $remote_path;
	}

	public function get_provider_name(): string {
		return 'scripted';
	}

	public function get_storage_stats( bool $force_refresh = false ): array {
		return array();
	}

	public function describe_error_body( string $body ): string {
		return '';
	}

	public function verify_upload_response( int $status, string $body ): ?string {
		return null === $this->verdict ? null : ( $this->verdict )( $status, $body );
	}

	public function prepare_batch_upload_handle( array $file_info ): array {
		$this->batch_prepared[] = $file_info;
		if ( null !== $this->batch_handle ) {
			return ( $this->batch_handle )( $file_info );
		}
		$client                = $this;
		$data                  = self::handle_on( (string) $file_info['local_path'] );
		$data['on_failure']    = static function () use ( $client ): void {
			++$client->failure_callbacks;
		};
		return $data;
	}

	public function begin_chunked_upload( array $file_info, ?string $resume_upload_id = null ): array {
		$this->begun[] = array( (string) $file_info['remote_path'], $resume_upload_id );
		if ( null !== $this->begin ) {
			return ( $this->begin )( $file_info, $resume_upload_id );
		}
		return array(
			'success' => false,
			'error'   => 'no chunked uploads scripted',
		);
	}

	public function prepare_part_handle( ChunkedUpload $upload, int $part ): array {
		$this->parts_prepared[] = $part;
		return null !== $this->part_handle ? ( $this->part_handle )( $upload, $part ) : self::handle_on( $upload->localPath() );
	}

	public function finish_part( ChunkedUpload $upload, int $part, int $status, string $body ): ?string {
		if ( null !== $this->part_verdict ) {
			return ( $this->part_verdict )( $upload, $part, $status, $body );
		}
		$upload->recordTag( $part, 'tag' . $part );
		return null;
	}

	public function prepare_commit_handle( ChunkedUpload $upload ): array {
		++$this->commits;
		return null !== $this->commit_handle ? ( $this->commit_handle )( $upload ) : self::handle_on( $upload->localPath() );
	}

	public function abort_chunked_upload( ChunkedUpload $upload ): void {
		$this->aborted[] = $upload;
	}

	public function prepare_download_handle( array $file_info ): array {
		if ( null !== $this->download_handle ) {
			return ( $this->download_handle )( $file_info );
		}
		return array(
			'success' => false,
			'error'   => 'no downloads scripted',
		);
	}
}
