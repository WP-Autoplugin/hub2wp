<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates the admin page for browsing GitHub plugins.
 */
class H2WP_Admin_Page {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_theme_browser_page' ) );
		add_action( 'admin_menu', array( __CLASS__, 'hide_theme_browser_submenu' ), 999 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_footer-themes.php', array( __CLASS__, 'render_themes_screen_button' ) );
		add_action( 'admin_notices', array( __CLASS__, 'display_rate_limit_notice' ) );
		add_filter( 'admin_title', array( __CLASS__, 'filter_admin_title' ), 10, 2 );
		add_filter( 'plugin_action_links_' . H2WP_PLUGIN_BASENAME, array( __CLASS__, 'add_action_links' ) );
		add_filter( 'views_plugins', array( __CLASS__, 'add_github_plugins_view' ) );
		add_filter( 'all_plugins', array( __CLASS__, 'filter_all_plugins_for_github_view' ) );
	}

	/**
	 * Add a dedicated "GitHub Plugins" filter link on Plugins > Installed Plugins.
	 *
	 * @param array $views Existing views.
	 * @return array
	 */
	public static function add_github_plugins_view( $views ) {
		$count      = self::count_installed_github_plugins();
		$is_current = self::is_github_plugins_view_active();
		$url        = add_query_arg(
			array(
				'plugin_status' => 'h2wp_github',
			),
			admin_url( 'plugins.php' )
		);

		$label = sprintf(
			/* translators: %s: number of installed GitHub plugins. */
			__( 'GitHub Plugins <span class="count">(%s)</span>', 'hub2wp' ),
			number_format_i18n( $count )
		);

		$views['h2wp_github'] = sprintf(
			'<a href="%1$s"%2$s%3$s>%4$s</a>',
			esc_url( $url ),
			$is_current ? ' class="current"' : '',
			$is_current ? ' aria-current="page"' : '',
			$label
		);

		return $views;
	}

	/**
	 * Filter the plugin list when the GitHub Plugins view is selected.
	 *
	 * @param array $plugins Full installed plugins list.
	 * @return array
	 */
	public static function filter_all_plugins_for_github_view( $plugins ) {
		if ( ! self::is_github_plugins_view_active() ) {
			return $plugins;
		}

		$github_plugin_files = self::get_installed_github_plugin_files();
		if ( empty( $github_plugin_files ) ) {
			return array();
		}

		return array_intersect_key( $plugins, array_flip( $github_plugin_files ) );
	}

