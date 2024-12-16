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

		$page = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$results = $api->search_plugins( $query, $page );

		$items = array();
		$total = 0;
		if ( ! is_wp_error( $results ) && isset( $results['items'] ) ) {
			$items = $results['items'];
			$total = isset( $results['total_count'] ) ? (int) $results['total_count'] : 0;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'GitHub Plugin Browser', 'github-plugin-installer' ) . '</h1>';
		
		echo '<form method="get" class="plugin-search-form">';
		echo '<input type="hidden" name="page" value="gpb-plugin-browser" />';
		echo '<input type="search" name="s" value="' . ( $user_query ? esc_attr( $user_query ) : '' ) . '" placeholder="' . esc_attr__( 'Search GitHub plugins...', 'github-plugin-installer' ) . '" />';
		submit_button( __( 'Search', 'github-plugin-installer' ), 'primary', false );
		echo '</form>';

		if ( ! empty( $items ) ) {
			echo '<div class="wp-list-table widefat plugin-install">';
			foreach ( $items as $item ) {
				$avatar = ! empty( $item['owner']['avatar_url'] ) ? $item['owner']['avatar_url'] : '';
				$name = $item['name'];
				$description = isset( $item['description'] ) ? $item['description'] : '';
				$stars = isset( $item['stargazers_count'] ) ? absint( $item['stargazers_count'] ) : 0;
				$forks = isset( $item['forks_count'] ) ? absint( $item['forks_count'] ) : 0;
				$owner = isset( $item['owner']['login'] ) ? $item['owner']['login'] : '';
				$homepage = isset( $item['homepage'] ) && $item['homepage'] ? $item['homepage'] : $item['html_url'];
				$updated = isset( $item['updated_at'] ) ? mysql2date( get_option( 'date_format' ), $item['updated_at'] ) : '';
				$license_name = isset( $item['license']['name'] ) ? $item['license']['name'] : '';
				$install_url = wp_nonce_url(
					add_query_arg(
						array(
							'page'        => 'gpb-plugin-browser',
							'gpb_install' => 1,
							'owner'       => $owner,
							'repo'        => $name,
						),
						admin_url( 'plugins.php' )
					),
					'gpb_install_plugin'
				);

				echo '<div class="gpb-plugin-card">';
				echo '<div class="gpb-plugin-card-top">';
				
				// Plugin Icon
				echo '<div class="gpb-plugin-icon">';
				if ( $avatar ) {
					echo '<img src="' . esc_url( $avatar ) . '" alt="' . esc_attr( $name ) . ' logo" />';
				} else {
					echo '<div class="default-icon">' . esc_html( substr( $name, 0, 2 ) ) . '</div>';
				}
				echo '</div>';

				// Plugin Name and Owner
				echo '<div class="name column-name">';
				echo '<h3>' . esc_html( $name ) . '</h3>';
				echo '<span class="plugin-owner">' . esc_html( $owner ) . '</span>';
				echo '</div>';

				// Action Links
				echo '<div class="action-links">';
				echo '<a href="' . esc_url( $homepage ) . '" class="plugin-info-link" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View Details', 'github-plugin-installer' ) . '</a>';
				if ( self::is_plugin_installed( $owner, $name ) ) {
					echo '<span class="button disabled already-installed">' . esc_html__( 'Installed', 'github-plugin-installer' ) . '</span>';
				} else {
					echo '<a class="install-now" href="' . esc_url( $install_url ) . '">' . esc_html__( 'Install Now', 'github-plugin-installer' ) . '</a>';
				}
				echo '</div>';
				echo '</div>';

				// Description
				echo '<div class="desc column-description">';
				echo '<p>' . esc_html( $description ) . '</p>';
				echo '</div>';

				// Plugin Meta
				echo '<div class="plugin-meta">';
				echo '<div class="plugin-stats">';
				echo '<span><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24"><path fill="currentColor" d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>' . esc_html( $stars ) . '</span>';
				echo '<span><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24"><path fill="currentColor" d="M14 4H2v16h20V8h-8V4zM4 6h8v2H4V6zm16 12H4v-8h16v8zm-8-6h6v4h-6v-4z"/></svg>' . esc_html( $forks ) . '</span>';
				echo '</div>';
				
				if ( $updated || $license_name ) {
					echo '<div class="plugin-details">';
					if ( $updated ) {
						echo '<span class="plugin-updated">' . esc_html__( 'Updated:', 'github-plugin-installer' ) . ' ' . esc_html( $updated ) . '</span>';
					}
					if ( $license_name ) {
						echo '<span class="plugin-license">' . esc_html__( 'License:', 'github-plugin-installer' ) . ' ' . esc_html( $license_name ) . '</span>';
					}
					echo '</div>';
				}
				echo '</div>';

				echo '</div>';
			}
			echo '</div>';

			// Pagination (similar to previous implementation)
			if ( $total > 10 ) {
				$total_pages = ceil( $total / 10 );
				$current_url = add_query_arg(
					array(
						'page' => 'gpb-plugin-browser',
						's'    => $user_query ? $user_query : '',
					),
					admin_url( 'plugins.php' )
				);
				if ( $total_pages > 1 ) {
					$links = paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%', $current_url ),
							'format'    => '',
							'current'   => $page,
							'total'     => $total_pages,
							'type'      => 'plain',
						)
					);
					echo '<div class="tablenav"><div class="tablenav-pages">' . $links . '</div></div>';
				}
			}
		} else {
			echo '<p>' . esc_html__( 'No plugins found.', 'github-plugin-installer' ) . '</p>';
		}
		echo '</div>';
	}
}
