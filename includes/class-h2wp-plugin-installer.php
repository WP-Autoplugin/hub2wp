<?php
/**
 * Handles plugin installation and activation.
 *
 * @package hub2wp
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

	/**
	 * Installed plugin metadata.
	 *
	 * @var array
	 */
	public $plugin_data = array();

	/**
	 * Installed theme metadata.
	 *
	 * @var array
	 */
	public $theme_data = array();

	/**
	 * Destination folder in the temporary upgrader workspace.
	 *
	 * @var string
	 */
	private $install_target_folder = '';

	/**
	 * Project subdirectory within a repository archive.
	 *
	 * @var string
	 */
	private $install_subdirectory = '';

	/**
	 * Install a plugin from a GitHub ZIP URL.
	 *
	 * @param string $download_url  ZIP file URL.
	 * @param string $access_token  Optional GitHub access token for private repos.
	 * @param string $subdirectory  Optional project subdirectory.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function install_plugin( $download_url, $access_token = '', $subdirectory = '' ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$subdirectory = H2WP_Settings::normalize_subdirectory( $subdirectory );
		if ( is_wp_error( $subdirectory ) ) {
			return $subdirectory;
		}

		$upgrader = new Plugin_Upgrader( new H2WP_Silent_Installer_Skin() );

		// For private repos the upgrader's built-in download_url() has no auth headers,
		// so we download the zip ourselves first and hand the local file to the upgrader.
		$local_file = null;
		if ( ! empty( $access_token ) && $this->requires_authenticated_download( $download_url ) ) {
			$local_file = $this->download_authenticated( $download_url, $access_token );
			if ( is_wp_error( $local_file ) ) {
				return $local_file;
			}
			$package = $local_file;
		} else {
			$package = $download_url;
		}

		// For monorepo plugins, target the plugin slug; for single repos, use the repo slug.
		$this->install_subdirectory  = trim( (string) $subdirectory, '/' );
		$this->install_target_folder = ! empty( $subdirectory )
			? basename( $subdirectory )
			: $this->get_repo_slug_from_download_url( $download_url );
		add_filter( 'upgrader_source_selection', array( $this, 'normalize_install_source_folder' ), 10, 4 );

		$buffer_level = ob_get_level();
		ob_start();
		try {
			$result = $upgrader->install( $package );
		} finally {
			$this->clean_output_buffers( $buffer_level );
			remove_filter( 'upgrader_source_selection', array( $this, 'normalize_install_source_folder' ), 10 );
			$this->install_target_folder = '';
			$this->install_subdirectory  = '';
			if ( null !== $local_file && file_exists( $local_file ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $local_file );
			}
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $result ) {
			return new WP_Error( 'h2wp_install_error', __( 'Failed to install the plugin.', 'hub2wp' ) );
		}

		$upgrader_result   = isset( $upgrader->result ) && is_array( $upgrader->result ) ? $upgrader->result : array();
		$new_plugin_data   = isset( $upgrader->new_plugin_data ) && is_array( $upgrader->new_plugin_data ) ? $upgrader->new_plugin_data : array();
		$this->plugin_data = array(
			'directory' => isset( $upgrader_result['destination_name'] ) ? $upgrader_result['destination_name'] : '',
			'name'      => isset( $new_plugin_data['Name'] ) ? $new_plugin_data['Name'] : '',
			'author'    => isset( $new_plugin_data['Author'] ) ? $new_plugin_data['Author'] : '',
			'version'   => isset( $new_plugin_data['Version'] ) ? $new_plugin_data['Version'] : '',
		);

		return true;
	}

	/**
	 * Install a theme from a GitHub ZIP URL.
	 *
	 * @param string $download_url ZIP file URL.
	 * @param string $access_token Optional GitHub access token for private repos.
	 * @param string $subdirectory Optional project subdirectory.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function install_theme( $download_url, $access_token = '', $subdirectory = '' ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$subdirectory = H2WP_Settings::normalize_subdirectory( $subdirectory );
		if ( is_wp_error( $subdirectory ) ) {
			return $subdirectory;
		}

		$upgrader = new Theme_Upgrader( new H2WP_Silent_Installer_Skin() );

		$local_file = null;
		if ( ! empty( $access_token ) && $this->requires_authenticated_download( $download_url ) ) {
			$local_file = $this->download_authenticated( $download_url, $access_token );
			if ( is_wp_error( $local_file ) ) {
				return $local_file;
			}
			$package = $local_file;
		} else {
			$package = $download_url;
		}

		$this->install_subdirectory  = trim( (string) $subdirectory, '/' );
		$this->install_target_folder = ! empty( $subdirectory )
			? basename( $subdirectory )
			: $this->get_repo_slug_from_download_url( $download_url );
		add_filter( 'upgrader_source_selection', array( $this, 'normalize_install_source_folder' ), 10, 4 );

		$buffer_level = ob_get_level();
		ob_start();
		try {
			$result = $upgrader->install( $package );
		} finally {
			$this->clean_output_buffers( $buffer_level );
			remove_filter( 'upgrader_source_selection', array( $this, 'normalize_install_source_folder' ), 10 );
			$this->install_target_folder = '';
			$this->install_subdirectory  = '';
			if ( null !== $local_file && file_exists( $local_file ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $local_file );
			}
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $result ) {
			return new WP_Error( 'h2wp_install_error', __( 'Failed to install the theme.', 'hub2wp' ) );
		}

		$upgrader_result = isset( $upgrader->result ) && is_array( $upgrader->result ) ? $upgrader->result : array();
		$stylesheet      = isset( $upgrader_result['destination_name'] ) ? $upgrader_result['destination_name'] : '';
		$theme           = $stylesheet ? wp_get_theme( $stylesheet ) : false;

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
		if ( empty( $tmpfname ) || ! is_string( $tmpfname ) ) {
			return new WP_Error( 'h2wp_temp_file_error', __( 'Could not create a temporary file for the download.', 'hub2wp' ) );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 300,
				'stream'      => true,
				'filename'    => $tmpfname,
				'redirection' => 5,
				'headers'     => array(
					'Authorization' => 'token ' . $access_token,
					// Release asset API URLs need octet-stream to receive the file.
					// Standard zipball/API URLs need the GitHub API accept header.
					'Accept'        => ( false !== strpos( $url, '/releases/assets/' ) )
						? 'application/octet-stream'
						: 'application/vnd.github+json',
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

	/**
	 * Check whether a package URL is a trusted GitHub API download.
	 *
	 * @param string $url Package URL.
	 * @return bool
	 */
	private function requires_authenticated_download( $url ) {
		return 'api.github.com' === strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	}

	/**
	 * Parse the repository slug from a GitHub download URL.
	 *
	 * @param string $download_url GitHub zipball URL.
	 * @return string Folder slug.
	 */
	private function get_repo_slug_from_download_url( $download_url ) {
		$path = wp_parse_url( $download_url, PHP_URL_PATH );
		if ( empty( $path ) ) {
			return '';
		}

		if ( preg_match( '#/repos/[^/]+/([^/]+)/zipball#', $path, $matches ) ) {
			return sanitize_title( $matches[1] );
		}

		return '';
	}

	/**
	 * Rename extracted GitHub archive folder to the repository slug during install.
	 *
	 * @param string      $source        Source path.
	 * @param string      $remote_source Remote source path.
	 * @param WP_Upgrader $upgrader      Upgrader instance.
	 * @param array       $hook_extra    Upgrader context.
	 * @return string|WP_Error
	 */
	public function normalize_install_source_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		unset( $upgrader, $hook_extra );
		global $wp_filesystem;

		if ( empty( $this->install_target_folder ) ) {
			return $source;
		}

		if ( ! $wp_filesystem || ! method_exists( $wp_filesystem, 'move' ) ) {
			return $source;
		}

		$final_dest     = trailingslashit( $remote_source ) . $this->install_target_folder;
		$project_source = $source;

		// A repository zip contains the configured nested path. A project release
		// asset commonly contains the plugin/theme at its archive root. Support both
		// layouts and let the core upgrader validate the selected package headers.
		if ( ! empty( $this->install_subdirectory ) ) {
			$nested_source = trailingslashit( $source ) . $this->install_subdirectory;
			if ( $wp_filesystem->is_dir( $nested_source ) ) {
				$project_source = $nested_source;
			}
		}

		if ( trailingslashit( $final_dest ) === trailingslashit( $project_source ) ) {
			return $project_source;
		}

		// Only clear a conflicting directory inside the upgrader's temporary
		// workspace. Never remove anything from the live plugin or theme directory.
		if ( $wp_filesystem->exists( $final_dest ) ) {
			if ( ! self::is_path_within( $final_dest, $remote_source ) || ! $wp_filesystem->delete( $final_dest, true ) ) {
				return new WP_Error( 'h2wp_temp_destination_error', __( 'Could not prepare the temporary install directory.', 'hub2wp' ) );
			}
		}

		if ( ! $wp_filesystem->move( untrailingslashit( $project_source ), $final_dest ) ) {
			return new WP_Error(
				'h2wp_rename_error',
				sprintf(
					/* translators: 1: extracted folder, 2: expected folder */
					__( 'Could not rename extracted folder from "%1$s" to "%2$s".', 'hub2wp' ),
					basename( $project_source ),
					$this->install_target_folder
				)
			);
		}

		if (
			untrailingslashit( $project_source ) !== untrailingslashit( $source ) &&
			self::is_path_within( $source, $remote_source )
		) {
			$wp_filesystem->delete( untrailingslashit( $source ), true );
		}

		return trailingslashit( $final_dest );
	}

	/**
	 * Close only output buffers opened after the supplied level.
	 *
	 * WordPress upgrader skins may close their own buffers, so an unconditional
	 * ob_end_clean() can emit a notice.
	 *
	 * @param int $buffer_level Initial output buffer level.
	 * @return void
	 */
	private function clean_output_buffers( $buffer_level ) {
		while ( ob_get_level() > $buffer_level ) {
			$status = ob_get_status();
			if ( empty( $status['flags'] ) || ! ( $status['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE ) ) {
				break;
			}
			ob_end_clean();
		}
	}

	/**
	 * Check that a path is inside a root directory.
	 *
	 * @param string $path Candidate path.
	 * @param string $root Root directory.
	 * @return bool
	 */
	private static function is_path_within( $path, $root ) {
		$path = wp_normalize_path( $path );
		$root = trailingslashit( wp_normalize_path( $root ) );

		return 0 === strpos( trailingslashit( $path ), $root );
	}
}
