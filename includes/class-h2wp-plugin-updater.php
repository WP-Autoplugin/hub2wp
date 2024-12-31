<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks for plugin updates and integrates with the WordPress update system.
 */
class H2WP_Plugin_Updater {

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

		// Filter plugin information
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 99, 3 );
	}

	/**
	 * Check for updates for all installed GitHub plugins.
	 */
	public static function check_for_updates() {
		$h2wp_plugins = get_option( 'h2wp_plugins', array() );
		$api = new H2WP_GitHub_API( H2WP_Settings::get_access_token() );
		$updated = false;

		foreach ( $h2wp_plugins as $plugin_id => &$plugin ) {
			list( $owner, $repo ) = explode( '/', $plugin_id );

			// Get readme headers
			$headers = $api->get_readme_headers( $owner, $repo );
			if ( is_wp_error( $headers ) || empty( $headers['stable tag'] ) ) {
				continue;
			}

			// Update plugin data
			$plugin['version']      = $headers['stable tag'];
			$plugin['requires']     = isset( $headers['requires at least'] ) ? $headers['requires at least'] : '';
			$plugin['tested']       = isset( $headers['tested up to'] ) ? $headers['tested up to'] : '';
			$plugin['requires_php'] = isset( $headers['requires php'] ) ? $headers['requires php'] : '';
			$plugin['last_checked'] = time();
			$plugin['download_url'] = $api->get_download_url( $owner, $repo );

			$updated = true;
		}

		if ( $updated ) {
			update_option( 'h2wp_plugins', $h2wp_plugins );
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

		$h2wp_plugins = get_option( 'h2wp_plugins', array() );

		foreach ( $h2wp_plugins as $plugin_id => $plugin ) {
			if ( empty( $plugin['plugin_file'] ) || empty( $plugin['version'] ) ) {
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
				$watchers     = $api->get_watchers_count( $owner, $repo );
				$og_image     = $api->get_og_image( $owner, $repo );

				if ( is_wp_error( $repo_details ) || is_wp_error( $readme_html ) ) {
					return $result;
				}

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
					'language'     => esc_html( $primary_language ),
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
	 * @return string Formatted installation instructions.
	 */
	public static function get_installation_instructions( $plugin_id = '' ) {
		$instructions  = '<h4>' . esc_html__( 'Installation via hub2wp', 'hub2wp' ) . '</h4>';
		$instructions .= '<ol>';
		$instructions .= '<li>' . sprintf(
			/* translators: %s: Plugin navigation path */
			__( 'Navigate to <strong>%s</strong> in your WordPress admin panel', 'hub2wp' ),
			'<strong>' . esc_html__( 'Plugins &rarr; Add GitHub Plugin', 'hub2wp' ) . '</strong>'
		) . '</li>';
		$instructions .= '<li>' . sprintf(
			/* translators: %s: Plugin identifier */
			__( 'Search for <strong class="h2wp-modal-title">%s</strong>', 'hub2wp' ),
			esc_html( $plugin_id )
		) . '</li>';
		$instructions .= '<li>' . esc_html__( 'Click the "Install" button', 'hub2wp' ) . '</li>';
		$instructions .= '<li>' . esc_html__( 'Activate the plugin through the WordPress Plugins menu', 'hub2wp' ) . '</li>';
		$instructions .= '</ol>';

		$instructions .= '<h4>' . esc_html__( 'Manual Installation', 'hub2wp' ) . '</h4>';
		$instructions .= '<ol>';
		$instructions .= '<li>' . esc_html__( 'Download the latest release from the GitHub repository', 'hub2wp' ) . '</li>';
		$instructions .= '<li>' . sprintf(
			/* translators: %s: Directory path */
			__( 'Upload the plugin files to your <code>%s</code> directory', 'hub2wp' ),
			'/wp-content/plugins/'
		) . '</li>';
		$instructions .= '<li>' . esc_html__( 'Activate the plugin through the WordPress Plugins menu', 'hub2wp' ) . '</li>';
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
				'plugin_file'  => H2WP_PLUGIN_FILE,
				'requires'     => '5.5',
				'tested'       => '6.7.1',
				'requires_php' => '7.0',
				'last_checked' => time(),
				'last_updated' => '',
				'download_url' => 'https://api.github.com/repos/wp-autoplugin/hub2wp/zipball',
			);
			update_option( 'h2wp_plugins', $h2wp_sources );
		}
	}

	/**
	 * Clean up scheduled events on plugin deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'h2wp_daily_update_check' );
	}
}
