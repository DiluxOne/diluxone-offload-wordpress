<?php
namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * The admin screens are styled by WordPress' own classes and the plugin's
 * stylesheets. The only inline style allowed is a value that is data: a
 * bar's width and the pie chart's slices.
 */
class NoInlineStylesTest extends TestCase {

	/** @return array<string, array{string}> */
	public static function files(): array {
		$root  = dirname( __DIR__, 3 );
		$files = array_merge(
			glob( $root . '/templates/*.php' ) ?: array(),
			glob( $root . '/templates/partials/*.php' ) ?: array(),
			glob( $root . '/assets/js/*.js' ) ?: array(),
			glob( $root . '/includes/*.php' ) ?: array()
		);
		$cases = array();
		foreach ( $files as $file ) {
			$cases[ substr( $file, strlen( $root ) + 1 ) ] = array( $file );
		}
		return $cases;
	}

	/** @dataProvider files */
	public function test_no_inline_style_but_data( string $file ): void {
		$source = (string) file_get_contents( $file );
		$this->addToAssertionCount( 1 );
		preg_match_all( '/style="([^"]*)"|\.css\(\s*\'([a-z-]+)\'/', $source, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			$declaration = isset( $match[2] ) && '' !== $match[2] ? $match[2] : $match[1];
			$this->assertMatchesRegularExpression(
				'/^\s*(width\s*:|background:\s*conic-gradient\(|width$|background$)/',
				$declaration,
				'Inline style in ' . basename( $file ) . ': ' . $declaration
			);
		}
	}
}
