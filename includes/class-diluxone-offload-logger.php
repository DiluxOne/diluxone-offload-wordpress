<?php
/**
 * Logging helper for the DiluxOne Offload plugin.
 *
 * This class IS the plugin's logger. Its job is to call error_log() once
 * formatted and gated by the verbose-logging toggle. The development-functions
 * sniff is intentionally suppressed file-wide:
 *
 * phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logging helper for the DiluxOne Offload plugin.
 *
 * Single entry point for every log line. Logging is off until the site asks
 * for it — the Settings tab toggle, the DILUXONE_OFFLOAD_VERBOSE_LOGGING
 * constant, or WP_DEBUG. Levels:
 *
 * - error   — written whenever logging is enabled.
 * - warning — written whenever logging is enabled.
 * - info    — only when verbose logging is enabled (Settings tab toggle, WP_DEBUG,
 *             or DILUXONE_OFFLOAD_VERBOSE_LOGGING constant), unless $force = true.
 * - debug   — only when verbose logging is enabled.
 *
 * Deduplicates repeated messages within a 5-minute window to prevent log spam
 * from hot paths (stream wrapper, cache lookups, etc.).
 *
 * @package DiluxOneOffload
 * @since 1.0.0
 */
class Logger {

	/** @var array<string, int> Cache of message hash → timestamp last logged, for spam prevention. */
	private static array $log_cache = array();

	/** @var int Dedupe window in seconds. */
	private static int $cache_duration = 300;

	/** @var bool Whether verbose (info/debug) logging is enabled. */
	private static bool $verbose_logging = false;

	/** @var bool Whether init() has run. */
	private static bool $initialized = false;

	/**
	 * Initialize logger — reads verbose-logging state from:
	 *
	 *   1. DILUXONE_OFFLOAD_VERBOSE_LOGGING constant (wins if defined and true).
	 *   2. diluxone_offload_config['debug_enabled'] (the Settings tab toggle).
	 *   3. WP_DEBUG (as a fallback developer hint).
	 *
	 * Idempotent; subsequent calls are no-ops.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}

		// Recompute from scratch every time. refresh() runs right after the
		// settings form is saved, and if the toggle was just turned OFF the
		// previous value must not survive: the old code only ever set the
		// flag to true here, so switching debug off did not take effect until
		// the next request.
		$config                = get_option( 'diluxone_offload_config', array() );
		self::$verbose_logging = ( defined( 'DILUXONE_OFFLOAD_VERBOSE_LOGGING' ) && DILUXONE_OFFLOAD_VERBOSE_LOGGING )
			|| ( is_array( $config ) && ! empty( $config['debug_enabled'] ) );

		self::$initialized = true;
	}

	/**
	 * Re-read the verbose-logging flag from persisted config.
	 *
	 * Call this after saving the Settings tab so the toggle takes effect immediately
	 * without requiring a page reload.
	 *
	 * @return void
	 */
	public static function refresh(): void {
		self::$initialized = false;
		self::init();
	}

	/**
	 * Whether this site wants log lines at all.
	 *
	 * Either the plugin's own debug toggle, or WordPress's WP_DEBUG. With both
	 * off the logger writes nothing, whatever the level.
	 *
	 * @return bool
	 */
	private static function logging_enabled(): bool {
		return self::$verbose_logging || ( defined( 'WP_DEBUG' ) && WP_DEBUG );
	}

	/**
	 * Write a log line.
	 *
	 * @param string $message Log message.
	 * @param string $level   'error' | 'warning' | 'info' | 'debug'. Defaults to 'info'.
	 * @param bool   $force   If true, bypass the level gate. Does not bypass
	 *                        logging_enabled(): a silent site stays silent.
	 * @return void
	 */
	public static function log( string $message, string $level = 'info', bool $force = false ): void {
		if ( ! self::$initialized ) {
			self::init();
		}

		// Nothing reaches the PHP error log unless the site asked for it. A
		// production install that never turned anything on stays silent, which
		// is what wordpress.org expects of a shipped plugin.
		if ( ! self::logging_enabled() ) {
			return;
		}

		// With logging on, info and debug still wait for the verbose toggle;
		// errors and warnings go through.
		$should_log = $force
			|| self::$verbose_logging
			|| $level === 'error'
			|| $level === 'warning';

		if ( ! $should_log ) {
			return;
		}

		// Dedupe: skip if we've logged the same message within the window.
		$cache_key = md5( $level . '|' . $message );
		$now       = time();
		if ( isset( self::$log_cache[ $cache_key ] ) && ( $now - self::$log_cache[ $cache_key ] ) < self::$cache_duration ) {
			return;
		}

		error_log( $message );
		self::$log_cache[ $cache_key ] = $now;
		self::clean_cache( $now );
	}

	/**
	 * Convenience shortcut: always-on error log.
	 *
	 * @param string $message
	 * @return void
	 */
	public static function error( string $message ): void {
		self::log( $message, 'error' );
	}

	/**
	 * Convenience shortcut: always-on warning log.
	 *
	 * @param string $message
	 * @return void
	 */
	public static function warning( string $message ): void {
		self::log( $message, 'warning' );
	}

	/**
	 * Convenience shortcut: gated info log.
	 *
	 * @param string $message
	 * @return void
	 */
	public static function info( string $message ): void {
		self::log( $message, 'info' );
	}

	/**
	 * Convenience shortcut: gated debug log.
	 *
	 * @param string $message
	 * @return void
	 */
	public static function debug( string $message ): void {
		self::log( $message, 'debug' );
	}

	/**
	 * Manually set verbose-logging state (useful for tests).
	 *
	 * @param bool $enabled
	 * @return void
	 */
	public static function set_verbose_logging( bool $enabled ): void {
		self::$verbose_logging = $enabled;
		self::$initialized     = true;
	}

	/**
	 * Whether verbose (info/debug) logging is currently enabled.
	 *
	 * @return bool
	 */
	public static function is_verbose_logging(): bool {
		if ( ! self::$initialized ) {
			self::init();
		}
		return self::$verbose_logging;
	}

	/**
	 * Remove stale dedupe-cache entries.
	 *
	 * @param int $now Current timestamp.
	 * @return void
	 */
	private static function clean_cache( int $now ): void {
		foreach ( self::$log_cache as $key => $timestamp ) {
			if ( ( $now - $timestamp ) > self::$cache_duration ) {
				unset( self::$log_cache[ $key ] );
			}
		}
	}
}
