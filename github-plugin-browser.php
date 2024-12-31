<?php
/**
 * Plugin Name: GitHub Plugin Browser
 * Description: Browse, install, and update WordPress plugins directly from GitHub repositories.
 * Version: 1.0.0
 * Author: Balázs Piller
 * Text Domain: github-plugin-browser
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'GPB_VERSION', '1.0.0' );
define( 'GPB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GPB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'GPB_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'GPB_PLUGIN_FILE', GPB_PLUGIN_DIR . GPB_PLUGIN_BASENAME );

// Include autoload.
require_once GPB_PLUGIN_DIR . 'vendor/autoload.php';

// Load text domain.
function gpb_load_textdomain() {
	load_plugin_textdomain( 'github-plugin-browser', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}
add_action( 'plugins_loaded', 'gpb_load_textdomain' );

// Activation hook.
register_activation_hook( __FILE__, array( 'GPB_Plugin_Updater', 'activate' ) );

// Deactivation hook.
register_deactivation_hook( __FILE__, array( 'GPB_Plugin_Updater', 'deactivate' ) );

// Initialize settings.
GPB_Settings::init();

// Initialize admin page.
GPB_Admin_Page::init();

// Handle plugin updates.
GPB_Plugin_Updater::init();

// Initialize AJAX handler.
new GPB_Admin_Ajax();
