<?php
/**
 * WP-CLI integration for hub2wp.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class H2WP_CLI_Command {

	/**
	 * Register CLI commands.
	 *
	 * @return void
	 */
	public static function init() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'hub2wp plugin', 'H2WP_CLI_Plugin_Command' );
			WP_CLI::add_command( 'hub2wp theme', 'H2WP_CLI_Theme_Command' );
			WP_CLI::add_command( 'hub2wp settings', 'H2WP_CLI_Settings_Command' );
		}
	}
}

/**
 * Shared helpers for hub2wp WP-CLI commands.
 */
class H2WP_CLI_Repo_Command {

	/**
	 * Parse a GitHub repository reference.
	 *
	 * @param string $reference Repository reference.
	 * @return array
	 */
	protected function parse_repository_reference( $reference ) {
		$reference = trim( (string) $reference );

		if ( preg_match( '#^https?://github\.com/([^/]+)/([^/]+?)(?:\.git|/.*)?$#i', $reference, $matches ) ) {
			return array( sanitize_text_field( $matches[1] ), sanitize_text_field( $matches[2] ) );
		}

		if ( preg_match( '#^([^/]+)/([^/]+)$#', $reference, $matches ) ) {
			return array( sanitize_text_field( $matches[1] ), sanitize_text_field( $matches[2] ) );
		}

		return array( '', '' );
	}

	/**
	 * Get the local directory/slug displayed for a tracked item.
	 *
	 * @param array  $item Tracked item data.
	 * @param string $repo_type Repository type.
	 * @return string
	 */
	protected function get_local_directory_name( $item, $repo_type ) {
		if ( 'theme' === $repo_type ) {
			if ( ! empty( $item['stylesheet'] ) ) {
				return (string) $item['stylesheet'];
			}

			if ( ! empty( $item['directory'] ) ) {
				return (string) $item['directory'];
			}

			return '';
		}

		if ( ! empty( $item['plugin_file'] ) ) {
			return dirname( (string) $item['plugin_file'] );
		}

		if ( ! empty( $item['directory'] ) ) {
			return (string) $item['directory'];
		}

		return '';
	}
}

/**
 * WP-CLI plugin commands.
 */
class H2WP_CLI_Plugin_Command extends H2WP_CLI_Repo_Command {

	/**
	 * List plugins tracked by hub2wp.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hub2wp plugin list
	 *
	 * @subcommand list
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function list_( $args, $assoc_args ) {
		$service = new H2WP_Tracked_Repo_Service();
		$tracked = $service->get_tracked_plugins();
		$rows    = array();

		foreach ( $tracked as $plugin ) {
			$rows[] = array(
				'name'      => isset( $plugin['name'] ) ? (string) $plugin['name'] : ( isset( $plugin['repo'] ) ? (string) $plugin['repo'] : '' ),
				'repo'      => isset( $plugin['repo'] ) ? (string) $plugin['repo'] : '',
				'directory' => isset( $plugin['directory'] ) ? (string) $plugin['directory'] : '',
			);
		}

		if ( empty( $rows ) ) {
			WP_CLI::success( __( 'No plugins are currently tracked by hub2wp.', 'hub2wp' ) );
			return;
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'name', 'repo', 'directory' ) );
	}

	/**
	 * Install a GitHub-hosted plugin and register it for hub2wp updates.
	 *
	 * ## OPTIONS
	 *
	 * <repository>
	 * : GitHub repository in the form owner/repo or a GitHub repository URL.
	 *
	 * [--branch=<branch>]
	 * : Track a specific branch instead of the repository default branch.
	 *
	 * [--no-release-priority]
	 * : Do not prefer the latest GitHub release when resolving versions and downloads.
	 *
	 * [--activate]
	 * : Activate the plugin after installation.
	 *
	 * [--token=<token>]
	 * : Use this GitHub token instead of the token stored in hub2wp settings.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hub2wp plugin install wp-autoplugin/hub2wp --activate
	 *     wp hub2wp plugin install https://github.com/acme/private-plugin --branch=main --token=ghp_xxx
	 *
	 * @subcommand install
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function install( $args, $assoc_args ) {
		list( $owner, $repo ) = $this->parse_repository_reference( $args[0] );

		if ( empty( $owner ) || empty( $repo ) ) {
			WP_CLI::error( __( 'Repository must be provided as owner/repo or a GitHub repository URL.', 'hub2wp' ) );
		}

		$result = H2WP_Repo_Manager::install_repository(
			$owner,
			$repo,
			array(
				'repo_type'           => 'plugin',
				'branch'              => isset( $assoc_args['branch'] ) ? sanitize_text_field( $assoc_args['branch'] ) : '',
				'prioritize_releases' => ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'no-release-priority', false ),
				'access_token'        => isset( $assoc_args['token'] ) ? sanitize_text_field( $assoc_args['token'] ) : '',
				'private'             => isset( $assoc_args['token'] ) ? true : null,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'activate', false ) ) {
			$activation_result = activate_plugin( $result['plugin_file'] );
			if ( is_wp_error( $activation_result ) ) {
				WP_CLI::warning( sprintf( __( 'Plugin installed and tracked, but activation failed: %s', 'hub2wp' ), $activation_result->get_error_message() ) );
			} else {
				WP_CLI::success( sprintf( __( 'Installed, tracked, and activated %s from %s/%s.', 'hub2wp' ), $result['plugin_file'], $owner, $repo ) );
				return;
			}
		}

		WP_CLI::success( sprintf( __( 'Installed and tracked %s from %s/%s.', 'hub2wp' ), $result['plugin_file'], $owner, $repo ) );
	}
}

/**
 * WP-CLI theme commands.
 */
class H2WP_CLI_Theme_Command extends H2WP_CLI_Repo_Command {

