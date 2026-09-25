<?php
/**
 * PHPStan analysis bootstrap.
 *
 * Defines plugin constants that are normally created at runtime by the
 * main plugin file (diluxone-offload.php). PHPStan analyzes the
 * codebase statically without executing anything, so it never sees the
 * `define()` calls there. Without these stubs, every reference to
 * `DILUXONE_OFFLOAD_DIR` and friends produces "Constant not found".
 *
 * This file is referenced from phpstan.neon's `bootstrapFiles:` list.
 * It is excluded from the wp.org deploy via .distignore. It is NOT
 * loaded at plugin runtime — only by PHPStan during analysis.
 *
 * @package DiluxOneOffload
 */

if ( ! defined( 'DILUXONE_OFFLOAD_VERSION' ) ) {
	define( 'DILUXONE_OFFLOAD_VERSION', '0.0.0-phpstan-stub' );
}
if ( ! defined( 'DILUXONE_OFFLOAD_DIR' ) ) {
	define( 'DILUXONE_OFFLOAD_DIR', __DIR__ . '/' );
}
if ( ! defined( 'DILUXONE_OFFLOAD_URL' ) ) {
	define( 'DILUXONE_OFFLOAD_URL', 'https://example.test/wp-content/plugins/diluxone-offload/' );
}
if ( ! defined( 'DILUXONE_OFFLOAD_FILE' ) ) {
	define( 'DILUXONE_OFFLOAD_FILE', __DIR__ . '/diluxone-offload.php' );
}

// Optional development-mode constants referenced by some code paths.
if ( ! defined( 'DILUXONE_OFFLOAD_DEV_MODE' ) ) {
	define( 'DILUXONE_OFFLOAD_DEV_MODE', false );
}
if ( ! defined( 'DILUXONE_OFFLOAD_VERBOSE_LOGGING' ) ) {
	define( 'DILUXONE_OFFLOAD_VERBOSE_LOGGING', false );
}
