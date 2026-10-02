<?php
/**
 * Must-use plugin for `make coverage-e2e-php` only: run.sh copies it into
 * the dev site's mu-plugins for the run and removes it afterwards. It never
 * ships and is never installed by `make test-e2e`.
 *
 * Every request the suite makes (page loads, admin-ajax, admin-post, REST,
 * the WP-CLI calls of the helpers) records Xdebug's line coverage of the
 * plugin's own files and, as the very last shutdown function, writes it as
 * one JSON file to build/e2e-php-coverage/raw/ in the mounted checkout.
 * Must-use plugins load before regular plugins, so the plugin's files are
 * all compiled after coverage has started. Nothing happens unless Xdebug is
 * loaded (wp-env started with --xdebug=coverage) and the folder exists.
 */

if ( ! function_exists( 'xdebug_start_code_coverage' ) ) {
	return;
}

$diluxone_offload_cov_plugin = WP_CONTENT_DIR . '/plugins/diluxone-offload-wordpress/';
$diluxone_offload_cov_out    = $diluxone_offload_cov_plugin . 'build/e2e-php-coverage/raw';
if ( ! is_dir( $diluxone_offload_cov_out ) ) {
	return;
}

// Only the plugin's files are instrumented, which keeps UNUSED | DEAD_CODE cheap.
xdebug_set_filter(
	XDEBUG_FILTER_CODE_COVERAGE,
	XDEBUG_PATH_INCLUDE,
	array(
		$diluxone_offload_cov_plugin . 'includes/',
		$diluxone_offload_cov_plugin . 'templates/',
		$diluxone_offload_cov_plugin . 'diluxone-offload.php',
		$diluxone_offload_cov_plugin . 'uninstall.php',
	)
);
xdebug_start_code_coverage( XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE );

register_shutdown_function(
	static function () use ( $diluxone_offload_cov_out ) {
		// Registered from inside the shutdown phase, so it runs after every
		// shutdown function WordPress and the plugin registered.
		register_shutdown_function(
			static function () use ( $diluxone_offload_cov_out ) {
				$data = xdebug_get_code_coverage();
				xdebug_stop_code_coverage();
				if ( $data ) {
					file_put_contents(
						$diluxone_offload_cov_out . '/' . str_replace( '.', '-', uniqid( '', true ) ) . '-' . getmypid() . '.json',
						json_encode( $data ),
						LOCK_EX
					);
				}
			}
		);
	}
);
