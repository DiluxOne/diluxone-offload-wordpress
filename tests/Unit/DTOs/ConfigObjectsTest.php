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
}
