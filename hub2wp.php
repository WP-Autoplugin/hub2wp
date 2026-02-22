<?php
/**
 * Plugin Name: hub2wp
 * Description: Browse, install, and update WordPress plugins directly from GitHub repositories.
 * Version: 1.0.2
 * Author: Balázs Piller
 * Text Domain: hub2wp
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 5.7
 * License: GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'H2WP_VERSION', '1.0.2' );
define( 'H2WP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'H2WP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'H2WP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Include autoload.
require_once H2WP_PLUGIN_DIR . 'vendor/autoload.php';

// Activation hook.
register_activation_hook( __FILE__, array( 'H2WP_Plugin_Updater', 'activate' ) );

// Deactivation hook.
register_deactivation_hook( __FILE__, array( 'H2WP_Plugin_Updater', 'deactivate' ) );

// Initialize settings.
H2WP_Settings::init();

// Initialize admin page.
H2WP_Admin_Page::init();

// Handle plugin updates.
H2WP_Plugin_Updater::init();

// Initialize AJAX handler.
new H2WP_Admin_Ajax();
