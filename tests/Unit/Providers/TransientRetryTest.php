<?php
namespace Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\Interfaces\CloudStorageClientInterface;
use DiluxOneOffload\Providers\AzureProvider;
use DiluxOneOffload\Providers\S3CompatibleProvider;

/**
 * A transient answer (500, 502, 503, 504, a dropped connection) is asked
 * again, up to three times in all, on both providers; a client error and a
 * timeout are not. Backblaze B2 answers 500 "internal incident" to about one
 * upload in a hundred: without this, that upload (a thumbnail, say) is lost.
 */
class TransientRetryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_wp_options']    = array();
		$GLOBALS['_test_wp_transients'] = array();
		$GLOBALS['_test_wp_http_log']   = array();
		unset( $GLOBALS['_test_wp_http'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_test_wp_http'], $GLOBALS['_test_wp_http_log'], $GLOBALS['_test_wp_transients'], $GLOBALS['_test_wp_options'] );
		parent::tearDown();
	}

	/** @return array<string, array{\Closure(): CloudStorageClientInterface, int, int}> What each answers to a PUT and to a DELETE that worked. */
	public function providers(): array {
		return array(
			'azure' => array(
				fn() => new AzureProvider( array( 'storage_account' => 'racct', 'container_name' => 'media', 'access_key' => base64_encode( random_bytes( 32 ) ) ) ),
				201,
				202,
			),
			's3'    => array(
				fn() => new S3CompatibleProvider(
					array(
						'preset'            => 'custom',
						'endpoint'          => 'https://s3.example.com',
						'region'            => 'us-east-1',
						'bucket'            => 'media',
						'access_key_id'     => 'AKIDUNIT',
						'secret_access_key' => 'unit-secret',
						'public_url'        => 'https://cdn.example.com',
						'path_style'        => true,
					)
				),
				200,
				204,
			),
		);
	}

	private static function reply( int $code, string $body = '' ): array {
		return array( 'response' => array( 'code' => $code, 'message' => '' ), 'body' => $body, 'headers' => array() );
	}

	/** Answers in turn, the last one for every request after. */
	private function answers( array $answers ): void {
		$GLOBALS['_test_wp_http'] = function () use ( &$answers ) {
			return count( $answers ) > 1 ? array_shift( $answers ) : $answers[0];
		};
	}

	private function tmp(): string {
		$f = (string) tempnam( sys_get_temp_dir(), 'tr' );
		file_put_contents( $f, str_repeat( 'x', 2048 ) );
		return $f;
	}

	/**
	 * @dataProvider providers
	 * @param \Closure(): CloudStorageClientInterface $make
	 */
	public function test_an_upload_that_meets_a_transient_error_goes_through_on_a_retry( \Closure $make, int $created ): void {
		foreach ( array( 500, 502, 503, 504 ) as $status ) {
			$GLOBALS['_test_wp_http_log'] = array();
			$this->answers( array( self::reply( $status, 'internal incident' ), self::reply( $created ) ) );
			$result = $make()->upload_file( $this->tmp(), 'uploads/2026/09/thumb-150x150.png' );
			$this->assertTrue( $result['success'], "after a $status: " . ( $result['error'] ?? '' ) );
			$this->assertCount( 2, $GLOBALS['_test_wp_http_log'], "one retry after a $status" );
		}
	}

	/**
	 * @dataProvider providers
	 * @param \Closure(): CloudStorageClientInterface $make
	 */
	public function test_a_dropped_connection_is_retried_too( \Closure $make, int $created ): void {
		$this->answers( array( new \WP_Error( 'http_request_failed', 'cURL error 56: Connection reset by peer' ), self::reply( $created ) ) );
		$this->assertTrue( $make()->upload_file( $this->tmp(), 'uploads/a.png' )['success'] );
		$this->assertCount( 2, $GLOBALS['_test_wp_http_log'] );
	}

	/**
	 * @dataProvider providers
	 * @param \Closure(): CloudStorageClientInterface $make
	 */
	public function test_three_transient_errors_in_a_row_fail_with_the_last_answer( \Closure $make ): void {
		$this->answers( array( self::reply( 503, 'slow down' ) ) );
		$result = $make()->upload_file( $this->tmp(), 'uploads/a.png' );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( '503', $result['error'] );
		$this->assertCount( 3, $GLOBALS['_test_wp_http_log'], 'three attempts, no more' );
	}

	/**
	 * @dataProvider providers
	 * @param \Closure(): CloudStorageClientInterface $make
	 */
	public function test_a_client_error_is_not_retried( \Closure $make ): void {
		foreach ( array( 400, 403, 404 ) as $status ) {
			$GLOBALS['_test_wp_http_log'] = array();
			$this->answers( array( self::reply( $status ) ) );
			$this->assertFalse( $make()->upload_file( $this->tmp(), 'uploads/a.png' )['success'] );
			$this->assertCount( 1, $GLOBALS['_test_wp_http_log'], "a $status is the request's fault or the key's" );
		}
	}

	/**
	 * @dataProvider providers
	 * @param \Closure(): CloudStorageClientInterface $make
	 */
	public function test_a_timeout_is_not_retried( \Closure $make ): void {
		$this->answers( array( new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 30001 milliseconds' ) ) );
		$this->assertFalse( $make()->upload_file( $this->tmp(), 'uploads/a.png' )['success'] );
		$this->assertCount( 1, $GLOBALS['_test_wp_http_log'], 'the time is already spent' );
	}

	/**
	 * @dataProvider providers
	 * @param \Closure(): CloudStorageClientInterface $make
	 */
	public function test_deletes_and_checks_are_retried_as_well( \Closure $make, int $created, int $deleted ): void {
		$this->answers( array( self::reply( 500 ), self::reply( $deleted ) ) );
		$this->assertTrue( $make()->delete_file( 'uploads/a.png' )['success'] );
		$this->assertCount( 2, $GLOBALS['_test_wp_http_log'] );

		$GLOBALS['_test_wp_http_log'] = array();
		$this->answers( array( self::reply( 503 ), self::reply( 200 ) ) );
		$this->assertTrue( $make()->file_exists( 'uploads/a.png' ) );
		$this->assertCount( 2, $GLOBALS['_test_wp_http_log'] );
	}
}
