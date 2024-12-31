<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the settings page and options.
 */
class H2WP_Settings {

	/**
	 * Option name.
	 */
	const OPTION_NAME = 'h2wp_settings';

	/**
	 * Initialize the settings.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'update_option_' . self::OPTION_NAME, array( __CLASS__, 'handle_monitored_plugins_update' ), 10, 3 );
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

		add_settings_field(
			'h2wp_monitored_plugins',
			__( 'Monitored Plugins', 'hub2wp' ),
			array( __CLASS__, 'monitored_plugins_field' ),
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
		</div>
		<?php
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

		// Store monitored plugins selection for processing after save
		$output['monitored_plugins'] = isset( $input['monitored_plugins'] ) ? 
			array_map( 'sanitize_text_field', $input['monitored_plugins'] ) : 
			array();

		return $output;
	}

	/**
	 * Handle monitored plugins updates after settings are saved.
	 *
	 * @param mixed  $old_value The old option value.
	 * @param mixed  $value     The new option value.
	 * @param string $option    Option name.
	 */
	public static function handle_monitored_plugins_update( $old_value, $value, $option ) {
		if ( self::OPTION_NAME !== $option || ! isset( $value['monitored_plugins'] ) ) {
			return;
		}

		$monitored_plugins = get_option( 'h2wp_plugins', array() );
		$new_plugins = array();

		foreach ( $monitored_plugins as $plugin_id => $plugin_data ) {
			if ( in_array( $plugin_id, $value['monitored_plugins'], true ) ) {
				$new_plugins[$plugin_id] = $plugin_data;
			}
		}

		update_option( 'h2wp_plugins', $new_plugins );
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

	/**
	 * Monitored plugins field callback.
	 * Shows a list of plugins that are being monitored, with checkboxes to disable monitoring.
	 * Once disabled, the plugin must be re-installed through hub2wp to re-enable monitoring.
	 */
	public static function monitored_plugins_field() {
		$monitored_plugins = get_option( 'h2wp_plugins', array() );

		if ( empty( $monitored_plugins ) ) {
			echo '<p class="description">' . esc_html__( 'No plugins are currently being monitored for updates. Install a plugin through hub2wp to enable update monitoring.', 'hub2wp' ) . '</p>';
			return;
		}

		foreach ( $monitored_plugins as $key => $plugin ) {
			?>
			<label>
				<input type="checkbox" name="h2wp_settings[monitored_plugins][]" value="<?php echo esc_attr( $key ); ?>" checked="checked" />
				<strong><?php echo esc_html( $plugin['name'] ); ?></strong> by <?php echo esc_html( $plugin['author'] ); ?>
				(<a href="<?php echo esc_url( 'https://github.com/' . $plugin['owner'] . '/' . $plugin['repo'] ); ?>" target="_blank"><?php echo esc_html( $plugin['owner'] . '/' . $plugin['repo'] ); ?></a>)
			</label><br />
			<?php
		}
		?>
		<?php
	}
}
