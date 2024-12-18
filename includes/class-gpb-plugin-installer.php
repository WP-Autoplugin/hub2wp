<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * This class could be extended if we need custom install logic.
 * For now, plugin installation is handled directly in the admin page class.
 * This class is provided as a placeholder for organizational purposes.
 */
class GPB_Plugin_Installer {

	/**
	 * Install a plugin from a GitHub ZIP URL.
	 *
	 * @param string $download_url ZIP file URL.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function install_plugin( $download_url ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$upgrader = new Plugin_Upgrader();
		$result   = $upgrader->install( $download_url );

		if ( is_wp_error( $result ) || ! $result ) {
			return new WP_Error( 'gpb_install_error', __( 'Failed to install the plugin.', 'github-plugin-browser' ) );
		}
		return true;
	}
}
