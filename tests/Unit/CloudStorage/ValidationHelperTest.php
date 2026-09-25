<?php
namespace Tests\Unit\CloudStorage;

use PHPUnit\Framework\TestCase;
use Mockery;
use DiluxOneOffload\ValidationHelper;

/**
 * Unit tests for ValidationHelper.
 *
 * Note: get_option() is already stubbed in wordpress-stubs.php (returns
 * the default). Brain Monkey's Functions\expect cannot redefine it
 * because Patchwork loads after the stub. The stub behavior — return
 * `[]` for `diluxone_offload_sync_meta` — is sufficient for these tests.
 *
 * ConfigManager is replaced via Mockery's `alias:` — that creates a
 * class alias whose static methods are intercepted, so validation
 * helpers see a controlled state without needing the real autoloaded
 * ConfigManager.
 */
class ValidationHelperTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
    }

    protected function tearDown(): void {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Uses a Mockery alias mock of ConfigManager, which can only be installed
     * while the real class is not loaded yet. Other suites load it, so this
     * test needs its own process.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_validate_sync_start_in_configured_state(): void {
        // Replace ConfigManager statically; configure_state returns 'configured'.
        $config_manager = Mockery::mock('alias:DiluxOneOffload\ConfigManager');
        $config_manager->shouldReceive('get_state')->andReturn('configured');

        // get_option('diluxone_offload_sync_meta', []) returns [] via stub — no active sync.

        $result = ValidationHelper::validate_sync_operation('session-123', 'start_sync');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('passed', $result);
    }

    /**
     * Uses a Mockery alias mock of ConfigManager, which can only be installed
     * while the real class is not loaded yet. Other suites load it, so this
     * test needs its own process.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_validate_returns_array_structure(): void {
        // Mockery::close() in tearDown clears the previous alias, so re-mock.
        $config_manager = Mockery::mock('alias:DiluxOneOffload\ConfigManager');
        $config_manager->shouldReceive('get_state')->andReturn('configured');

        $result = ValidationHelper::validate_sync_operation('session-abc', 'start_sync');

        $this->assertArrayHasKey('passed', $result);
        $this->assertArrayHasKey('reason', $result);
        $this->assertIsBool($result['passed']);
        $this->assertIsString($result['reason']);
    }
}