	/**
	 * List themes tracked by hub2wp.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hub2wp theme list
	 *
	 * @subcommand list
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function list_( $args, $assoc_args ) {
		$service = new H2WP_Tracked_Repo_Service();
		$tracked = $service->get_tracked_themes();
		$rows    = array();

		foreach ( $tracked as $theme ) {
			$rows[] = array(
				'name'      => isset( $theme['name'] ) ? (string) $theme['name'] : ( isset( $theme['repo'] ) ? (string) $theme['repo'] : '' ),
				'repo'      => isset( $theme['repo'] ) ? (string) $theme['repo'] : '',
				'directory' => isset( $theme['directory'] ) ? (string) $theme['directory'] : '',
			);
		}

		if ( empty( $rows ) ) {
			WP_CLI::success( __( 'No themes are currently tracked by hub2wp.', 'hub2wp' ) );
			return;
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'name', 'repo', 'directory' ) );
	}

	/**
	 * Install a GitHub-hosted theme and register it for hub2wp updates.
	 *
	 * ## OPTIONS
	 *
	 * <repository>
	 * : GitHub repository in the form owner/repo or a GitHub repository URL.
	 *
	 * [--branch=<branch>]
	 * : Track a specific branch instead of the repository default branch.
	 *
	 * [--no-release-priority]
	 * : Do not prefer the latest GitHub release when resolving versions and downloads.
	 *
	 * [--activate]
	 * : Activate the theme after installation.
	 *
	 * [--token=<token>]
	 * : Use this GitHub token instead of the token stored in hub2wp settings.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hub2wp theme install acme/my-theme --activate
	 *     wp hub2wp theme install https://github.com/acme/private-theme --branch=main --token=ghp_xxx
	 *
	 * @subcommand install
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function install( $args, $assoc_args ) {
		list( $owner, $repo ) = $this->parse_repository_reference( $args[0] );

		if ( empty( $owner ) || empty( $repo ) ) {
			WP_CLI::error( __( 'Repository must be provided as owner/repo or a GitHub repository URL.', 'hub2wp' ) );
		}

		$result = H2WP_Repo_Manager::install_repository(
			$owner,
			$repo,
			array(
				'repo_type'           => 'theme',
				'branch'              => isset( $assoc_args['branch'] ) ? sanitize_text_field( $assoc_args['branch'] ) : '',
				'prioritize_releases' => ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'no-release-priority', false ),
				'access_token'        => isset( $assoc_args['token'] ) ? sanitize_text_field( $assoc_args['token'] ) : '',
				'private'             => isset( $assoc_args['token'] ) ? true : null,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'activate', false ) ) {
			switch_theme( $result['stylesheet'] );

			if ( get_stylesheet() === $result['stylesheet'] ) {
				WP_CLI::success( sprintf( __( 'Installed, tracked, and activated theme %s from %s/%s.', 'hub2wp' ), $result['stylesheet'], $owner, $repo ) );
				return;
			}

			WP_CLI::warning( sprintf( __( 'Theme installed and tracked, but activation could not be confirmed for %s.', 'hub2wp' ), $result['stylesheet'] ) );
		}

		WP_CLI::success( sprintf( __( 'Installed and tracked theme %s from %s/%s.', 'hub2wp' ), $result['stylesheet'], $owner, $repo ) );
	}
}

/**
 * WP-CLI settings commands.
 */
class H2WP_CLI_Settings_Command {

