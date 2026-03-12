<?php
/**
 * Shared system actions for cache clearing and update checks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class H2WP_System_Action_Service {

	/**
	 * Clear the hub2wp cache.
	 *
	 * @return array<string, mixed>
	 */
	public function clear_cache() {
		H2WP_Cache::clear_all();
		delete_transient( 'h2wp_last_update_check' );

		return array(
			'message'    => __( 'Cache cleared successfully.', 'hub2wp' ),
			'cleared_at' => time(),
		);
	}

	/**
	 * Run an immediate update check.
	 *
	 * @return array<string, mixed>
	 */
	public function run_update_check() {
		delete_transient( 'h2wp_last_update_check' );
		H2WP_Plugin_Updater::check_for_updates();
		wp_update_plugins();
		wp_update_themes();

		return array(
			'message'       => __( 'Update check completed for monitored plugins and themes.', 'hub2wp' ),
			'tracked_plugins' => count( get_option( 'h2wp_plugins', array() ) ),
			'tracked_themes'  => count( get_option( 'h2wp_themes', array() ) ),
			'ran_at'          => time(),
		);
	}
}
