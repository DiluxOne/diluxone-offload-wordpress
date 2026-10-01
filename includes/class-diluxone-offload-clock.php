<?php
/**
 * The clock every time budget is measured on.
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seconds on the monotonic clock.
 *
 * A request's budget (a sync or download batch, a delete pass, a retry that
 * took too long) is the time that went by, not the time of day. The wall
 * clock, which microtime reads, can be stepped back while a request runs
 * (NTP on a VM, WSL resyncing it), and a budget measured on it then looks
 * untouched: the request keeps starting uploads past its limit. hrtime()
 * never goes back.
 */
class Clock {

	/**
	 * Seconds since an arbitrary point, for differences only.
	 *
	 * @return float
	 */
	public static function now(): float {
		return hrtime( true ) / 1e9;
	}
}
