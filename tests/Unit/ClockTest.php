<?php
namespace Tests\Unit;

use DiluxOneOffload\Clock;
use PHPUnit\Framework\TestCase;

/**
 * Every time budget is measured on the monotonic clock. A wall clock stepped
 * back mid-request (it happened on WSL, seen as a sync request that logged
 * 0.02 s after uploading 31 files) made a 0-second budget look untouched,
 * and the pool started every file in one request.
 */
class ClockTest extends TestCase {

	public function test_it_measures_time_that_went_by(): void {
		$start = Clock::now();
		usleep( 20000 );
		$elapsed = Clock::now() - $start;
		$this->assertGreaterThanOrEqual( 0.019, $elapsed );
		$this->assertLessThan( 5.0, $elapsed );
	}

	public function test_it_never_goes_back(): void {
		$last = Clock::now();
		for ( $i = 0; $i < 1000; $i++ ) {
			$now = Clock::now();
			$this->assertGreaterThanOrEqual( $last, $now );
			$last = $now;
		}
	}

	/** @return array<string, array{string}> */
	public static function sources(): array {
		$root  = dirname( __DIR__, 2 );
		$cases = array();
		foreach ( array_merge( glob( $root . '/includes/*.php' ) ?: array(), glob( $root . '/includes/*/*.php' ) ?: array() ) as $file ) {
			$cases[ substr( $file, strlen( $root ) + 1 ) ] = array( $file );
		}
		return $cases;
	}

	/** @dataProvider sources */
	public function test_no_budget_is_measured_on_the_wall_clock( string $file ): void {
		$this->assertDoesNotMatchRegularExpression(
			'/\bmicrotime\s*\(/',
			(string) file_get_contents( $file ),
			basename( $file ) . ' measures time with microtime(): use Clock::now(), which a clock change cannot step back.'
		);
	}
}
