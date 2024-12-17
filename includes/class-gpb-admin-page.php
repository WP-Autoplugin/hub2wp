<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates the admin page for browsing GitHub plugins.
 */
class GPB_Admin_Page {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_install_action' ) );
	}

	/**
	 * Add admin menu page.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'plugins.php',
			__( 'GitHub Plugin Browser', 'github-plugin-installer' ),
			__( 'GitHub Browser', 'github-plugin-installer' ),
			'install_plugins',
			'gpb-plugin-browser',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Handle the install action.
	 */
	public static function handle_install_action() {
		if ( isset( $_GET['gpb_install'] ) && check_admin_referer( 'gpb_install_plugin' ) && current_user_can( 'install_plugins' ) ) {
			$owner = sanitize_text_field( $_GET['owner'] );
			$repo  = sanitize_text_field( $_GET['repo'] );

			if ( ! empty( $owner ) && ! empty( $repo ) ) {
				if ( self::is_plugin_installed( $owner, $repo ) ) {
					add_action( 'admin_notices', function() {
						echo '<div class="notice notice-error"><p>' . esc_html__( 'Plugin is already installed.', 'github-plugin-installer' ) . '</p></div>';
					} );
				} else {
					self::install_plugin_from_github( $owner, $repo );
				}
			}
		}
	}

	/**
	 * Check if a plugin is installed.
	 *
	 * @param string $owner Owner name.
	 * @param string $repo  Repo name.
	 * @return bool True if installed, false otherwise.
	 */
	private static function is_plugin_installed( $owner, $repo ) {
		$plugins = get_plugins();
		foreach ( $plugins as $plugin_file => $plugin_data ) {
			// Check if the plugin is installed by loosely comparing the plugin name or the plugin folder name.
			$plugin_name = sanitize_title( $plugin_data['Name'] );
			$repo_name   = sanitize_title( $repo );
			if ( false !== strpos( $plugin_name, $repo_name ) || false !== strpos( $repo_name, $plugin_name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Install plugin from GitHub.
	 *
	 * @param string $owner Owner name.
	 * @param string $repo  Repo name.
	 */
	private static function install_plugin_from_github( $owner, $repo ) {
		$api          = new GPB_GitHub_API( GPB_Settings::get_access_token() );
		$download_url = $api->get_latest_release_zip( $owner, $repo );
		if ( is_wp_error( $download_url ) ) {
			add_action( 'admin_notices', function() use ( $download_url ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $download_url->get_error_message() ) . '</p></div>';
			} );
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$upgrader = new Plugin_Upgrader();
		$result   = $upgrader->install( $download_url );

		if ( is_wp_error( $result ) || ! $result ) {
			add_action( 'admin_notices', function() {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Failed to install the plugin.', 'github-plugin-installer' ) . '</p></div>';
			} );
			return;
		}

		add_action( 'admin_notices', function() {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Plugin installed successfully.', 'github-plugin-installer' ) . '</p></div>';
		} );
	}

	/**
	 * Render the admin page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'github-plugin-installer' ) );
		}
	
		$access_token = GPB_Settings::get_access_token();
		$api = new GPB_GitHub_API( $access_token );
	
		$query = 'topic:wordpress-plugin';
		$user_query = '';
		if ( isset( $_GET['s'] ) && ! empty( $_GET['s'] ) ) {
			$user_query = sanitize_text_field( $_GET['s'] );
			$query = $user_query . ' topic:wordpress-plugin';
		}
	
		if ( isset( $_GET['tag'] ) && ! empty( $_GET['tag'] ) ) {
			$tag = sanitize_text_field( $_GET['tag'] );
			$query .= ' topic:' . $tag;
		}
	
		$page = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$results = $api->search_plugins( $query, $page );
	
		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'GitHub Plugin Browser', 'github-plugin-installer' ) . '</h1>';
	
		// Top bar with tags and search
		echo '<div class="gpb-top-bar">';
		echo '<div class="gpb-popular-tags">';
		$popular_tags = array('woocommerce', 'seo', 'security', 'social', 'forms');
		foreach ($popular_tags as $tag) {
			echo '<a href="' . esc_url(add_query_arg('tag', $tag)) . '" class="gpb-tag ' . ($tag === $_GET['tag'] ? 'gpb-tag-active' : '') . '">' . esc_html($tag) . '</a>';
		}
		echo '</div>';
	
		echo '<form method="get" class="gpb-search-form">';
		echo '<input type="hidden" name="page" value="gpb-plugin-browser" />';
		echo '<input type="search" name="s" value="' . esc_attr($user_query) . '" placeholder="' . esc_attr__('Search plugins...', 'github-plugin-installer') . '" />';
		submit_button( __( 'Search', 'github-plugin-installer' ), 'primary', 'search', false );
		echo '</form>';
		echo '</div>';
	
		if ( ! empty( $results['items'] ) ) {
			echo '<div class="gpb-plugins-grid">';
			foreach ( $results['items'] as $item ) {
				self::render_plugin_card($item);
			}
			echo '</div>';
	
			// Pagination
			if ( $results['total_count'] > 10 ) {
				$total_pages = ceil( $results['total_count'] / 10 );
				echo '<div class="tablenav bottom">';
				echo '<div class="tablenav-pages gpb-pagination">';
				echo paginate_links( array(
					'base' => add_query_arg( 'paged', '%#%' ),
					'format' => '',
					'prev_text' => __('&laquo;'),
					'next_text' => __('&raquo;'),
					'total' => $total_pages,
					'current' => $page
				) );
				echo '</div>';
				echo '</div>';
			}
		} else {
			echo '<div class="no-plugin-results">';
			echo '<p>' . esc_html__( 'No plugins found. Try a different search.', 'github-plugin-installer' ) . '</p>';
			echo '</div>';
		}
		echo '</div>';
	}
	
	private static function render_plugin_card( $item ) {
		$name = $item['name'];
		$description = isset( $item['description'] ) ? $item['description'] : '';
		$owner = isset($item['owner']['login']) ? $item['owner']['login'] : '';
		$avatar = isset($item['owner']['avatar_url']) ? $item['owner']['avatar_url'] : '';
		$stars = isset($item['stargazers_count']) ? number_format($item['stargazers_count']) : 0;
		$forks = isset($item['forks_count']) ? number_format($item['forks_count']) : 0;
		$updated = isset($item['updated_at']) ? human_time_diff(strtotime($item['updated_at'])) . ' ago' : '';
	
		$install_url = wp_nonce_url(
			add_query_arg(
				array(
					'page' => 'gpb-plugin-browser',
					'gpb_install' => 1,
					'owner' => $owner,
					'repo' => $name,
				),
				admin_url('plugins.php')
			),
			'gpb_install_plugin'
		);
	
		echo '<div class="gpb-plugin-card">';
		echo '<div class="gpb-plugin-header">';
		echo '<div class="gpb-plugin-icon">';
		if ($avatar) {
			echo '<img src="' . esc_url($avatar) . '" alt="" />';
		} else {
			echo '<div class="gpb-plugin-icon-placeholder"></div>';
		}
		echo '</div>';
		
		echo '<div class="gpb-plugin-info">';
		echo '<h3 class="gpb-plugin-name">' . esc_html($name) . '</h3>';
		echo '<div class="gpb-plugin-author">By <a href="https://github.com/' . esc_attr($owner) . '">' . esc_html($owner) . '</a></div>';
		echo '</div>';
		echo '</div>';
	
		echo '<div class="gpb-plugin-description">' . esc_html(wp_trim_words($description, 20)) . '</div>';
	
		echo '<div class="gpb-plugin-actions">';
		if (self::is_plugin_installed($owner, $name)) {
			echo '<span class="gpb-button gpb-button-disabled">' . esc_html__('Installed', 'github-plugin-installer') . '</span>';
		} else {
			echo '<a href="' . esc_url($install_url) . '" class="gpb-button gpb-button-primary">' . esc_html__( 'Install Now', 'github-plugin-installer' ) . '</a>';
		}
		echo '<a href="' . esc_url($item['html_url']) . '" class="gpb-more-details-link" target="_blank">' . esc_html__( 'More Details', 'github-plugin-installer' ) . '</a>';
		echo '</div>';
	
		echo '<div class="gpb-plugin-meta">';
		echo '<div class="gpb-meta-stats">';
		echo '<span class="gpb-meta-stat"><svg viewBox="0 0 16 16"><path fill-rule="evenodd" d="M8 .25a.75.75 0 01.673.418l1.882 3.815 4.21.612a.75.75 0 01.416 1.279l-3.046 2.97.719 4.192a.75.75 0 01-1.088.791L8 12.347l-3.766 1.98a.75.75 0 01-1.088-.79l.72-4.194L.818 6.374a.75.75 0 01.416-1.28l4.21-.611L7.327.668A.75.75 0 018 .25z"></path></svg>' . esc_html( $stars ) . '</span>';
		echo '<span class="gpb-meta-stat"><svg viewBox="0 0 16 16"><path fill-rule="evenodd" d="M5 3.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm0 2.122a2.25 2.25 0 10-1.5 0v.878A2.25 2.25 0 005.75 8.5h1.5v2.128a2.251 2.251 0 101.5 0V8.5h1.5a2.25 2.25 0 002.25-2.25v-.878a2.25 2.25 0 10-1.5 0v.878a.75.75 0 01-.75.75h-4.5A.75.75 0 015 6.25v-.878zm3.75 7.378a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm3-8.75a.75.75 0 100-1.5.75.75 0 000 1.5z"></path></svg>' . esc_html( $forks ) . '</span>';
		echo '</div>';
		echo '<span class="gpb-meta-stat gpb-meta-updated"><svg viewBox="0 0 16 16" title="' . esc_attr( $updated ) . '">';
		echo '<path fill-rule="evenodd" d="M8 1.5a6.5 6.5 0 100 13 6.5 6.5 0 000-13zM0 8a8 8 0 1116 0A8 8 0 010 8zm8.5-4a.5.5 0 00-1 0v4a.5.5 0 00.146.354l2.5 2.5a.5.5 0 00.708-.708L8.5 7.793V4z"></path></svg>' . esc_html( $updated ) . '</span>';
		echo '</div>';
		echo '</div>';
	}
}
