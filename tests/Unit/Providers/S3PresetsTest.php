<?php
namespace Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\Providers\S3Presets;

/**
 * The preset table: what each service derives from a region and a bucket,
 * and what it leaves for the user to type.
 */
class S3PresetsTest extends TestCase {

	/** @return array<string,array{0:string,1:string,2:string,3:string}> */
	public function derived(): array {
		return array(
			'aws'    => array( 'aws', 'eu-west-1', 'https://s3.eu-west-1.amazonaws.com', 'https://demo.s3.eu-west-1.amazonaws.com' ),
			'b2'     => array( 'b2', 'us-west-004', 'https://s3.us-west-004.backblazeb2.com', 'https://demo.s3.us-west-004.backblazeb2.com' ),
			'spaces' => array( 'spaces', 'nyc3', 'https://nyc3.digitaloceanspaces.com', 'https://demo.nyc3.digitaloceanspaces.com' ),
			'wasabi' => array( 'wasabi', 'us-east-1', 'https://s3.us-east-1.wasabisys.com', 'https://s3.us-east-1.wasabisys.com/demo' ),
			'gcs'    => array( 'gcs', 'auto', 'https://storage.googleapis.com', 'https://storage.googleapis.com/demo' ),
		);
	}

	/** @dataProvider derived */
	public function test_a_preset_derives_the_endpoint_and_the_public_url( string $preset, string $region, string $endpoint, string $public ): void {
		$this->assertSame( $endpoint, S3Presets::endpoint( $preset, $region ) );
		$this->assertSame( $public, S3Presets::public_url( $preset, 'demo', $region ) );
	}

	public function test_r2_and_custom_leave_both_urls_to_the_user(): void {
		foreach ( array( 'r2', 'custom' ) as $preset ) {
			$this->assertSame( '', S3Presets::endpoint( $preset, 'auto' ), $preset );
			$this->assertSame( '', S3Presets::public_url( $preset, 'demo', 'auto' ), $preset );
		}
	}

	public function test_only_amazon_s3_addresses_the_bucket_in_the_host_name(): void {
		foreach ( array_keys( S3Presets::all() ) as $preset ) {
			$this->assertSame( 'aws' !== $preset, S3Presets::path_style( $preset ), $preset );
		}
	}

	public function test_only_custom_accepts_a_plain_http_endpoint(): void {
		foreach ( array_keys( S3Presets::all() ) as $preset ) {
			$this->assertSame( 'custom' === $preset, S3Presets::allows_http( $preset ), $preset );
		}
	}

	public function test_r2_and_google_have_a_fixed_region(): void {
		$this->assertTrue( S3Presets::region_is_fixed( 'r2' ) );
		$this->assertSame( 'auto', S3Presets::default_region( 'r2' ) );
		$this->assertTrue( S3Presets::region_is_fixed( 'gcs' ) );
		$this->assertFalse( S3Presets::region_is_fixed( 'aws' ) );
		$this->assertSame( 'us-east-1', S3Presets::default_region( 'aws' ) );
	}

	public function test_an_unknown_preset_is_not_one(): void {
		$this->assertFalse( S3Presets::exists( 'dropbox' ) );
		$this->assertSame( '', S3Presets::label( 'dropbox' ) );
		$this->assertSame( '', S3Presets::endpoint( 'dropbox', 'x' ) );
		$this->assertTrue( S3Presets::exists( 'aws' ) );
		$this->assertSame( 'Amazon S3', S3Presets::label( 'aws' ) );
	}
}
