<?php
/**
 * Plugin Name: GitHub Plugin Browser
 * Description: Browse, install, and update WordPress plugins directly from GitHub repositories.
 * Version: 1.0.0
 * Author: Balázs Piller
 * Text Domain: github-plugin-installer
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'GPB_VERSION', '1.0.0' );
define( 'GPB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GPB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Include required files.
require_once GPB_PLUGIN_DIR . 'includes/class-gpb-cache.php';
require_once GPB_PLUGIN_DIR . 'includes/class-gpb-github-api.php';
require_once GPB_PLUGIN_DIR . 'includes/class-gpb-settings.php';
require_once GPB_PLUGIN_DIR . 'includes/class-gpb-admin-page.php';
require_once GPB_PLUGIN_DIR . 'includes/class-gpb-plugin-installer.php';
require_once GPB_PLUGIN_DIR . 'includes/class-gpb-plugin-updater.php';

// Load text domain.
function gpb_load_textdomain() {
	load_plugin_textdomain( 'github-plugin-installer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}
add_action( 'plugins_loaded', 'gpb_load_textdomain' );

// Initialize settings.
GPB_Settings::init();

// Initialize admin page.
GPB_Admin_Page::init();

// Handle plugin updates.
GPB_Plugin_Updater::init();

// Add action to display rate limit notices if needed.
add_action( 'admin_notices', 'gpb_display_rate_limit_notice' );
function gpb_display_rate_limit_notice() {
	$rate_limited = get_transient( 'gpb_rate_limit_reached' );
	if ( $rate_limited ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'GitHub API rate limit reached. Please add a personal access token in the settings to continue.', 'github-plugin-installer' ) . '</p></div>';
	}
}

// Enqueue admin assets conditionally.
function gpb_enqueue_admin_assets( $hook ) {
	if ( 'plugins_page_gpb-plugin-browser' === $hook ) {
		wp_enqueue_style( 'gpb-admin-styles', GPB_PLUGIN_URL . 'assets/css/admin-styles.css', array(), GPB_VERSION );
		wp_enqueue_script( 'gpb-admin-scripts', GPB_PLUGIN_URL . 'assets/js/admin-scripts.js', array( 'jquery' ), GPB_VERSION, true );
	}
}
add_action( 'admin_enqueue_scripts', 'gpb_enqueue_admin_assets' );