	/**
	 * List hub2wp settings.
	 *
	 * ## OPTIONS
	 *
	 * [--show-secrets]
	 * : Show secret values such as the GitHub access token without masking.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hub2wp settings list
	 *     wp hub2wp settings list --show-secrets
	 *
	 * @subcommand list
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function list_( $args, $assoc_args ) {
		$settings = get_option( H2WP_Settings::OPTION_NAME, array() );
		$rows     = array(
			array(
				'field' => 'access_token',
				'value' => $this->format_setting_value(
					'access_token',
					isset( $settings['access_token'] ) ? $settings['access_token'] : '',
					\WP_CLI\Utils\get_flag_value( $assoc_args, 'show-secrets', false )
				),
			),
			array(
				'field' => 'cache_duration',
				'value' => isset( $settings['cache_duration'] ) ? (string) absint( $settings['cache_duration'] ) : '12',
			),
		);

		WP_CLI\Utils\format_items( 'table', $rows, array( 'field', 'value' ) );
	}

	/**
	 * Get a hub2wp setting value.
	 *
	 * ## OPTIONS
	 *
	 * <field>
	 * : Setting field. Supported: access_token, cache_duration.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hub2wp settings get access_token
	 *     wp hub2wp settings get cache_duration
	 *
	 * @subcommand get
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function get( $args, $assoc_args ) {
		$field    = $this->normalize_field_name( $args[0] );
		$settings = get_option( H2WP_Settings::OPTION_NAME, array() );

		if ( ! $this->is_supported_field( $field ) ) {
			WP_CLI::error( __( 'Unsupported setting. Supported fields: access_token, cache_duration.', 'hub2wp' ) );
		}

		$value = isset( $settings[ $field ] ) ? $settings[ $field ] : $this->get_default_setting_value( $field );
		WP_CLI::line( (string) $value );
	}

	/**
	 * Update a hub2wp setting value.
	 *
	 * ## OPTIONS
	 *
	 * <field>
	 * : Setting field. Supported: access_token, cache_duration.
	 *
	 * <value>
	 * : New value for the setting.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hub2wp settings set access_token ghp_xxx
	 *     wp hub2wp settings set cache_duration 6
	 *
	 * @subcommand set
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function set( $args, $assoc_args ) {
		$field    = $this->normalize_field_name( $args[0] );
		$value    = isset( $args[1] ) ? $args[1] : '';
		$settings = get_option( H2WP_Settings::OPTION_NAME, array() );

		if ( ! $this->is_supported_field( $field ) ) {
			WP_CLI::error( __( 'Unsupported setting. Supported fields: access_token, cache_duration.', 'hub2wp' ) );
		}

		$settings[ $field ] = $value;
		$settings           = H2WP_Settings::sanitize_settings( $settings );

		if ( 'cache_duration' === $field && empty( $settings['cache_duration'] ) ) {
			WP_CLI::error( __( 'cache_duration must be a positive integer number of hours.', 'hub2wp' ) );
		}

		update_option( H2WP_Settings::OPTION_NAME, $settings, false );

		WP_CLI::success( sprintf( __( 'Updated hub2wp setting "%s".', 'hub2wp' ), $field ) );
	}

	/**
	 * Delete a hub2wp setting value so the default applies again.
	 *
	 * ## OPTIONS
	 *
	 * <field>
	 * : Setting field. Supported: access_token, cache_duration.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hub2wp settings delete access_token
	 *
	 * @subcommand delete
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function delete( $args, $assoc_args ) {
		$field    = $this->normalize_field_name( $args[0] );
		$settings = get_option( H2WP_Settings::OPTION_NAME, array() );

		if ( ! $this->is_supported_field( $field ) ) {
			WP_CLI::error( __( 'Unsupported setting. Supported fields: access_token, cache_duration.', 'hub2wp' ) );
		}

		unset( $settings[ $field ] );
		update_option( H2WP_Settings::OPTION_NAME, H2WP_Settings::sanitize_settings( $settings ), false );

		WP_CLI::success( sprintf( __( 'Deleted hub2wp setting "%s".', 'hub2wp' ), $field ) );
	}

	/**
	 * Normalize a requested field name.
	 *
	 * @param string $field Setting field.
	 * @return string
	 */
	private function normalize_field_name( $field ) {
		return sanitize_key( (string) $field );
	}

	/**
	 * Check whether a field is supported.
	 *
	 * @param string $field Setting field.
	 * @return bool
	 */
	private function is_supported_field( $field ) {
		return in_array( $field, array( 'access_token', 'cache_duration' ), true );
	}

	/**
	 * Get the default value for a supported setting.
	 *
	 * @param string $field Setting field.
	 * @return string
	 */
	private function get_default_setting_value( $field ) {
		if ( 'cache_duration' === $field ) {
			return '12';
		}

		return '';
	}

	/**
	 * Format a setting value for table output.
	 *
	 * @param string $field Setting field.
	 * @param string $value Setting value.
	 * @param bool   $show_secrets Whether secrets should be shown unmasked.
	 * @return string
	 */
	private function format_setting_value( $field, $value, $show_secrets ) {
		if ( 'access_token' === $field && ! $show_secrets ) {
			if ( '' === $value ) {
				return '';
			}

			$length = strlen( $value );
			if ( $length <= 8 ) {
				return str_repeat( '*', $length );
			}

			return substr( $value, 0, 4 ) . str_repeat( '*', $length - 8 ) . substr( $value, -4 );
		}

		return (string) $value;
	}
}
