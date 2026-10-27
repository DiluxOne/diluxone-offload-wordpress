<?php
namespace Tests\Unit\Repro;

use DiluxOneOffload\Providers\S3CompatibleProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #102: on Google Cloud Storage a download reads through the signed
 * endpoint, which Google's cache serves for as long as the object's
 * Cache-Control allows. A key overwritten inside that window comes back with
 * the old bytes, and they were written to disk without a word. The listing
 * (ListObjectsV2) is never cached, so its ETag is the MD5 of what is stored.
 */
class Issue102Test extends TestCase {

	private const STORED = 'the bytes the bucket holds now';
	private const STALE  = 'an earlier run\'s copy, still cached';

	private string $dest = '';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_wp_options']    = array();
		$GLOBALS['_test_wp_transients'] = array();
		$GLOBALS['_test_wp_http_log']   = array();
		$this->dest                     = sys_get_temp_dir() . '/issue102-' . uniqid() . '/photo.png';
	}

	protected function tearDown(): void {
		if ( file_exists( $this->dest ) ) {
			unlink( $this->dest );
		}
		if ( is_dir( dirname( $this->dest ) ) ) {
			rmdir( dirname( $this->dest ) );
		}
		unset( $GLOBALS['_test_wp_http'], $GLOBALS['_test_wp_http_log'], $GLOBALS['_test_wp_transients'], $GLOBALS['_test_wp_options'] );
		parent::tearDown();
	}

	private static function google(): S3CompatibleProvider {
		return new S3CompatibleProvider(
			array(
				'preset'            => 'gcs',
				'endpoint'          => 'https://storage.googleapis.com',
				'region'            => 'auto',
				'bucket'            => 'media',
				'access_key_id'     => 'GOOGUNIT',
				'secret_access_key' => 'unit-test-secret',
				'public_url'        => 'https://storage.googleapis.com/media',
				'path_style'        => true,
				'cache_control'     => 'public, max-age=604800',
			)
		);
	}

	/** Google: the object's GET answers $served from its cache; the listing tells what is stored. */
	private function bucket_serves( string $served, string $stored ): void {
		$GLOBALS['_test_wp_http'] = function ( string $method, string $url ) use ( $served, $stored ): array {
			if ( false !== strpos( $url, 'list-type=2' ) ) {
				$body = '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Name>media</Name>'
					. '<Contents><Key>uploads/photo.png</Key><Size>' . strlen( $stored ) . '</Size><ETag>"' . md5( $stored ) . '"</ETag><LastModified>2026-10-26T00:00:00.000Z</LastModified></Contents>'
					. '<IsTruncated>false</IsTruncated></ListBucketResult>';
				return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => $body, 'headers' => array() );
			}
			return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => $served, 'headers' => array( 'etag' => '"' . md5( $served ) . '"' ) );
		};
	}

	public function test_a_download_never_writes_the_cached_old_copy_of_an_overwritten_object(): void {
		$this->bucket_serves( self::STALE, self::STORED );

		$result = self::google()->download_file( 'uploads/photo.png', $this->dest );

		if ( empty( $result['success'] ) ) {
			$this->assertFileDoesNotExist( $this->dest, 'A download that could not get the stored object fails and leaves nothing on disk.' );
			return;
		}
		$this->assertFileExists( $this->dest );
		$this->assertSame( md5( self::STORED ), md5_file( $this->dest ), 'A download reported as done has the stored object\'s MD5, never the copy Google\'s cache served.' );
	}

	public function test_a_download_whose_md5_matches_the_listing_is_written(): void {
		$this->bucket_serves( self::STORED, self::STORED );

		$result = self::google()->download_file( 'uploads/photo.png', $this->dest );

		$this->assertTrue( $result['success'] );
		$this->assertSame( self::STORED, file_get_contents( $this->dest ) );
	}
}
