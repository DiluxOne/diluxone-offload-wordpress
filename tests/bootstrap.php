<?php
/**
 * PHPUnit bootstrap for unit tests.
 *
 * Loads Composer dependencies (PHPUnit, Brain Monkey, Mockery), defines
 * the WordPress constants the plugin's runtime expects, registers the
 * plugin's class autoloader, and pulls in stubs for the WordPress
 * functions the helpers and DTOs use directly.
 *
 * Unit tests do NOT require a running WordPress installation. For
 * integration tests, use phpunit-integration.xml + bootstrap-integration.php.
 */

// 1. Composer autoload (PHPUnit, Brain Monkey, Mockery, Tests\ namespace).
require_once __DIR__ . '/../vendor/autoload.php';

// 2. WordPress constants the plugin expects at the top of diluxone-offload.php.
//    ABSPATH is normally defined by WordPress core; in unit tests we just need
//    `defined('ABSPATH')` to be true so the `if (!defined('ABSPATH')) { exit; }`
//    guards in plugin files don't terminate the test process.
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

if (!defined('DILUXONE_OFFLOAD_DIR')) {
    define('DILUXONE_OFFLOAD_DIR', dirname(__DIR__) . '/');
}

if (!defined('DILUXONE_OFFLOAD_VERSION')) {
    define('DILUXONE_OFFLOAD_VERSION', '1.0.0-test');
}

if (!defined('DILUXONE_OFFLOAD_FILE')) {
    define('DILUXONE_OFFLOAD_FILE', dirname(__DIR__) . '/diluxone-offload.php');
}

if (!defined('DILUXONE_OFFLOAD_URL')) {
    define('DILUXONE_OFFLOAD_URL', 'http://localhost/wp-content/plugins/diluxone-offload/');
}

// 3. WordPress function stubs (sanitize_text_field, get_option, esc_html, etc.).
//    These are minimal implementations sufficient for the helpers/DTOs under test.
//    For hook functions (add_action, add_filter), use Brain Monkey in the test itself.
// Like a developer's site: the debug-only logging branches run too.
if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', true);
}
require_once __DIR__ . '/stubs/wordpress-stubs.php';

// 4. Plugin's class autoloader. Maps DiluxOneOffload\* to includes/.
require_once DILUXONE_OFFLOAD_DIR . 'includes/enhanced-autoloader.php';
