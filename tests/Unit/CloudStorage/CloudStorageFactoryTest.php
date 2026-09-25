<?php
namespace Tests\Unit\CloudStorage;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\Factories\CloudStorageFactory;
use DiluxOneOffload\Interfaces\CloudStorageClientInterface;
use DiluxOneOffload\Providers\AzureProvider;

/**
 * Unit tests for CloudStorageFactory — provider instantiation, supported
 * provider listing, config-field metadata.
 */
class CloudStorageFactoryTest extends TestCase {

    public function test_create_azure_provider(): void {
        $provider = CloudStorageFactory::create('azure', [
            'storage_account' => 'testacc',
            'container_name'  => 'testcont',
            'access_key'      => 'testkey',
        ]);

        $this->assertInstanceOf(CloudStorageClientInterface::class, $provider);
        $this->assertInstanceOf(AzureProvider::class, $provider);
    }

    public function test_create_unsupported_provider_throws(): void {
        $this->expectException(\Exception::class);

        CloudStorageFactory::create('aws', []);
    }

    public function test_get_supported_providers(): void {
        $providers = CloudStorageFactory::get_supported_providers();

        $this->assertIsArray($providers);
        $this->assertArrayHasKey('azure', $providers);
    }

    public function test_is_provider_supported(): void {
        $this->assertTrue(CloudStorageFactory::is_provider_supported('azure'));
        $this->assertFalse(CloudStorageFactory::is_provider_supported('s3'));
    }

    public function test_get_provider_config_fields(): void {
        $azure_fields = CloudStorageFactory::get_provider_config_fields('azure');

        $this->assertIsArray($azure_fields);
        $this->assertNotEmpty($azure_fields);
        $this->assertArrayHasKey('storage_account', $azure_fields);
    }
}
