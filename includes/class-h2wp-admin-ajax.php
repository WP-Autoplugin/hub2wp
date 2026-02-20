<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles AJAX requests for the hub2wp plugin.
 */
class H2WP_Admin_Ajax {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_h2wp_get_plugin_details', array( $this, 'get_plugin_details' ) );
		add_action( 'wp_ajax_h2wp_check_compatibility', array( $this, 'check_compatibility' ) );
		add_action( 'wp_ajax_h2wp_get_changelog', array( $this, 'get_changelog' ) );
		add_action( 'wp_ajax_h2wp_install_plugin', array( $this, 'install_plugin' ) );
		add_action( 'wp_ajax_h2wp_activate_plugin', array( $this, 'activate_plugin' ) );
	}

	/**
	 * Handle AJAX request to get plugin details.
	 */
	public function get_plugin_details() {
		// Check nonce.
		check_ajax_referer( 'h2wp_plugin_details_nonce', 'nonce' );

		// Check user capabilities.
		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'hub2wp' ) ) );
		}

		// Get and sanitize parameters.
		$owner = isset( $_POST['owner'] ) ? sanitize_text_field( wp_unslash( $_POST['owner'] ) ) : '';
		$repo  = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';

		if ( empty( $owner ) || empty( $repo ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'hub2wp' ) ) );
		}

		// Get access token from settings.
		$access_token = H2WP_Settings::get_access_token();
		$api          = new H2WP_GitHub_API( $access_token );

		// Fetch data.
		$repo_details = $api->get_repo_details( $owner, $repo );
		if ( is_wp_error( $repo_details ) ) {
			wp_send_json_error( array( 'message' => $repo_details->get_error_message() ) );
		}

		$readme_html = $api->get_readme_html( $owner, $repo );
		if ( is_wp_error( $readme_html ) ) {
			$readme_html = __( 'No README available.', 'hub2wp' );
		}

		$readme_html = $this->strip_plugin_headers( $readme_html );

		$og_image = $api->get_og_image( $owner, $repo );
		if ( is_wp_error( $og_image ) ) {
			$og_image = $repo_details['owner']['avatar_url'];
		}

		$last_updated = '';
		if ( isset( $repo_details['pushed_at'] ) ) {
			$last_updated = sprintf(
				/* translators: %s: human-readable time difference */
				__( '%s ago', 'hub2wp' ),
				human_time_diff( strtotime( $repo_details['pushed_at'] ) )
			);
		}

		// Let's try to use the pushed_at date of the default_branch
		if ( isset( $repo_details['default_branch'] ) ) {
			$branch_details = $api->get_branch_details( $owner, $repo, $repo_details['default_branch'] );
			if ( ! is_wp_error( $branch_details ) && isset( $branch_details['commit']['commit']['author']['date'] ) ) {
				$last_updated = sprintf(
					/* translators: %s: human-readable time difference */
					__( '%s ago', 'hub2wp' ),
					human_time_diff( strtotime( $branch_details['commit']['commit']['author']['date'] ) )
				);
			}
		}

		// Prepare data.
		$data = array(
			'name'             => isset( $repo_details['name'] ) ? $repo_details['name'] : '',
			'display_name'     => isset( $repo_details['name'] ) ? ucwords( str_replace( array( '-', 'wp', 'wordpress', 'seo' ), array( ' ', 'WP', 'WordPress', 'SEO' ), $repo_details['name'] ) ) : '',
			'owner'            => isset( $repo_details['owner']['login'] ) ? sanitize_text_field( $repo_details['owner']['login'] ) : '',
			'repo'             => isset( $repo_details['name'] ) ? sanitize_text_field( $repo_details['name'] ) : '',
			'description'      => isset( $repo_details['description'] ) ? $repo_details['description'] : '',
			'readme'           => $readme_html,
			'stargazers'       => isset( $repo_details['stargazers_count'] ) ? number_format_i18n( $repo_details['stargazers_count'] ) : '0',
			'forks'            => isset( $repo_details['forks_count'] ) ? number_format_i18n( $repo_details['forks_count'] ) : '0',
			'watchers'         => $api->get_watchers_count( $owner, $repo ),
			'open_issues'      => isset( $repo_details['open_issues_count'] ) ? number_format_i18n( $repo_details['open_issues_count'] ) : '0',
			'html_url'         => isset( $repo_details['html_url'] ) ? esc_url_raw( $repo_details['html_url'] ) : '',
			'homepage'         => isset( $repo_details['homepage'] ) ? esc_url_raw( $repo_details['homepage'] ) : '',
			'og_image'         => esc_url_raw( $og_image ),
			'owner_avatar_url' => isset( $repo_details['owner']['avatar_url'] ) ? esc_url_raw( $repo_details['owner']['avatar_url'] ) : '',
			'author'           => isset( $repo_details['owner']['login'] ) ? sanitize_text_field( $repo_details['owner']['login'] ) : '',
			'author_url'       => isset( $repo_details['owner']['html_url'] ) ? esc_url_raw( $repo_details['owner']['html_url'] ) : '',
			'updated_at'       => $last_updated,
			'topics'           => isset( $repo_details['topics'] ) ? $this->extract_topics( $repo_details['topics'] ) : array(),
			'is_installed'     => H2WP_Admin_Page::is_plugin_installed( $owner, $repo ),
		);

		wp_send_json_success( $data );
	}

	/**
	 * Try to strip plugin headers like "Contributors: x", "Donate link: Y", "Tags: Z", etc. from the readme because they already appear in the sidebar.
	 *
	 * @param string $readme Readme HTML.
	 * @return string Filtered readme HTML.
	 */
	private function strip_plugin_headers( $readme ) {
		$skip = array(
			'contributors',
			'donate link',
			'tags',
			'requires at least',
			'tested up to',
			'stable tag',
			'requires php',
			'license',
			'license uri',
		);

		$lines = explode( "\n", $readme );
		$filtered_lines = array();
		$header_section = true;

		foreach ( $lines as $index => $line ) {
			// Only check first x lines for headers.
			if ( $index >= 40 ) {
				$header_section = false;
			}

			if ( $header_section ) {
				$skip_line = false;
				foreach ( $skip as $header ) {
					if ( stripos( $line, $header . ':' ) === 0 ) {
						$skip_line = true;
						break;
					}
				}
				if ( $skip_line ) {
					continue;
				}
			}

			$filtered_lines[] = $line;
		}

		return implode( "\n", $filtered_lines );
	}

	/**
	 * Handle AJAX request to install a plugin.
	 */
	public function install_plugin() {
		// Check nonce.
		check_ajax_referer( 'h2wp_plugin_details_nonce', 'nonce' );

		// Check user capabilities.
		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'hub2wp' ) ) );
		}

		// Get and sanitize parameters.
		$owner = isset( $_POST['owner'] ) ? sanitize_text_field( wp_unslash( $_POST['owner'] ) ) : '';
		$repo  = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';

		if ( empty( $owner ) || empty( $repo ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'hub2wp' ) ) );
		}

		// Check if plugin is compatible.
		$api = new H2WP_GitHub_API( H2WP_Settings::get_access_token() );
		$compatibility = $api->check_compatibility( $owner, $repo );
		if ( ! $compatibility['is_compatible'] ) {
			wp_send_json_error( array( 'message' => $compatibility['reason'] ) );
		}

		$download_url = $api->get_download_url( $owner, $repo );

		// Install the plugin.
		$installer = new H2WP_Plugin_Installer();
		$result = $installer->install_plugin( $download_url );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$plugin_data = $installer->plugin_data;
		$plugin_data['owner'] = $owner;
		$plugin_data['repo'] = $repo;

		$plugin_data['plugin_file'] = $this->find_plugin_file( $plugin_data );

		// Store plugin data in the h2wp_plugins option.
		$h2wp_plugins = get_option( 'h2wp_plugins', array() );
		$repo_key = $owner . '/' . $repo;
		// Preserve existing fields (e.g. 'private' flag set when manually monitoring the repo).
		$existing = isset( $h2wp_plugins[ $repo_key ] ) ? $h2wp_plugins[ $repo_key ] : array();
		$h2wp_plugins[ $repo_key ] = array_merge( $existing, $plugin_data );
		$h2wp_plugins[ $repo_key ]['last_checked'] = time();
		$h2wp_plugins[ $repo_key ]['last_updated'] = time();
		update_option( 'h2wp_plugins', $h2wp_plugins, false );

		$plugin_data['activate_url'] = add_query_arg( array(
			'action' => 'activate',
			'plugin' => $plugin_data['plugin_file'],
			'_wpnonce' => wp_create_nonce( 'activate-plugin_' . $plugin_data['plugin_file'] ),
		), admin_url( 'plugins.php' ) );

		wp_send_json_success( $plugin_data );
	}

	/**
	 * Handle AJAX request to check plugin compatibility.
	 */
	public function check_compatibility() {

		// Check nonce.
		check_ajax_referer( 'h2wp_plugin_details_nonce', 'nonce' );

		// Check user capabilities.
		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'hub2wp' ) ) );
		}

		// Get and sanitize parameters.
		$owner = isset( $_POST['owner'] ) ? sanitize_text_field( wp_unslash( $_POST['owner'] ) ) : '';
		$repo  = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';

		if ( empty( $owner ) || empty( $repo ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'hub2wp' ) ) );
		}

		// Get access token from settings.
		$access_token = H2WP_Settings::get_access_token();
		$api          = new H2WP_GitHub_API( $access_token );

		// Check compatibility.
		$compatibility = $api->check_compatibility( $owner, $repo );

		wp_send_json_success( array( 'is_compatible' => $compatibility['is_compatible'], 'reason' => $compatibility['reason'], 'headers' => ! empty( $compatibility['headers'] ) ? $compatibility['headers'] : array() ) );
	}

	/**
	 * Handle AJAX request to activate a plugin.
	 */
	public function activate_plugin() {
		// Check nonce.
		check_ajax_referer( 'h2wp_plugin_details_nonce', 'nonce' );

		// Check user capabilities.
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'hub2wp' ) ) );
		}

		// Get and sanitize parameters.
		$plugin_file = isset( $_POST['plugin_file'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin_file'] ) ) : '';

		if ( empty( $plugin_file ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'hub2wp' ) ) );
		}

		// Activate the plugin.
		activate_plugin( $plugin_file );

		wp_send_json_success();
	}

	/**
	 * Extract topics from repo details.
	 * 
	 * @param array $topics Topics array.
	 * @return array Filtered topics.
	 */
	private function extract_topics( $topics ) {
		return array_values( array_filter( array_map( function( $topic ) {
			$skip = array( 'wordpress-plugin', 'wordpress-plugins', 'wordpress', 'plugin', 'wp-plugin', 'wp' );
			if ( in_array( strtolower( $topic ), $skip ) ) {
				return;
			}

			return array(
				'name' => $topic,
				'url' => add_query_arg( 'page', 'h2wp-plugin-browser', admin_url( 'plugins.php' ) ) . '&tag=' . urlencode( $topic ),
			);
		}, $topics)));
	}

	/**
	 * Find the plugin file in the plugin directory.
	 *
	 * @param array $plugin_data Plugin data.
	 * @return string|bool Plugin file path or false if not found.
	 */
	private function find_plugin_file( $plugin_data ) {
		$plugin_file = false;

		$plugins = get_plugins();
		foreach ( $plugins as $file => $data ) {
			if ( $data['Name'] === $plugin_data['name'] && $data['Author'] === $plugin_data['author'] ) {
				$plugin_file = $file;
				break;
			}
		}

		return $plugin_file;
	}

	/**
	 * Handle AJAX request to get changelog.
	 */
	public function get_changelog() {
		// Check nonce
		check_ajax_referer( 'h2wp_plugin_details_nonce', 'nonce' );

		// Check user capabilities
		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'hub2wp' ) ) );
		}

		// Get and sanitize parameters
		$owner = isset( $_POST['owner'] ) ? sanitize_text_field( wp_unslash( $_POST['owner'] ) ) : '';
		$repo  = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';

		if ( empty( $owner ) || empty( $repo ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'hub2wp' ) ) );
		}

		// Get access token from settings
		$access_token = H2WP_Settings::get_access_token();
		$api = new H2WP_GitHub_API( $access_token );

		// Fetch changelog
		$changelog = $api->get_changelog( $owner, $repo );
		if ( is_wp_error( $changelog ) ) {
			wp_send_json_error( array( 'message' => $changelog->get_error_message() ) );
		}

		if ( empty( $changelog ) ) {
			wp_send_json_error( array( 'message' => __( 'No changelog available.', 'hub2wp' ) ) );
		}

		$changelog_html = '<ul class="h2wp-changelog">';
		foreach ( $changelog as $release ) {
			$changelog_html .= '<li>';
			$changelog_html .= '<h4>' . esc_html( $release['version'] ) . ( $release['title'] ? ' (' . esc_html( $release['title'] ) . ')' : '' ) . '</h4>';
			$changelog_html .= '<p><strong>' . __( 'Released:', 'hub2wp' ) . '</strong> ' . date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $release['date'] ) ) . '</p>';
			$changelog_html .= '<p>' . nl2br( $release['description'] ) . '</p>';
			$changelog_html .= '<p><a href="' . $release['url'] . '" target="_blank">' . __( 'View on GitHub', 'hub2wp' ) . '</a></p>';
			$changelog_html .= '</li>';
		}
		$changelog_html .= '</ul>';

		wp_send_json_success( array( 'changelog_html' => $changelog_html ) );
	}
}
