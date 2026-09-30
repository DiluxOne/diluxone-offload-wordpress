<?php
namespace Tests\Unit\DTOs;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\DTOs\PluginSettings;
use DiluxOneOffload\DTOs\ProviderConfig;
use DiluxOneOffload\DTOs\PluginConfig;
use DiluxOneOffload\DTOs\AzureConfig;
use DiluxOneOffload\DTOs\FileInfo;

/**
 * Unit tests for the configuration value objects.
 *
 * merge() is the method that matters here: the admin saves partial updates, so
 * a merge that drops untouched keys silently resets settings the user never
 * opened — and for provider_config it would drop stored credentials. Both are
 * tested for what they PRESERVE, not only for what they change.
 *
 * Pure value objects: no WordPress runtime needed.
 */
class ConfigObjectsTest extends TestCase {

	// ── PluginSettings: defaults and validation ─────────────

	public function test_defaults_are_the_documented_ones(): void {
		$s = new PluginSettings();
		$this->assertFalse( $s->isDebugEnabled() );
		$this->assertTrue( $s->shouldKeepLocalFiles() );
		$this->assertTrue( $s->shouldAutoActivateOffloading() );
		$this->assertTrue( $s->shouldForceHttpsOnCloud() );
		$this->assertSame( 60, $s->getTimeout() );
		$this->assertSame( '*', $s->getAllowedFileTypes() );
	}

