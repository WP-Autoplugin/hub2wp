<?php
/**
 * Handles plugin installation and activation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * This class could be extended if we need custom install logic.
 * For now, plugin installation is handled directly in the admin page class.
 * This class is provided as a placeholder for organizational purposes.
 */
class H2WP_Plugin_Installer {

	public $plugin_data = array();

	/**
	 * Install a plugin from a GitHub ZIP URL.
	 *
	 * @param string $download_url ZIP file URL.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function install_plugin( $download_url ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		
		$upgrader = new Plugin_Upgrader( new H2WP_Silent_Installer_Skin() );

		ob_start();
		$result   = $upgrader->install( $download_url );
		ob_end_clean();

		if ( is_wp_error( $result ) || ! $result ) {
			return new WP_Error( 'h2wp_install_error', __( 'Failed to install the plugin.', 'hub2wp' ) );
		}

		$this->plugin_data = array(
			'directory' => $upgrader->result['destination_name'],
			'name'      => $upgrader->new_plugin_data['Name'],
			'author'    => $upgrader->new_plugin_data['Author'],
			'version'   => $upgrader->new_plugin_data['Version'],
		);

		return true;
	}
}
