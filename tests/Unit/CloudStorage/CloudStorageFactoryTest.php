<?php
namespace Tests\Unit\CloudStorage;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\Factories\CloudStorageFactory;
use DiluxOneOffload\Interfaces\CloudStorageClientInterface;
use DiluxOneOffload\Providers\AzureProvider;
use DiluxOneOffload\Providers\S3CompatibleProvider;

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
        $this->assertTrue(CloudStorageFactory::is_provider_supported('s3'));
        $this->assertFalse(CloudStorageFactory::is_provider_supported('gcs'));
    }

    public function test_the_label_is_the_name_the_screens_show(): void {
        $this->assertSame('Microsoft Azure Blob Storage', CloudStorageFactory::get_provider_label('azure'));
        $this->assertSame('', CloudStorageFactory::get_provider_label('nope'));
    }

    public function test_get_provider_config_fields(): void {
        $azure_fields = CloudStorageFactory::get_provider_config_fields('azure');

        $this->assertIsArray($azure_fields);
        $this->assertNotEmpty($azure_fields);
        $this->assertArrayHasKey('storage_account', $azure_fields);
    }

    public function test_create_s3_provider_is_case_insensitive(): void {
        $provider = CloudStorageFactory::create('S3', [
            'preset'            => 'custom',
            'endpoint'          => 'https://s3.example.com',
            'region'            => 'us-east-1',
            'bucket'            => 'media',
            'access_key_id'     => 'AKID',
            'secret_access_key' => 'secret',
            'public_url'        => 'https://cdn.example.com',
        ]);

        $this->assertInstanceOf(S3CompatibleProvider::class, $provider);
        $this->assertSame('https://cdn.example.com/uploads/a.jpg', $provider->get_file_url('uploads/a.jpg'));
    }

    public function test_the_s3_label_is_the_family_name(): void {
        $this->assertSame('S3-compatible storage', CloudStorageFactory::get_provider_label('s3'));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public function serviceConfigs(): array {
        return [
            'an S3 preset names its service' => [['cloud_provider' => 's3', 'provider_config' => ['preset' => 'r2']], 'Cloudflare R2'],
            'Amazon'                         => [['cloud_provider' => 's3', 'provider_config' => ['preset' => 'aws']], 'Amazon S3'],
            'Custom names the family'        => [['cloud_provider' => 's3', 'provider_config' => ['preset' => 'custom']], 'S3-compatible storage'],
            'an unknown preset, the family'  => [['cloud_provider' => 's3', 'provider_config' => ['preset' => 'nope']], 'S3-compatible storage'],
            'no preset, the family'          => [['cloud_provider' => 's3', 'provider_config' => []], 'S3-compatible storage'],
            'a preset on Azure is ignored'   => [['cloud_provider' => 'azure', 'provider_config' => ['preset' => 'r2']], 'Microsoft Azure Blob Storage'],
            'nothing configured'             => [[], ''],
        ];
    }

    /**
     * @dataProvider serviceConfigs
     * @param array<string, mixed> $config
     */
    public function test_the_service_label_names_the_preset_except_custom(array $config, string $label): void {
        $this->assertSame($label, CloudStorageFactory::get_service_label($config));
    }
}
