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
}

/**
 * WP-CLI plugin commands.
 */
class H2WP_CLI_Plugin_Command extends H2WP_CLI_Repo_Command {

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