	public function test_zero_timeout_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		new PluginSettings( false, true, true, true, 0 );
	}

	public function test_negative_timeout_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		new PluginSettings( false, true, true, true, -5 );
	}

	public function test_zero_max_file_size_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		new PluginSettings( false, true, true, true, 60, 0 );
	}

	public function test_max_file_size_converts_to_megabytes(): void {
		$s = new PluginSettings( false, true, true, true, 60, 20971520 );
		$this->assertSame( 20.0, $s->getMaxFileSizeMB() );
	}

	public function test_one_megabyte_reads_as_one(): void {
		$s = new PluginSettings( false, true, true, true, 60, 1048576 );
		$this->assertSame( 1.0, $s->getMaxFileSizeMB() );
	}

	// ── PluginSettings: merge ───────────────────────────────

	public function test_merge_changes_only_the_given_key(): void {
		$s = ( new PluginSettings() )->merge( array( 'debug_enabled' => true ) );
		$this->assertTrue( $s->isDebugEnabled() );
		$this->assertSame( 60, $s->getTimeout(), 'an untouched setting must survive the merge' );
		$this->assertTrue( $s->shouldKeepLocalFiles() );
	}

	public function test_merge_returns_a_new_instance(): void {
		$a = new PluginSettings();
		$b = $a->merge( array( 'debug_enabled' => true ) );
		$this->assertNotSame( $a, $b );
		$this->assertFalse( $a->isDebugEnabled(), 'the original must not change' );
	}

	public function test_merging_nothing_keeps_everything(): void {
		$a = new PluginSettings( true, false, false, false, 30, 1048576, 'jpg' );
		$this->assertSame( $a->toArray(), $a->merge( array() )->toArray() );
	}

	public function test_settings_round_trip_through_array(): void {
		$a = new PluginSettings( true, false, false, false, 90, 5242880, 'jpg,png' );
		$this->assertSame( $a->toArray(), PluginSettings::fromArray( $a->toArray() )->toArray() );
	}

	// ── ProviderConfig ──────────────────────────────────────

	public function test_empty_provider_is_not_configured(): void {
		$this->assertFalse( ( new ProviderConfig() )->isConfigured() );
	}

	public function test_provider_without_config_is_not_configured(): void {
		$this->assertFalse( ( new ProviderConfig( 'azure' ) )->isConfigured() );
	}

	public function test_provider_with_config_is_configured(): void {
		$p = new ProviderConfig( 'azure', array( 'storage_account' => 'acct' ) );
		$this->assertTrue( $p->isConfigured() );
		$this->assertSame( 'azure', $p->getCloudProvider() );
	}

	// ── fingerprint and describe ────────────────────────────

	public function test_the_fingerprint_covers_every_field_the_secret_included(): void {
		$tested = new ProviderConfig( 'azure', array( 'storage_account' => 'acct', 'container_name' => 'media', 'access_key' => 'k1' ) );

		$this->assertSame( $tested->fingerprint(), ( new ProviderConfig( 'azure', array( 'access_key' => 'k1', 'container_name' => 'media', 'storage_account' => 'acct' ) ) )->fingerprint(), 'key order does not matter' );
		$this->assertNotSame( $tested->fingerprint(), ( new ProviderConfig( 'azure', array( 'storage_account' => 'acct', 'container_name' => 'media', 'access_key' => 'k2' ) ) )->fingerprint(), 'another key is another configuration' );
		$this->assertNotSame( $tested->fingerprint(), ( new ProviderConfig( 'azure', array( 'storage_account' => 'acct', 'container_name' => 'other', 'access_key' => 'k1' ) ) )->fingerprint() );
		$this->assertStringNotContainsString( 'k1', $tested->fingerprint() );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $tested->fingerprint() );
	}

	public function test_describe_gives_the_rows_of_an_azure_connection_and_never_the_key(): void {
		$rows = ( new ProviderConfig( 'azure', array( 'storage_account' => 'acct', 'container_name' => 'media', 'access_key' => 'secret-key' ) ) )->describe();

		$this->assertSame(
			array(
				'Storage Account'   => 'acct',
				'Container'         => 'media',
				'Media served from' => 'https://acct.blob.core.windows.net/media/',
			),
			$rows
		);
		$this->assertNotContains( 'secret-key', $rows );
	}

	public function test_describe_is_empty_for_an_unknown_provider(): void {
		$this->assertSame( array(), ( new ProviderConfig( 'gcp', array( 'bucket' => 'b' ) ) )->describe() );
	}

	// ── S3-compatible: fromPost and describe ────────────────

	/** @param array<string,string> $over */
	private static function s3_post( array $over = array() ): array {
		return $over + array(
			'cloud_provider'       => 's3',
			's3_preset'            => 'aws',
			's3_region'            => 'EU-West-1',
			's3_endpoint'          => 'HTTPS://S3.eu-west-1.amazonaws.com/?x=1#f',
			's3_bucket'            => 'demo-media',
			's3_access_key_id'     => 'AKIAEXAMPLE',
			's3_secret_access_key' => 'secret/with+symbols',
			's3_public_url'        => 'https://CDN.example.com/media/',
		);
	}

	public function test_an_s3_form_maps_to_the_stored_keys_normalised(): void {
		$config = ProviderConfig::fromPost( self::s3_post() )->getProviderConfig();

		$this->assertSame(
			array(
				'preset'            => 'aws',
				'endpoint'          => 'https://s3.eu-west-1.amazonaws.com',
				'region'            => 'eu-west-1',
				'bucket'            => 'demo-media',
				'access_key_id'     => 'AKIAEXAMPLE',
				'secret_access_key' => 'secret/with+symbols',
				'public_url'        => 'https://cdn.example.com/media',
				'path_style'        => false,
				'object_acl'        => false,
			),
			$config
		);
	}

	public function test_the_object_acl_is_kept_only_where_the_service_honours_one(): void {
		$this->assertTrue( ProviderConfig::fromPost( self::s3_post( array( 's3_object_acl' => '1' ) ) )->getProviderConfig()['object_acl'] );
		$this->assertTrue( ProviderConfig::fromPost( self::s3_post( array( 's3_preset' => 'spaces', 's3_endpoint' => 'https://nyc3.digitaloceanspaces.com', 's3_object_acl' => '1' ) ) )->getProviderConfig()['object_acl'] );
		$this->assertFalse( ProviderConfig::fromPost( self::s3_post( array( 's3_preset' => 'r2', 's3_endpoint' => 'https://a.r2.cloudflarestorage.com', 's3_object_acl' => '1' ) ) )->getProviderConfig()['object_acl'], 'R2 has no object ACL' );
	}

	public function test_the_addressing_style_is_the_services_except_under_custom(): void {
		$this->assertFalse( ProviderConfig::fromPost( self::s3_post( array( 's3_path_style' => 'path' ) ) )->getProviderConfig()['path_style'], 'Amazon stays virtual-hosted' );
		$custom = array( 's3_preset' => 'custom', 's3_endpoint' => 'https://ceph.example.com' );
		$this->assertTrue( ProviderConfig::fromPost( self::s3_post( $custom ) )->getProviderConfig()['path_style'] );
		$this->assertFalse( ProviderConfig::fromPost( self::s3_post( $custom + array( 's3_path_style' => 'virtual' ) ) )->getProviderConfig()['path_style'] );
	}

	public function test_a_fixed_region_service_stores_its_own_region_whatever_was_posted(): void {
		$config = ProviderConfig::fromPost( self::s3_post( array( 's3_preset' => 'r2', 's3_region' => 'us-east-1', 's3_endpoint' => 'https://acc.r2.cloudflarestorage.com' ) ) )->getProviderConfig();
		$this->assertSame( 'auto', $config['region'] );
		$this->assertTrue( $config['path_style'] );
	}

	/** @return array<string,array{0:array<string,string>,1:string}> */
	public function s3_refusals(): array {
		return array(
			'unknown service'   => array( array( 's3_preset' => 'dropbox' ), 'Service is required' ),
			'no bucket'         => array( array( 's3_bucket' => '' ), 'Bucket is required' ),
			'no secret'         => array( array( 's3_secret_access_key' => '' ), 'Secret Access Key is required' ),
			'no public url'     => array( array( 's3_public_url' => '' ), 'Public URL is required' ),
			'bad region'        => array( array( 's3_region' => 'eu west 1' ), 'Region must be' ),
			'upper-case bucket' => array( array( 's3_bucket' => 'Demo' ), 'Bucket must be' ),
			'ip bucket'         => array( array( 's3_bucket' => '192.168.1.10' ), 'Bucket must be' ),
			'dotted on amazon'  => array( array( 's3_bucket' => 'media.example.com' ), 'Bucket names with dots' ),
			'http endpoint'     => array( array( 's3_endpoint' => 'http://s3.example.com' ), 'Endpoint must be an https:// URL' ),
			'ftp public url'    => array( array( 's3_public_url' => 'ftp://cdn.example.com' ), 'Public URL ' ),
			'long key id'       => array( array( 's3_access_key_id' => str_repeat( 'A', 129 ) ), 'Access Key ID must be' ),
		);
	}

	/**
	 * @dataProvider s3_refusals
	 * @param array<string,string> $over
	 */
	public function test_an_s3_form_is_refused_with_the_field_named_and_no_value( array $over, string $message ): void {
		try {
			ProviderConfig::fromPost( self::s3_post( $over ) );
			$this->fail( 'expected a refusal' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringStartsWith( $message, $e->getMessage() );
			$this->assertStringNotContainsString( 'secret/with+symbols', $e->getMessage() );
		}
	}

	public function test_only_custom_accepts_a_plain_http_endpoint(): void {
		$config = ProviderConfig::fromPost( self::s3_post( array( 's3_preset' => 'custom', 's3_endpoint' => 'http://minio:9000', 's3_public_url' => 'http://minio:9000/e2e' ) ) )->getProviderConfig();
		$this->assertSame( 'http://minio:9000', $config['endpoint'] );
		$this->assertSame( 'http://minio:9000/e2e', $config['public_url'] );
	}

	public function test_describe_gives_the_rows_of_an_s3_connection_and_never_the_secret(): void {
		$rows = ProviderConfig::fromPost( self::s3_post() )->describe();

		$this->assertSame(
			array(
				'Service'           => 'Amazon S3',
				'Bucket'            => 'demo-media',
				'Endpoint'          => 'https://s3.eu-west-1.amazonaws.com',
				'Region'            => 'eu-west-1',
				'Access Key ID'           => 'AKIAEXAMPLE',
				'Uploads are made public' => 'No, the bucket decides',
				'Media served from'       => 'https://cdn.example.com/media/',
			),
			$rows
		);
		$this->assertNotContains( 'secret/with+symbols', $rows );
	}

	public function test_every_supported_provider_names_its_form_fields(): void {
		foreach ( array_keys( \DiluxOneOffload\Factories\CloudStorageFactory::get_supported_providers() ) as $provider ) {
			$this->assertNotEmpty( ProviderConfig::FORM_FIELDS[ $provider ] ?? array(), $provider );
		}
	}

	/**
	 * The nested merge is the one that protects credentials: a partial update
	 * touching only the container name must not wipe the stored access key.
	 */
	public function test_merge_preserves_untouched_credentials(): void {
		$p = new ProviderConfig(
			'azure',
			array(
				'storage_account' => 'acct',
				'container_name'  => 'old',
				'access_key'      => 'SECRET',
			)
		);

		$merged = $p->merge( array( 'provider_config' => array( 'container_name' => 'new' ) ) );

		$this->assertSame( 'new', $merged->getProviderConfig()['container_name'] );
		$this->assertSame( 'SECRET', $merged->getProviderConfig()['access_key'], 'the key must survive a partial update' );
		$this->assertSame( 'acct', $merged->getProviderConfig()['storage_account'] );
	}

	public function test_merge_can_switch_provider(): void {
		$p = ( new ProviderConfig( 'azure', array( 'a' => 1 ) ) )->merge( array( 'cloud_provider' => 'aws' ) );
		$this->assertSame( 'aws', $p->getCloudProvider() );
	}

	public function test_provider_merge_returns_a_new_instance(): void {
		$a = new ProviderConfig( 'azure', array( 'k' => 'v' ) );
		$b = $a->merge( array( 'cloud_provider' => 'aws' ) );
		$this->assertNotSame( $a, $b );
		$this->assertSame( 'azure', $a->getCloudProvider() );
	}

	public function test_provider_accessors(): void {
		$p = new ProviderConfig(
			'azure',
			array(
				'storage_account' => 'acct',
				'container_name'  => 'cont',
			)
		);
		$this->assertSame( 'acct', $p->getStorageAccount() );
		$this->assertSame( 'cont', $p->getContainerName() );
	}

	// ── PluginConfig ────────────────────────────────────────

	public function test_plugin_config_delegates_to_its_parts(): void {
		$c = new PluginConfig(
			new ProviderConfig( 'azure', array( 'storage_account' => 'acct' ) ),
			new PluginSettings( true, false, false, true, 30, 1048576, 'jpg' )
		);
		$this->assertTrue( $c->hasCloudProvider() );
		$this->assertSame( 'azure', $c->getCloudProvider() );
		$this->assertTrue( $c->isDebugEnabled() );
		$this->assertSame( 30, $c->getTimeout() );
		$this->assertSame( 1.0, $c->getMaxFileSizeMB() );
		$this->assertSame( 'jpg', $c->getAllowedFileTypes() );
	}

	public function test_plugin_config_without_provider(): void {
		$c = new PluginConfig( new ProviderConfig(), new PluginSettings() );
		$this->assertFalse( $c->hasCloudProvider() );
		$this->assertSame( '', $c->getCloudProvider() );
	}

	public function test_with_provider_returns_a_new_instance(): void {
		$a = new PluginConfig( new ProviderConfig(), new PluginSettings() );
		$b = $a->withProvider( new ProviderConfig( 'azure', array( 'k' => 'v' ) ) );
		$this->assertNotSame( $a, $b );
		$this->assertFalse( $a->hasCloudProvider(), 'the original must not change' );
		$this->assertTrue( $b->hasCloudProvider() );
	}

	public function test_with_settings_returns_a_new_instance(): void {
		$a = new PluginConfig( new ProviderConfig(), new PluginSettings() );
		$b = $a->withSettings( new PluginSettings( true ) );
		$this->assertNotSame( $a, $b );
		$this->assertFalse( $a->isDebugEnabled() );
		$this->assertTrue( $b->isDebugEnabled() );
	}

	public function test_plugin_config_round_trips_through_array(): void {
		$a = PluginConfig::fromArray(
			array(
				'cloud_provider'  => 'azure',
				'provider_config' => array( 'storage_account' => 'acct' ),
				'debug_enabled'   => true,
				'timeout'         => 45,
			)
		);
		$this->assertSame( 'azure', $a->getCloudProvider() );
		$this->assertTrue( $a->isDebugEnabled() );
		$this->assertSame( 45, $a->getTimeout() );
		$this->assertSame( $a->toArray(), PluginConfig::fromArray( $a->toArray() )->toArray() );
	}

	// ── AzureConfig ─────────────────────────────────────────

	public function test_azure_endpoint_is_derived_from_the_account(): void {
		$c = new AzureConfig( 'myacct', 'uploads', 'key' );
		$this->assertSame( 'https://myacct.blob.core.windows.net', $c->getEndpoint() );
	}

	public function test_azure_rejects_an_empty_storage_account(): void {
		$this->expectException( \InvalidArgumentException::class );
		new AzureConfig( '', 'uploads', 'key' );
	}

	public function test_azure_rejects_an_empty_container(): void {
		$this->expectException( \InvalidArgumentException::class );
		new AzureConfig( 'acct', '', 'key' );
	}

	public function test_azure_rejects_an_empty_access_key(): void {
		$this->expectException( \InvalidArgumentException::class );
		new AzureConfig( 'acct', 'uploads', '' );
	}

	public function test_azure_config_is_valid_once_built(): void {
		$this->assertTrue( ( new AzureConfig( 'a', 'b', 'c' ) )->isValid() );
	}

	public function test_azure_round_trips_through_array(): void {
		$a = new AzureConfig( 'acct', 'cont', 'key' );
		$this->assertSame( $a->toArray(), AzureConfig::fromArray( $a->toArray() )->toArray() );
	}

	// ── FileInfo ────────────────────────────────────────────

	public function test_file_info_accessors(): void {
		$f = new FileInfo( '2026/01/a.jpg', 1234, 'abc==', 'Mon, 01 Jan 2026 00:00:00 GMT' );
		$this->assertSame( '2026/01/a.jpg', $f->getPath() );
		$this->assertSame( 1234, $f->getSize() );
		$this->assertSame( 'abc==', $f->getMd5() );
		$this->assertSame( 'Mon, 01 Jan 2026 00:00:00 GMT', $f->getLastModified() );
	}

	public function test_file_info_without_a_checksum(): void {
		$f = new FileInfo( 'a.jpg', 10 );
		$this->assertFalse( $f->hasMd5() );
		$this->assertNull( $f->getMd5() );
		$this->assertNull( $f->getLastModified() );
	}

	public function test_file_info_with_a_checksum(): void {
		$this->assertTrue( ( new FileInfo( 'a.jpg', 10, 'xyz' ) )->hasMd5() );
	}

	public function test_file_info_round_trips_through_array(): void {
		$a = new FileInfo( 'a.jpg', 10, 'xyz', 'now' );
		$this->assertSame( $a->toArray(), FileInfo::fromArray( $a->toArray() )->toArray() );
	}

	public function test_file_info_from_empty_array_is_safe(): void {
		$f = FileInfo::fromArray( array() );
		$this->assertSame( '', $f->getPath() );
		$this->assertSame( 0, $f->getSize() );
		$this->assertFalse( $f->hasMd5() );
	}

	public function test_new_uploads_default_to_a_week_of_caching_in_the_standard_class(): void {
		$s = new PluginSettings();
		$this->assertTrue( $s->isCacheControlEnabled() );
		$this->assertSame( 'public, max-age=604800', $s->getCacheControlHeader() );
		$this->assertSame( 'standard', $s->getStorageClass() );
		$this->assertSame( $s->toArray(), PluginSettings::fromArray( $s->toArray() )->toArray() );
	}

	public function test_a_serving_save_sets_caching_and_the_class_and_keeps_the_rest(): void {
		$s = ( new PluginSettings( false, true, true, true, 90 ) )->withPostedGroup(
			'serving',
			array( 'force_https_on_cloud' => '1', 'cache_control' => 'public, max-age=60', 'storage_class' => 'infrequent' )
		);
		$this->assertFalse( $s->isCacheControlEnabled(), 'an unchecked box is off' );
		$this->assertSame( '', $s->getCacheControlHeader() );
		$this->assertSame( 'public, max-age=60', $s->getCacheControl(), 'the value is kept for when it is turned on again' );
		$this->assertSame( 'infrequent', $s->getStorageClass() );
		$this->assertSame( 90, $s->getTimeout() );
	}

	public function test_a_cache_control_value_keeps_only_what_a_header_may_carry(): void {
		$this->assertSame( 'public, max-age=60', PluginSettings::clean_cache_control( "public,\r\n max-age=60;\"" ) );
		$this->assertSame( 'public, max-age=604800', PluginSettings::clean_cache_control( '"";' ) );
		$this->assertSame( 200, strlen( PluginSettings::clean_cache_control( str_repeat( 'a', 300 ) ) ) );
	}

	public function test_an_unknown_storage_class_is_standard(): void {
		$this->assertSame( 'standard', PluginSettings::fromArray( array( 'storage_class' => 'GLACIER' ) )->getStorageClass() );
	}

	public function test_excluded_folders_are_relative_to_uploads_and_end_in_a_slash(): void {
		$this->assertSame(
			array( 'backups/', 'cache/tmp/' ),
			PluginSettings::clean_folders( array( ' /backups ', 'wp-content/uploads/cache//tmp', 'uploads/backups/', '', '../etc', 'a/../b' ) )
		);
		$this->assertCount( 50, PluginSettings::clean_folders( array_map( fn( $i ) => "f{$i}", range( 1, 80 ) ) ) );
	}

	public function test_transfers_saves_the_folders_one_per_line_and_logging_the_email_switch(): void {
		$s = ( new PluginSettings() )->withPostedGroup( 'transfers', array( 'excluded_folders' => "backups\r\ncache/\n\n" ) );
		$this->assertSame( array( 'backups/', 'cache/' ), $s->getExcludedFolders() );
		$this->assertTrue( $s->shouldNotifyEmail(), 'on by default' );
		$s = $s->withPostedGroup( 'logging', array() );
		$this->assertFalse( $s->shouldNotifyEmail(), 'an unchecked box is off' );
		$this->assertSame( array( 'backups/', 'cache/' ), $s->getExcludedFolders(), 'another tab leaves the folders alone' );
	}
}
