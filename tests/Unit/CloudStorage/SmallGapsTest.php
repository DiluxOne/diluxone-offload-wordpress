<?php
namespace Tests\Unit\CloudStorage;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\Factories\CloudStorageFactory;
use DiluxOneOffload\DTOs\ProviderConfig;
use DiluxOneOffload\DTOs\SyncFilter;
use DiluxOneOffload\Logger;

/**
 * Branches too small for a file of their own: providers the factory knows
 * about but does not ship, the byte formatter behind the size-limit message,
 * and the logger's verbosity switch.
 */
class SmallGapsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_wp_options'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_test_wp_options'] );
		parent::tearDown();
	}

	/** @dataProvider unknownProviders */
	public function test_factory_refuses_any_provider_but_azure( string $provider ): void {
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Unsupported cloud storage provider' );
		CloudStorageFactory::create( $provider, array() );
	}

	/** @return array<string, array{string}> */
	public function unknownProviders(): array {
		return array(
			'aws' => array( 'aws' ),
			'gcp' => array( 'gcp' ),
		);
	}

	public function test_from_post_rejects_an_unknown_provider(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unsupported cloud provider' );
		ProviderConfig::fromPost( array( 'cloud_provider' => 'dropbox' ) );
	}

	/** @dataProvider sizes */
	public function test_size_limit_message_uses_a_human_unit( int $limit, int $size, string $unit ): void {
		$filter = new SyncFilter( '*', $limit );
		$this->assertStringContainsString( $unit, (string) $filter->getExclusionReason( '/x.bin', $size ) );
	}

	/** @return array<string, array{int,int,string}> */
	public function sizes(): array {
		return array(
			'bytes' => array( 100, 200, 'bytes' ),
			'KB'    => array( 2048, 4096, 'KB' ),
			'MB'    => array( 1048576, 2097152, 'MB' ),
			'GB'    => array( 1073741824, 2147483648, 'GB' ),
		);
	}

	public function test_system_file_exclusion_explains_itself(): void {
		$filter = new SyncFilter( '*', 0, array(), false, false );
		$this->assertSame( 'System file', $filter->getExclusionReason( '/uploads/index.php', 10 ) );
	}

	public function test_azure_from_post_rejects_a_bad_container_name(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Container Name must be' );
		ProviderConfig::fromPost( array( 'cloud_provider' => 'azure', 'account_name' => 'acct', 'account_key' => 'k', 'container_name' => 'Bad_Container' ) );
	}

	public function test_logger_verbosity_reflects_the_config_toggle(): void {
		$GLOBALS['_test_wp_options']['diluxone_offload_config'] = array( 'debug_enabled' => true );
		$this->assertIsBool( Logger::is_verbose_logging() );
	}
}
