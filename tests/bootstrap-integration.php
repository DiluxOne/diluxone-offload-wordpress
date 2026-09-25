<?php
/**
 * PHPUnit bootstrap for integration tests.
 *
 * Loads WordPress core with the plugin already activated and ensures
 * the plugin's custom DB table exists. Runs inside the wp-env "tests"
 * Docker container, where:
 *
 * - WordPress core lives at  /var/www/html/
 * - This plugin is mounted at /var/www/html/wp-content/plugins/diluxone-offload/
 * - Composer vendor/ ships from the host repo via the same mount.
 * - The MySQL database for tests is named `tests-wordpress` (wp-env default).
 *
 * Outside of the container, this file MUST NOT be required — the
 * /var/www/html paths do not exist on the host. The CI workflow
 * (the shared plugin-tests-wp workflow) invokes phpunit via `npx wp-env run tests-cli`
 * specifically for that reason.
 */

// 0. Prevent WordPress from sending headers / loading themes during bootstrap.
define('WP_USE_THEMES', false);

// 1. Simulate admin AJAX context so handlers register and wp_die is interceptable.
if (!defined('WP_ADMIN')) {
    define('WP_ADMIN', true);
}
if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}
$_SERVER['PHP_SELF'] = '/wp-admin/admin-ajax.php';

// 1b. The two DEV MODE AJAX endpoints (enable/disconnect offloading without a
//     sync) are only registered behind this constant. Production never
//     defines it; the suite does, so those handlers get exercised too.
if (!defined('DILUXONE_OFFLOAD_DEV_MODE')) {
    define('DILUXONE_OFFLOAD_DEV_MODE', true);
}

// 2. Set HTTP_HOST etc. to prevent "Undefined array key" warnings under CLI.
//
//    On a multisite network the host MUST be one WordPress knows: for an
//    unknown domain ms-settings.php redirects to the signup page and calls
//    exit(0) before PHPUnit ever starts — no banner, no error, exit code 0,
//    which is the worst possible way for a suite to fail. wp-config.php
//    carries the network domain as DOMAIN_CURRENT_SITE; read it from the
//    file (constants are not available before WordPress loads) and fall
//    back to a placeholder on single-site installs, where any host works.
if (empty($_SERVER['HTTP_HOST'])) {
    $host = getenv('HTTP_HOST') ?: '';
    if ($host === '' && is_readable('/var/www/html/wp-config.php')) {
        if (preg_match(
            "/define\\(\\s*'DOMAIN_CURRENT_SITE'\\s*,\\s*'([^']+)'/",
            (string) file_get_contents('/var/www/html/wp-config.php'),
            $m
        )) {
            $host = $m[1];
        }
    }
    $_SERVER['HTTP_HOST'] = $host !== '' ? $host : 'tests.local';
}
if (empty($_SERVER['SERVER_NAME'])) {
    $_SERVER['SERVER_NAME'] = $_SERVER['HTTP_HOST'];
}
if (empty($_SERVER['REQUEST_URI'])) {
    $_SERVER['REQUEST_URI'] = '/';
}

// 3. Resolve the plugin directory dynamically. This bootstrap file lives at
//    <plugin-root>/tests/bootstrap-integration.php — inside the wp-env tests
//    container that resolves to /var/www/html/wp-content/plugins/<slug>/tests/,
//    so dirname(__DIR__) gives the plugin root regardless of the slug. This
//    keeps the bootstrap working if the plugin folder is ever renamed.
$plugin_dir_in_container = dirname(__DIR__);

// 4. Load WordPress (this loads the plugin since it's activated).
$wp_load = '/var/www/html/wp-load.php';
if (!file_exists($wp_load)) {
    fwrite(STDERR, "ERROR: wp-load.php not found at {$wp_load}\n");
    fwrite(STDERR, "Integration tests must run inside the wp-env tests container.\n");
    fwrite(STDERR, "Use: npx wp-env run tests-cli ./vendor/bin/phpunit -c phpunit-integration.xml\n");
    exit(1);
}
require_once $wp_load;

