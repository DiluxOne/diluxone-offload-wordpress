<?php
namespace Tests\Unit\CloudStorage;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\Logger;

/**
 * Unit tests for Logger — the plugin's single error_log() sink.
 *
 * Three behaviours carry weight here. The sink gate: a site that asked for
 * nothing — verbose off, WP_DEBUG off — gets nothing, at any level, which is
 * what wordpress.org expects of a shipped plugin. The level gate, once logging
 * is on: errors and warnings go through, info/debug wait for verbose, because
 * a site with verbose off must not fill its log with a line per request. And
 * the dedupe window: the stream wrapper can log the same message
 * hundreds of times in one page load, and without dedupe that is the log.
 *
 * The sink is captured by pointing PHP's error_log ini at a temp file, so
 * assertions read exactly what a real site would have written.
 */
class LoggerTest extends TestCase {

	private string $sink;
	private string $previous_sink;

	protected function setUp(): void {
		parent::setUp();
		$this->sink          = tempnam( sys_get_temp_dir(), 'dlx-log-' );
		$this->previous_sink = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $this->sink );
		$GLOBALS['_test_wp_options'] = array();
		// Known starting state, regardless of what a previous test left behind.
		Logger::set_verbose_logging( false );
	}

	protected function tearDown(): void {
		ini_set( 'error_log', $this->previous_sink );
		@unlink( $this->sink );
		Logger::set_verbose_logging( false );
		unset( $GLOBALS['_test_wp_options'] );
		parent::tearDown();
	}

	private function written(): string {
		return (string) file_get_contents( $this->sink );
	}

	/** Every message is unique so the dedupe window never crosses tests. */
	private function msg( string $tag ): string {
		return '[LoggerTest] ' . $tag . ' ' . uniqid( '', true );
	}

	// ── Level gate ──────────────────────────────────────────

	public function test_a_site_that_asked_for_nothing_gets_nothing(): void {
		// WP_DEBUG is on inside the test bootstrap, so this runs in a child
		// process that has neither switch. It is the one case the suite cannot
		// set up in-process, and the one a production install actually is.
		$logger  = dirname( __DIR__, 3 ) . '/includes/class-diluxone-offload-logger.php';
		$sink    = $this->sink;
		$script  = <<<PHP
<?php
define( 'ABSPATH', __DIR__ );
define( 'WP_DEBUG', false );
function get_option( \$name, \$default = false ) { return \$default; }
ini_set( 'error_log', '{$sink}' );
require '{$logger}';
\DiluxOneOffload\Logger::error( '[LoggerTest] quiet-site error' );
\DiluxOneOffload\Logger::warning( '[LoggerTest] quiet-site warning' );
\DiluxOneOffload\Logger::info( '[LoggerTest] quiet-site info' );
PHP;
		$file = tempnam( sys_get_temp_dir(), 'dlx-quiet-' ) . '.php';
		file_put_contents( $file, $script );
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ) . ' 2>&1', $out, $code );
		@unlink( $file );

		$this->assertSame( 0, $code, 'the child ran: ' . implode( "\n", $out ) );
		$this->assertSame( '', trim( $this->written() ), 'not one line reached the PHP error log' );
	}

	public function test_error_is_logged_when_wp_debug_is_on(): void {
		$m = $this->msg( 'error' );
		Logger::error( $m );
		$this->assertStringContainsString( $m, $this->written() );
	}

	public function test_warning_is_logged_when_wp_debug_is_on(): void {
		$m = $this->msg( 'warning' );
		Logger::warning( $m );
		$this->assertStringContainsString( $m, $this->written() );
	}

	public function test_info_is_dropped_when_verbose_is_off(): void {
		$m = $this->msg( 'info' );
		Logger::info( $m );
		$this->assertStringNotContainsString( $m, $this->written() );
	}

	public function test_debug_is_dropped_when_verbose_is_off(): void {
		$m = $this->msg( 'debug' );
		Logger::debug( $m );
		$this->assertStringNotContainsString( $m, $this->written() );
	}

	public function test_info_is_logged_when_verbose_is_on(): void {
		Logger::set_verbose_logging( true );
		$m = $this->msg( 'info-verbose' );
		Logger::info( $m );
		$this->assertStringContainsString( $m, $this->written() );
	}

	public function test_debug_is_logged_when_verbose_is_on(): void {
		Logger::set_verbose_logging( true );
		$m = $this->msg( 'debug-verbose' );
		Logger::debug( $m );
		$this->assertStringContainsString( $m, $this->written() );
	}

	public function test_force_bypasses_the_level_gate(): void {
		$m = $this->msg( 'forced' );
		Logger::log( $m, 'debug', true );
		$this->assertStringContainsString( $m, $this->written() );
	}

	// ── Verbose flag and its sources ────────────────────────

	public function test_set_verbose_logging_is_reported_back(): void {
		Logger::set_verbose_logging( true );
		$this->assertTrue( Logger::is_verbose_logging() );
		Logger::set_verbose_logging( false );
		$this->assertFalse( Logger::is_verbose_logging() );
	}

	public function test_refresh_reads_the_debug_toggle_from_the_saved_config(): void {
		// The Settings form's checkbox is persisted under this key: the
		// logger has to read the same one, or the toggle does nothing.
		$GLOBALS['_test_wp_options']['diluxone_offload_config'] = array( 'debug_enabled' => true );
		Logger::refresh();
		$this->assertTrue( Logger::is_verbose_logging() );
	}

	public function test_refresh_with_the_toggle_off_disables_verbose(): void {
		Logger::set_verbose_logging( true );
		$GLOBALS['_test_wp_options']['diluxone_offload_config'] = array( 'enable_debug_logging' => false );
		Logger::refresh();
		$this->assertFalse( Logger::is_verbose_logging() );
	}

	public function test_refresh_with_no_config_leaves_verbose_off(): void {
		Logger::refresh();
		$this->assertFalse( Logger::is_verbose_logging() );
	}

	public function test_a_non_array_config_value_is_ignored(): void {
		$GLOBALS['_test_wp_options']['diluxone_offload_config'] = 'corrupt';
		Logger::refresh();
		$this->assertFalse( Logger::is_verbose_logging() );
	}

	// ── Dedupe window ───────────────────────────────────────

	public function test_the_same_message_is_written_once_within_the_window(): void {
		$m = $this->msg( 'dup' );
		Logger::error( $m );
		Logger::error( $m );
		Logger::error( $m );
		$this->assertSame( 1, substr_count( $this->written(), $m ) );
	}

	public function test_different_levels_of_the_same_text_are_not_deduped_together(): void {
		$m = $this->msg( 'level-key' );
		Logger::error( $m );
		Logger::warning( $m );
		$this->assertSame( 2, substr_count( $this->written(), $m ), 'the dedupe key includes the level' );
	}

	public function test_different_messages_are_all_written(): void {
		$a = $this->msg( 'a' );
		$b = $this->msg( 'b' );
		Logger::error( $a );
		Logger::error( $b );
		$out = $this->written();
		$this->assertStringContainsString( $a, $out );
		$this->assertStringContainsString( $b, $out );
	}

	private static function property( string $name ): \ReflectionProperty {
		$p = new \ReflectionProperty( Logger::class, $name );
		if ( PHP_VERSION_ID < 80100 ) { // Required before 8.1, deprecated from 8.5.
			$p->setAccessible( true );
		}
		return $p;
	}

	public function test_a_message_logged_again_after_the_window_is_written_again(): void {
		$m     = $this->msg( 'window' );
		$cache = self::property( 'log_cache' );
		Logger::error( $m );
		// Age the entry past the 300-second window, as if the first line was
		// written six minutes ago.
		$entries = $cache->getValue();
		$key     = md5( 'error|' . $m );
		$entries[ $key ] = time() - 301;
		$cache->setValue( null, $entries );

		Logger::error( $m );

		$this->assertSame( 2, substr_count( $this->written(), $m ) );
	}

	public function test_stale_dedupe_entries_are_dropped_when_the_next_line_is_written(): void {
		$cache   = self::property( 'log_cache' );
		$entries = $cache->getValue();
		$entries['stale-entry'] = time() - 301;
		$entries['fresh-entry'] = time() - 10;
		$cache->setValue( null, $entries );

		Logger::error( $this->msg( 'sweeper' ) );

		$after = $cache->getValue();
		$this->assertArrayNotHasKey( 'stale-entry', $after, 'the cache does not grow for ever' );
		$this->assertArrayHasKey( 'fresh-entry', $after );
	}

	public function test_init_is_a_no_op_once_initialised(): void {
		Logger::set_verbose_logging( true );
		// The saved toggle says off, but init() after initialisation must not
		// re-read it: only refresh() does.
		$GLOBALS['_test_wp_options']['diluxone_offload_config'] = array( 'debug_enabled' => false );
		Logger::init();
		$this->assertTrue( Logger::is_verbose_logging() );
	}

	public function test_the_flag_is_read_lazily_on_first_use(): void {
		self::property( 'initialized' )->setValue( null, false );
		$GLOBALS['_test_wp_options']['diluxone_offload_config'] = array( 'debug_enabled' => true );

		$this->assertTrue( Logger::is_verbose_logging(), 'is_verbose_logging() initialises from the saved config' );
	}

	public function test_logging_initialises_from_the_saved_config_on_first_line(): void {
		self::property( 'initialized' )->setValue( null, false );
		$GLOBALS['_test_wp_options']['diluxone_offload_config'] = array( 'debug_enabled' => true );
		$m = $this->msg( 'lazy-info' );

		Logger::info( $m );

		$this->assertStringContainsString( $m, $this->written(), 'info goes through because the toggle is on' );
	}
}
