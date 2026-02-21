<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the settings page and options.
 */
class H2WP_Settings {

	/**
	 * Option name for settings.
	 */
	const OPTION_NAME = 'h2wp_settings';

	/**
	 * Initialize the settings.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_private_repo_actions' ) );
		add_action( 'admin_notices', array( __CLASS__, 'display_private_repo_notices' ) );
	}

	/**
	 * Register settings.
	 */
	public static function register_settings() {
		register_setting( 'h2wp_settings_group', self::OPTION_NAME, array( __CLASS__, 'sanitize_settings' ) );

		add_settings_section(
			'h2wp_settings_section',
			'', // no title
			'__return_false',
			'h2wp_settings_page'
		);

		add_settings_field(
			'h2wp_access_token',
			__( 'Personal Access Token', 'hub2wp' ),
			array( __CLASS__, 'access_token_field' ),
			'h2wp_settings_page',
			'h2wp_settings_section'
		);

		add_settings_field(
			'h2wp_cache_duration',
			__( 'Cache Duration (Hours)', 'hub2wp' ),
			array( __CLASS__, 'cache_duration_field' ),
			'h2wp_settings_page',
			'h2wp_settings_section'
		);
	}

	/**
	 * Add settings page to the menu.
	 */
	public static function add_settings_page() {
		add_options_page(
			__( 'hub2wp Settings', 'hub2wp' ),
			__( 'GitHub Plugins', 'hub2wp' ),
			'manage_options',
			'h2wp_settings_page',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Render settings page.
	 */
	public static function render_settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'hub2wp Settings', 'hub2wp' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'h2wp_settings_group' ); ?>
				<?php do_settings_sections( 'h2wp_settings_page' ); ?>

				<?php submit_button(); ?>
			</form>

			<hr />

			<?php self::render_monitored_plugins_section(); ?>
		</div>
		<?php
	}