	/**
	 * Determine whether the GitHub Plugins custom view is active.
	 *
	 * @return bool
	 */
	private static function is_github_plugins_view_active() {
		global $pagenow;

		if ( 'plugins.php' !== $pagenow ) {
			return false;
		}

		$plugin_status = isset( $_GET['plugin_status'] ) ? sanitize_key( wp_unslash( $_GET['plugin_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return 'h2wp_github' === $plugin_status;
	}

	/**
	 * Count installed plugins tracked by hub2wp.
	 *
	 * @return int
	 */
	private static function count_installed_github_plugins() {
		return count( self::get_installed_github_plugin_files() );
	}

	/**
	 * Get installed plugin files tracked by hub2wp.
	 *
	 * @return string[]
	 */
	private static function get_installed_github_plugin_files() {
		$h2wp_plugins = get_option( 'h2wp_plugins', array() );
		$files        = array();

		foreach ( $h2wp_plugins as $plugin ) {
			if ( empty( $plugin['plugin_file'] ) || ! is_string( $plugin['plugin_file'] ) ) {
				continue;
			}

			$plugin_file = self::normalize_plugin_file( $plugin['plugin_file'] );
			if ( '' === $plugin_file ) {
				continue;
			}

			if ( file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
				$files[ $plugin_file ] = true;
			}
		}

		return array_keys( $files );
	}

	/**
	 * Normalize plugin file path to the canonical plugin basename form.
	 *
	 * @param string $plugin_file Plugin file path.
	 * @return string
	 */
	private static function normalize_plugin_file( $plugin_file ) {
		$plugin_file = trim( $plugin_file );

		if ( '' === $plugin_file ) {
			return '';
		}

		$plugin_root = wp_normalize_path( WP_PLUGIN_DIR ) . '/';
		$normalized  = wp_normalize_path( $plugin_file );

		if ( 0 === strpos( $normalized, $plugin_root ) ) {
			$normalized = substr( $normalized, strlen( $plugin_root ) );
		}

		return ltrim( $normalized, '/' );
	}

	/**
	 * Add admin menu page.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'plugins.php',
			__( 'Add Plugins', 'hub2wp' ),
			__( 'Add GitHub Plugin', 'hub2wp' ),
			'install_plugins',
			'h2wp-plugin-browser',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register the hidden theme browser page.
	 */
	public static function add_theme_browser_page() {
		add_submenu_page(
			'themes.php',
			__( 'GitHub Themes', 'hub2wp' ),
			__( 'GitHub Themes', 'hub2wp' ),
			'install_themes',
			'h2wp-theme-browser',
			array( __CLASS__, 'render_theme_page' )
		);
	}

	/**
	 * Hide the GitHub themes page from the Appearance submenu.
	 */
	public static function hide_theme_browser_submenu() {
		remove_submenu_page( 'themes.php', 'h2wp-theme-browser' );
	}

	/**
	 * Add a "GitHub Themes" button beside "Add Theme" on Appearance > Themes.
	 */
	public static function render_themes_screen_button() {
		if ( ! current_user_can( 'install_themes' ) ) {
			return;
		}

		$url = admin_url( 'themes.php?page=h2wp-theme-browser' );
		?>
		<script>
			document.addEventListener('DOMContentLoaded', function () {
				var addThemeButton = document.querySelector('.wrap a.page-title-action');
				if (!addThemeButton) {
					return;
				}
				var githubThemesButton = document.createElement('a');
				githubThemesButton.className = 'page-title-action';
				githubThemesButton.href = <?php echo wp_json_encode( $url ); ?>;
				githubThemesButton.textContent = <?php echo wp_json_encode( __( 'GitHub Themes', 'hub2wp' ) ); ?>;
				addThemeButton.insertAdjacentElement('afterend', githubThemesButton);
			});
		</script>
		<?php
	}

	/**
	 * Check if a plugin is installed.
	 *
	 * @param string $owner Owner name.
	 * @param string $repo  Repo name.
	 * @return bool True if installed, false otherwise.
	 */
	public static function is_plugin_installed( $owner, $repo ) {
		return (bool) self::get_installed_plugin_file( $owner, $repo );
	}

	/**
	 * Check if a repository is already installed as a plugin or theme.
	 *
	 * @param string $owner     Owner name.
	 * @param string $repo      Repo name.
	 * @param string $repo_type Repository type.
	 * @return bool
	 */
	public static function is_repo_installed( $owner, $repo, $repo_type = 'plugin' ) {
		if ( 'theme' === $repo_type ) {
			return (bool) self::get_installed_theme_stylesheet( $owner, $repo );
		}

		return self::is_plugin_installed( $owner, $repo );
	}

	/**
	 * Get the plugin file path if a plugin is installed.
	 *
	 * @param string $owner Owner name.
	 * @param string $repo  Repo name.
	 * @return string|bool Plugin file path if installed, false otherwise.
	 */
	public static function get_installed_plugin_file( $owner, $repo ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		$repo = strtolower( $repo );
		foreach ( $plugins as $plugin_file => $plugin_data ) {
			// Check if the plugin is installed by comparing folder name and/or the plugin file name (without ".php") to repo name, and finally check the filename-sanitized plugin name against the repo name.
			$folder_name = strtolower( dirname( $plugin_file ) );
			$plugin_name = strtolower( basename( $plugin_file, '.php' ) );
			if ( $repo === $folder_name || $repo === $plugin_name || $repo === sanitize_title( $plugin_data['Name'] ) ) {
				return $plugin_file;
			}
		}
		return false;
	}

	/**
	 * Get the installed stylesheet slug if a matching theme is installed.
	 *
	 * @param string $owner Owner name.
	 * @param string $repo  Repo name.
	 * @return string|bool
	 */
	public static function get_installed_theme_stylesheet( $owner, $repo ) {
		$themes = wp_get_themes();
		$repo   = strtolower( $repo );

		foreach ( $themes as $stylesheet => $theme ) {
			$theme_name = strtolower( $theme->get( 'Name' ) );
			if ( $repo === strtolower( $stylesheet ) || $repo === sanitize_title( $theme_name ) ) {
				return $stylesheet;
			}
		}

		return false;
	}

	/**
	 * Get repository browser type from query string.
	 *
	 * @return string plugin|theme
	 */
	public static function get_repo_type() {
		$repo_type = isset( $_GET['repo_type'] ) ? sanitize_key( wp_unslash( $_GET['repo_type'] ) ) : 'plugin'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $repo_type, array( 'plugin', 'theme' ), true ) ? $repo_type : 'plugin';
	}

	/**
	 * Get UI labels for the current repository type.
	 *
	 * @param string $repo_type Repository type.
	 * @return array
	 */
	private static function get_repo_labels( $repo_type ) {
		if ( 'theme' === $repo_type ) {
			return array(
				'singular'      => __( 'Theme', 'hub2wp' ),
				'plural'        => __( 'Themes', 'hub2wp' ),
				'search'        => __( 'Search themes...', 'hub2wp' ),
				'not_found'     => __( 'No themes found. Try a different search.', 'hub2wp' ),
				'github_page'   => __( 'GitHub Theme Page »', 'hub2wp' ),
				'homepage'      => __( 'Theme Homepage »', 'hub2wp' ),
				'modal_github'  => __( 'View theme on GitHub', 'hub2wp' ),
				'more_than_1000'=> __( '<strong>GitHub Search API limit reached.</strong> Only the first 1,000 results are accessible. There are at least %s more themes matching your search that cannot be shown. Try refining your search or adding topic filters to narrow down the results.', 'hub2wp' ),
			);
		}

		return array(
			'singular'      => __( 'Plugin', 'hub2wp' ),
			'plural'        => __( 'Plugins', 'hub2wp' ),
			'search'        => __( 'Search plugins...', 'hub2wp' ),
			'not_found'     => __( 'No plugins found. Try a different search.', 'hub2wp' ),
			'github_page'   => __( 'GitHub Plugin Page »', 'hub2wp' ),
			'homepage'      => __( 'Plugin Homepage »', 'hub2wp' ),
			'modal_github'  => __( 'View plugin on GitHub', 'hub2wp' ),
			'more_than_1000'=> __( '<strong>GitHub Search API limit reached.</strong> Only the first 1,000 results are accessible. There are at least %s more plugins matching your search that cannot be shown. Try refining your search or adding topic filters to narrow down the results.', 'hub2wp' ),
		);
	}

	/**
	 * Render the admin page.
	 */
	public static function render_page() {
		self::render_browser_page( 'plugin' );
	}

	/**
	 * Render the themes browser page.
	 */
	public static function render_theme_page() {
		self::render_browser_page( 'theme' );
	}

	/**
	 * Ensure hidden admin pages keep a meaningful <title>.
	 *
	 * @param string $admin_title Full admin title.
	 * @param string $title       Page title segment.
	 * @return string
	 */
	public static function filter_admin_title( $admin_title, $title ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'h2wp-theme-browser' !== $page ) {
			return $admin_title;
		}

		$site_name = get_bloginfo( 'name' );
		$wp_name   = __( 'WordPress' );
		$page_name = __( 'Add GitHub Themes', 'hub2wp' );

		return sprintf( '%1$s %2$s %3$s %4$s', $page_name, "\xE2\x80\xB9", $site_name, "\xE2\x80\x94 $wp_name" );
	}

	/**
	 * Render a browser page for repository type.
	 *
	 * @param string $repo_type Repository type.
	 */
	private static function render_browser_page( $repo_type ) {
		$repo_type = in_array( $repo_type, array( 'plugin', 'theme' ), true ) ? $repo_type : 'plugin';
		$required_cap = ( 'theme' === $repo_type ) ? 'install_themes' : 'install_plugins';

		if ( ! current_user_can( $required_cap ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'hub2wp' ) );
		}

		$access_token = H2WP_Settings::get_access_token();
		$api          = new H2WP_GitHub_API( $access_token );
		$labels       = self::get_repo_labels( $repo_type );
		$topic        = ( 'theme' === $repo_type ) ? 'wordpress-theme' : 'wordpress-plugin';
		$base_page_url = ( 'theme' === $repo_type )
			? admin_url( 'themes.php?page=h2wp-theme-browser' )
			: admin_url( 'plugins.php?page=h2wp-plugin-browser' );

		// Check if we're viewing private repos.
		$is_private_tab = isset( $_GET['tab'] ) && 'private' === sanitize_key( wp_unslash( $_GET['tab'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$query      = 'topic:' . $topic . ( 'theme' === $repo_type ? ' -topic:wordpress-plugin -topic:build-tool' : '' );
		$user_query = '';
		$queried_tag = '';
		if ( ! $is_private_tab && isset( $_GET['s'] ) && ! empty( $_GET['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$user_query = sanitize_text_field( wp_unslash( $_GET['s'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$query      = $user_query . ' topic:' . $topic . ( 'theme' === $repo_type ? ' -topic:wordpress-plugin' : '' );
		}

		if ( ! $is_private_tab && isset( $_GET['tag'] ) && ! empty( $_GET['tag'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$queried_tag = sanitize_text_field( wp_unslash( $_GET['tag'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$query .= ' topic:' . $queried_tag;
		}

		$page = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// If viewing private repos, fetch them separately.
		if ( $is_private_tab ) {
			$results = self::get_private_repos_data( $api, $repo_type );
		} else {
			$results = $api->search_plugins( $query, $page );
		}

		if ( is_wp_error( $results ) ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html( $results->get_error_message() ) . '</p></div></div>';
			return;
		}

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html( sprintf( __( 'Add GitHub %s', 'hub2wp' ), $labels['plural'] ) ) . '</h1>';

		// Top bar with tags and search
		echo '<div class="h2wp-top-bar">';
		echo '<div class="h2wp-popular-tags">';
		echo '<a href="' . esc_url( $base_page_url ) . '" class="h2wp-tag ' . ( ! isset( $_GET['tag'] ) && ! isset( $_GET['s'] ) && ! $is_private_tab ? 'h2wp-tag-active' : '' ) . '">' . esc_html__( 'All', 'hub2wp' ) . '</a>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$popular_tags = ( 'theme' === $repo_type )
			? array(
				'block-theme'            => __( 'Block Theme', 'hub2wp' ),
				'full-site-editing'      => __( 'FSE', 'hub2wp' ),
				'woocommerce'            => __( 'WooCommerce', 'hub2wp' ),
				'portfolio'              => __( 'Portfolio', 'hub2wp' ),
				'blog'                   => __( 'Blog', 'hub2wp' ),
				'starter-theme'          => __( 'Starter', 'hub2wp' ),
				'accessibility'          => __( 'Accessibility', 'hub2wp' ),
			)
			: array(
				'woocommerce'             => __( 'WooCommerce', 'hub2wp' ),
				'seo'                     => __( 'SEO', 'hub2wp' ),
				'artificial-intelligence' => __( 'AI', 'hub2wp' ),
				'security'                => __( 'Security', 'hub2wp' ),
				'social'                  => __( 'Social', 'hub2wp' ),
				'forms'                   => __( 'Forms', 'hub2wp' ),
				'gallery'                 => __( 'Gallery', 'hub2wp' ),
				'caching'                 => __( 'Caching', 'hub2wp' ),
			);

		foreach ( $popular_tags as $tag => $label ) {
			echo '<a href="' . esc_url( add_query_arg( 'tag', strtolower( $tag ), remove_query_arg( array( 'paged', 's', 'tab' ) ) ) ) . '" class="h2wp-tag ' . ( ( ! $is_private_tab && isset( $_GET['tag'] ) && strtolower( $tag ) === $_GET['tag'] ) ? 'h2wp-tag-active' : '' ) . '">' . esc_html( $label ) . '</a>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( ! empty( $queried_tag ) && ! in_array( $queried_tag, array_map( 'strtolower', array_keys( $popular_tags ) ) ) ) {
			echo '<a href="' . esc_url( add_query_arg( 'tag', $queried_tag, remove_query_arg( array( 'paged', 'tab' ) ) ) ) . '" class="h2wp-tag h2wp-tag-active">' . esc_html( $queried_tag ) . '</a>';
		}

		// Private repos tab.
		echo '<a href="' . esc_url( add_query_arg( 'tab', 'private', remove_query_arg( array( 'paged', 's', 'tag' ) ) ) ) . '" class="h2wp-tag h2wp-tag-private ' . ( $is_private_tab ? 'h2wp-tag-active' : '' ) . '">' . esc_html__( 'Private', 'hub2wp' ) . '</a>';

		echo '</div>';

		// Search form (only show when not on private tab)
		if ( ! $is_private_tab ) {
			echo '<form method="get" class="h2wp-search-form">';
			echo '<input type="hidden" name="page" value="' . esc_attr( 'theme' === $repo_type ? 'h2wp-theme-browser' : 'h2wp-plugin-browser' ) . '" />';
			echo '<input type="search" name="s" value="' . esc_attr( $user_query ) . '" placeholder="' . esc_attr( $labels['search'] ) . '" />';
			submit_button( __( 'Search', 'hub2wp' ), 'primary', 'search', false );
			echo '</form>';
		}
		echo '</div>';

		// Display results
		if ( $is_private_tab ) {
			self::render_private_repos_section( $results, $repo_type );
		} else {
			self::render_public_repos_section( $results, $page, $repo_type );
		}

		echo '</div>';

		// Modal HTML
		self::render_modal( $repo_type );
	}

	/**
	 * Get private repositories data.
	 *
	 * @param H2WP_GitHub_API $api       GitHub API instance.
	 * @param string          $repo_type Repository type.
	 * @return array|WP_Error Array of private repo data or error.
	 */
	private static function get_private_repos_data( $api, $repo_type = 'plugin' ) {
		$option_name       = ( 'theme' === $repo_type ) ? 'h2wp_themes' : 'h2wp_plugins';
		$monitored_plugins = get_option( $option_name, array() );
		$private_repos = array();

		foreach ( $monitored_plugins as $repo_key => $repo_data ) {
			if ( ! empty( $repo_data['private'] ) ) {
				$private_repos[ $repo_key ] = $repo_data;
			}
		}

		if ( empty( $private_repos ) ) {
			return array(
				'items'       => array(),
				'total_count' => 0,
				'errors'      => array(),
			);
		}

		$items  = array();
		$errors = array();

		foreach ( $private_repos as $repo_key => $repo_data ) {
			list( $owner, $repo ) = explode( '/', $repo_key, 2 );

			$repo_details = $api->get_private_repo_details( $owner, $repo );

			if ( is_wp_error( $repo_details ) ) {
				$errors[] = array(
					'repo'  => $repo_key,
					'error' => $repo_details->get_error_message(),
				);
				continue;
			}

			// Transform API response to match search results format
			$items[] = array(
				'id'                => $repo_details['id'],
				'name'              => $repo_details['name'],
				'full_name'         => $repo_details['full_name'],
				'description'       => isset( $repo_details['description'] ) ? $repo_details['description'] : '',
				'owner'             => array(
					'login'      => $repo_details['owner']['login'],
					'avatar_url' => $repo_details['owner']['avatar_url'],
					'html_url'   => $repo_details['owner']['html_url'],
				),
				'stargazers_count'  => $repo_details['stargazers_count'],
				'forks_count'       => $repo_details['forks_count'],
				'watchers_count'    => $repo_details['watchers_count'],
				'open_issues_count' => $repo_details['open_issues_count'],
				'updated_at'        => $repo_details['updated_at'],
				'created_at'        => $repo_details['created_at'],
				'html_url'          => $repo_details['html_url'],
				'homepage'          => isset( $repo_details['homepage'] ) ? $repo_details['homepage'] : '',
				'topics'            => isset( $repo_details['topics'] ) ? $repo_details['topics'] : array(),
				'language'          => isset( $repo_details['language'] ) ? $repo_details['language'] : '',
				'private'           => true,
			);
		}

		return array(
			'items'       => $items,
			'total_count' => count( $items ),
			'errors'      => $errors,
		);
	}

	/**
	 * Render private repositories section.
	 *
	 * @param array  $results   Private repos data.
	 * @param string $repo_type Repository type.
	 */
	private static function render_private_repos_section( $results, $repo_type ) {
		$repo_label_plural = ( 'theme' === $repo_type ) ? __( 'themes', 'hub2wp' ) : __( 'plugins', 'hub2wp' );

		// Display any errors
		if ( ! empty( $results['errors'] ) ) {
			echo '<div class="notice notice-warning is-dismissible">';
			echo '<p><strong>' . esc_html__( 'Some private repositories could not be accessed:', 'hub2wp' ) . '</strong></p>';
			echo '<ul>';
			foreach ( $results['errors'] as $error_data ) {
				echo '<li><code>' . esc_html( $error_data['repo'] ) . '</code>: ' . esc_html( $error_data['error'] ) . '</li>';
			}
			echo '</ul>';
			echo '<p>' . wp_kses_post( sprintf(
				/* translators: %s: settings page URL */
				__( 'You can manage your monitored %1$s in the %2$s.', 'hub2wp' ),
				esc_html( $repo_label_plural ),
				'<a href="' . esc_url( admin_url( 'options-general.php?page=h2wp_settings_page' ) ) . '">' . esc_html__( 'settings', 'hub2wp' ) . '</a>'
			) ) . '</p>';
			echo '</div>';
		}

		// Show message if no private repos configured
		if ( empty( $results['items'] ) && empty( $results['errors'] ) ) {
			echo '<div class="no-plugin-results">';
			echo '<p>' . esc_html__( 'No private repositories configured.', 'hub2wp' ) . '</p>';
			echo '<p>' . wp_kses_post( sprintf(
				/* translators: %s: settings page URL */
				__( 'Add repositories in the %s.', 'hub2wp' ),
				'<a href="' . esc_url( admin_url( 'options-general.php?page=h2wp_settings_page' ) ) . '">' . esc_html__( 'settings page', 'hub2wp' ) . '</a>'
			) ) . '</p>';
			echo '</div>';
			return;
		}

		// Show message if all repos failed
		if ( empty( $results['items'] ) && ! empty( $results['errors'] ) ) {
			echo '<div class="no-plugin-results">';
			echo '<p>' . esc_html__( 'Unable to access any private repositories.', 'hub2wp' ) . '</p>';
			echo '<p>' . esc_html__( 'Please check your GitHub access token has the "repo" scope and that the repositories exist.', 'hub2wp' ) . '</p>';
			echo '</div>';
			return;
		}

		// Display private repos
		if ( ! empty( $results['items'] ) ) {
			echo '<div class="h2wp-private-repos-notice notice notice-info is-dismissible" style="margin: 20px 0;">';
			echo '<p>' . esc_html__( 'These are your private GitHub repositories. They require a personal access token with "repo" scope to access.', 'hub2wp' ) . '</p>';
			echo '</div>';

			if ( 'theme' === $repo_type ) {
				echo '<div class="h2wp-themes-grid">';
				foreach ( $results['items'] as $item ) {
					self::render_theme_card( $item, true );
				}
				echo '</div>';
			} else {
				echo '<div class="h2wp-plugins-grid">';
				foreach ( $results['items'] as $item ) {
					self::render_plugin_card( $item, true, $repo_type );
				}
				echo '</div>';
			}
		}
	}

	/**
	 * Render public repositories section.
	 *
	 * @param array  $results   Search results.
	 * @param int    $page      Current page number.
	 * @param string $repo_type Repository type.
	 */
	private static function render_public_repos_section( $results, $page, $repo_type ) {
		$labels = self::get_repo_labels( $repo_type );

		if ( ! empty( $results['items'] ) ) {
			if ( 'theme' === $repo_type ) {
				echo '<div class="h2wp-themes-grid">';
				foreach ( $results['items'] as $item ) {
					self::render_theme_card( $item );
				}
				echo '</div>';
			} else {
				echo '<div class="h2wp-plugins-grid">';
				foreach ( $results['items'] as $item ) {
					self::render_plugin_card( $item, false, $repo_type );
				}
				echo '</div>';
			}

			// Pagination
			if ( $results['total_count'] > H2WP_RESULTS_PER_PAGE ) {
				$total_pages = ceil( min( $results['total_count'], 1000 ) / H2WP_RESULTS_PER_PAGE ); // GitHub Search API caps at 1000 results
				echo '<div class="tablenav bottom">';
				echo '<div class="tablenav-pages h2wp-pagination">';
				echo wp_kses_post( paginate_links( array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'prev_text' => '«',
					'next_text' => '»',
					'total'     => $total_pages,
					'current'   => $page,
				) ) );
				echo '</div>';
				echo '</div>';

				// Show a notice on the last page if total results exceed GitHub's 1000-result cap
				if ( $results['total_count'] > 1000 && $page >= $total_pages ) {
					$hidden_count = number_format( $results['total_count'] - 1000 );
					echo '<div class="notice notice-warning inline" style="margin: 16px 0;">';
						echo '<p>' . wp_kses_post( sprintf(
							/* translators: 1: number of hidden results */
							$labels['more_than_1000'],
							'<strong>' . esc_html( $hidden_count ) . '</strong>'
						) ) . '</p>';
						echo '</div>';
					}
				}
			} else {
				echo '<div class="no-plugin-results">';
				echo '<p>' . esc_html( $labels['not_found'] ) . '</p>';
				echo '</div>';
			}
		}

	/**
	 * Render a theme card with WordPress theme-browser style markup.
	 *
	 * @param array $item       Theme data.
	 * @param bool  $is_private Whether this is a private repository.
	 */
	private static function render_theme_card( $item, $is_private = false ) {
		$name         = $item['name'];
		$display_name = ucwords( str_replace( array( '-', 'wp', 'wordpress', 'seo' ), array( ' ', 'WP', 'WordPress', 'SEO' ), $name ) );
		$description  = isset( $item['description'] ) ? $item['description'] : '';
		$owner        = isset( $item['owner']['login'] ) ? $item['owner']['login'] : '';
		$avatar       = isset( $item['owner']['avatar_url'] ) ? $item['owner']['avatar_url'] : '';
		$stars        = isset( $item['stargazers_count'] ) ? number_format( $item['stargazers_count'] ) : 0;
		$forks        = isset( $item['forks_count'] ) ? number_format( $item['forks_count'] ) : 0;
		$updated      = isset( $item['updated_at'] ) ? human_time_diff( strtotime( $item['updated_at'] ) ) . ' ago' : '';

		echo '<div class="h2wp-theme-card">';
		echo '<div class="h2wp-theme-screenshot">';
		if ( $avatar ) {
			echo '<img src="' . esc_url( $avatar ) . '" alt="" class="h2wp-theme-hero-image h2wp-plugin-thumbnail" data-owner="' . esc_attr( $owner ) . '" data-repo="' . esc_attr( $name ) . '" data-type="theme" style="cursor:pointer;" />';
		} else {
			echo '<div class="h2wp-plugin-icon-placeholder"></div>';
		}
		echo '</div>';

		echo '<div class="h2wp-theme-header">';
		echo '<h3 class="h2wp-theme-name">' . esc_html( $display_name ) . '</h3>';
		echo '<span class="h2wp-theme-author-text">' . esc_html__( 'By', 'hub2wp' ) . ' <a href="https://github.com/' . esc_attr( $owner ) . '">' . esc_html( $owner ) . '</a></span>';
		if ( $avatar ) {
			echo '<img src="' . esc_url( $avatar ) . '" alt="" class="h2wp-theme-author-avatar" />';
		}
		echo '</div>';
		echo '<div class="h2wp-theme-description"><p>' . esc_html( wp_trim_words( $description, 20 ) ) . '</p></div>';

		echo '<div class="h2wp-theme-actions">';
		if ( self::is_repo_installed( $owner, $name, 'theme' ) ) {
			echo '<a href="#" class="h2wp-button h2wp-button-disabled h2wp-install-plugin" data-owner="' . esc_attr( $owner ) . '" data-repo="' . esc_attr( $name ) . '" data-type="theme" disabled>' . esc_html__( 'Installed', 'hub2wp' ) . '</a>';
		} else {
			echo '<a href="#" class="h2wp-button h2wp-button-secondary h2wp-install-plugin" data-owner="' . esc_attr( $owner ) . '" data-repo="' . esc_attr( $name ) . '" data-type="theme">' . esc_html__( 'Install Now', 'hub2wp' ) . '</a>';
			echo '<a href="#" class="h2wp-button h2wp-button-secondary h2wp-activate-plugin h2wp-hidden" data-owner="' . esc_attr( $owner ) . '" data-repo="' . esc_attr( $name ) . '" data-type="theme">' . esc_html__( 'Activate', 'hub2wp' ) . '</a>';
		}
		echo '<a href="#" class="h2wp-more-details-link" data-owner="' . esc_attr( $owner ) . '" data-repo="' . esc_attr( $name ) . '" data-type="theme">' . esc_html__( 'More Details', 'hub2wp' ) . '</a>';
		if ( $is_private ) {
			echo '<span class="h2wp-private-badge">' . esc_html__( 'Private', 'hub2wp' ) . '</span>';
		}
		echo '</div>';

		echo '<div class="h2wp-plugin-meta">';
		echo '<div class="h2wp-meta-stats">';
		echo '<span class="h2wp-meta-stat"><svg viewBox="0 0 16 16"><path fill-rule="evenodd" d="M8 .25a.75.75 0 01.673.418l1.882 3.815 4.21.612a.75.75 0 01.416 1.279l-3.046 2.97.719 4.192a.75.75 0 01-1.088.791L8 12.347l-3.766 1.98a.75.75 0 01-1.088-.79l.72-4.194L.818 6.374a.75.75 0 01.416-1.28l4.21-.611L7.327.668A.75.75 0 018 .25z"></path></svg>' . esc_html( $stars ) . '</span>';
		echo '<span class="h2wp-meta-stat"><svg viewBox="0 0 16 16"><path fill-rule="evenodd" d="M5 3.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm0 2.122a2.25 2.25 0 10-1.5 0v.878A2.25 2.25 0 005.75 8.5h1.5v2.128a2.251 2.251 0 101.5 0V8.5h1.5a2.25 2.25 0 002.25-2.25v-.878a2.25 2.25 0 10-1.5 0v.878a.75.75 0 01-.75.75h-4.5A.75.75 0 015 6.25v-.878zm3.75 7.378a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm3-8.75a.75.75 0 100-1.5.75.75 0 000 1.5z"></path></svg>' . esc_html( $forks ) . '</span>';
		echo '</div>';
		echo '<span class="h2wp-meta-stat h2wp-meta-updated" data-owner="' . esc_attr( $owner ) . '" data-repo="' . esc_attr( $name ) . '" data-type="theme"><svg viewBox="0 0 16 16" title="' . esc_attr( $updated ) . '">';
		echo '<path fill-rule="evenodd" d="M8 1.5a6.5 6.5 0 100 13 6.5 6.5 0 000-13zM0 8a8 8 0 1116 0A8 8 0 010 8zm8.5-4a.5.5 0 00-1 0v4a.5.5 0 00.146.354l2.5 2.5a.5.5 0 00.708-.708L8.5 7.793V4z"></path></svg><span>' . esc_html( $updated ) . '</span></span>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render a single plugin card.
	 *
	 * @param array  $item       Plugin data.
	 * @param bool   $is_private Whether this is a private repository.
	 * @param string $repo_type  Repository type.
	 */
	private static function render_plugin_card( $item, $is_private = false, $repo_type = 'plugin' ) {
		$name = $item['name'];
		$display_name = ucwords( str_replace( array( '-', 'wp', 'wordpress', 'seo' ), array( ' ', 'WP', 'WordPress', 'SEO' ), $name ) );
		$description = isset( $item['description'] ) ? $item['description'] : '';
		$owner = isset( $item['owner']['login'] ) ? $item['owner']['login'] : '';
		$avatar = isset( $item['owner']['avatar_url'] ) ? $item['owner']['avatar_url'] : '';
		$stars = isset( $item['stargazers_count'] ) ? number_format( $item['stargazers_count'] ) : 0;
		$forks = isset( $item['forks_count'] ) ? number_format( $item['forks_count'] ) : 0;
		$updated = isset( $item['updated_at'] ) ? human_time_diff( strtotime( $item['updated_at'] ) ) . ' ago' : '';

		echo '<div class="h2wp-plugin-card">';
		echo '<div class="h2wp-plugin-header">';
		echo '<div class="h2wp-plugin-icon">';
		if ( $avatar ) {
			// Add data attributes for AJAX.
			echo '<img src="' . esc_url( $avatar ) . '" alt="" class="h2wp-plugin-thumbnail" data-owner="' . esc_attr( $owner ) . '" data-repo="' . esc_attr( $name ) . '" data-type="' . esc_attr( $repo_type ) . '" style="cursor:pointer;" />';
		} else {
			echo '<div class="h2wp-plugin-icon-placeholder"></div>';
		}
		echo '</div>';

		echo '<div class="h2wp-plugin-info">';
		// Make plugin name clickable for modal.
		echo '<h3 class="h2wp-plugin-name" data-owner="' . esc_attr( $owner ) . '" data-repo="' . esc_attr( $name ) . '" data-type="' . esc_attr( $repo_type ) . '" style="cursor:pointer;">' . esc_html( $display_name ) . '</h3>';
		echo '<div class="h2wp-plugin-author">By <a href="https://github.com/' . esc_attr( $owner ) . '">' . esc_html( $owner ) . '</a></div>';
		
		// Add private badge if applicable
		if ( $is_private ) {
			echo '<span class="h2wp-private-badge">' . esc_html__( 'Private', 'hub2wp' ) . '</span>';
		}
		
		echo '</div>';
		echo '</div>';

		echo '<div class="h2wp-plugin-description">' . esc_html( wp_trim_words( $description, 20 ) ) . '</div>';

		echo '<div class="h2wp-plugin-actions">';
		if ( self::is_repo_installed( $owner, $name, $repo_type ) ) {
			echo '<a href="#" class="h2wp-button h2wp-button-disabled h2wp-install-plugin" data-owner="' . esc_attr( $owner ) . '" data-repo="' . esc_attr( $name ) . '" data-type="' . esc_attr( $repo_type ) . '" disabled>' . esc_html__( 'Installed', 'hub2wp' ) . '</a>';
		} else {
			echo '<a href="#" class="h2wp-button h2wp-button-secondary h2wp-install-plugin" data-owner="' . esc_attr( $owner ) . '" data-repo="' . esc_attr( $name ) . '" data-type="' . esc_attr( $repo_type ) . '">' . esc_html__( 'Install Now', 'hub2wp' ) . '</a>';
			echo '<a href="#" class="h2wp-button h2wp-button-secondary h2wp-activate-plugin h2wp-hidden" data-owner="' . esc_attr( $owner ) . '" data-repo="' . esc_attr( $name ) . '" data-type="' . esc_attr( $repo_type ) . '">' . esc_html__( 'Activate', 'hub2wp' ) . '</a>';
		}

		// Add data attributes for "More Details" link.
		echo '<a href="javascript:void(0);" class="h2wp-more-details-link" data-owner="' . esc_attr( $owner ) . '" data-repo="' . esc_attr( $name ) . '" data-type="' . esc_attr( $repo_type ) . '">' . esc_html__( 'More Details', 'hub2wp' ) . '</a>';
		echo '</div>';

		echo '<div class="h2wp-plugin-meta">';
		echo '<div class="h2wp-meta-stats">';
		echo '<span class="h2wp-meta-stat"><svg viewBox="0 0 16 16"><path fill-rule="evenodd" d="M8 .25a.75.75 0 01.673.418l1.882 3.815 4.21.612a.75.75 0 01.416 1.279l-3.046 2.97.719 4.192a.75.75 0 01-1.088.791L8 12.347l-3.766 1.98a.75.75 0 01-1.088-.79l.72-4.194L.818 6.374a.75.75 0 01.416-1.28l4.21-.611L7.327.668A.75.75 0 018 .25z"></path></svg>' . esc_html( $stars ) . '</span>';
		echo '<span class="h2wp-meta-stat"><svg viewBox="0 0 16 16"><path fill-rule="evenodd" d="M5 3.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm0 2.122a2.25 2.25 0 10-1.5 0v.878A2.25 2.25 0 005.75 8.5h1.5v2.128a2.251 2.251 0 101.5 0V8.5h1.5a2.25 2.25 0 002.25-2.25v-.878a2.25 2.25 0 10-1.5 0v.878a.75.75 0 01-.75.75h-4.5A.75.75 0 015 6.25v-.878zm3.75 7.378a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm3-8.75a.75.75 0 100-1.5.75.75 0 000 1.5z"></path></svg>' . esc_html( $forks ) . '</span>';
		echo '</div>';
		echo '<span class="h2wp-meta-stat h2wp-meta-updated" data-owner="' . esc_attr( $owner ) . '" data-repo="' . esc_attr( $name ) . '" data-type="' . esc_attr( $repo_type ) . '"><svg viewBox="0 0 16 16" title="' . esc_attr( $updated ) . '">';
		echo '<path fill-rule="evenodd" d="M8 1.5a6.5 6.5 0 100 13 6.5 6.5 0 000-13zM0 8a8 8 0 1116 0A8 8 0 010 8zm8.5-4a.5.5 0 00-1 0v4a.5.5 0 00.146.354l2.5 2.5a.5.5 0 00.708-.708L8.5 7.793V4z"></path></svg><span>' . esc_html( $updated ) . '</span></span>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render the modal structure.
	 *
	 * @param string $repo_type Repository type (plugin|theme).
	 */
	private static function render_modal( $repo_type = 'plugin' ) {
		$instructions = H2WP_Plugin_Updater::get_installation_instructions( '', $repo_type );
		echo '
		<div id="h2wp-plugin-modal" class="h2wp-modal">
			<div class="h2wp-modal-content">
				<span class="h2wp-modal-close">×</span>
				<div class="h2wp-modal-header">
					<div class="h2wp-modal-header-text">
						<h2 class="h2wp-modal-title"></h2>
					</div>
				</div>
				<div class="h2wp-modal-tabs">
					<ul>
						<li><a href="#" class="h2wp-modal-tab h2wp-modal-readme-tab h2wp-modal-tab-active" data-tab="readme">' . esc_html__( 'Readme', 'hub2wp' ) . '</a></li>
						<li><a href="#" class="h2wp-modal-tab h2wp-meta-readme-tab" data-tab="meta">' . esc_html__( 'Meta', 'hub2wp' ) . '</a></li>
						<li><a href="#" class="h2wp-modal-tab h2wp-modal-changelog-tab" data-tab="changelog">' . esc_html__( 'Changelog', 'hub2wp' ) . '</a></li>
						<li><a href="#" class="h2wp-modal-tab h2wp-installation-readme-tab" data-tab="installation">' . esc_html__( 'Installation', 'hub2wp' ) . '</a></li>
					</ul>
				</div>
				<div class="h2wp-modal-body">
					<div class="h2wp-modal-main">
						<div class="h2wp-modal-readme-content"></div>
						<div class="h2wp-modal-meta-content h2wp-hidden">
							<h3>' . esc_html__( 'GitHub Meta', 'hub2wp' ) . '</h3>
							<div class="h2wp-modal-stats-grid">
								<div class="h2wp-modal-stat-card">
									<div class="h2wp-modal-stat-header">
										<span class="dashicons dashicons-admin-users"></span>
										<span class="h2wp-modal-stat-label">' . esc_html__( 'Author:', 'hub2wp' ) . '</span>
									</div>
									<span class="h2wp-modal-author"></span>
								</div>
								
								<div class="h2wp-modal-stat-card">
									<div class="h2wp-modal-stat-header">
										<span class="dashicons dashicons-star-filled"></span>
										<span class="h2wp-modal-stat-label">' . esc_html__( 'Stars:', 'hub2wp' ) . '</span>
									</div>
									<span class="h2wp-modal-stars"></span>
								</div>
								
								<div class="h2wp-modal-stat-card">
									<div class="h2wp-modal-stat-header">
										<span class="dashicons dashicons-networking"></span>
										<span class="h2wp-modal-stat-label">' . esc_html__( 'Forks:', 'hub2wp' ) . '</span>
									</div>
									<span class="h2wp-modal-forks"></span>
								</div>
								
								<div class="h2wp-modal-stat-card">
									<div class="h2wp-modal-stat-header">
										<span class="dashicons dashicons-visibility"></span>
										<span class="h2wp-modal-stat-label">' . esc_html__( 'Watchers:', 'hub2wp' ) . '</span>
									</div>
									<span class="h2wp-modal-watchers"></span>
								</div>
								
								<div class="h2wp-modal-stat-card">
									<div class="h2wp-modal-stat-header">
										<span class="dashicons dashicons-warning"></span>
										<span class="h2wp-modal-stat-label">' . esc_html__( 'Open Issues:', 'hub2wp' ) . '</span>
									</div>
									<span class="h2wp-modal-issues"></span>
								</div>
								
								<div class="h2wp-modal-stat-card">
									<div class="h2wp-modal-stat-header">
										<span class="dashicons dashicons-clock"></span>
										<span class="h2wp-modal-stat-label">' . esc_html__( 'Last Updated:', 'hub2wp' ) . '</span>
									</div>
									<span class="h2wp-modal-updated"></span>
								</div>
							</div>

							<div class="h2wp-modal-topics-section">
								<h4>' . esc_html__( 'Topics:', 'hub2wp' ) . '</h4>
								<div class="h2wp-modal-topics"></div>
							</div>

							<p class="h2wp-modal-links">
								<a href="#" target="_blank" class="h2wp-modal-github-link">' . esc_html__( 'View on GitHub', 'hub2wp' ) . '</a>
							</p>
						</div>
						<div class="h2wp-modal-changelog-content h2wp-hidden"></div>
						<div class="h2wp-modal-installation-content h2wp-hidden">
						' . wp_kses_post( $instructions ) . '
						</div>
					</div>
					<div class="h2wp-modal-sidebar">
						<div class="h2wp-modal-sidebar-content">
							<div class="h2wp-modal-compatibility-details">
								<div class="h2wp-loading">
									<span class="dashicons dashicons-info-outline"></span> <span class="h2wp-modal-compatibility">' . esc_html__( 'Checking compatibility...', 'hub2wp' ) . '</span>
								</div>
								<p class="h2wp-modal-compatibility-version"><strong>' . esc_html__( 'Version:', 'hub2wp' ) . '</strong> <span class="h2wp-modal-version">' . esc_html__( 'Unknown', 'hub2wp' ) . '</span></p>
								<p class="h2wp-modal-compatibility-author"><strong>' . esc_html__( 'Author:', 'hub2wp' ) . '</strong> <a class="h2wp-modal-author" href="#">' . esc_html__( 'Unknown', 'hub2wp' ) . '</a></p>
								<p class="h2wp-modal-compatibility-last-updated"><strong>' . esc_html__( 'Last Updated:', 'hub2wp' ) . '</strong> <span class="h2wp-modal-updated">' . esc_html__( 'Unknown', 'hub2wp' ) . '</span></p>
								<p class="h2wp-modal-compatibility-required-wp"><strong>' . esc_html__( 'Requires WordPress:', 'hub2wp' ) . '</strong> <span class="h2wp-modal-compatibility-required-wp-version">' . esc_html__( 'Unknown', 'hub2wp' ) . '</span></p>
								<p class="h2wp-modal-compatibility-tested-wp"><strong>' . esc_html__( 'Tested up to:', 'hub2wp' ) . '</strong> <span class="h2wp-modal-compatibility-tested-wp-version">' . esc_html__( 'Unknown', 'hub2wp' ) . '</span></p>
								<p class="h2wp-modal-compatibility-required-php"><strong>' . esc_html__( 'Requires PHP:', 'hub2wp' ) . '</strong> <span class="h2wp-modal-compatibility-required-php-version">' . esc_html__( 'Unknown', 'hub2wp' ) . '</span></p>
							</div>

							<div class="h2wp-modal-links">
									<p><a href="#" target="_blank" class="h2wp-modal-github-link">' . esc_html__( 'GitHub Repository &raquo;', 'hub2wp' ) . '</a></p>
									<p><a href="#" target="_blank" class="h2wp-modal-homepage-link">' . esc_html__( 'Homepage &raquo;', 'hub2wp' ) . '</a></p>
							</div>

							<h3>' . esc_html__( 'Stars', 'hub2wp' ) . '</h3>
							<div class="h2wp-modal-stars-content">
								<span class="dashicons dashicons-star-filled"></span> <span class="h2wp-modal-stars"></span> ' . esc_html__( 'stars on GitHub', 'hub2wp' ) . '
							</div>

							<h3>' . esc_html__( 'Topics', 'hub2wp' ) . '</h3>
							<div class="h2wp-modal-topics"></div>
						</div>
					</div>
				</div>
				<div class="h2wp-modal-footer">
					<a href="#" class="h2wp-button h2wp-button-secondary h2wp-install-plugin h2wp-modal-install-button" data-owner="" data-repo="" data-type="plugin">' . esc_html__( 'Install Now', 'hub2wp' ) . '</a>
					<a href="#" class="h2wp-button h2wp-button-secondary h2wp-activate-plugin h2wp-modal-activate-button h2wp-hidden" data-owner="" data-repo="" data-type="plugin">' . esc_html__( 'Activate', 'hub2wp' ) . '</a>
				</div>
			</div>
			<div class="h2wp-modal-spinner"><img src="' . esc_url( admin_url( 'images/spinner.gif' ) ) . '" alt="" /></div>
		</div>';
	}

	/**
	 * Enqueue admin assets.
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'plugins_page_h2wp-plugin-browser' === $hook || 'appearance_page_h2wp-theme-browser' === $hook ) {
			$repo_type = ( 'appearance_page_h2wp-theme-browser' === $hook ) ? 'theme' : 'plugin';

			wp_enqueue_style( 'h2wp-admin-styles', H2WP_PLUGIN_URL . 'assets/css/admin-styles.css', array(), H2WP_VERSION );
			wp_enqueue_script( 'h2wp-admin-scripts', H2WP_PLUGIN_URL . 'assets/js/admin-scripts.js', array( 'jquery' ), H2WP_VERSION, true );

			// Localize script with AJAX URL and nonce.
			wp_localize_script( 'h2wp-admin-scripts', 'h2wp_ajax_object', array(
				'ajax_url'  => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'h2wp_plugin_details_nonce' ),
				'repo_type' => $repo_type,
			) );
			}
		}

	/**
	 * Display a notice if the rate limit is low.
	 */
	public static function display_rate_limit_notice() {
		$rate_limited = get_transient( 'h2wp_rate_limit_reached' );
		if ( $rate_limited ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'GitHub API rate limit reached. Please add a personal access token in the settings to continue.', 'hub2wp' ) . '</p></div>';
			delete_transient( 'h2wp_rate_limit_reached' );
		}
	}

	/**
	 * Add action links to the plugin list: Settings and Add Plugin.
	 *
	 * @param array $links Plugin action links.
	 * @return array
	 */
	public static function add_action_links( $links ) {
		$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=h2wp_settings_page' ) ) . '">' . esc_html__( 'Settings', 'hub2wp' ) . '</a>';
		$add_plugin_link = '<a href="' . esc_url( admin_url( 'plugins.php?page=h2wp-plugin-browser' ) ) . '">' . esc_html__( 'Add GitHub Plugin', 'hub2wp' ) . '</a>';
		array_unshift( $links, $settings_link, $add_plugin_link );
		return $links;
	}
}
