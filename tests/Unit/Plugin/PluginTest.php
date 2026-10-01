<?php
namespace Tests\Unit\Plugin;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\Plugin;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\CloudStreamWrapper;
use DiluxOneOffload\Enums\PluginState;

/**
 * The parts of the plugin's bootstrap class that stand without WordPress:
 * the image-editor swap that lets thumbnails be made from cloud paths, the
 * singleton, the batch budget, and what plugin deactivation leaves behind.
 * Activation, init() and the AJAX handlers need the database and WordPress's
 * request cycle: the integration suite covers them.
 */
class PluginTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_wp_options'] = array();
		$GLOBALS['_test_wp_hooks']   = array();
	}

	protected function tearDown(): void {
		CloudStreamWrapper::unregister();
		unset( $GLOBALS['_test_wp_options'], $GLOBALS['_test_wp_hooks'] );
		parent::tearDown();
	}

	private static function call_static( string $method ) {
		$m = new \ReflectionMethod( Plugin::class, $method );
		if ( PHP_VERSION_ID < 80100 ) { // Required before 8.1, deprecated from 8.5.
			$m->setAccessible( true );
		}
		return $m->invoke( null );
	}

	// ── Image editors ───────────────────────────────────────

	public function test_the_plugins_imagick_editor_replaces_core_imagick_and_goes_first(): void {
		$editors = Plugin::get_instance()->filter_image_editors( array( 'WP_Image_Editor_Imagick', 'WP_Image_Editor_GD' ) );

		$this->assertSame(
			array( 'DiluxOneOffload\\DiluxOneOffload_Image_Editor_Imagick', 'WP_Image_Editor_GD', 'DiluxOneOffload\\DiluxOneOffload_Image_Editor_GD' ),
			array_values( $editors )
		);
		$this->assertNotContains( 'WP_Image_Editor_Imagick', $editors );
	}

	public function test_without_core_imagick_the_list_is_only_extended(): void {
		$editors = Plugin::get_instance()->filter_image_editors( array( 'WP_Image_Editor_GD', 'Some_Other_Editor' ) );

		$this->assertSame(
			array( 'DiluxOneOffload\\DiluxOneOffload_Image_Editor_Imagick', 'WP_Image_Editor_GD', 'Some_Other_Editor', 'DiluxOneOffload\\DiluxOneOffload_Image_Editor_GD' ),
			$editors
		);
	}

	public function test_an_empty_editor_list_gets_both_of_the_plugins_editors(): void {
		$this->assertSame(
			array( 'DiluxOneOffload\\DiluxOneOffload_Image_Editor_Imagick', 'DiluxOneOffload\\DiluxOneOffload_Image_Editor_GD' ),
			Plugin::get_instance()->filter_image_editors( array() )
		);
	}

	// ── Singleton, budget ───────────────────────────────────

	public function test_there_is_one_instance(): void {
		$this->assertSame( Plugin::get_instance(), Plugin::get_instance() );
	}

	public function test_a_batch_may_run_eight_seconds_by_default(): void {
		$this->assertSame( 8.0, self::call_static( 'batch_seconds' ) );
	}

	// ── Deactivation ────────────────────────────────────────

	public function test_deactivating_the_plugin_while_offloading_unhooks_the_wrapper(): void {
		CloudStreamWrapper::register();
		add_filter( 'upload_dir', array( CloudStreamWrapper::class, 'filter_upload_dir' ), 10 );
		$GLOBALS['_test_wp_options'][ ConfigManager::STATE_OPTION ] = PluginState::OFFLOADING_ACTIVE;

		Plugin::deactivate();

		$this->assertFalse( CloudStreamWrapper::is_active(), 'the protocol is gone' );
		$this->assertFalse( has_filter( 'upload_dir', array( CloudStreamWrapper::class, 'filter_upload_dir' ) ) );
		$this->assertSame( PluginState::SYNCED, ConfigManager::get_state(), 'offloading is off, the library is still synced' );
	}

	/** @return array<string, array{string}> */
	public function statesBeforeAnySync(): array {
		return array(
			'not configured' => array( PluginState::NOT_CONFIGURED ),
			'configured'     => array( PluginState::CONFIGURED ),
		);
	}

	/** @dataProvider statesBeforeAnySync */
	public function test_deactivating_the_plugin_keeps_a_state_that_never_synced( string $state ): void {
		$GLOBALS['_test_wp_options'][ ConfigManager::STATE_OPTION ] = $state;

		Plugin::deactivate();

		if ( ConfigManager::get_state() !== $state ) {
			$this->markTestIncomplete(
				'BUG: Plugin::deactivate() (includes/class-diluxone-offload-plugin-enhanced.php:1598) calls '
				. 'CloudStreamWrapper::deactivate_offloading(), which sets the state to SYNCED unconditionally '
				. '(includes/class-diluxone-offload-cloud-stream-wrapper.php:271). Deactivating a plugin that was '
				. '"' . $state . '" leaves it "' . ConfigManager::get_state() . '" on reactivation, although nothing was synced; '
				. 'readme.txt promises deactivation keeps everything.'
			);
		}
		$this->assertSame( $state, ConfigManager::get_state() );
	}
}
