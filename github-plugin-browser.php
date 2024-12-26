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

// Include autoload.
require_once GPB_PLUGIN_DIR . 'vendor/autoload.php';

// Load text domain.
function gpb_load_textdomain() {
	load_plugin_textdomain( 'github-plugin-browser', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}
add_action( 'plugins_loaded', 'gpb_load_textdomain' );

// Initialize settings.
GPB_Settings::init();

// Initialize admin page.
GPB_Admin_Page::init();

// Handle plugin updates.
GPB_Plugin_Updater::init();

// Initialize AJAX handler.
new GPB_Admin_Ajax();

// Add action to display rate limit notices if needed.
add_action( 'admin_notices', 'gpb_display_rate_limit_notice' );
function gpb_display_rate_limit_notice() {
	$rate_limited = get_transient( 'gpb_rate_limit_reached' );
	if ( $rate_limited ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'GitHub API rate limit reached. Please add a personal access token in the settings to continue.', 'github-plugin-browser' ) . '</p></div>';
	}
}

// Enqueue admin assets conditionally.
function gpb_enqueue_admin_assets( $hook ) {
	if ( 'plugins_page_gpb-plugin-browser' === $hook ) {
		wp_enqueue_style( 'gpb-admin-styles', GPB_PLUGIN_URL . 'assets/css/admin-styles.css', array(), GPB_VERSION );
		wp_enqueue_script( 'gpb-admin-scripts', GPB_PLUGIN_URL . 'assets/js/admin-scripts.js', array( 'jquery' ), GPB_VERSION, true );

		// Localize script with AJAX URL and nonce.
		wp_localize_script( 'gpb-admin-scripts', 'gpb_ajax_object', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'gpb_plugin_details_nonce' ),
		) );
	}
}
add_action( 'admin_enqueue_scripts', 'gpb_enqueue_admin_assets' );
