<?php
namespace Tests\Unit\DTOs;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\DTOs\PluginSettings;
use DiluxOneOffload\DTOs\ProviderConfig;
use DiluxOneOffload\DTOs\PluginConfig;
use DiluxOneOffload\DTOs\AzureConfig;
use DiluxOneOffload\DTOs\SyncFilter;
use DiluxOneOffload\Enums\SyncStatus;

/**
 * Unit tests for the named constructors that build DTOs from outside data.
 *
 * fromPost() is the boundary where a form submission becomes a typed object,
 * so the checkbox semantics matter: an unchecked HTML checkbox sends nothing at
 * all, and reading it with ?? true would turn "off" into "on" on every save.
 *
 * Pure logic: WordPress functions come from tests/stubs/wordpress-stubs.php.
 */
class FactoriesTest extends TestCase {

	// ── PluginSettings::fromPost ────────────────────────────

	public function test_from_post_reads_a_checked_debug_box(): void {
		$s = PluginSettings::fromPost( array( 'enable_debug_logging' => '1' ) );
		$this->assertTrue( $s->isDebugEnabled() );
	}

	/** An unchecked checkbox is absent from $_POST, not present-and-false. */
	public function test_from_post_treats_a_missing_checkbox_as_off(): void {
		$s = PluginSettings::fromPost( array() );
		$this->assertFalse( $s->isDebugEnabled() );
		$this->assertFalse( $s->shouldForceHttpsOnCloud() );
	}

	public function test_from_post_converts_megabytes_to_bytes(): void {
		$s = PluginSettings::fromPost( array( 'max_file_size' => '5' ) );
		$this->assertSame( 5 * 1048576, $s->getMaxFileSize() );
		$this->assertSame( 5.0, $s->getMaxFileSizeMB() );
	}

	public function test_from_post_uses_defaults_when_fields_are_absent(): void {
		$s = PluginSettings::fromPost( array() );
		$this->assertSame( 60, $s->getTimeout() );
		$this->assertSame( '*', $s->getAllowedFileTypes() );
		$this->assertSame( 20 * 1048576, $s->getMaxFileSize() );
	}

	public function test_from_post_reads_the_timeout(): void {
		$this->assertSame( 120, PluginSettings::fromPost( array( 'timeout' => '120' ) )->getTimeout() );
	}

	/** The numbers are clamped to the range the form offers, so a typo cannot overflow the typed constructor. */
	public function test_from_post_clamps_the_numbers_to_the_form_range(): void {
		$s = PluginSettings::fromPost( array( 'timeout' => '99999', 'max_file_size' => '99999999999999' ) );
		$this->assertSame( 600, $s->getTimeout() );
		$this->assertSame( 500 * 1048576, $s->getMaxFileSize() );
		$s = PluginSettings::fromPost( array( 'timeout' => '1', 'max_file_size' => '0' ) );
		$this->assertSame( 30, $s->getTimeout() );
		$this->assertSame( 1048576, $s->getMaxFileSize() );
	}

	public function test_from_post_reads_the_allowed_types(): void {
		$this->assertSame( 'jpg,png', PluginSettings::fromPost( array( 'allowed_file_types' => 'jpg,png' ) )->getAllowedFileTypes() );
	}

	// ── ProviderConfig::fromPost ────────────────────────────

	public function test_provider_from_post_requires_a_provider(): void {
		$this->expectException( \InvalidArgumentException::class );
		ProviderConfig::fromPost( array() );
	}

	public function test_azure_from_post_builds_the_config(): void {
		$p = ProviderConfig::fromPost(
			array(
				'cloud_provider' => 'azure',
				'account_name'   => 'acct',
				'account_key'    => 'key',
				'container_name' => 'cont',
			)
		);
		$this->assertSame( 'azure', $p->getCloudProvider() );
		$this->assertSame( 'acct', $p->getStorageAccount() );
		$this->assertSame( 'cont', $p->getContainerName() );
		$this->assertTrue( $p->isConfigured() );
	}

	public function test_azure_from_post_requires_the_storage_account(): void {
		$this->expectException( \InvalidArgumentException::class );
		ProviderConfig::fromPost(
			array(
				'cloud_provider' => 'azure',
				'account_key'    => 'key',
				'container_name' => 'cont',
			)
		);
	}

	public function test_azure_from_post_requires_the_access_key(): void {
		$this->expectException( \InvalidArgumentException::class );
		ProviderConfig::fromPost(
			array(
				'cloud_provider' => 'azure',
				'account_name'   => 'acct',
				'container_name' => 'cont',
			)
		);
	}

	public function test_azure_from_post_requires_the_container(): void {
		$this->expectException( \InvalidArgumentException::class );
		ProviderConfig::fromPost(
			array(
				'cloud_provider' => 'azure',
				'account_name'   => 'acct',
				'account_key'    => 'key',
			)
		);
	}

	// ── Remaining accessors ─────────────────────────────────

	public function test_azure_accessors(): void {
		$c = new AzureConfig( 'acct', 'cont', 'key' );
		$this->assertSame( 'acct', $c->getStorageAccount() );
		$this->assertSame( 'cont', $c->getContainerName() );
		$this->assertSame( 'key', $c->getAccessKey() );
	}

	public function test_plugin_config_exposes_its_parts(): void {
		$provider = new ProviderConfig( 'azure', array( 'storage_account' => 'a' ) );
		$settings = new PluginSettings( false, false, false, true, 60, 1048576, '*' );
		$c        = new PluginConfig( $provider, $settings );

		$this->assertSame( $provider, $c->getProvider() );
		$this->assertSame( $settings, $c->getSettings() );
		$this->assertSame( array( 'storage_account' => 'a' ), $c->getProviderConfig() );
		$this->assertFalse( $c->shouldKeepLocalFiles() );
		$this->assertFalse( $c->shouldAutoActivateOffloading() );
		$this->assertSame( 1048576, $c->getMaxFileSize() );
	}

	public function test_sync_filter_reports_its_excluded_paths(): void {
		$f = new SyncFilter( '*', 0, array( 'a/', 'b/' ) );
		$this->assertSame( array( 'a/', 'b/' ), $f->getExcludedPaths() );
	}
}