// 5. Verify we're talking to the test database. wp-env names it
//    "tests-wordpress" by default. The substring check is the safety net
//    against accidentally running integration tests against a real database.
global $wpdb;
$db_name = $wpdb->dbname;
if (strpos($db_name, 'test') === false) {
    fwrite(STDERR, "SAFETY CHECK FAILED: database '{$db_name}' does not contain 'test'.\n");
    fwrite(STDERR, "Integration tests MUST run on a test database. Aborting.\n");
    exit(1);
}

// 6. Composer autoload (PHPUnit, Brain Monkey, Mockery, Tests\ namespace).
//    The vendor/ tree comes from the host's `composer install`, mounted
//    into the container via the plugin's volume.
$composer_autoload = $plugin_dir_in_container . '/vendor/autoload.php';
if (!file_exists($composer_autoload)) {
    fwrite(STDERR, "ERROR: composer autoload not found at {$composer_autoload}.\n");
    fwrite(STDERR, "Run `composer install` on the host before invoking integration tests.\n");
    exit(1);
}
require_once $composer_autoload;

// 7. Verify the plugin loaded.
if (!defined('DILUXONE_OFFLOAD_DIR')) {
    fwrite(STDERR, "ERROR: diluxone-offload plugin is not activated in the tests environment.\n");
    fwrite(STDERR, "Run: npx wp-env run tests-cli wp plugin activate diluxone-offload\n");
    exit(1);
}

// 8. Force-load DiluxOneOffloadDB (the plugin's normal flow loads it via require_once
//    inside its bootstrap; making it explicit here ensures it's present
//    before any test method runs, regardless of plugin load order).
require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';

// 9. Ensure the plugin's custom table exists in the tests database.
\DiluxOneOffload\DiluxOneOffloadDB::create_files_table();

// 10. Mark integration test context — useful inside plugin code that wants
//     to bypass certain side effects (sending real HTTP, registering cron,
//     etc.) when DILUXONE_OFFLOAD_INTEGRATION_TESTS is defined.
if (!defined('DILUXONE_OFFLOAD_INTEGRATION_TESTS')) {
    define('DILUXONE_OFFLOAD_INTEGRATION_TESTS', true);
}

// 11. Override wp_die handlers globally so AJAX handlers throw an
//     exception instead of terminating the PHPUnit process. Tests that
//     expect wp_die catch WPAjaxDieContinueException.
// Extends \Error, not \Exception, on purpose. wp_send_json_*() ends with
// wp_die(), which this throws to stand in for exit(). Almost every AJAX
// handler in the plugin wraps its body in try { … } catch ( \Exception $e ),
// and an Exception-based stand-in gets swallowed by that catch: the handler
// then emits a SECOND json_error("… : ") after the real answer and the test
// sees two concatenated documents. In production exit() is not catchable;
// an \Error is the closest thing PHP offers to "not for you to catch".
class WPAjaxDieContinueException extends \Error {}

$_diluxone_offload_wp_die_test_handler = function ($message, $title = '', $args = []) {
    if (function_exists('is_wp_error') && is_wp_error($message)) {
        $message = $message->get_error_message();
    }
    throw new WPAjaxDieContinueException((string) $message);
};

add_filter('wp_die_ajax_handler', function () use ($_diluxone_offload_wp_die_test_handler) {
    return $_diluxone_offload_wp_die_test_handler;
}, 999);

add_filter('wp_die_handler', function () use ($_diluxone_offload_wp_die_test_handler) {
    return $_diluxone_offload_wp_die_test_handler;
}, 999);

// 12. Confirmation banner (shows in CI logs).
echo "Integration test bootstrap loaded.\n";
echo "  Database:    {$db_name}\n";
echo "  Prefix:      {$wpdb->prefix}\n";
echo "  Plugin DB:   " . \DiluxOneOffload\DiluxOneOffloadDB::get_table_name() . "\n";
echo "  WP version:  " . get_bloginfo('version') . "\n";
echo "  Plugin ver:  " . DILUXONE_OFFLOAD_VERSION . "\n";
