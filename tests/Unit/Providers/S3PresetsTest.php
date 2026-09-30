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
			'hetzner'  => array( 'hetzner', 'nbg1', 'https://nbg1.your-objectstorage.com', 'https://demo.nbg1.your-objectstorage.com' ),
			'linode'   => array( 'linode', 'us-ord-10', 'https://us-ord-10.linodeobjects.com', 'https://demo.us-ord-10.linodeobjects.com' ),
			'vultr'    => array( 'vultr', 'ams1', 'https://ams1.vultrobjects.com', 'https://demo.ams1.vultrobjects.com' ),
			'scaleway' => array( 'scaleway', 'nl-ams', 'https://s3.nl-ams.scw.cloud', 'https://demo.s3.nl-ams.scw.cloud' ),
			'ovh'      => array( 'ovh', 'sbg', 'https://s3.sbg.io.cloud.ovh.net', 'https://demo.s3.sbg.io.cloud.ovh.net' ),
		);
	}

	/** @dataProvider derived */
	public function test_a_preset_derives_the_endpoint_and_the_public_url( string $preset, string $region, string $endpoint, string $public ): void {
		$this->assertSame( $endpoint, S3Presets::endpoint( $preset, $region ) );
		$this->assertSame( $public, S3Presets::public_url( $preset, 'demo', $region ) );
	}

	public function test_idrive_e2_fills_the_endpoint_and_leaves_the_public_url_to_the_user(): void {
		$this->assertSame( 'https://s3.eu-west-4.idrivee2.com', S3Presets::endpoint( 'idrive', 'eu-west-4' ) );
		$this->assertSame( '', S3Presets::public_url( 'idrive', 'demo', 'eu-west-4' ) );
	}

	public function test_the_object_acl_is_offered_only_where_the_service_honours_it(): void {
		foreach ( array( 'aws', 'spaces', 'wasabi', 'hetzner', 'vultr', 'scaleway', 'ovh' ) as $preset ) {
			$this->assertTrue( S3Presets::offers_acl( $preset ), $preset );
		}
		foreach ( array( 'r2', 'b2', 'gcs', 'linode', 'idrive', 'custom' ) as $preset ) {
			$this->assertFalse( S3Presets::offers_acl( $preset ), $preset );
		}
	}

	public function test_scaleway_allows_1000_parts_and_the_rest_10000(): void {
		$this->assertSame( 1000, S3Presets::max_parts( 'scaleway' ) );
		$this->assertSame( 10000, S3Presets::max_parts( 'aws' ) );
		$this->assertSame( 10000, S3Presets::max_parts( 'dropbox' ) );
	}

	public function test_every_preset_has_a_hint_on_the_connection_form(): void {
		$form = (string) file_get_contents( dirname( __DIR__, 3 ) . '/templates/admin-provider-connection.php' );
		foreach ( array_keys( S3Presets::all() ) as $preset ) {
			$this->assertStringContainsString( 'data-preset="' . $preset . '"', $form, $preset );
		}
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
