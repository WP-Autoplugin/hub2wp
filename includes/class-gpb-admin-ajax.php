<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles AJAX requests for the GitHub Plugin Browser.
 */
class GPB_Admin_Ajax {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_gpb_get_plugin_details', array( $this, 'get_plugin_details' ) );
	}

	/**
	 * Handle AJAX request to get plugin details.
	 */
	public function get_plugin_details() {
		// Check nonce.
		check_ajax_referer( 'gpb_plugin_details_nonce', 'nonce' );

		// Check user capabilities.
		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'github-plugin-browser' ) ) );
		}

		// Get and sanitize parameters.
		$owner = isset( $_POST['owner'] ) ? sanitize_text_field( wp_unslash( $_POST['owner'] ) ) : '';
		$repo  = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';

		if ( empty( $owner ) || empty( $repo ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'github-plugin-browser' ) ) );
		}

		// Get access token from settings.
		$access_token = GPB_Settings::get_access_token();
		$api          = new GPB_GitHub_API( $access_token );

		// Fetch data.
		$repo_details = $api->get_repo_details( $owner, $repo );
		if ( is_wp_error( $repo_details ) ) {
			wp_send_json_error( array( 'message' => $repo_details->get_error_message() ) );
		}

		$readme_html = $api->get_readme_html( $owner, $repo );
		if ( is_wp_error( $readme_html ) ) {
			$readme_html = __( 'No README available.', 'github-plugin-browser' );
		}

		$og_image = $api->get_og_image( $owner, $repo );
		if ( is_wp_error( $og_image ) ) {
			$og_image = $repo_details['owner']['avatar_url'];
		}

		// Prepare data.
		$data = array(
			'name'           => isset( $repo_details['name'] ) ? $repo_details['name'] : '',
			'description'    => isset( $repo_details['description'] ) ? $repo_details['description'] : '',
			'readme'         => $readme_html,
			'stargazers'     => isset( $repo_details['stargazers_count'] ) ? number_format_i18n( $repo_details['stargazers_count'] ) : '0',
			'forks'          => isset( $repo_details['forks_count'] ) ? number_format_i18n( $repo_details['forks_count'] ) : '0',
			'watchers'       => $api->get_watchers_count( $owner, $repo ),
			'open_issues'    => isset( $repo_details['open_issues_count'] ) ? number_format_i18n( $repo_details['open_issues_count'] ) : '0',
			'html_url'       => isset( $repo_details['html_url'] ) ? esc_url_raw( $repo_details['html_url'] ) : '',
			'homepage'       => isset( $repo_details['homepage'] ) ? esc_url_raw( $repo_details['homepage'] ) : '',
			'version'        => $api->get_latest_release_tag( $owner, $repo ),
			'og_image'       => esc_url_raw( $og_image ),
			'owner_avatar_url' => isset($repo_details['owner']['avatar_url']) ? esc_url_raw($repo_details['owner']['avatar_url']) : '',
			'author'         => isset( $repo_details['owner']['login'] ) ? sanitize_text_field( $repo_details['owner']['login'] ) : '',
			'author_url'     => isset( $repo_details['owner']['html_url'] ) ? esc_url_raw( $repo_details['owner']['html_url'] ) : '',
			'updated_at'     => isset($repo_details['updated_at']) ? human_time_diff(strtotime($repo_details['updated_at'])) . ' ago' : '',
		);

		wp_send_json_success( $data );
	}
}