<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every class, trait and interface in includes/ is found by the plugin's own
 * autoloader. uninstall.php runs with the plugin inactive and nothing but the
 * autoloader loaded: DiluxOneOffloadDB missing from its map made the uninstall
 * skip cancelling unfinished uploads, silently, while every test that loads
 * the plugin first still passed.
 */
class AutoloaderTest extends TestCase {

	/** @return array<string, array{string, string}> */
	public static function declarations(): array {
		$root  = dirname( __DIR__, 2 );
		$cases = array();
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/includes', \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$source = (string) file_get_contents( $file->getPathname() );
			if ( ! preg_match( '/^namespace\s+(DiluxOneOffload[^;]*);/m', $source, $ns ) ) {
				continue;
			}
			preg_match_all( '/^(?:final\s+|abstract\s+)?(?:class|trait|interface)\s+(\w+)/m', $source, $names );
			foreach ( $names[1] as $name ) {
				$cases[ $ns[1] . '\\' . $name ] = array( $ns[1] . '\\' . $name, substr( $file->getPathname(), strlen( $root ) + 1 ) );
			}
		}
		return $cases;
	}

	/** @return array<string, string> Class name without the namespace root => mapped file. */
	private static function map(): array {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/enhanced-autoloader.php' );
		preg_match_all( "/'([A-Za-z0-9_\\\\]+)'\\s*=>\\s*'(includes\\/[^']+)'/", $source, $m, PREG_SET_ORDER );
		$map = array();
		foreach ( $m as $row ) {
			$map[ str_replace( '\\\\', '\\', $row[1] ) ] = $row[2];
		}
		return $map;
	}

	/** @dataProvider declarations */
	public function test_the_autoloader_maps_it_to_its_own_file( string $name, string $file ): void {
		$key = substr( $name, strlen( 'DiluxOneOffload\\' ) );
		$map = self::map();
		$this->assertArrayHasKey( $key, $map, "$name is not in includes/enhanced-autoloader.php's map" );
		$this->assertSame( $file, $map[ $key ], "$name is mapped to another file" );
	}

	/** The map names files that exist: a typo there fails here, not on a customer's uninstall. */
	public function test_every_mapped_file_exists(): void {
		$root   = dirname( __DIR__, 2 );
		$source = (string) file_get_contents( $root . '/includes/enhanced-autoloader.php' );
		preg_match_all( "/=>\s*'(includes\/[^']+)'/", $source, $files );
		$this->assertNotEmpty( $files[1] );
		foreach ( $files[1] as $file ) {
			$this->assertFileExists( $root . '/' . $file );
		}
	}
}
