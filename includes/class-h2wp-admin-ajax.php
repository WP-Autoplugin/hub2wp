<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles AJAX requests for the hub2wp plugin.
 */
class H2WP_Admin_Ajax {
	/**
	 * Clean any buffered output started after the given level.
	 *
	 * @param int $buffer_level Buffer level to return to.
	 * @return void
	 */
	private function clean_ajax_buffers( $buffer_level ) {
		while ( ob_get_level() > $buffer_level ) {
			ob_end_clean();
		}
	}
	/**
	 * Normalize repository type from request.
	 *
	 * @return string plugin|theme
	 */
	private function get_repo_type_from_request() {
		$repo_type = isset( $_POST['repo_type'] ) ? sanitize_key( wp_unslash( $_POST['repo_type'] ) ) : 'plugin';
		return in_array( $repo_type, array( 'plugin', 'theme' ), true ) ? $repo_type : 'plugin';
	}

	/**
	 * Check capability for the current repository type.
	 *
	 * @param string $repo_type Repository type.
	 * @return bool
	 */
	private function can_manage_repo_type( $repo_type ) {
		$cap = ( 'theme' === $repo_type ) ? 'install_themes' : 'install_plugins';
		return current_user_can( $cap );
	}

	/**
	 * Resolve monitored tracking preferences for a repo, if configured.
	 *
	 * @param string $owner     Repository owner.
	 * @param string $repo      Repository name.
	 * @param string $repo_type Repository type.
	 * @return array
	 */
	private function get_monitored_tracking_preferences( $owner, $repo, $repo_type = 'plugin' ) {
		return H2WP_Settings::get_repo_tracking_preferences( $owner, $repo, $repo_type );
	}

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

		$repo_type = $this->get_repo_type_from_request();

		// Check user capabilities.
		if ( ! $this->can_manage_repo_type( $repo_type ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'hub2wp' ) ) );
		}

		// Get and sanitize parameters.
		$owner = isset( $_POST['owner'] ) ? sanitize_text_field( wp_unslash( $_POST['owner'] ) ) : '';
		$repo  = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';

		if ( empty( $owner ) || empty( $repo ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'hub2wp' ) ) );
		}

		$service = new H2WP_Repository_Query_Service();
		$data    = $service->get_repository_details( $owner, $repo, $repo_type );

		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array( 'message' => $data->get_error_message() ) );
		}

		wp_send_json_success( $data );
	}

	/**
	 * Handle AJAX request to install a plugin.
	 */
	public function install_plugin() {
		// Check nonce.
		check_ajax_referer( 'h2wp_plugin_details_nonce', 'nonce' );

		$repo_type = $this->get_repo_type_from_request();

		// Check user capabilities.
		if ( ! $this->can_manage_repo_type( $repo_type ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'hub2wp' ) ) );
		}

		// Get and sanitize parameters.
		$owner = isset( $_POST['owner'] ) ? sanitize_text_field( wp_unslash( $_POST['owner'] ) ) : '';
		$repo  = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';

		if ( empty( $owner ) || empty( $repo ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'hub2wp' ) ) );
		}

		ob_start();

		$tracking = $this->get_monitored_tracking_preferences( $owner, $repo, $repo_type );

		$result = H2WP_Repo_Manager::install_repository(
			$owner,
			$repo,
			array(
				'repo_type'           => $repo_type,
				'branch'              => $tracking['branch'],
				'prioritize_releases' => $tracking['prioritize_releases'],
				'access_token'        => H2WP_Settings::get_access_token(),
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->clean_ajax_buffers( 0 );
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		if ( 'theme' === $repo_type ) {
			$template               = ! empty( $result['template'] ) ? $result['template'] : $result['stylesheet'];
			$result['activate_url'] = add_query_arg(
				array(
					'action'     => 'activate',
					'stylesheet' => $result['stylesheet'],
					'template'   => $template,
					'_wpnonce'   => wp_create_nonce( 'switch-theme_' . $result['stylesheet'] ),
				),
				admin_url( 'themes.php' )
			);

			$this->clean_ajax_buffers( 0 );
			wp_send_json_success( $result );
		}

		$result['activate_url'] = add_query_arg( array(
			'action' => 'activate',
			'plugin' => $result['plugin_file'],
			'_wpnonce' => wp_create_nonce( 'activate-plugin_' . $result['plugin_file'] ),
		), admin_url( 'plugins.php' ) );

		$this->clean_ajax_buffers( 0 );
		wp_send_json_success( $result );
	}

	/**
	 * Handle AJAX request to check plugin compatibility.
	 */
	public function check_compatibility() {

		// Check nonce.
		check_ajax_referer( 'h2wp_plugin_details_nonce', 'nonce' );

		$repo_type = $this->get_repo_type_from_request();

		// Check user capabilities.
		if ( ! $this->can_manage_repo_type( $repo_type ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'hub2wp' ) ) );
		}

		// Get and sanitize parameters.
		$owner = isset( $_POST['owner'] ) ? sanitize_text_field( wp_unslash( $_POST['owner'] ) ) : '';
		$repo  = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';

		if ( empty( $owner ) || empty( $repo ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'hub2wp' ) ) );
		}

		$service       = new H2WP_Repository_Query_Service();
		$compatibility = $service->check_repository_compatibility( $owner, $repo, $repo_type );

		wp_send_json_success(
			array(
				'is_compatible' => $compatibility['is_compatible'],
				'reason'        => $compatibility['reason'],
				'headers'       => ! empty( $compatibility['headers'] ) ? $compatibility['headers'] : array(),
				'version_source'=> isset( $compatibility['source_context']['source'] ) ? $compatibility['source_context']['source'] : 'branch',
				'uses_releases' => ! empty( $compatibility['source_context']['uses_releases'] ),
			)
		);
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
	 * Handle AJAX request to get changelog.
	 */
	public function get_changelog() {
		// Check nonce
		check_ajax_referer( 'h2wp_plugin_details_nonce', 'nonce' );

		$repo_type = $this->get_repo_type_from_request();

		// Check user capabilities
		if ( ! $this->can_manage_repo_type( $repo_type ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'hub2wp' ) ) );
		}

		// Get and sanitize parameters
		$owner = isset( $_POST['owner'] ) ? sanitize_text_field( wp_unslash( $_POST['owner'] ) ) : '';
		$repo  = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';

		if ( empty( $owner ) || empty( $repo ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'hub2wp' ) ) );
		}

		$service   = new H2WP_Repository_Query_Service();
		$changelog = $service->get_changelog( $owner, $repo );
		if ( is_wp_error( $changelog ) ) {
			wp_send_json_error( array( 'message' => $changelog->get_error_message() ) );
		}

		if ( empty( $changelog ) ) {
			wp_send_json_error( array( 'message' => __( 'No changelog available.', 'hub2wp' ) ) );
		}

		wp_send_json_success( array( 'changelog_html' => $service->render_changelog_html( $changelog ) ) );
	}
}
