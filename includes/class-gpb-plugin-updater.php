<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Checks for plugin updates and integrates with the WordPress update system.
 */
class GPB_Plugin_Updater {

    /**
     * Initialize the updater.
     */
    public static function init() {
        // Schedule the daily update check if not already scheduled
        if ( ! wp_next_scheduled( 'gpb_daily_update_check' ) ) {
            wp_schedule_event( time(), 'daily', 'gpb_daily_update_check' );
        }

        // Hook into the update check
        add_action( 'gpb_daily_update_check', array( __CLASS__, 'check_for_updates' ) );

        // Filter the update_plugins transient
        add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_plugin_updates' ) );

        // Filter plugin information
        add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 99, 3 );

        // Clean up schedule on plugin deactivation
        register_deactivation_hook( GPB_PLUGIN_DIR . 'github-plugin-browser.php', array( __CLASS__, 'deactivate' ) );
    }

    /**
     * Check for updates for all installed GitHub plugins.
     */
    public static function check_for_updates() {
        $gpb_plugins = get_option( 'gpb_plugins', array() );
        $access_token = GPB_Settings::get_access_token();
        $api = new GPB_GitHub_API( $access_token );

        foreach ( $gpb_plugins as $plugin_id => &$plugin ) {
            list( $owner, $repo ) = explode( '/', $plugin_id );

            // Skip if checked recently (within last 12 hours)
            if ( isset( $plugin['last_checked'] ) && ( time() - $plugin['last_checked'] < 12 * HOUR_IN_SECONDS ) ) {
                continue;
            }

            // Get readme.txt content
            $url = "https://api.github.com/repos/{$owner}/{$repo}/contents/readme.txt";
            $response = wp_remote_get( $url, array(
                'headers' => array(
                    'Accept' => 'application/vnd.github.v3.raw',
                    'Authorization' => $access_token ? "token {$access_token}" : '',
                )
            ) );

            if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
                continue;
            }

            $readme_content = wp_remote_retrieve_body( $response );
            
            // Parse readme content
            $headers = self::parse_readme_headers( $readme_content );
            
            if ( empty( $headers['stable tag'] ) ) {
                continue;
            }

            // Update plugin data
            $plugin['version'] = $headers['stable tag'];
            $plugin['requires'] = isset( $headers['requires at least'] ) ? $headers['requires at least'] : '';
            $plugin['tested'] = isset( $headers['tested up to'] ) ? $headers['tested up to'] : '';
            $plugin['requires_php'] = isset( $headers['requires php'] ) ? $headers['requires php'] : '';
            $plugin['last_checked'] = time();
            
            // Get latest release info
            $release_info = $api->get_repo_details( $owner, $repo );
            if ( ! is_wp_error( $release_info ) ) {
                $plugin['last_updated'] = $release_info['updated_at'];
                $plugin['download_url'] = $api->get_download_url( $owner, $repo );
            }
        }

        update_option( 'gpb_plugins', $gpb_plugins );
    }

    /**
     * Parse readme.txt headers.
     *
     * @param string $content Readme content.
     * @return array Parsed headers.
     */
    private static function parse_readme_headers( $content ) {
        $headers = array();
        $lines = explode( "\n", $content );

		$i = 0;
        foreach ( $lines as $line ) {
			if ( $i++ > 20 ) {
				break;
			}

            $line = trim( $line );
            if ( empty( $line ) || $line[0] === '=' ) {
                continue;
            }

            if ( preg_match( '/^([^:]+):\s*(.+)$/i', $line, $matches ) ) {
                $key = strtolower( trim( $matches[1] ) );
                $value = trim( $matches[2] );
                $headers[ $key ] = $value;
            }
        }

        return $headers;
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

        $gpb_plugins = get_option( 'gpb_plugins', array() );

        foreach ( $gpb_plugins as $plugin_id => $plugin ) {
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
                    'icons'         => array(),
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
	 * @param string            $action The type of information being requested from the Plugin Installation API.
	 * @param object            $args   Plugin API arguments.
	 * @return false|object Plugin information or false if not our plugin.
	 */
	public static function plugin_info( $result, $action, $args ) {
		// Only proceed if we're getting plugin information
		if ( $action !== 'plugin_information' ) {
			return $result;
		}

		$gpb_plugins = get_option( 'gpb_plugins', array() );

		// Find the plugin by slug
		foreach ( $gpb_plugins as $plugin_id => $plugin ) {
			$plugin_slug = dirname( $plugin['plugin_file'] );

			if ( $plugin_slug === $args->slug ) {
				list( $owner, $repo ) = explode( '/', $plugin_id );
				
				$api = new GPB_GitHub_API( GPB_Settings::get_access_token() );
				$repo_details = $api->get_repo_details( $owner, $repo );
				$readme_html = $api->get_readme_html( $owner, $repo );
				$contributors = $api->get_contributors( $owner, $repo );
				$primary_language = $api->get_primary_language( $owner, $repo );
				$watchers = $api->get_watchers_count( $owner, $repo );

				if ( is_wp_error( $repo_details ) || is_wp_error( $readme_html ) ) {
					return $result;
				}

				$info = new stdClass();
				
				// Basic plugin information
				$info->name = isset($plugin['name']) ? $plugin['name'] : $repo;
				$info->slug = $args->slug;
				$info->version = isset($plugin['version']) ? $plugin['version'] : '1.0.0';
				$info->author = sprintf( '<a href="https://github.com/%s">%s</a>', $owner, isset($plugin['author']) ? $plugin['author'] : $owner );
				$info->author_profile = "https://github.com/{$owner}";
				$info->homepage = "https://github.com/{$plugin_id}";
				$info->requires = isset($plugin['requires']) ? $plugin['requires'] : '';
				$info->tested = isset($plugin['tested']) ? $plugin['tested'] : '';
				$info->requires_php = isset($plugin['requires_php']) ? $plugin['requires_php'] : '';
				$info->downloaded = 0;
				$info->last_updated = isset($plugin['last_updated']) ? $plugin['last_updated'] : '';
				
				// Enhanced sections
				$info->sections = array(
					'description' => $readme_html,
					'installation' => self::get_installation_instructions($plugin_id),
					'github' => self::get_github_tab_content($repo_details, $contributors, $primary_language, $watchers),
					'changelog' => self::get_changelog_content($owner, $repo, $api)
				);

				// Add GitHub-specific banners and icons
				$info->banners = array(
					'low' => isset($repo_details['owner']['avatar_url']) ? $repo_details['owner']['avatar_url'] : '',
					'high' => isset($repo_details['owner']['avatar_url']) ? $repo_details['owner']['avatar_url'] : ''
				);
				
				$info->icons = array(
					'default' => isset($repo_details['owner']['avatar_url']) ? $repo_details['owner']['avatar_url'] : '',
					'1x' => isset($repo_details['owner']['avatar_url']) ? $repo_details['owner']['avatar_url'] : '',
					'2x' => isset($repo_details['owner']['avatar_url']) ? $repo_details['owner']['avatar_url'] : ''
				);

				// Additional metadata
				$info->download_link = isset($plugin['download_url']) ? $plugin['download_url'] : '';
				$info->rating = 0;
				$info->num_ratings = 0;
				$info->active_installs = 0;
				$info->contributors = self::format_contributors($contributors);
				
				// GitHub-specific metadata
				$info->github = array(
					'stars' => isset($repo_details['stargazers_count']) ? $repo_details['stargazers_count'] : 0,
					'forks' => isset($repo_details['forks_count']) ? $repo_details['forks_count'] : 0,
					'open_issues' => isset($repo_details['open_issues_count']) ? $repo_details['open_issues_count'] : 0,
					'watchers' => $watchers,
					'language' => $primary_language,
					'last_commit' => isset($repo_details['updated_at']) ? $repo_details['updated_at'] : '',
					'created_at' => isset($repo_details['created_at']) ? $repo_details['created_at'] : '',
					'license' => isset($repo_details['license']['name']) ? $repo_details['license']['name'] : 'Unknown'
				);
				
				// Short description from GitHub
				$info->short_description = isset($repo_details['description']) ? $repo_details['description'] : '';

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
	private static function get_installation_instructions($plugin_id) {
		$instructions = '<h4>Installation via GitHub Plugin Browser</h4>';
		$instructions .= '<ol>';
		$instructions .= '<li>Navigate to <strong>Plugins > GitHub Plugin Browser</strong> in your WordPress admin panel</li>';
		$instructions .= '<li>Search for <strong>' . esc_html($plugin_id) . '</strong></li>';
		$instructions .= '<li>Click the "Install" button</li>';
		$instructions .= '<li>Activate the plugin through the WordPress Plugins menu</li>';
		$instructions .= '</ol>';
		
		$instructions .= '<h4>Manual Installation</h4>';
		$instructions .= '<ol>';
		$instructions .= '<li>Download the latest version from the <a href="https://github.com/' . esc_html($plugin_id) . '/releases">GitHub releases page</a></li>';
		$instructions .= '<li>Upload the plugin files to your <code>/wp-content/plugins/</code> directory</li>';
		$instructions .= '<li>Activate the plugin through the WordPress Plugins menu</li>';
		$instructions .= '</ol>';
		
		return $instructions;
	}

	/**
	 * Format GitHub tab content.
	 *
	 * @param array  $repo_details Repository details.
	 * @param array  $contributors Contributors list.
	 * @param string $primary_language Primary programming language.
	 * @param int    $watchers Number of watchers.
	 * @return string Formatted GitHub information.
	 */
	private static function get_github_tab_content($repo_details, $contributors, $primary_language, $watchers) {
		$content = '<div class="github-info">';
		
		// Repository Statistics
		$content .= '<h3>Repository Statistics</h3>';
		$content .= '<ul class="github-stats">';
		$content .= '<li>⭐ Stars: ' . number_format_i18n($repo_details['stargazers_count']) . '</li>';
		$content .= '<li>🔀 Forks: ' . number_format_i18n($repo_details['forks_count']) . '</li>';
		$content .= '<li>👀 Watchers: ' . number_format_i18n($watchers) . '</li>';
		$content .= '<li>❗ Open Issues: ' . number_format_i18n($repo_details['open_issues_count']) . '</li>';
		$content .= '</ul>';

		// Technical Details
		$content .= '<h3>Technical Details</h3>';
		$content .= '<ul class="github-technical">';
		$content .= '<li>Primary Language: ' . esc_html($primary_language) . '</li>';
		$content .= '<li>License: ' . esc_html(isset($repo_details['license']['name']) ? $repo_details['license']['name'] : 'Unknown') . '</li>';
		$content .= '<li>Created: ' . human_time_diff(strtotime($repo_details['created_at'])) . ' ago</li>';
		$content .= '<li>Last Updated: ' . human_time_diff(strtotime($repo_details['updated_at'])) . ' ago</li>';
		$content .= '</ul>';

		// Contributors section
		if (!empty($contributors)) {
			$content .= '<h3>Top Contributors</h3>';
			$content .= '<div class="github-contributors">';
			foreach ($contributors as $contributor) {
				$content .= sprintf(
					'<a href="%s" title="%s" target="_blank"><img src="%s" alt="%s" width="40" height="40" style="border-radius: 50%%; margin: 5px; width: 40px; height: 40px;"></a>',
					esc_url($contributor['html_url']),
					esc_attr($contributor['login']),
					esc_url($contributor['avatar_url']),
					esc_attr($contributor['login'])
				);
			}
			$content .= '</div>';
		}

		// Quick Links
		$content .= '<h3>Quick Links</h3>';
		$content .= '<ul class="github-links">';
		$content .= '<li><a href="' . esc_url($repo_details['html_url']) . '" target="_blank">View on GitHub</a></li>';
		$content .= '<li><a href="' . esc_url($repo_details['html_url'] . '/issues') . '" target="_blank">Issue Tracker</a></li>';
		$content .= '<li><a href="' . esc_url($repo_details['html_url'] . '/releases') . '" target="_blank">Releases</a></li>';
		if (!empty($repo_details['wiki'])) {
			$content .= '<li><a href="' . esc_url($repo_details['html_url'] . '/wiki') . '" target="_blank">Documentation Wiki</a></li>';
		}
		$content .= '</ul>';

		$content .= '</div>';
		return $content;
	}

	/**
	 * Format changelog content from GitHub releases.
	 *
	 * @param string $owner Repository owner.
	 * @param string $repo Repository name.
	 * @param GPB_GitHub_API $api GitHub API instance.
	 * @return string Formatted changelog content.
	 */
	private static function get_changelog_content($owner, $repo, $api) {
		$url = "https://api.github.com/repos/{$owner}/{$repo}/releases";
		$response = wp_remote_get($url, array(
			'headers' => array(
				'Accept' => 'application/vnd.github.v3+json',
				'Authorization' => 'token ' . GPB_Settings::get_access_token(),
			)
		));

		if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
			return '<p>No changelog information available.</p>';
		}

		$releases = json_decode(wp_remote_retrieve_body($response), true);
		if (empty($releases)) {
			return '<p>No release information available.</p>';
		}

		$changelog = '';
		foreach ($releases as $release) {
			$changelog .= '<h4>' . esc_html($release['tag_name']) . ' - ' . 
						date('F j, Y', strtotime($release['published_at'])) . '</h4>';
			
			if (!empty($release['body'])) {
				$changelog .= '<div class="release-notes">';
				//$changelog .= wp_kses_post(Parsedown::instance()->text($release['body']));
				$changelog .= wp_kses_post($release['body']);
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
	private static function format_contributors($contributors) {
		$formatted = array();
		foreach ($contributors as $contributor) {
			$formatted[$contributor['login']] = array(
				'profile' => $contributor['html_url'],
				'avatar' => $contributor['avatar_url'],
				'display_name' => $contributor['login']
			);
		}
		return $formatted;
	}

    /**
     * Clean up scheduled events on plugin deactivation.
     */
    public static function deactivate() {
        wp_clear_scheduled_hook( 'gpb_daily_update_check' );
    }
}