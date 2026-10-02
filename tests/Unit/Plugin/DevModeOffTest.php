<?php
namespace Tests\Unit\Plugin;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\Enums\PluginState;
use DiluxOneOffload\Plugin;
use Tests\Unit\Support\AjaxAnswer;

/**
 * The two DEV MODE endpoints on a site that never defined
 * DILUXONE_OFFLOAD_DEV_MODE, which is every production site.
 *
 * They are not even hooked there; this is the second lock: called
 * directly, each one refuses before it reads or changes anything, so
 * offloading cannot be switched on without a sync, nor off without
 * bringing the files back.
 *
 * In their own process: the AJAX helpers they end with are defined for
 * this test only (tests/Unit/Support/ajax-functions.php), and the unit
 * bootstrap never defines DILUXONE_OFFLOAD_DEV_MODE.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class DevModeOffTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__ ) . '/Support/ajax-functions.php';
		$GLOBALS['_test_wp_options'] = array( ConfigManager::STATE_OPTION => PluginState::CONFIGURED );
		$GLOBALS['_test_wp_hooks']   = array();
	}

	/** @return array<string, array{string, string}> */
	public function endpoints(): array {
		return array(
			'enable without a sync'     => array( 'ajax_dev_enable_without_sync', PluginState::CONFIGURED ),
			'disconnect without a sync' => array( 'ajax_dev_disconnect_without_sync', PluginState::OFFLOADING_ACTIVE ),
		);
	}

	/** @dataProvider endpoints */
	public function test_a_dev_endpoint_refuses_when_dev_mode_is_off( string $method, string $state ): void {
		$this->assertFalse( defined( 'DILUXONE_OFFLOAD_DEV_MODE' ), 'precondition: a production site' );
		$GLOBALS['_test_wp_options'][ ConfigManager::STATE_OPTION ] = $state;

		try {
			Plugin::get_instance()->$method();
			$this->fail( 'the handler answered nothing' );
		} catch ( AjaxAnswer $answer ) {
			$this->assertFalse( $answer->success );
			$this->assertSame( 'DILUXONE_OFFLOAD_DEV_MODE is not enabled', $answer->data );
		}
		$this->assertSame( $state, ConfigManager::get_state(), 'the state is untouched' );
	}
}
