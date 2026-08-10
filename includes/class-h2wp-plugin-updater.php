<?php
/**
 * WordPress update integration for tracked GitHub extensions.
 *
 * @package hub2wp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks for plugin updates and integrates with the WordPress update system.
 */
class H2WP_Plugin_Updater {

	/**
	 * Whether check_for_updates() has already been called in this request.
	 *
	 * @var bool
	 */
	private static $update_check_done = false;

	/**
	 * Initialize the updater.
	 */
	public static function init() {
		// Schedule the daily update check if not already scheduled.
		if ( ! wp_next_scheduled( 'h2wp_daily_update_check' ) ) {
			wp_schedule_event( time(), 'daily', 'h2wp_daily_update_check' );
		}

		// Hook into the update check.
		add_action( 'h2wp_daily_update_check', array( __CLASS__, 'check_for_updates' ) );

		// Filter the update_plugins transient.
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_plugin_updates' ) );
		add_filter( 'pre_set_site_transient_update_themes', array( __CLASS__, 'inject_theme_updates' ) );

		// Filter plugin information.
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 99, 3 );

		// Intercept downloads for private-repo updates so they are authenticated.
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'authenticated_download' ), 10, 3 );

		// Rename the GitHub-generated folder (owner-repo-hash) to the plugin's
		// existing folder name so WordPress keeps it active after an update.
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_source_folder' ), 10, 4 );
	}

	/**
	 * Debug logger, enabled only when WP_DEBUG is true.
	 *
	 * @param string $message Log message.
	 * @return void
	 */
	private static function log_debug( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional WP_DEBUG-only diagnostics.
			error_log( '[hub2wp] ' . $message );
		}
	}

	/**
	 * Read a tracked-repository option defensively.
	 *
	 * @param string $option_name Option name.
	 * @return array Tracked records.
	 */
	private static function get_tracked_option( $option_name ) {
		$value = get_option( $option_name, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Check for updates for all monitored GitHub plugins and themes.
	 */
	public static function check_for_updates() {
		$h2wp_plugins    = self::get_tracked_option( 'h2wp_plugins' );
		$h2wp_themes     = self::get_tracked_option( 'h2wp_themes' );
		$api             = new H2WP_GitHub_API( H2WP_Settings::get_access_token() );
		$plugins_updated = false;
		$themes_updated  = false;
		$now             = time();

		foreach ( $h2wp_plugins as $plugin_id => &$plugin ) {
			if ( ! is_array( $plugin ) ) {
				self::log_debug( sprintf( 'Invalid tracked plugin payload: %s', $plugin_id ) );
				continue;
			}
			$identity = H2WP_Settings::get_tracked_repo_identity( $plugin_id, $plugin );
			if ( is_wp_error( $identity ) ) {
				self::log_debug( sprintf( 'Invalid tracked plugin record: %s', $plugin_id ) );
				continue;
			}

			$owner                  = $identity['owner'];
			$repo                   = $identity['repo'];
			$subdirectory           = $identity['subdirectory'];
			$plugin['owner']        = $owner;
			$plugin['repo']         = $repo;
			$plugin['subdirectory'] = $subdirectory;

			$tracking_preferences = H2WP_Settings::get_repo_tracking_preferences( $owner, $repo, 'plugin', $subdirectory );
			$branch               = $tracking_preferences['branch'];
			$prioritize_releases  = $tracking_preferences['prioritize_releases'];
			$source_context       = $api->resolve_version_source( $owner, $repo, $branch, $prioritize_releases, $subdirectory );

			// The installed package version comes from the plugin PHP header.
			$headers = $api->get_plugin_headers( $owner, $repo, $branch, $prioritize_releases, $source_context, $subdirectory );
			if ( is_wp_error( $headers ) || empty( $headers['version'] ) ) {
				if ( is_wp_error( $headers ) ) {
					self::log_debug( sprintf( 'Plugin update check failed for %s: %s', $plugin_id, $headers->get_error_message() ) );
				}
				continue;
			}

			// Update plugin data.
			$plugin['version']             = $headers['version'];
			$plugin['requires']            = isset( $headers['requires at least'] ) ? $headers['requires at least'] : '';
			$plugin['tested']              = isset( $headers['tested up to'] ) ? $headers['tested up to'] : '';
			$plugin['requires_php']        = isset( $headers['requires php'] ) ? $headers['requires php'] : '';
			$plugin['last_checked']        = $now;
			$plugin['uses_releases']       = ! empty( $source_context['uses_releases'] );
			$plugin['version_source']      = isset( $source_context['source'] ) ? $source_context['source'] : 'branch';
			$plugin['prioritize_releases'] = $prioritize_releases;
			$plugin['download_url']        = isset( $source_context['download_url'] ) ? $source_context['download_url'] : '';
			$plugin['package_scope']       = isset( $source_context['package_scope'] ) ? $source_context['package_scope'] : 'repository';
			if ( empty( $plugin['plugin_file'] ) ) {
				$lookup_slug           = '' === $subdirectory ? $repo : basename( $subdirectory );
				$plugin['plugin_file'] = H2WP_Admin_Page::get_installed_plugin_file( $owner, $lookup_slug );
			}

			$plugins_updated = true;
		}
		unset( $plugin );

		if ( $plugins_updated ) {
			update_option( 'h2wp_plugins', $h2wp_plugins );
		}

		foreach ( $h2wp_themes as $theme_id => &$theme ) {
			if ( ! is_array( $theme ) ) {
				self::log_debug( sprintf( 'Invalid tracked theme payload: %s', $theme_id ) );
				continue;
			}
			$identity = H2WP_Settings::get_tracked_repo_identity( $theme_id, $theme );
			if ( is_wp_error( $identity ) ) {
				self::log_debug( sprintf( 'Invalid tracked theme record: %s', $theme_id ) );
				continue;
			}

			$owner                 = $identity['owner'];
			$repo                  = $identity['repo'];
			$subdirectory          = $identity['subdirectory'];
			$theme['owner']        = $owner;
			$theme['repo']         = $repo;
			$theme['subdirectory'] = $subdirectory;
			$tracking_preferences  = H2WP_Settings::get_repo_tracking_preferences( $owner, $repo, 'theme', $subdirectory );
			$branch                = $tracking_preferences['branch'];
			$prioritize_releases   = $tracking_preferences['prioritize_releases'];
			$source_context        = $api->resolve_version_source( $owner, $repo, $branch, $prioritize_releases, $subdirectory );

			$headers = $api->get_theme_headers( $owner, $repo, $branch, $prioritize_releases, $source_context, $subdirectory );
			if ( is_wp_error( $headers ) || empty( $headers['version'] ) ) {
				if ( is_wp_error( $headers ) ) {
					self::log_debug( sprintf( 'Theme update check failed for %s: %s', $theme_id, $headers->get_error_message() ) );
				}
				continue;
			}

			$theme['version']             = $headers['version'];
			$theme['requires']            = isset( $headers['requires at least'] ) ? $headers['requires at least'] : '';
			$theme['tested']              = isset( $headers['tested up to'] ) ? $headers['tested up to'] : '';
			$theme['requires_php']        = isset( $headers['requires php'] ) ? $headers['requires php'] : '';
			$theme['last_checked']        = $now;
			$theme['download_url']        = isset( $source_context['download_url'] ) ? $source_context['download_url'] : '';
			$theme['uses_releases']       = ! empty( $source_context['uses_releases'] );
			$theme['version_source']      = isset( $source_context['source'] ) ? $source_context['source'] : 'branch';
			$theme['prioritize_releases'] = $prioritize_releases;
			$theme['package_scope']       = isset( $source_context['package_scope'] ) ? $source_context['package_scope'] : 'repository';

			if ( empty( $theme['stylesheet'] ) ) {
				$lookup_slug         = '' === $subdirectory ? $repo : basename( $subdirectory );
				$theme['stylesheet'] = H2WP_Admin_Page::get_installed_theme_stylesheet( $owner, $lookup_slug );
			}

			$themes_updated = true;
		}
		unset( $theme );

		if ( $themes_updated ) {
			update_option( 'h2wp_themes', $h2wp_themes );
		}

		self::log_debug(
			sprintf(
				'Update check completed. plugins=%d, themes=%d, plugins_updated=%s, themes_updated=%s',
				count( $h2wp_plugins ),
				count( $h2wp_themes ),
				$plugins_updated ? 'yes' : 'no',
				$themes_updated ? 'yes' : 'no'
			)
		);
	}

	/**
	 * Refresh stored version data from GitHub if stale.
	 *
	 * @return void
	 */
	private static function ensure_update_data_fresh() {
		if ( false === get_transient( 'h2wp_last_update_check' ) && ! self::$update_check_done ) {
			self::$update_check_done = true;
			set_transient( 'h2wp_last_update_check', 1, H2WP_Settings::get_cache_duration() );
			self::check_for_updates();
		}
	}

	/**
	 * Inject update information into the update_plugins transient.
	 *
	 * @param object $transient Update plugins transient.
	 * @return object Modified transient.
	 */
	public static function inject_plugin_updates( $transient ) {
		if ( empty( $transient ) || ! is_object( $transient ) ) {
			return $transient;
		}

		// Refresh the stored version data from GitHub if stale.
		// We gate this behind a transient (duration configured in Settings) so
		// the API is not hit on every single filter invocation, while still
		// being much more responsive than the once-daily cron (which may never
		// run in some environments).
		self::ensure_update_data_fresh();
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		$h2wp_plugins = self::get_tracked_option( 'h2wp_plugins' );

		foreach ( $h2wp_plugins as $plugin_id => $plugin ) {
			if ( ! is_array( $plugin ) ) {
				continue;
			}
			if ( empty( $plugin['plugin_file'] ) || empty( $plugin['version'] ) || empty( $plugin['download_url'] ) ) {
				continue;
			}
			$identity = H2WP_Settings::get_tracked_repo_identity( $plugin_id, $plugin );
			if ( is_wp_error( $identity ) ) {
				continue;
			}

			if ( ! file_exists( WP_PLUGIN_DIR . '/' . $plugin['plugin_file'] ) ) {
				continue;
			}

			$installed_data    = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin['plugin_file'] );
			$installed_version = isset( $installed_data['Version'] ) ? $installed_data['Version'] : '';
			if ( '' === $installed_version ) {
				continue;
			}

			if ( version_compare( $installed_version, $plugin['version'], '<' ) ) {
				$item = (object) array(
					'id'            => $plugin_id,
					'slug'          => dirname( $plugin['plugin_file'] ),
					'plugin'        => $plugin['plugin_file'],
					'new_version'   => $plugin['version'],
					'url'           => 'https://github.com/' . $identity['owner'] . '/' . $identity['repo'],
					'package'       => $plugin['download_url'],
					'icons'         => ! empty( $plugin['owner_avatar_url'] ) ? array(
						'1x' => $plugin['owner_avatar_url'],
						'2x' => $plugin['owner_avatar_url'],
					) : array(),
					'banners'       => array(),
					'banners_rtl'   => array(),
					'tested'        => isset( $plugin['tested'] ) ? $plugin['tested'] : '',
					'requires_php'  => isset( $plugin['requires_php'] ) ? $plugin['requires_php'] : '',
					'compatibility' => new stdClass(),
				);

				$transient->response[ $plugin['plugin_file'] ] = $item;
				self::log_debug( sprintf( 'Plugin update available: %s %s -> %s', $plugin['plugin_file'], $installed_version, $plugin['version'] ) );
			}
		}

		return $transient;
	}

	/**
	 * Inject update information into the update_themes transient.
	 *
	 * @param object $transient Update themes transient.
	 * @return object Modified transient.
	 */
	public static function inject_theme_updates( $transient ) {
		if ( empty( $transient ) || ! is_object( $transient ) ) {
			return $transient;
		}

		self::ensure_update_data_fresh();
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		$h2wp_themes = self::get_tracked_option( 'h2wp_themes' );
		$themes      = wp_get_themes();

		foreach ( $h2wp_themes as $theme_id => $theme ) {
			if ( ! is_array( $theme ) ) {
				continue;
			}
			if ( empty( $theme['version'] ) || empty( $theme['download_url'] ) ) {
				continue;
			}
			$identity = H2WP_Settings::get_tracked_repo_identity( $theme_id, $theme );
			if ( is_wp_error( $identity ) ) {
				continue;
			}

			$stylesheet = isset( $theme['stylesheet'] ) ? $theme['stylesheet'] : '';
			if ( empty( $stylesheet ) || ! isset( $themes[ $stylesheet ] ) ) {
				$lookup_slug = '' === $identity['subdirectory'] ? $identity['repo'] : basename( $identity['subdirectory'] );
				$stylesheet  = H2WP_Admin_Page::get_installed_theme_stylesheet( $identity['owner'], $lookup_slug );
			}

			if ( empty( $stylesheet ) || ! isset( $themes[ $stylesheet ] ) ) {
				continue;
			}

			$installed_version = $themes[ $stylesheet ]->get( 'Version' );
			if ( version_compare( $installed_version, $theme['version'], '<' ) ) {
				$transient->response[ $stylesheet ] = array(
					'theme'        => $stylesheet,
					'new_version'  => $theme['version'],
					'url'          => 'https://github.com/' . $identity['owner'] . '/' . $identity['repo'],
					'package'      => isset( $theme['download_url'] ) ? $theme['download_url'] : '',
					'requires'     => isset( $theme['requires'] ) ? $theme['requires'] : '',
					'requires_php' => isset( $theme['requires_php'] ) ? $theme['requires_php'] : '',
				);
				self::log_debug( sprintf( 'Theme update available: %s %s -> %s', $stylesheet, $installed_version, $theme['version'] ) );
			}
		}

		return $transient;
	}
	/**
	 * Provide detailed plugin information for the update modal.
	 *
	 * @param false|object|array $result The result object or array.
	 * @param string             $action The type of information being requested from the Plugin Installation API.
	 * @param object             $args   Plugin API arguments.
	 * @return false|object Plugin information or false if not our plugin.
	 */
	public static function plugin_info( $result, $action, $args ) {
		// Only proceed if we're getting plugin information.
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) ) {
			return $result;
		}

		$h2wp_plugins = self::get_tracked_option( 'h2wp_plugins' );

		// Find the plugin by slug.
		foreach ( $h2wp_plugins as $plugin_id => $plugin ) {
			if ( ! is_array( $plugin ) ) {
				continue;
			}
			if ( empty( $plugin['plugin_file'] ) ) {
				continue;
			}

			$plugin_slug = dirname( $plugin['plugin_file'] );

			if ( $plugin_slug === $args->slug ) {
				$identity = H2WP_Settings::get_tracked_repo_identity( $plugin_id, $plugin );
				if ( is_wp_error( $identity ) ) {
					continue;
				}
				$owner                = $identity['owner'];
				$repo                 = $identity['repo'];
				$subdirectory         = $identity['subdirectory'];
				$tracking_preferences = H2WP_Settings::get_repo_tracking_preferences( $owner, $repo, 'plugin', $subdirectory );
				$branch               = $tracking_preferences['branch'];
				$prioritize_releases  = $tracking_preferences['prioritize_releases'];

				$api            = new H2WP_GitHub_API( H2WP_Settings::get_access_token() );
				$source_context = $api->resolve_version_source( $owner, $repo, $branch, $prioritize_releases, $subdirectory );
				$repo_details   = $api->get_repo_details( $owner, $repo );
				$readme_html    = $api->get_readme_html( $owner, $repo, $source_context['ref'], $subdirectory );

				if ( is_wp_error( $repo_details ) ) {
					return $result;
				}
				if ( is_wp_error( $readme_html ) ) {
					$readme_html = '<p>' . esc_html__( 'No README available.', 'hub2wp' ) . '</p>';
				}

				// watchers and og_image are scraped from the public GitHub HTML page,
				// which is inaccessible for private repos. Fall back gracefully.
				$watchers_raw = $api->get_watchers_count( $owner, $repo );
				$watchers     = is_wp_error( $watchers_raw ) ? 0 : $watchers_raw;
				$og_image_raw = $api->get_og_image( $owner, $repo );
				$og_image     = is_wp_error( $og_image_raw ) ? ( isset( $repo_details['owner']['avatar_url'] ) ? $repo_details['owner']['avatar_url'] : '' ) : $og_image_raw;

				$info = new stdClass();

				// Basic plugin information.
				$info->name           = isset( $plugin['name'] ) ? $plugin['name'] : $repo;
				$info->slug           = $args->slug;
				$info->version        = isset( $plugin['version'] ) ? $plugin['version'] : '1.0.0';
				$info->author         = sprintf(
					'<a href="%s">%s</a>',
					esc_url( 'https://github.com/' . $owner ),
					isset( $plugin['author'] ) ? $plugin['author'] : $owner
				);
				$info->author_profile = esc_url( "https://github.com/{$owner}" );
				$info->homepage       = esc_url( 'https://github.com/' . $owner . '/' . $repo );
				$info->requires       = isset( $plugin['requires'] ) ? $plugin['requires'] : '';
				$info->tested         = isset( $plugin['tested'] ) ? $plugin['tested'] : '';
				$info->requires_php   = isset( $plugin['requires_php'] ) ? $plugin['requires_php'] : '';
				$info->downloaded     = 0;
				$info->last_updated   = isset( $plugin['last_updated'] ) ? $plugin['last_updated'] : '';

				// Enhanced sections.
				$info->sections = array(
					'description'  => $readme_html,
					'installation' => self::get_installation_instructions( $plugin_id ),
					'github'       => self::get_github_tab_content( $repo_details, $watchers ),
					'changelog'    => self::get_changelog_content( $owner, $repo, $api, $subdirectory ),
				);

				// Add GitHub-specific banners and icons.
				$info->banners = array(
					'low'  => isset( $og_image ) ? esc_url( $og_image ) : '',
					'high' => isset( $og_image ) ? esc_url( $og_image ) : '',
				);

				$info->icons = array(
					'default' => isset( $repo_details['owner']['avatar_url'] ) ? esc_url( $repo_details['owner']['avatar_url'] ) : '',
					'1x'      => isset( $repo_details['owner']['avatar_url'] ) ? esc_url( $repo_details['owner']['avatar_url'] ) : '',
					'2x'      => isset( $repo_details['owner']['avatar_url'] ) ? esc_url( $repo_details['owner']['avatar_url'] ) : '',
				);

				// Additional metadata.
				$info->download_link = isset( $plugin['download_url'] ) ? esc_url( $plugin['download_url'] ) : '';
				$info->rating        = 0;
				$info->num_ratings   = 0;
				$info->contributors  = array();

				// GitHub-specific metadata.
				$info->github = array(
					'stars'       => isset( $repo_details['stargazers_count'] ) ? intval( $repo_details['stargazers_count'] ) : 0,
					'forks'       => isset( $repo_details['forks_count'] ) ? intval( $repo_details['forks_count'] ) : 0,
					'open_issues' => isset( $repo_details['open_issues_count'] ) ? intval( $repo_details['open_issues_count'] ) : 0,
					'watchers'    => intval( $watchers ),
					'language'    => isset( $repo_details['language'] ) ? esc_html( $repo_details['language'] ) : '',
					'last_commit' => isset( $repo_details['updated_at'] ) ? esc_html( $repo_details['updated_at'] ) : '',
					'created_at'  => isset( $repo_details['created_at'] ) ? esc_html( $repo_details['created_at'] ) : '',
					'license'     => esc_html( isset( $repo_details['license']['name'] ) ? $repo_details['license']['name'] : __( 'Unknown', 'hub2wp' ) ),
				);

				// Short description from GitHub.
				$info->short_description = isset( $repo_details['description'] ) ? esc_html( $repo_details['description'] ) : '';

				return $info;
			}
		}

		return $result;
	}

	/**
	 * Format installation instructions.
	 *
	 * @param string $plugin_id Plugin identifier (owner/repo).
	 * @param string $repo_type Repository type (plugin|theme).
	 * @return string Formatted installation instructions.
	 */
	public static function get_installation_instructions( $plugin_id = '', $repo_type = 'plugin' ) {
		$repo_type = in_array( $repo_type, array( 'plugin', 'theme' ), true ) ? $repo_type : 'plugin';

		$is_theme     = ( 'theme' === $repo_type );
		$admin_path   = $is_theme ? esc_html__( 'Appearance &rarr; Themes &rarr; GitHub Themes', 'hub2wp' ) : esc_html__( 'Plugins &rarr; Add GitHub Plugin', 'hub2wp' );
		$install_verb = $is_theme ? esc_html__( 'theme', 'hub2wp' ) : esc_html__( 'plugin', 'hub2wp' );
		$manual_path  = $is_theme ? '/wp-content/themes/' : '/wp-content/plugins/';

		$instructions  = '<h4>' . esc_html__( 'Installation via hub2wp', 'hub2wp' ) . '</h4>';
		$instructions .= '<ol>';
		$instructions .= '<li>' . sprintf(
			/* translators: %s: Navigation path in WordPress admin */
			__( 'Navigate to <strong>%s</strong> in your WordPress admin panel', 'hub2wp' ),
			'<strong>' . $admin_path . '</strong>'
		) . '</li>';
		$instructions .= '<li>' . sprintf(
			/* translators: %s: Repository identifier */
			__( 'Search for <strong class="h2wp-modal-title">%s</strong>', 'hub2wp' ),
			esc_html( $plugin_id )
		) . '</li>';
		$instructions .= '<li>' . esc_html__( 'Click the "Install" button', 'hub2wp' ) . '</li>';
		$instructions .= '<li>' . sprintf(
			/* translators: %s: Extension type (plugin/theme). */
			esc_html__( 'Activate the %s in WordPress', 'hub2wp' ),
			$install_verb
		) . '</li>';
		$instructions .= '</ol>';

		$instructions .= '<h4>' . esc_html__( 'Manual Installation', 'hub2wp' ) . '</h4>';
		$instructions .= '<ol>';
		$instructions .= '<li>' . esc_html__( 'Download the latest release from the GitHub repository', 'hub2wp' ) . '</li>';
		$instructions .= '<li>' . sprintf(
			/* translators: %s: Directory path */
			__( 'Upload the files to your <code>%s</code> directory', 'hub2wp' ),
			$manual_path
		) . '</li>';
		$instructions .= '<li>' . sprintf(
			/* translators: %s: Extension type (plugin/theme). */
			esc_html__( 'Activate the %s in WordPress', 'hub2wp' ),
			$install_verb
		) . '</li>';
		$instructions .= '</ol>';

		return $instructions;
	}

	/**
	 * Format GitHub tab content.
	 *
	 * @param array $repo_details      Repository details.
	 * @param int   $watchers          Number of watchers.
	 * @return string Formatted GitHub information.
	 */
	private static function get_github_tab_content( $repo_details, $watchers ) {
		$stars       = isset( $repo_details['stargazers_count'] ) ? (int) $repo_details['stargazers_count'] : 0;
		$forks       = isset( $repo_details['forks_count'] ) ? (int) $repo_details['forks_count'] : 0;
		$open_issues = isset( $repo_details['open_issues_count'] ) ? (int) $repo_details['open_issues_count'] : 0;
		$created_at  = ! empty( $repo_details['created_at'] ) ? strtotime( $repo_details['created_at'] ) : false;
		$updated_at  = ! empty( $repo_details['updated_at'] ) ? strtotime( $repo_details['updated_at'] ) : false;
		$html_url    = isset( $repo_details['html_url'] ) ? (string) $repo_details['html_url'] : '';
		$content     = '<div class="github-info">';

		// Repository Statistics.
		$content .= '<h3>' . esc_html__( 'Repository Statistics', 'hub2wp' ) . '</h3>';
		$content .= '<ul class="github-stats">';
		$content .= '<li>⭐ ' . esc_html__( 'Stars:', 'hub2wp' ) . ' ' . number_format_i18n( $stars ) . '</li>';
		$content .= '<li>🔀 ' . esc_html__( 'Forks:', 'hub2wp' ) . ' ' . number_format_i18n( $forks ) . '</li>';
		$content .= '<li>👀 ' . esc_html__( 'Watchers:', 'hub2wp' ) . ' ' . number_format_i18n( $watchers ) . '</li>';
		$content .= '<li>❗ ' . esc_html__( 'Open Issues:', 'hub2wp' ) . ' ' . number_format_i18n( $open_issues ) . '</li>';
		$content .= '</ul>';

		// Technical Details.
		$content .= '<h3>' . esc_html__( 'Technical Details', 'hub2wp' ) . '</h3>';
		$content .= '<ul class="github-technical">';
		$content .= '<li>' . esc_html__( 'License:', 'hub2wp' ) . ' ' . esc_html( isset( $repo_details['license']['name'] ) ? $repo_details['license']['name'] : __( 'Unknown', 'hub2wp' ) ) . '</li>';
		$content .= '<li>' . esc_html__( 'Created:', 'hub2wp' ) . ' ' . ( false !== $created_at ? human_time_diff( $created_at ) . ' ' . esc_html__( 'ago', 'hub2wp' ) : esc_html__( 'Unknown', 'hub2wp' ) ) . '</li>';
		$content .= '<li>' . esc_html__( 'Last Updated:', 'hub2wp' ) . ' ' . ( false !== $updated_at ? human_time_diff( $updated_at ) . ' ' . esc_html__( 'ago', 'hub2wp' ) : esc_html__( 'Unknown', 'hub2wp' ) ) . '</li>';
		$content .= '</ul>';

		// Quick Links.
		$content .= '<h3>' . esc_html__( 'Quick Links', 'hub2wp' ) . '</h3>';
		$content .= '<ul class="github-links">';
		if ( '' !== $html_url ) {
			$content .= '<li><a href="' . esc_url( $html_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View on GitHub', 'hub2wp' ) . '</a></li>';
			$content .= '<li><a href="' . esc_url( $html_url . '/issues' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Issue Tracker', 'hub2wp' ) . '</a></li>';
			$content .= '<li><a href="' . esc_url( $html_url . '/releases' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Releases', 'hub2wp' ) . '</a></li>';
			if ( ! empty( $repo_details['wiki'] ) ) {
				$content .= '<li><a href="' . esc_url( $html_url . '/wiki' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Documentation Wiki', 'hub2wp' ) . '</a></li>';
			}
		}
		$content .= '</ul>';

		$content .= '</div>';

		return $content;
	}

	/**
	 * Format changelog content from GitHub releases.
	 *
	 * @param string          $owner Repository owner.
	 * @param string          $repo  Repository name.
	 * @param H2WP_GitHub_API $api   GitHub API instance.
	 * @param string          $subdirectory Optional project subdirectory.
	 * @return string Formatted changelog content.
	 */
	private static function get_changelog_content( $owner, $repo, $api, $subdirectory = '' ) {
		$releases = $api->get_changelog( $owner, $repo, $subdirectory );
		if ( is_wp_error( $releases ) ) {
			return '<p>' . esc_html__( 'No changelog information available.', 'hub2wp' ) . '</p>';
		}

		if ( empty( $releases ) ) {
			return '<p>' . esc_html__( 'No release information available.', 'hub2wp' ) . '</p>';
		}

		$changelog = '';
		foreach ( $releases as $release ) {
			$changelog .= '<h4>' . esc_html( $release['version'] ) . ' - ' . esc_html( date_i18n( get_option( 'date_format' ), strtotime( $release['date'] ) ) ) . '</h4>';

			if ( ! empty( $release['description'] ) ) {
				$changelog .= '<div class="release-notes">';
				$changelog .= wp_kses_post( $release['description'] );
				$changelog .= '</div>';
			}
		}

		return $changelog;
	}

	/**
	 * Format contributors for the plugins API.
	 *
	 * @param array $contributors List of contributors from GitHub API.
	 * @return array Formatted contributors list.
	 */
	private static function format_contributors( $contributors ) {
		$formatted = array();
		foreach ( $contributors as $contributor ) {
			$formatted[ $contributor['login'] ] = array(
				'profile'      => esc_url( $contributor['html_url'] ),
				'avatar'       => esc_url( $contributor['avatar_url'] ),
				'display_name' => esc_html( $contributor['login'] ),
			);
		}
		return $formatted;
	}

	/**
	 * Add "hub2wp" to the list of update sources on activation.
	 */
	public static function activate() {
		$h2wp_sources = self::get_tracked_option( 'h2wp_plugins' );
		if ( ! isset( $h2wp_sources['hub2wp'] ) ) {
			$h2wp_sources['hub2wp'] = array(
				'directory'    => H2WP_PLUGIN_BASENAME,
				'name'         => 'hub2wp',
				'author'       => 'Balázs Piller',
				'version'      => H2WP_VERSION,
				'owner'        => 'wp-autoplugin',
				'repo'         => 'hub2wp',
				'plugin_file'  => H2WP_PLUGIN_BASENAME,
				'requires'     => '5.5',
				'tested'       => '6.7.1',
				'requires_php' => '7.0',
				'last_checked' => time(),
				'last_updated' => '',
				'download_url' => 'https://api.github.com/repos/wp-autoplugin/hub2wp/zipball',
			);
			update_option( 'h2wp_plugins', $h2wp_sources );
		}

		// Check if the plugin_file contains WP_PLUGIN_DIR and if so, update it to the correct value.
		if ( isset( $h2wp_sources['hub2wp']['plugin_file'] ) && false !== strpos( $h2wp_sources['hub2wp']['plugin_file'], WP_PLUGIN_DIR ) ) {
			$h2wp_sources['hub2wp']['plugin_file'] = H2WP_PLUGIN_BASENAME;
			update_option( 'h2wp_plugins', $h2wp_sources );
		}
	}

	/**
	 * Rename the extracted source folder to match the plugin's existing folder.
	 *
	 * GitHub zip files always extract to a folder named
	 * `{owner}-{repo}-{commithash}/`. If that name differs from the plugin's
	 * current folder, WordPress moves the new code to the wrong path, the old
	 * folder is left behind, and the plugin gets deactivated. This filter renames
	 * the extracted folder before WordPress moves it, keeping the path stable.
	 *
	 * Hooked to: upgrader_source_selection
	 *
	 * @param string      $source        Path to the extracted source folder.
	 * @param string      $remote_source Path to the temp directory.
	 * @param WP_Upgrader $upgrader      The upgrader instance.
	 * @param array       $hook_extra    Extra data (contains 'plugin' key on updates).
	 * @return string|WP_Error Corrected source path, or original on failure.
	 */
	public static function fix_source_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		if ( empty( $hook_extra['plugin'] ) && empty( $hook_extra['theme'] ) ) {
			return $source;
		}
		if ( ! $wp_filesystem || ! method_exists( $wp_filesystem, 'move' ) ) {
			return $source;
		}

		$is_plugin      = ! empty( $hook_extra['plugin'] );
		$correct_folder = '';
		$tracked        = null;

		if ( $is_plugin ) {
			$plugin_file  = $hook_extra['plugin'];
			$h2wp_plugins = self::get_tracked_option( 'h2wp_plugins' );
			foreach ( $h2wp_plugins as $plugin ) {
				if ( isset( $plugin['plugin_file'] ) && $plugin['plugin_file'] === $plugin_file ) {
					$tracked = $plugin;
					break;
				}
			}
			if ( null === $tracked ) {
				return $source;
			}
			$correct_folder = dirname( $plugin_file );
		} else {
			$stylesheet  = $hook_extra['theme'];
			$h2wp_themes = self::get_tracked_option( 'h2wp_themes' );
			foreach ( $h2wp_themes as $theme ) {
				if ( isset( $theme['stylesheet'] ) && $theme['stylesheet'] === $stylesheet ) {
					$tracked = $theme;
					break;
				}
			}
			if ( null === $tracked ) {
				return $source;
			}
			$correct_folder = $stylesheet;
		}

		if ( '.' === $correct_folder || '' === $correct_folder ) {
			return $source;
		}
		$normalized_folder = H2WP_Settings::normalize_subdirectory( $correct_folder );
		if ( is_wp_error( $normalized_folder ) || basename( $normalized_folder ) !== $normalized_folder ) {
			return new WP_Error( 'h2wp_invalid_destination', __( 'The tracked extension has an invalid destination folder.', 'hub2wp' ) );
		}
		$correct_folder = $normalized_folder;

		$subdirectory = isset( $tracked['subdirectory'] ) ? H2WP_Settings::normalize_subdirectory( $tracked['subdirectory'] ) : '';
		if ( is_wp_error( $subdirectory ) ) {
			return $subdirectory;
		}

		$project_source = $source;
		if ( '' !== $subdirectory ) {
			$nested_source = trailingslashit( $source ) . $subdirectory;
			if ( $wp_filesystem->is_dir( $nested_source ) ) {
				$project_source = $nested_source;
			}
		}

		$new_source = trailingslashit( $remote_source ) . $correct_folder;

		// Nothing to do if it already has the right name.
		if ( trailingslashit( $new_source ) === trailingslashit( $project_source ) ) {
			return $project_source;
		}

		if ( $wp_filesystem->exists( $new_source ) ) {
			if ( ! self::is_path_within( $new_source, $remote_source ) || ! $wp_filesystem->delete( $new_source, true ) ) {
				return new WP_Error( 'h2wp_temp_destination_error', __( 'Could not prepare the temporary update directory.', 'hub2wp' ) );
			}
		}

		if ( ! $wp_filesystem->move( untrailingslashit( $project_source ), $new_source ) ) {
			return new WP_Error(
				'h2wp_rename_error',
				sprintf(
					/* translators: 1: extracted folder, 2: expected folder */
					__( 'Could not rename extracted folder from "%1$s" to "%2$s".', 'hub2wp' ),
					basename( $project_source ),
					$correct_folder
				)
			);
		}

		if (
			untrailingslashit( $project_source ) !== untrailingslashit( $source ) &&
			self::is_path_within( $source, $remote_source )
		) {
			$wp_filesystem->delete( untrailingslashit( $source ), true );
		}

		return trailingslashit( $new_source );
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

	/**
	 * Intercept the upgrader's package download for private GitHub repos.
	 *
	 * WordPress's built-in download_url() never sends an Authorization header,
	 * so update downloads for private repos would fail with a 404/401.
	 * This filter downloads the zip ourselves with the stored access token and
	 * returns the local temp-file path so the upgrader can continue normally.
	 *
	 * Hooked to: upgrader_pre_download
	 *
	 * @param false|string $reply    Current pre-download reply (false = not handled yet).
	 * @param string       $package  The package URL to download.
	 * @param WP_Upgrader  $upgrader The upgrader instance.
	 * @return false|string|WP_Error Local file path on success, WP_Error on failure,
	 *                               or false to let WP handle it normally.
	 */
	public static function authenticated_download( $reply, $package, $upgrader ) {
		// Let other filters or WP's default handle it if it's already resolved.
		if ( false !== $reply ) {
			return $reply;
		}

		// Never send the stored GitHub token to a non-GitHub host.
		if ( 'api.github.com' !== strtolower( (string) wp_parse_url( $package, PHP_URL_HOST ) ) ) {
			return $reply;
		}

		$access_token = H2WP_Settings::get_access_token();
		if ( empty( $access_token ) ) {
			return $reply;
		}

		// Only intercept packages that belong to one of our tracked GitHub repos.
		$package_repo_key = self::get_repo_key_from_package_url( $package );
		if ( empty( $package_repo_key ) ) {
			return $reply;
		}

		if ( ! self::is_tracked_package_for_upgrade( $package_repo_key, $upgrader ) ) {
			return $reply;
		}

		// Stream the zip to a temp file with the Authorization header.
		$tmpfname = wp_tempnam( $package );
		if ( empty( $tmpfname ) || ! is_string( $tmpfname ) ) {
			return new WP_Error(
				'h2wp_temp_file_error',
				__( 'Could not create a temporary file for the update download.', 'hub2wp' )
			);
		}

		$response = wp_remote_get(
			$package,
			array(
				'timeout'     => 300,
				'stream'      => true,
				'filename'    => $tmpfname,
				'redirection' => 5,
				'headers'     => array(
					'Authorization' => 'token ' . $access_token,
					// Release asset URLs need octet-stream to receive the file itself.
					// Zipball URLs need the standard API accept header.
					'Accept'        => ( false !== strpos( $package, '/releases/assets/' ) )
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
			return new WP_Error(
				'h2wp_download_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Could not download the update zip (HTTP %d). Please verify your access token has the "repo" scope.', 'hub2wp' ),
					$code
				)
			);
		}

		return $tmpfname;
	}

	/**
	 * Check whether a package URL belongs to the plugin or theme currently being upgraded.
	 *
	 * @param string      $package_repo_key Repository key parsed from the package URL.
	 * @param WP_Upgrader $upgrader         The upgrader instance.
	 * @return bool
	 */
	private static function is_tracked_package_for_upgrade( $package_repo_key, $upgrader ) {
		$upgrade_repo_key = self::get_repo_key_from_upgrader( $upgrader );
		if ( '' !== $upgrade_repo_key ) {
			return $package_repo_key === $upgrade_repo_key;
		}

		$h2wp_plugins = self::get_tracked_option( 'h2wp_plugins' );
		$h2wp_themes  = self::get_tracked_option( 'h2wp_themes' );

		foreach ( array( $h2wp_plugins, $h2wp_themes ) as $tracked_repositories ) {
			foreach ( $tracked_repositories as $repo_key => $repo_data ) {
				$identity = H2WP_Settings::get_tracked_repo_identity( $repo_key, $repo_data );
				if ( ! is_wp_error( $identity ) && strtolower( $identity['owner'] . '/' . $identity['repo'] ) === $package_repo_key ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Resolve the tracked repository key for the current upgrader context.
	 *
	 * @param WP_Upgrader $upgrader The upgrader instance.
	 * @return string
	 */
	private static function get_repo_key_from_upgrader( $upgrader ) {
		if ( ! isset( $upgrader->skin ) || ! isset( $upgrader->skin->options ) || ! is_array( $upgrader->skin->options ) ) {
			return '';
		}

		$hook_extra = isset( $upgrader->skin->options['hook_extra'] ) && is_array( $upgrader->skin->options['hook_extra'] )
			? $upgrader->skin->options['hook_extra']
			: array();

		if ( ! empty( $hook_extra['plugin'] ) ) {
			$plugin_file = (string) $hook_extra['plugin'];
			foreach ( self::get_tracked_option( 'h2wp_plugins' ) as $repo_key => $plugin ) {
				if ( isset( $plugin['plugin_file'] ) && $plugin['plugin_file'] === $plugin_file ) {
					$identity = H2WP_Settings::get_tracked_repo_identity( $repo_key, $plugin );
					return is_wp_error( $identity ) ? '' : strtolower( $identity['owner'] . '/' . $identity['repo'] );
				}
			}
		}

		if ( ! empty( $hook_extra['theme'] ) ) {
			$stylesheet = (string) $hook_extra['theme'];
			foreach ( self::get_tracked_option( 'h2wp_themes' ) as $repo_key => $theme ) {
				if ( isset( $theme['stylesheet'] ) && $theme['stylesheet'] === $stylesheet ) {
					$identity = H2WP_Settings::get_tracked_repo_identity( $repo_key, $theme );
					return is_wp_error( $identity ) ? '' : strtolower( $identity['owner'] . '/' . $identity['repo'] );
				}
			}
		}

		return '';
	}

	/**
	 * Parse a tracked repository key from a GitHub package URL.
	 *
	 * @param string $package Package URL.
	 * @return string
	 */
	private static function get_repo_key_from_package_url( $package ) {
		$path = wp_parse_url( $package, PHP_URL_PATH );
		if ( empty( $path ) ) {
			return '';
		}

		// Standard zipball: /repos/{owner}/{repo}/zipball/....
		if ( preg_match( '#/repos/([^/]+)/([^/]+)/zipball(?:/.*)?$#', $path, $matches ) ) {
			return strtolower( $matches[1] . '/' . $matches[2] );
		}

		// Release asset: /repos/{owner}/{repo}/releases/assets/{id}.
		if ( preg_match( '#/repos/([^/]+)/([^/]+)/releases/assets/#', $path, $matches ) ) {
			return strtolower( $matches[1] . '/' . $matches[2] );
		}

		return '';
	}

	/**
	 * Clean up scheduled events on plugin deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'h2wp_daily_update_check' );
	}
}
