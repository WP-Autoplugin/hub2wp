<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the settings page and options.
 */
class GPB_Settings {

	/**
	 * Option name.
	 */
	const OPTION_NAME = 'gpb_settings';

	/**
	 * Initialize the settings.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
	}

	/**
	 * Register settings.
	 */
	public static function register_settings() {
		register_setting( 'gpb_settings_group', self::OPTION_NAME, array( __CLASS__, 'sanitize_settings' ) );

		add_settings_section(
			'gpb_settings_section',
			'', // no title
			'__return_false',
			'gpb_settings_page'
		);

		add_settings_field(
			'gpb_access_token',
			__( 'Personal Access Token', 'github-plugin-browser' ),
			array( __CLASS__, 'access_token_field' ),
			'gpb_settings_page',
			'gpb_settings_section'
		);

		add_settings_field(
			'gpb_cache_duration',
			__( 'Cache Duration (Hours)', 'github-plugin-browser' ),
			array( __CLASS__, 'cache_duration_field' ),
			'gpb_settings_page',
			'gpb_settings_section'
		);

		add_settings_field(
			'gpb_monitored_plugins',
			__( 'Monitored Plugins', 'github-plugin-browser' ),
			array( __CLASS__, 'monitored_plugins_field' ),
			'gpb_settings_page',
			'gpb_settings_section'
		);
	}

	/**
	 * Add settings page to the menu.
	 */
	public static function add_settings_page() {
		add_options_page(
			__( 'GitHub Plugin Browser Settings', 'github-plugin-browser' ),
			__( 'GitHub Installer', 'github-plugin-browser' ),
			'manage_options',
			'gpb_settings_page',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Render settings page.
	 */
	public static function render_settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'GitHub Plugin Browser Settings', 'github-plugin-browser' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'gpb_settings_group' ); ?>
				<?php do_settings_sections( 'gpb_settings_page' ); ?>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public static function next_run_schedule() {
		?>
		<p>
			<?php
			$next_check = wp_next_scheduled( 'gpb_daily_update_check' );
			// translators: %s: human-readable time difference (e.g. "1 hour"), %s: link to run the update check, %d: number of API calls
			printf(
				esc_html__( 'The daily update check is scheduled to run in %s. %s (note: the GitHub API will be called %d times).', 'github-plugin-browser' ),
				'<span>' . $next_check ? human_time_diff( time(), $next_check ) : __( 'less than 1 minute', 'github-plugin-browser' ) . '</span>',
				sprintf(
					'<a href="%s">%s</a>',
					wp_nonce_url( admin_url( 'options-general.php?page=gpb_settings_page&action=gpb_run_update_check' ), 'gpb_run_update_check' ),
					esc_html__( 'Run now', 'github-plugin-browser' )
				),
				count( get_option( 'gpb_plugins', array() ) )
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
		<input type="text" name="gpb_settings[access_token]" value="<?php echo esc_attr( $access_token ); ?>" size="50" />
		<p class="description">
			<?php esc_html_e( 'Enter your GitHub personal access token to increase your rate limit.', 'github-plugin-browser' ); ?>
			<?php printf(
				/* translators: %s: URL to create a personal access token */
				__( 'Get a free token from %s.', 'github-plugin-browser' ),
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
		<input type="number" name="gpb_settings[cache_duration]" value="<?php echo esc_attr( $cache_duration ); ?>" min="1" />
		<p class="description"><?php esc_html_e( 'How long to cache search results and plugin data in hours.', 'github-plugin-browser' ); ?></p>
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
		} else {
			$output['cache_duration'] = 12; // default
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

	/**
	 * Monitored plugins field callback.
	 * Shows a list of plugins that are being monitored, with checkboxes to disable monitoring.
	 * Once disabled, the plugin must be re-installed through the GitHub Plugin Browser to re-enable monitoring.
	 */
	public static function monitored_plugins_field() {
		$monitored_plugins = get_option( 'gpb_plugins', array() );

		if ( empty( $monitored_plugins ) ) {
			echo '<p class="description">' . esc_html__( 'No plugins are currently being monitored for updates. Install a plugin through the GitHub Plugin Browser to enable update monitoring.', 'github-plugin-browser' ) . '</p>';
			return;
		}

		foreach ( $monitored_plugins as $key => $plugin ) {
			?>
			<label>
				<input type="checkbox" name="gpb_settings[monitored_plugins][]" value="<?php echo esc_attr( $key ); ?>" checked="checked" />
				<?php echo esc_html( $plugin['name'] ); ?>
				(<a href="<?php echo esc_url( 'https://github.com/' . $key ); ?>" target="_blank"><?php echo esc_html( $key ); ?></a>)
			</label><br />
			<?php
		}
		?>
		<?php
	}
}
