<?php
/**
 * Plugin Name: DiluxOne Offload – Media Storage
 * Plugin URI: https://github.com/DiluxOne/diluxone-offload-wordpress
 * Description: Move your WordPress media to cloud object storage and serve it from there. Replaces /uploads/ transparently via a PHP stream wrapper.
 * Version: 1.0.0
 * Author: Pablo Ariel Di Loreto
 * Author URI: https://diluxone.com/plugins-wordpress
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: diluxone-offload
 * Requires at least: 5.1
 * Requires PHP: 7.4
 *
 * @package DiluxOneOffload
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants
define( 'DILUXONE_OFFLOAD_VERSION', '1.0.0' );
define( 'DILUXONE_OFFLOAD_DIR', plugin_dir_path( __FILE__ ) );
define( 'DILUXONE_OFFLOAD_URL', plugin_dir_url( __FILE__ ) );
define( 'DILUXONE_OFFLOAD_FILE', __FILE__ );

// Load enhanced autoloader
require_once DILUXONE_OFFLOAD_DIR . 'includes/enhanced-autoloader.php';

use DiluxOneOffload\Plugin;

global $diluxone_offload_plugin;

/**
 * Initialize the enhanced plugin.
 *
 * Translations for plugins hosted on wordpress.org are loaded automatically
 * by WordPress since 4.6, so we no longer call load_plugin_textdomain().
 *
 * @return void
 */
function diluxone_offload_init() {
	global $diluxone_offload_plugin;

	$diluxone_offload_plugin = Plugin::get_instance();
	$diluxone_offload_plugin->init();
}
add_action( 'plugins_loaded', 'diluxone_offload_init', 10 );

// A site added to a network after activation still needs this plugin's table.
add_action( 'wp_initialize_site', array( Plugin::class, 'on_new_site' ), 20 );

/**
 * Plugin activation hook.
 */
register_activation_hook(
	__FILE__,
	function ( $network_wide ) {
		Plugin::activate( (bool) $network_wide );
	}
);

/**
 * Plugin deactivation hook.
 */
register_deactivation_hook(
	__FILE__,
	function () {
		Plugin::deactivate();
	}
);