	/**
	 * Render the monitored plugins section.
	 */
	public static function render_monitored_plugins_section() {
		$monitored_plugins = get_option( 'h2wp_plugins', array() );
		?>
		<h2><?php esc_html_e( 'Monitored Plugins', 'hub2wp' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Add GitHub repositories to monitor them for updates and browse them through hub2wp. Private repositories require a personal access token with "repo" scope.', 'hub2wp' ); ?>
		</p>

		<form method="post" action="">
			<?php wp_nonce_field( 'h2wp_add_private_repo', 'h2wp_private_repo_nonce' ); ?>
			<input type="hidden" name="h2wp_action" value="add_private_repo" />
			
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="h2wp_private_repo_input">
							<?php esc_html_e( 'Add Repository', 'hub2wp' ); ?>
						</label>
					</th>
					<td>
						<input 
							type="text" 
							id="h2wp_private_repo_input"
							name="h2wp_private_repo" 
							value="" 
							placeholder="owner/repo" 
							size="50"
						/>
						<button type="submit" class="button button-secondary">
							<?php esc_html_e( 'Add Repository', 'hub2wp' ); ?>
						</button>
						<p class="description">
							<?php esc_html_e( 'Enter the repository in the format: owner/repo (e.g., mycompany/private-plugin)', 'hub2wp' ); ?>
						</p>
					</td>
				</tr>
			</table>
		</form>

		<?php if ( ! empty( $monitored_plugins ) ) : ?>
			<h3><?php esc_html_e( 'Monitored Plugins', 'hub2wp' ); ?></h3>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Repository', 'hub2wp' ); ?></th>
						<th style="width:80px;max-width:80px;"><?php esc_html_e( 'Status', 'hub2wp' ); ?></th>
						<th style="width:80px;max-width:80px;"><?php esc_html_e( 'Actions', 'hub2wp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $monitored_plugins as $repo_key => $repo_data ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( isset( $repo_data['name'] ) ? $repo_data['name'] : $repo_key ); ?></strong>
								<br />
								<small>
									<a href="<?php echo esc_url( 'https://github.com/' . $repo_key ); ?>" target="_blank">
										<?php echo esc_html( $repo_key ); ?>
									</a>
									<?php if ( ! empty( $repo_data['plugin_file'] ) ) : ?>
										&rarr; <code><?php echo esc_html( $repo_data['plugin_file'] ); ?></code>
									<?php endif; ?>
								</small>
							</td>
							<td>
								<?php
								if ( ! empty( $repo_data['plugin_file'] ) ) {
									esc_html_e( 'Installed', 'hub2wp' );
								} else {
									esc_html_e( 'Not Installed', 'hub2wp' );
								}
								if ( ! empty( $repo_data['private'] ) ) {
									echo ' <span class="dashicons dashicons-lock" title="' . esc_attr__( 'Private Repository', 'hub2wp' ) . '"></span>';
								}
								?>
							</td>
							<td>
								<form method="post" action="" style="display: inline;">
									<?php wp_nonce_field( 'h2wp_remove_private_repo', 'h2wp_remove_repo_nonce' ); ?>
									<input type="hidden" name="h2wp_action" value="remove_private_repo" />
									<input type="hidden" name="h2wp_repo_key" value="<?php echo esc_attr( $repo_key ); ?>" />
									<button type="submit" class="button button-small button-link-delete" onclick="return confirm('<?php echo esc_js( sprintf( __( 'Stop monitoring "%s"?', 'hub2wp' ), $repo_key ) ); ?>');">
										<?php esc_html_e( 'Remove', 'hub2wp' ); ?>
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">
				<?php
				printf(
					/* translators: %s: URL to the Private tab */
					__( 'These repositories will be monitored for updates. Private repositories can be installed via the <a href="%s">Private tab</a> in Plugins > Add GitHub Plugin.', 'hub2wp' ),
					esc_url( admin_url( 'plugins.php?page=h2wp-plugin-browser&tab=private' ) )
					);
				?>
			</p>
		<?php else : ?>
			<p class="description">
				<?php esc_html_e( 'No repositories added yet.', 'hub2wp' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Handle private repository actions (add/remove).
	 */
	public static function handle_private_repo_actions() {
		if ( ! isset( $_POST['h2wp_action'] ) ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_POST['h2wp_action'] ) );

		if ( 'add_private_repo' === $action ) {
			self::handle_add_private_repo();
		} elseif ( 'remove_private_repo' === $action ) {
			self::handle_remove_private_repo();
		}
	}

	/**
	 * Handle adding a repository.
	 */
	private static function handle_add_private_repo() {
		if ( ! isset( $_POST['h2wp_private_repo_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['h2wp_private_repo_nonce'] ) ), 'h2wp_add_private_repo' ) ) {
			add_settings_error(
				'h2wp_private_repos',
				'h2wp_nonce_error',
				__( 'Security check failed. Please try again.', 'hub2wp' ),
				'error'
			);
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			add_settings_error(
				'h2wp_private_repos',
				'h2wp_permission_error',
				__( 'You do not have permission to add repositories.', 'hub2wp' ),
				'error'
			);
			return;
		}

		if ( ! isset( $_POST['h2wp_private_repo'] ) || empty( $_POST['h2wp_private_repo'] ) ) {
			add_settings_error(
				'h2wp_private_repos',
				'h2wp_empty_repo',
				__( 'Please enter a repository in the format owner/repo.', 'hub2wp' ),
				'error'
			);
			return;
		}

		$repo_input = sanitize_text_field( wp_unslash( $_POST['h2wp_private_repo'] ) );

		// Validate format: owner/repo
		if ( ! self::validate_repo_format( $repo_input ) ) {
			add_settings_error(
				'h2wp_private_repos',
				'h2wp_invalid_format',
				__( 'Invalid repository format. Please use "owner/repo" format (e.g., mycompany/private-plugin).', 'hub2wp' ),
				'error'
			);
			return;
		}

		// Normalize to lowercase
		$repo_key = strtolower( $repo_input );

		// Check if already exists
		$monitored_plugins = get_option( 'h2wp_plugins', array() );
		if ( isset( $monitored_plugins[ $repo_key ] ) ) {
			add_settings_error(
				'h2wp_private_repos',
				'h2wp_repo_exists',
				sprintf( __( 'Repository "%s" is already in your monitored plugins list.', 'hub2wp' ), $repo_key ),
				'warning'
			);
			return;
		}

		$access_token = self::get_access_token();

		// Verify the repository exists and is accessible
		$repo_data = self::verify_repo( $repo_key, $access_token );
		if ( is_wp_error( $repo_data ) ) {
			add_settings_error(
				'h2wp_private_repos',
				'h2wp_verification_failed',
				$repo_data->get_error_message(),
				'error'
			);
			return;
		}

		// Add the repository
		list( $owner, $repo ) = explode( '/', $repo_key, 2 );
		
		$plugin_file = false;
		if ( class_exists( 'H2WP_Admin_Page' ) ) {
			$plugin_file = H2WP_Admin_Page::get_installed_plugin_file( $owner, $repo );
		}

		$monitored_plugins[ $repo_key ] = array(
			'owner'            => $owner,
			'repo'             => $repo,
			'name'             => isset( $repo_data['name'] ) ? $repo_data['name'] : $repo,
			'private'          => isset( $repo_data['private'] ) ? $repo_data['private'] : false,
			'added'            => time(),
			'added_by'         => get_current_user_id(),
			'last_checked'     => time(),
			'last_updated'     => time(),
		);

		if ( $plugin_file ) {
			$monitored_plugins[ $repo_key ]['plugin_file'] = $plugin_file;
		}

		$result = update_option( 'h2wp_plugins', $monitored_plugins );
		if ( ! $result ) {
			add_settings_error(
				'h2wp_private_repos',
				'h2wp_add_failed',
				__( 'Failed to save repository. Please try again.', 'hub2wp' ),
				'error'
			);
			return;
		}

		add_settings_error(
			'h2wp_private_repos',
			'h2wp_repo_added',
			sprintf( __( 'Repository "%s" has been added successfully.', 'hub2wp' ), $repo_key ),
			'success'
		);
	}

	/**
	 * Handle removing a repository.
	 */
	private static function handle_remove_private_repo() {
		if ( ! isset( $_POST['h2wp_remove_repo_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['h2wp_remove_repo_nonce'] ) ), 'h2wp_remove_private_repo' ) ) {
			add_settings_error(
				'h2wp_private_repos',
				'h2wp_nonce_error',
				__( 'Security check failed. Please try again.', 'hub2wp' ),
				'error'
			);
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			add_settings_error(
				'h2wp_private_repos',
				'h2wp_permission_error',
				__( 'You do not have permission to remove repositories.', 'hub2wp' ),
				'error'
			);
			return;
		}

		if ( ! isset( $_POST['h2wp_repo_key'] ) || empty( $_POST['h2wp_repo_key'] ) ) {
			add_settings_error(
				'h2wp_private_repos',
				'h2wp_missing_repo',
				__( 'No repository specified for removal.', 'hub2wp' ),
				'error'
			);
			return;
		}

		$repo_key = sanitize_text_field( wp_unslash( $_POST['h2wp_repo_key'] ) );

		$monitored_plugins = get_option( 'h2wp_plugins', array() );
		if ( ! isset( $monitored_plugins[ $repo_key ] ) ) {
			add_settings_error(
				'h2wp_private_repos',
				'h2wp_remove_failed',
				sprintf( __( 'Repository "%s" not found.', 'hub2wp' ), $repo_key ),
				'error'
			);
			return;
		}

		unset( $monitored_plugins[ $repo_key ] );
		$result = update_option( 'h2wp_plugins', $monitored_plugins );

		if ( ! $result ) {
			add_settings_error(
				'h2wp_private_repos',
				'h2wp_remove_failed',
				__( 'Failed to remove repository. Please try again.', 'hub2wp' ),
				'error'
			);
			return;
		}

		add_settings_error(
			'h2wp_private_repos',
			'h2wp_repo_removed',
			sprintf( __( 'Repository "%s" has been removed.', 'hub2wp' ), $repo_key ),
			'success'
		);
	}

	/**
	 * Display notices for private repository actions.
	 */
	public static function display_private_repo_notices() {
		// Only show on our settings page
		$screen = get_current_screen();
		if ( ! $screen || 'settings_page_h2wp_settings_page' !== $screen->id ) {
			return;
		}

		// Note: settings_errors() is not called here because WordPress automatically
		// displays settings errors on options pages. Calling it manually would cause
		// duplicate notices. The add_settings_error() calls in handle_private_repo_actions()
		// are sufficient for notices to appear.
	}

	/**
	 * Validate repository format (owner/repo).
	 *
	 * @param string $repo The repository string to validate.
	 * @return bool True if valid, false otherwise.
	 */
	public static function validate_repo_format( $repo ) {
		// Format: owner/repo where both parts contain only allowed characters
		// GitHub usernames/repos can contain alphanumeric, hyphens, underscores
		// but cannot start or end with hyphens
		$pattern = '/^[a-zA-Z0-9_-]+\/[a-zA-Z0-9_.-]+$/';
		return preg_match( $pattern, $repo ) === 1;
	}

	/**
	 * Verify a repository exists and is accessible.
	 *
	 * @param string $repo_key The repository in owner/repo format.
	 * @param string $access_token The GitHub access token.
	 * @return array|WP_Error Repo data if verified, WP_Error on failure.
	 */
	public static function verify_repo( $repo_key, $access_token ) {
		$url = 'https://api.github.com/repos/' . $repo_key;

		$headers = array(
			'Accept'     => 'application/vnd.github.v3+json',
			'User-Agent' => 'hub2wp/1.0',
		);

		if ( ! empty( $access_token ) ) {
			$headers['Authorization'] = 'Bearer ' . $access_token;
		}

		$response = wp_remote_get(
			$url,
			array(
				'headers' => $headers,
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'request_failed',
				sprintf( __( 'Failed to connect to GitHub: %s', 'hub2wp' ), $response->get_error_message() )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 404 === $status_code ) {
			return new WP_Error(
				'repo_not_found',
				sprintf( __( 'Repository "%s" not found or you do not have access to it. If it is a private repository, please ensure your access token has the "repo" scope.', 'hub2wp' ), $repo_key )
			);
		}

		if ( 401 === $status_code ) {
			return new WP_Error(
				'unauthorized',
				__( 'Your access token is invalid or does not have permission to access this repository. Please check your token and ensure it has the "repo" scope.', 'hub2wp' )
			);
		}

		if ( 403 === $status_code ) {
			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );
			
			if ( isset( $data['message'] ) && strpos( $data['message'], 'rate limit' ) !== false ) {
				return new WP_Error(
					'rate_limited',
					__( 'GitHub API rate limit exceeded. Please wait a few minutes before trying again.', 'hub2wp' )
				);
			}

			return new WP_Error(
				'forbidden',
				__( 'Your access token does not have permission to access this repository. Please ensure it has the "repo" scope.', 'hub2wp' )
			);
		}

		if ( 200 !== $status_code ) {
			return new WP_Error(
				'api_error',
				sprintf( __( 'GitHub API returned error code %d. Please try again later.', 'hub2wp' ), $status_code )
			);
		}

		$body = wp_remote_retrieve_body( $response );
		return json_decode( $body, true );
	}

	public static function next_run_schedule() {
		?>
		<p>
			<?php
			$next_check = wp_next_scheduled( 'h2wp_daily_update_check' );
			// translators: %s: human-readable time difference (e.g. "1 hour"), %s: link to run the update check, %d: number of API calls
			printf(
				esc_html__( 'The daily update check is scheduled to run in %s. %s (note: the GitHub API will be called %d times).', 'hub2wp' ),
				'<span>' . $next_check ? human_time_diff( time(), $next_check ) : __( 'less than 1 minute', 'hub2wp' ) . '</span>',
				sprintf(
					'<a href="%s">%s</a>',
					wp_nonce_url( admin_url( 'options-general.php?page=h2wp_settings_page&action=h2wp_run_update_check' ), 'h2wp_run_update_check' ),
					esc_html__( 'Run now', 'hub2wp' )
				),
				count( get_option( 'h2wp_plugins', array() ) )
			);
			?>
		</p>
		<?php
	}

	/**
	 * Access token field callback.
	 */
	public static function access_token_field() {
		$options      = get_option( self::OPTION_NAME, array() );
		$access_token = isset( $options['access_token'] ) ? $options['access_token'] : '';
		?>
		<input type="text" name="h2wp_settings[access_token]" value="<?php echo esc_attr( $access_token ); ?>" size="50" />
		<p class="description">
			<?php esc_html_e( 'Enter your GitHub personal access token to increase your rate limit.', 'hub2wp' ); ?>
			<?php printf(
				/* translators: %s: URL to create a personal access token */
				__( 'Get a free token from %s.', 'hub2wp' ),
				'<a href="https://github.com/settings/tokens" target="_blank">GitHub</a>'
			); ?>
		</p>
		<?php
	}

	/**
	 * Cache duration field callback.
	 */
	public static function cache_duration_field() {
		$options         = get_option( self::OPTION_NAME, array() );
		$cache_duration  = isset( $options['cache_duration'] ) ? (int) $options['cache_duration'] : 12;
		?>
		<input type="number" name="h2wp_settings[cache_duration]" value="<?php echo esc_attr( $cache_duration ); ?>" min="1" />
		<p class="description"><?php esc_html_e( 'How long to cache search results and plugin data in hours.', 'hub2wp' ); ?></p>
		<?php
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Input options.
	 * @return array Sanitized options.
	 */
	public static function sanitize_settings( $input ) {
		$output = array();

		if ( isset( $input['access_token'] ) ) {
			$output['access_token'] = sanitize_text_field( $input['access_token'] );
		}

		if ( isset( $input['cache_duration'] ) ) {
			$output['cache_duration'] = absint( $input['cache_duration'] );
		}

		return $output;
	}

	/**
	 * Get access token.
	 *
	 * @return string Token.
	 */
	public static function get_access_token() {
		$options = get_option( self::OPTION_NAME, array() );
		return isset( $options['access_token'] ) ? $options['access_token'] : '';
	}

	/**
	 * Get cache duration in seconds.
	 *
	 * @return int Duration in seconds.
	 */
	public static function get_cache_duration() {
		$options = get_option( self::OPTION_NAME, array() );
		$hours   = isset( $options['cache_duration'] ) ? (int) $options['cache_duration'] : 12;
		return $hours * HOUR_IN_SECONDS;
	}
}
