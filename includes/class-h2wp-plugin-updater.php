<?php
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
		// Schedule the daily update check if not already scheduled
		if ( ! wp_next_scheduled( 'h2wp_daily_update_check' ) ) {
			wp_schedule_event( time(), 'daily', 'h2wp_daily_update_check' );
		}

		// Hook into the update check
		add_action( 'h2wp_daily_update_check', array( __CLASS__, 'check_for_updates' ) );

		// Filter the update_plugins transient
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_plugin_updates' ) );
		add_filter( 'pre_set_site_transient_update_themes', array( __CLASS__, 'inject_theme_updates' ) );

		// Filter plugin information
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
			error_log( '[hub2wp] ' . $message );
		}
	}

	/**
	 * Check for updates for all monitored GitHub plugins and themes.
	 */
	public static function check_for_updates() {
		$h2wp_plugins = get_option( 'h2wp_plugins', array() );
		$h2wp_themes  = get_option( 'h2wp_themes', array() );
		$api = new H2WP_GitHub_API( H2WP_Settings::get_access_token() );
		$plugins_updated = false;
		$themes_updated  = false;
		$now             = time();

		foreach ( $h2wp_plugins as $plugin_id => &$plugin ) {
			list( $owner, $repo ) = explode( '/', $plugin_id );

			// Get readme headers
			$headers = $api->get_readme_headers( $owner, $repo );
			if ( is_wp_error( $headers ) || empty( $headers['stable tag'] ) ) {
				if ( is_wp_error( $headers ) ) {
					self::log_debug( sprintf( 'Plugin update check failed for %s: %s', $plugin_id, $headers->get_error_message() ) );
				}
				continue;
			}

			// Update plugin data
			$plugin['version']      = $headers['stable tag'];
			$plugin['requires']     = isset( $headers['requires at least'] ) ? $headers['requires at least'] : '';
			$plugin['tested']       = isset( $headers['tested up to'] ) ? $headers['tested up to'] : '';
			$plugin['requires_php'] = isset( $headers['requires php'] ) ? $headers['requires php'] : '';
			$plugin['last_checked'] = $now;
			$plugin['download_url'] = $api->get_download_url( $owner, $repo );

			$plugins_updated = true;
		}
		unset( $plugin );

		if ( $plugins_updated ) {
			update_option( 'h2wp_plugins', $h2wp_plugins );
		}

		foreach ( $h2wp_themes as $theme_id => &$theme ) {
			list( $owner, $repo ) = explode( '/', $theme_id );

			$headers = $api->get_theme_headers( $owner, $repo );
			if ( is_wp_error( $headers ) || empty( $headers['version'] ) ) {
				if ( is_wp_error( $headers ) ) {
					self::log_debug( sprintf( 'Theme update check failed for %s: %s', $theme_id, $headers->get_error_message() ) );
				}
				continue;
			}

			$theme['version']      = $headers['version'];
			$theme['requires']     = isset( $headers['requires at least'] ) ? $headers['requires at least'] : '';
			$theme['tested']       = isset( $headers['tested up to'] ) ? $headers['tested up to'] : '';
			$theme['requires_php'] = isset( $headers['requires php'] ) ? $headers['requires php'] : '';
			$theme['last_checked'] = $now;
			$theme['download_url'] = $api->get_download_url( $owner, $repo );

			if ( empty( $theme['stylesheet'] ) ) {
				$theme['stylesheet'] = H2WP_Admin_Page::get_installed_theme_stylesheet( $owner, $repo );
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

		$h2wp_plugins = get_option( 'h2wp_plugins', array() );

		foreach ( $h2wp_plugins as $plugin_id => $plugin ) {
			if ( empty( $plugin['plugin_file'] ) || empty( $plugin['version'] ) ) {
				continue;
			}

			if ( ! file_exists( WP_PLUGIN_DIR . '/' . $plugin['plugin_file'] ) ) {
				continue;
			}

			$installed_version = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin['plugin_file'] )['Version'];

			if ( version_compare( $installed_version, $plugin['version'], '<' ) ) {
				$item = (object) array(
					'id'            => $plugin_id,
					'slug'          => dirname( $plugin['plugin_file'] ),
					'plugin'        => $plugin['plugin_file'],
					'new_version'   => $plugin['version'],
					'url'           => "https://github.com/{$plugin_id}",
					'package'       => $plugin['download_url'],
					'icons'         => ! empty( $plugin['owner_avatar_url'] ) ? array( '1x' => $plugin['owner_avatar_url'], '2x' => $plugin['owner_avatar_url'] ) : array(),
					'banners'       => array(),
					'banners_rtl'   => array(),
					'tested'        => $plugin['tested'],
					'requires_php'  => $plugin['requires_php'],
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

		$h2wp_themes = get_option( 'h2wp_themes', array() );
		$themes      = wp_get_themes();

		foreach ( $h2wp_themes as $theme_id => $theme ) {
			if ( empty( $theme['version'] ) ) {
				continue;
			}

			$stylesheet = isset( $theme['stylesheet'] ) ? $theme['stylesheet'] : '';
			if ( empty( $stylesheet ) || ! isset( $themes[ $stylesheet ] ) ) {
				list( $owner, $repo ) = explode( '/', $theme_id );
				$stylesheet = H2WP_Admin_Page::get_installed_theme_stylesheet( $owner, $repo );
			}

			if ( empty( $stylesheet ) || ! isset( $themes[ $stylesheet ] ) ) {
				continue;
			}

			$installed_version = $themes[ $stylesheet ]->get( 'Version' );
			if ( version_compare( $installed_version, $theme['version'], '<' ) ) {
				$transient->response[ $stylesheet ] = array(
					'theme'       => $stylesheet,
					'new_version' => $theme['version'],
					'url'         => "https://github.com/{$theme_id}",
					'package'     => isset( $theme['download_url'] ) ? $theme['download_url'] : '',
					'requires'    => isset( $theme['requires'] ) ? $theme['requires'] : '',
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
		if ( $action !== 'plugin_information' ) {
			return $result;
		}

		$h2wp_plugins = get_option( 'h2wp_plugins', array() );

		// Find the plugin by slug.
		foreach ( $h2wp_plugins as $plugin_id => $plugin ) {
			$plugin_slug = dirname( $plugin['plugin_file'] );

			if ( $plugin_slug === $args->slug ) {
				list( $owner, $repo ) = explode( '/', $plugin_id );

				$api          = new H2WP_GitHub_API( H2WP_Settings::get_access_token() );
				$repo_details = $api->get_repo_details( $owner, $repo );
				$readme_html  = $api->get_readme_html( $owner, $repo );

				if ( is_wp_error( $repo_details ) || is_wp_error( $readme_html ) ) {
					return $result;
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
				$info->homepage       = esc_url( "https://github.com/{$plugin_id}" );
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
					'changelog'    => self::get_changelog_content( $owner, $repo, $api ),
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
					'stars'        => isset( $repo_details['stargazers_count'] ) ? intval( $repo_details['stargazers_count'] ) : 0,
					'forks'        => isset( $repo_details['forks_count'] ) ? intval( $repo_details['forks_count'] ) : 0,
					'open_issues'  => isset( $repo_details['open_issues_count'] ) ? intval( $repo_details['open_issues_count'] ) : 0,
					'watchers'     => intval( $watchers ),
					'language'     => isset( $repo_details['language'] ) ? esc_html( $repo_details['language'] ) : '',
					'last_commit'  => isset( $repo_details['updated_at'] ) ? esc_html( $repo_details['updated_at'] ) : '',
					'created_at'   => isset( $repo_details['created_at'] ) ? esc_html( $repo_details['created_at'] ) : '',
					'license'      => esc_html( isset( $repo_details['license']['name'] ) ? $repo_details['license']['name'] : __( 'Unknown', 'hub2wp' ) ),
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

		$is_theme    = ( 'theme' === $repo_type );
		$admin_path  = $is_theme ? esc_html__( 'Appearance &rarr; Themes &rarr; GitHub Themes', 'hub2wp' ) : esc_html__( 'Plugins &rarr; Add GitHub Plugin', 'hub2wp' );
		$install_verb = $is_theme ? esc_html__( 'theme', 'hub2wp' ) : esc_html__( 'plugin', 'hub2wp' );
		$manual_path = $is_theme ? '/wp-content/themes/' : '/wp-content/plugins/';

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
	 * @param array  $repo_details      Repository details.
	 * @param int    $watchers          Number of watchers.
	 * @return string Formatted GitHub information.
	 */
	private static function get_github_tab_content( $repo_details, $watchers ) {
		$content  = '<div class="github-info">';

		// Repository Statistics.
		$content .= '<h3>' . esc_html__( 'Repository Statistics', 'hub2wp' ) . '</h3>';
		$content .= '<ul class="github-stats">';
		$content .= '<li>⭐ ' . esc_html__( 'Stars:', 'hub2wp' ) . ' ' . number_format_i18n( $repo_details['stargazers_count'] ) . '</li>';
		$content .= '<li>🔀 ' . esc_html__( 'Forks:', 'hub2wp' ) . ' ' . number_format_i18n( $repo_details['forks_count'] ) . '</li>';
		$content .= '<li>👀 ' . esc_html__( 'Watchers:', 'hub2wp' ) . ' ' . number_format_i18n( $watchers ) . '</li>';
		$content .= '<li>❗ ' . esc_html__( 'Open Issues:', 'hub2wp' ) . ' ' . number_format_i18n( $repo_details['open_issues_count'] ) . '</li>';
		$content .= '</ul>';

		// Technical Details.
		$content .= '<h3>' . esc_html__( 'Technical Details', 'hub2wp' ) . '</h3>';
		$content .= '<ul class="github-technical">';
		$content .= '<li>' . esc_html__( 'License:', 'hub2wp' ) . ' ' . esc_html( isset( $repo_details['license']['name'] ) ? $repo_details['license']['name'] : __( 'Unknown', 'hub2wp' ) ) . '</li>';
		$content .= '<li>' . esc_html__( 'Created:', 'hub2wp' ) . ' ' . human_time_diff( strtotime( $repo_details['created_at'] ) ) . ' ' . esc_html__( 'ago', 'hub2wp' ) . '</li>';
		$content .= '<li>' . esc_html__( 'Last Updated:', 'hub2wp' ) . ' ' . human_time_diff( strtotime( $repo_details['updated_at'] ) ) . ' ' . esc_html__( 'ago', 'hub2wp' ) . '</li>';
		$content .= '</ul>';

		// Quick Links.
		$content .= '<h3>' . esc_html__( 'Quick Links', 'hub2wp' ) . '</h3>';
		$content .= '<ul class="github-links">';
		$content .= '<li><a href="' . esc_url( $repo_details['html_url'] ) . '" target="_blank">' . esc_html__( 'View on GitHub', 'hub2wp' ) . '</a></li>';
		$content .= '<li><a href="' . esc_url( $repo_details['html_url'] . '/issues' ) . '" target="_blank">' . esc_html__( 'Issue Tracker', 'hub2wp' ) . '</a></li>';
		$content .= '<li><a href="' . esc_url( $repo_details['html_url'] . '/releases' ) . '" target="_blank">' . esc_html__( 'Releases', 'hub2wp' ) . '</a></li>';
		if ( ! empty( $repo_details['wiki'] ) ) {
			$content .= '<li><a href="' . esc_url( $repo_details['html_url'] . '/wiki' ) . '" target="_blank">' . esc_html__( 'Documentation Wiki', 'hub2wp' ) . '</a></li>';
		}
		$content .= '</ul>';

		$content .= '</div>';

		return $content;
	}

	/**
	 * Format changelog content from GitHub releases.
	 *
	 * @param string         $owner Repository owner.
	 * @param string         $repo  Repository name.
	 * @param H2WP_GitHub_API $api   GitHub API instance.
	 * @return string Formatted changelog content.
	 */
	private static function get_changelog_content( $owner, $repo, $api ) {
		$url      = "https://api.github.com/repos/{$owner}/{$repo}/releases";
		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Accept'        => 'application/vnd.github.v3+json',
					'Authorization' => 'token ' . H2WP_Settings::get_access_token(),
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return '<p>' . esc_html__( 'No changelog information available.', 'hub2wp' ) . '</p>';
		}

		$releases = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $releases ) ) {
			return '<p>' . esc_html__( 'No release information available.', 'hub2wp' ) . '</p>';
		}

		$changelog = '';
		foreach ( $releases as $release ) {
			$changelog .= '<h4>' . esc_html( $release['tag_name'] ) . ' - ' . esc_html( date( 'F j, Y', strtotime( $release['published_at'] ) ) ) . '</h4>';

			if ( ! empty( $release['body'] ) ) {
				$changelog .= '<div class="release-notes">';
				//$changelog .= wp_kses_post( Parsedown::instance()->text( $release['body'] ) );
				$changelog .= wp_kses_post( $release['body'] );
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
		$h2wp_sources = get_option( 'h2wp_plugins', array() );
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

		$is_plugin      = ! empty( $hook_extra['plugin'] );
		$correct_folder = '';

		if ( $is_plugin ) {
			$plugin_file = $hook_extra['plugin'];
			$h2wp_plugins = get_option( 'h2wp_plugins', array() );
			$found        = false;
			foreach ( $h2wp_plugins as $plugin ) {
				if ( isset( $plugin['plugin_file'] ) && $plugin['plugin_file'] === $plugin_file ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				return $source;
			}
			$correct_folder = dirname( $plugin_file );
		} else {
			$stylesheet  = $hook_extra['theme'];
			$h2wp_themes = get_option( 'h2wp_themes', array() );
			$found       = false;
			foreach ( $h2wp_themes as $theme ) {
				if ( isset( $theme['stylesheet'] ) && $theme['stylesheet'] === $stylesheet ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				return $source;
			}
			$correct_folder = $stylesheet;
		}

		$new_source     = trailingslashit( $remote_source ) . $correct_folder;

		// Nothing to do if it already has the right name.
		if ( trailingslashit( $new_source ) === trailingslashit( $source ) ) {
			return $source;
		}

		if ( ! $wp_filesystem->move( untrailingslashit( $source ), $new_source ) ) {
			return new WP_Error(
				'h2wp_rename_error',
				sprintf(
					/* translators: 1: extracted folder, 2: expected folder */
					__( 'Could not rename extracted folder from "%1$s" to "%2$s".', 'hub2wp' ),
					basename( $source ),
					$correct_folder
				)
			);
		}

		return trailingslashit( $new_source );
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

		// Only intercept GitHub API zipball URLs.
		if ( false === strpos( $package, 'api.github.com/repos/' ) ) {
			return $reply;
		}

		$access_token = H2WP_Settings::get_access_token();
		if ( empty( $access_token ) ) {
			return $reply;
		}

		// Only intercept packages that belong to one of our tracked private repos.
		$h2wp_plugins = get_option( 'h2wp_plugins', array() );
		$h2wp_themes  = get_option( 'h2wp_themes', array() );
		$is_private   = false;
		foreach ( $h2wp_plugins as $plugin ) {
			if (
				isset( $plugin['download_url'] ) &&
				$plugin['download_url'] === $package &&
				! empty( $plugin['private'] )
			) {
				$is_private = true;
				break;
			}
		}
		if ( ! $is_private ) {
			foreach ( $h2wp_themes as $theme ) {
				if (
					isset( $theme['download_url'] ) &&
					$theme['download_url'] === $package &&
					! empty( $theme['private'] )
				) {
					$is_private = true;
					break;
				}
			}
		}

		if ( ! $is_private ) {
			return $reply;
		}

		// Stream the zip to a temp file with the Authorization header.
		$tmpfname = wp_tempnam( $package );

		$response = wp_remote_get(
			$package,
			array(
				'timeout'     => 300,
				'stream'      => true,
				'filename'    => $tmpfname,
				'redirection' => 5,
				'headers'     => array(
					'Authorization' => 'token ' . $access_token,
					'Accept'        => 'application/vnd.github+json',
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
	 * Clean up scheduled events on plugin deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'h2wp_daily_update_check' );
	}
}
