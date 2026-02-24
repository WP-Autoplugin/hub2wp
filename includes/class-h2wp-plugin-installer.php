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
	public $theme_data  = array();

	/**
	 * Install a plugin from a GitHub ZIP URL.
	 *
	 * @param string $download_url  ZIP file URL.
	 * @param string $access_token  Optional GitHub access token for private repos.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function install_plugin( $download_url, $access_token = '' ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$upgrader = new Plugin_Upgrader( new H2WP_Silent_Installer_Skin() );

		// For private repos the upgrader's built-in download_url() has no auth headers,
		// so we download the zip ourselves first and hand the local file to the upgrader.
		$local_file = null;
		if ( ! empty( $access_token ) ) {
			$local_file = $this->download_authenticated( $download_url, $access_token );
			if ( is_wp_error( $local_file ) ) {
				return $local_file;
			}
			$package = $local_file;
		} else {
			$package = $download_url;
		}

		ob_start();
		$result = $upgrader->install( $package );
		ob_end_clean();

		// Always clean up the temp file.
		if ( null !== $local_file && file_exists( $local_file ) ) {
			// phpcs:ignore -- WordPress.PHP.NoSilencedErrors.Discouraged & WordPress.WP.AlternativeFunctions.file_system_read_file -- We want to suppress errors here since the file might not exist or be deletable, and there's no real alternative function for this.
			@unlink( $local_file );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $result ) {
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

	/**
	 * Install a theme from a GitHub ZIP URL.
	 *
	 * @param string $download_url ZIP file URL.
	 * @param string $access_token Optional GitHub access token for private repos.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function install_theme( $download_url, $access_token = '' ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$upgrader = new Theme_Upgrader( new H2WP_Silent_Installer_Skin() );

		$local_file = null;
		if ( ! empty( $access_token ) ) {
			$local_file = $this->download_authenticated( $download_url, $access_token );
			if ( is_wp_error( $local_file ) ) {
				return $local_file;
			}
			$package = $local_file;
		} else {
			$package = $download_url;
		}

		ob_start();
		$result = $upgrader->install( $package );
		ob_end_clean();

		// Always clean up the temp file.
		if ( null !== $local_file && file_exists( $local_file ) ) {
			// phpcs:ignore -- WordPress.PHP.NoSilencedErrors.Discouraged & WordPress.WP.AlternativeFunctions.file_system_read_file -- We want to suppress errors here since the file might not exist or be deletable, and there's no real alternative function for this.
			@unlink( $local_file );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $result ) {
			return new WP_Error( 'h2wp_install_error', __( 'Failed to install the theme.', 'hub2wp' ) );
		}

		$stylesheet = isset( $upgrader->result['destination_name'] ) ? $upgrader->result['destination_name'] : '';
		$theme      = $stylesheet ? wp_get_theme( $stylesheet ) : false;

		$this->theme_data = array(
			'directory'  => $stylesheet,
			'name'       => $theme ? $theme->get( 'Name' ) : '',
			'author'     => $theme ? $theme->get( 'Author' ) : '',
			'version'    => $theme ? $theme->get( 'Version' ) : '',
			'stylesheet' => $stylesheet,
			'template'   => $theme ? $theme->get_template() : $stylesheet,
		);

		return true;
	}

	/**
	 * Download a file from a URL using an Authorization header and save it to a temp file.
	 *
	 * WordPress's built-in download_url() never sends auth headers, so for private
	 * GitHub repos we must handle the download ourselves.
	 *
	 * @param string $url          The URL to download.
	 * @param string $access_token GitHub personal access token.
	 * @return string|WP_Error Path to the temp file on success, WP_Error on failure.
	 */
	private function download_authenticated( $url, $access_token ) {
		$tmpfname = wp_tempnam( $url );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 300,
				'stream'      => true,
				'filename'    => $tmpfname,
				'redirection' => 5,
				'headers'     => array(
					'Authorization' => 'token ' . $access_token,
					'Accept'        => 'application/vnd.github+json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			// phpcs:ignore -- WordPress.PHP.NoSilencedErrors.Discouraged & WordPress.WP.AlternativeFunctions.file_system_read_file -- We want to suppress errors here since the file might not exist or be deletable, and there's no real alternative function for this.
			@unlink( $tmpfname );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			// phpcs:ignore -- WordPress.PHP.NoSilencedErrors.Discouraged & WordPress.WP.AlternativeFunctions.file_system_read_file -- We want to suppress errors here since the file might not exist or be deletable, and there's no real alternative function for this.
			@unlink( $tmpfname );
			/* translators: %d: HTTP status code */
			return new WP_Error(
				'h2wp_download_error',
				sprintf(
					// Translators: %d: HTTP status code.
					__( 'Could not download the repository zip (HTTP %d). Please verify your access token has the "repo" scope and that you can access this repository.', 'hub2wp' ),
					$code
				)
			);
		}

		return $tmpfname;
	}
}
