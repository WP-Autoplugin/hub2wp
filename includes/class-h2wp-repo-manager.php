<?php
/**
 * Shared install and tracking workflows for GitHub plugins and themes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class H2WP_Repo_Manager {

	/**
	 * Install a GitHub repository and register it for update tracking.
	 *
	 * @param string $owner Repository owner.
	 * @param string $repo Repository name.
	 * @param array  $args Install arguments.
	 * @return array|WP_Error
	 */
	public static function install_repository( $owner, $repo, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'repo_type'           => 'plugin',
				'branch'              => '',
				'prioritize_releases' => true,
				'access_token'        => '',
				'private'             => null,
			)
		);

		list( $owner, $repo ) = self::normalize_repository_identifier( $owner, $repo );

		$repo_type = in_array( $args['repo_type'], array( 'plugin', 'theme' ), true ) ? $args['repo_type'] : 'plugin';
		$branch    = is_string( $args['branch'] ) ? trim( $args['branch'] ) : '';
		$token     = is_string( $args['access_token'] ) ? $args['access_token'] : '';

		if ( '' === $owner || '' === $repo ) {
			return new WP_Error( 'h2wp_invalid_repository', __( 'A GitHub repository in the form owner/repo is required.', 'hub2wp' ) );
		}

		if ( '' === $token ) {
			$token = H2WP_Settings::get_access_token();
		}

		$api            = new H2WP_GitHub_API( $token );
		$source_context = $api->resolve_version_source( $owner, $repo, $branch, ! empty( $args['prioritize_releases'] ) );
		$compatibility  = $api->check_compatibility( $owner, $repo, $repo_type, $branch, ! empty( $args['prioritize_releases'] ), $source_context );

		if ( empty( $compatibility['is_compatible'] ) ) {
			$message = ! empty( $compatibility['reason'] ) ? $compatibility['reason'] : __( 'The repository is not a compatible WordPress extension.', 'hub2wp' );
			return new WP_Error( 'h2wp_incompatible_repository', $message );
		}

		if ( empty( $source_context['download_url'] ) ) {
			return new WP_Error( 'h2wp_missing_download_url', __( 'Could not determine a download URL for this repository.', 'hub2wp' ) );
		}

		$installer = new H2WP_Plugin_Installer();
		$result    = ( 'theme' === $repo_type )
			? $installer->install_theme( $source_context['download_url'], $token )
			: $installer->install_plugin( $source_context['download_url'], $token );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( 'theme' === $repo_type ) {
			return self::register_installed_theme( $owner, $repo, $installer->theme_data, $source_context, $compatibility, $args );
		}

		return self::register_installed_plugin( $owner, $repo, $installer->plugin_data, $source_context, $compatibility, $args );
	}

	/**
	 * Register an installed plugin for update tracking.
	 *
	 * @param string $owner Repository owner.
	 * @param string $repo Repository name.
	 * @param array  $plugin_data Installed plugin data.
	 * @param array  $source_context Resolved source context.
	 * @param array  $compatibility Compatibility metadata.
	 * @param array  $args Install arguments.
	 * @return array|WP_Error
	 */
	private static function register_installed_plugin( $owner, $repo, $plugin_data, $source_context, $compatibility, $args ) {
		list( $owner, $repo ) = self::normalize_repository_identifier( $owner, $repo );

		$plugin_file = self::find_plugin_file( $plugin_data );
		if ( empty( $plugin_file ) ) {
			return new WP_Error( 'h2wp_plugin_file_not_found', __( 'The plugin was installed, but hub2wp could not determine its main plugin file for update tracking.', 'hub2wp' ) );
		}

		$headers      = isset( $compatibility['headers'] ) && is_array( $compatibility['headers'] ) ? $compatibility['headers'] : array();
		$option_name  = 'h2wp_plugins';
		$repo_key     = $owner . '/' . $repo;
		$tracked_repos = get_option( $option_name, array() );
		$existing     = isset( $tracked_repos[ $repo_key ] ) && is_array( $tracked_repos[ $repo_key ] ) ? $tracked_repos[ $repo_key ] : array();
		$now          = time();

		$tracked_repos[ $repo_key ] = self::build_tracked_repo_data(
			array_merge(
				$existing,
				$plugin_data,
				array(
					'owner'       => $owner,
					'repo'        => $repo,
					'repo_type'   => 'plugin',
					'plugin_file' => $plugin_file,
					'version'     => isset( $headers['version'] ) ? $headers['version'] : ( isset( $plugin_data['version'] ) ? $plugin_data['version'] : '' ),
					'requires'    => isset( $headers['requires at least'] ) ? $headers['requires at least'] : '',
					'tested'      => isset( $headers['tested up to'] ) ? $headers['tested up to'] : '',
					'requires_php' => isset( $headers['requires php'] ) ? $headers['requires php'] : '',
				)
			),
			$source_context,
			$args,
			$now
		);

		update_option( $option_name, $tracked_repos, false );

		return $tracked_repos[ $repo_key ];
	}

	/**
	 * Register an installed theme for update tracking.
	 *
	 * @param string $owner Repository owner.
	 * @param string $repo Repository name.
	 * @param array  $theme_data Installed theme data.
	 * @param array  $source_context Resolved source context.
	 * @param array  $compatibility Compatibility metadata.
	 * @param array  $args Install arguments.
	 * @return array|WP_Error
	 */
	private static function register_installed_theme( $owner, $repo, $theme_data, $source_context, $compatibility, $args ) {
		list( $owner, $repo ) = self::normalize_repository_identifier( $owner, $repo );

		$stylesheet = self::find_theme_stylesheet( $theme_data );
		if ( empty( $stylesheet ) ) {
			return new WP_Error( 'h2wp_theme_stylesheet_not_found', __( 'The theme was installed, but hub2wp could not determine its stylesheet for update tracking.', 'hub2wp' ) );
		}

		$headers      = isset( $compatibility['headers'] ) && is_array( $compatibility['headers'] ) ? $compatibility['headers'] : array();
		$option_name  = 'h2wp_themes';
		$repo_key     = $owner . '/' . $repo;
		$tracked_repos = get_option( $option_name, array() );
		$existing     = isset( $tracked_repos[ $repo_key ] ) && is_array( $tracked_repos[ $repo_key ] ) ? $tracked_repos[ $repo_key ] : array();
		$now          = time();

		$tracked_repos[ $repo_key ] = self::build_tracked_repo_data(
			array_merge(
				$existing,
				$theme_data,
				array(
					'owner'       => $owner,
					'repo'        => $repo,
					'repo_type'   => 'theme',
					'stylesheet'  => $stylesheet,
					'template'    => ! empty( $theme_data['template'] ) ? $theme_data['template'] : $stylesheet,
					'version'     => isset( $headers['version'] ) ? $headers['version'] : ( isset( $theme_data['version'] ) ? $theme_data['version'] : '' ),
					'requires'    => isset( $headers['requires at least'] ) ? $headers['requires at least'] : '',
					'tested'      => isset( $headers['tested up to'] ) ? $headers['tested up to'] : '',
					'requires_php' => isset( $headers['requires php'] ) ? $headers['requires php'] : '',
				)
			),
			$source_context,
			$args,
			$now
		);

		update_option( $option_name, $tracked_repos, false );

		return $tracked_repos[ $repo_key ];
	}

	/**
	 * Build the normalized tracked repository payload.
	 *
	 * @param array $repo_data Repository data.
	 * @param array $source_context Source context.
	 * @param array $args Install arguments.
	 * @param int   $timestamp Unix timestamp.
	 * @return array
	 */
	private static function build_tracked_repo_data( $repo_data, $source_context, $args, $timestamp ) {
		$repo_data['branch']              = isset( $args['branch'] ) ? (string) $args['branch'] : '';
		$repo_data['prioritize_releases'] = ! empty( $args['prioritize_releases'] );
		$repo_data['uses_releases']       = ! empty( $source_context['uses_releases'] );
		$repo_data['version_source']      = isset( $source_context['source'] ) ? (string) $source_context['source'] : 'branch';
		$repo_data['download_url']        = isset( $source_context['download_url'] ) ? (string) $source_context['download_url'] : '';
		$repo_data['last_checked']        = $timestamp;
		$repo_data['last_updated']        = $timestamp;

		if ( null !== $args['private'] ) {
			$repo_data['private'] = (bool) $args['private'];
		}

		return $repo_data;
	}

	/**
	 * Normalize a repository identifier to hub2wp's canonical lowercase owner/repo format.
	 *
	 * @param string $owner Repository owner.
	 * @param string $repo Repository name.
	 * @return array{0:string,1:string}
	 */
	private static function normalize_repository_identifier( $owner, $repo ) {
		return array(
			strtolower( trim( (string) $owner ) ),
			strtolower( trim( (string) $repo ) ),
		);
	}

	/**
	 * Find the installed plugin file.
	 *
	 * @param array $plugin_data Installed plugin data.
	 * @return string|false
	 */
	private static function find_plugin_file( $plugin_data ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins   = get_plugins();
		$directory = isset( $plugin_data['directory'] ) ? strtolower( (string) $plugin_data['directory'] ) : '';
		$name      = isset( $plugin_data['name'] ) ? (string) $plugin_data['name'] : '';
		$author    = isset( $plugin_data['author'] ) ? wp_strip_all_tags( (string) $plugin_data['author'] ) : '';

		foreach ( $plugins as $file => $data ) {
			if ( '' !== $directory && strtolower( dirname( $file ) ) === $directory ) {
				return $file;
			}
		}

		foreach ( $plugins as $file => $data ) {
			$data_author = isset( $data['Author'] ) ? wp_strip_all_tags( (string) $data['Author'] ) : '';
			if ( $name === $data['Name'] && $author === $data_author ) {
				return $file;
			}
		}

		return false;
	}

	/**
	 * Find the installed theme stylesheet slug.
	 *
	 * @param array $theme_data Installed theme data.
	 * @return string|false
	 */
	private static function find_theme_stylesheet( $theme_data ) {
		if ( ! empty( $theme_data['stylesheet'] ) ) {
			return $theme_data['stylesheet'];
		}

		$themes    = wp_get_themes();
		$directory = isset( $theme_data['directory'] ) ? strtolower( (string) $theme_data['directory'] ) : '';
		$name      = isset( $theme_data['name'] ) ? (string) $theme_data['name'] : '';

		foreach ( $themes as $stylesheet => $theme ) {
			if ( '' !== $directory && strtolower( $stylesheet ) === $directory ) {
				return $stylesheet;
			}
		}

		foreach ( $themes as $stylesheet => $theme ) {
			if ( $theme->get( 'Name' ) === $name ) {
				return $stylesheet;
			}
		}

		return false;
	}
}
