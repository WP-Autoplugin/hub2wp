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
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'display_rate_limit_notice' ) );
		add_filter( 'plugin_action_links_' . H2WP_PLUGIN_BASENAME, array( __CLASS__, 'add_action_links' ) );
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
	 * Check if a plugin is installed.
	 *
	 * @param string $owner Owner name.
	 * @param string $repo  Repo name.
	 * @return bool True if installed, false otherwise.
	 */
	public static function is_plugin_installed( $owner, $repo ) {
		$plugins = get_plugins();
		$repo = strtolower( $repo );
		foreach ( $plugins as $plugin_file => $plugin_data ) {
			// Check if the plugin is installed by comparing folder name and/or the plugin file name (without ".php") to repo name, and finally check the filename-sanitized plugin name against the repo name.
			$folder_name = strtolower( dirname( $plugin_file ) );
			$plugin_name = strtolower( basename( $plugin_file, '.php' ) );
			if ( $repo === $folder_name || $repo === $plugin_name || $repo === sanitize_title( $plugin_data['Name'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Render the admin page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'hub2wp' ) );
		}

		$access_token = H2WP_Settings::get_access_token();
		$api = new H2WP_GitHub_API( $access_token );

		$query = 'topic:wordpress-plugin';
		$user_query = '';
		if ( isset( $_GET['s'] ) && ! empty( $_GET['s'] ) ) {
			$user_query = sanitize_text_field( $_GET['s'] );
			$query = $user_query . ' topic:wordpress-plugin';
		}

		if ( isset( $_GET['tag'] ) && ! empty( $_GET['tag'] ) ) {
			$queried_tag = sanitize_text_field( $_GET['tag'] );
			$query .= ' topic:' . $queried_tag;
		}

		$page = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$results = $api->search_plugins( $query, $page );

		if ( is_wp_error( $results ) ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html( $results->get_error_message() ) . '</p></div></div>';
			return;
		}

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Add GitHub Plugins', 'hub2wp' ) . '</h1>';

		// Top bar with tags and search
		echo '<div class="h2wp-top-bar">';
		echo '<div class="h2wp-popular-tags">';
		echo '<a href="' . esc_url( admin_url( 'plugins.php?page=h2wp-plugin-browser' ) ) . '" class="h2wp-tag ' . ( ! isset( $_GET['tag'] ) && ! isset( $_GET['s'] ) ? 'h2wp-tag-active' : '' ) . '">' . esc_html__( 'All', 'hub2wp' ) . '</a>';
		$popular_tags = array(
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
			echo '<a href="' . esc_url( add_query_arg( 'tag', strtolower( $tag ), remove_query_arg( array( 'paged', 's' ) ) ) ) . '" class="h2wp-tag ' . ( ( isset( $_GET['tag'] ) && strtolower( $tag ) === $_GET['tag'] ) ? 'h2wp-tag-active' : '' ) . '">' . esc_html( $label ) . '</a>';
		}
		if ( ! empty( $queried_tag ) && ! in_array( $queried_tag, array_map( 'strtolower', array_keys( $popular_tags ) ) ) ) {
			echo '<a href="' . esc_url( add_query_arg( 'tag', $queried_tag, remove_query_arg( 'paged' ) ) ) . '" class="h2wp-tag h2wp-tag-active">' . esc_html( $queried_tag ) . '</a>';
		}
		echo '</div>';

		// Search form
		echo '<form method="get" class="h2wp-search-form">';
		echo '<input type="hidden" name="page" value="h2wp-plugin-browser" />';
		echo '<input type="search" name="s" value="' . esc_attr( $user_query ) . '" placeholder="' . esc_attr__( 'Search plugins...', 'hub2wp' ) . '" />';
		submit_button( __( 'Search', 'hub2wp' ), 'primary', 'search', false );
		echo '</form>';
		echo '</div>';

		if ( ! empty( $results['items'] ) ) {
			echo '<div class="h2wp-plugins-grid">';
			foreach ( $results['items'] as $item ) {
				self::render_plugin_card( $item );
			}
			echo '</div>';

			// Pagination
			if ( $results['total_count'] > 10 ) {
				$total_pages = ceil( $results['total_count'] / 10 );
				echo '<div class="tablenav bottom">';
				echo '<div class="tablenav-pages h2wp-pagination">';
				echo paginate_links( array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'prev_text' => __( '«' ),
					'next_text' => __( '»' ),
					'total'   => $total_pages,
					'current' => $page
				) );
				echo '</div>';
				echo '</div>';
			}
		} else {
			echo '<div class="no-plugin-results">';
			echo '<p>' . esc_html__( 'No plugins found. Try a different search.', 'hub2wp' ) . '</p>';
			echo '</div>';
		}
		echo '</div>';

		// Modal HTML
		self::render_modal();
	}

	/**
	 * Render a single plugin card.
	 *
	 * @param array $item Plugin data.
	 */
	private static function render_plugin_card( $item ) {
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
			echo '<img src="' . esc_url( $avatar ) . '" alt="" class="h2wp-plugin-thumbnail" data-owner="' . esc_attr( $owner ) . '" data-repo="' . esc_attr( $name ) . '" style="cursor:pointer;" />';
		} else {
			echo '<div class="h2wp-plugin-icon-placeholder"></div>';
		}
		echo '</div>';

		echo '<div class="h2wp-plugin-info">';
		// Make plugin name clickable for modal.
		echo '<h3 class="h2wp-plugin-name" data-owner="' . esc_attr( $owner ) . '" data-repo="' . esc_attr( $name ) . '" style="cursor:pointer;">' . esc_html( $display_name ) . '</h3>';
		echo '<div class="h2wp-plugin-author">By <a href="https://github.com/' . esc_attr( $owner ) . '">' . esc_html( $owner ) . '</a></div>';
		echo '</div>';
		echo '</div>';

		echo '<div class="h2wp-plugin-description">' . esc_html( wp_trim_words( $description, 20 ) ) . '</div>';

		echo '<div class="h2wp-plugin-actions">';
		if ( self::is_plugin_installed( $owner, $name ) ) {
			echo '<a href="#" class="h2wp-button h2wp-button-disabled h2wp-install-plugin" data-owner="' . esc_attr( $owner ) . '" data-repo="' . esc_attr( $name ) . '" disabled>' . esc_html__( 'Installed', 'hub2wp' ) . '</a>';
		} else {
			echo '<a href="#" class="h2wp-button h2wp-button-secondary h2wp-install-plugin" data-owner="' . esc_attr( $owner ) . '" data-repo="' . esc_attr( $name ) . '">' . esc_html__( 'Install Now', 'hub2wp' ) . '</a>';
			echo '<a href="#" class="h2wp-button h2wp-button-secondary h2wp-activate-plugin h2wp-hidden" data-owner="' . esc_attr( $owner ) . '" data-repo="' . esc_attr( $name ) . '">' . esc_html__( 'Activate', 'hub2wp' ) . '</a>';
		}

		// Add data attributes for "More Details" link.
		echo '<a href="javascript:void(0);" class="h2wp-more-details-link" data-owner="' . esc_attr( $owner ) . '" data-repo="' . esc_attr( $name ) . '">' . esc_html__( 'More Details', 'hub2wp' ) . '</a>';
		echo '</div>';

		echo '<div class="h2wp-plugin-meta">';
		echo '<div class="h2wp-meta-stats">';
		echo '<span class="h2wp-meta-stat"><svg viewBox="0 0 16 16"><path fill-rule="evenodd" d="M8 .25a.75.75 0 01.673.418l1.882 3.815 4.21.612a.75.75 0 01.416 1.279l-3.046 2.97.719 4.192a.75.75 0 01-1.088.791L8 12.347l-3.766 1.98a.75.75 0 01-1.088-.79l.72-4.194L.818 6.374a.75.75 0 01.416-1.28l4.21-.611L7.327.668A.75.75 0 018 .25z"></path></svg>' . esc_html( $stars ) . '</span>';
		echo '<span class="h2wp-meta-stat"><svg viewBox="0 0 16 16"><path fill-rule="evenodd" d="M5 3.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm0 2.122a2.25 2.25 0 10-1.5 0v.878A2.25 2.25 0 005.75 8.5h1.5v2.128a2.251 2.251 0 101.5 0V8.5h1.5a2.25 2.25 0 002.25-2.25v-.878a2.25 2.25 0 10-1.5 0v.878a.75.75 0 01-.75.75h-4.5A.75.75 0 015 6.25v-.878zm3.75 7.378a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm3-8.75a.75.75 0 100-1.5.75.75 0 000 1.5z"></path></svg>' . esc_html( $forks ) . '</span>';
		echo '</div>';
		echo '<span class="h2wp-meta-stat h2wp-meta-updated"><svg viewBox="0 0 16 16" title="' . esc_attr( $updated ) . '">';
		echo '<path fill-rule="evenodd" d="M8 1.5a6.5 6.5 0 100 13 6.5 6.5 0 000-13zM0 8a8 8 0 1116 0A8 8 0 010 8zm8.5-4a.5.5 0 00-1 0v4a.5.5 0 00.146.354l2.5 2.5a.5.5 0 00.708-.708L8.5 7.793V4z"></path></svg>' . esc_html( $updated ) . '</span>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render the modal structure.
	 */
	private static function render_modal() {
		$instructions = H2WP_Plugin_Updater::get_installation_instructions();
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
								<a href="#" target="_blank" class="h2wp-modal-github-link">' . esc_html__( 'View plugin on GitHub', 'hub2wp' ) . '</a>
							</p>
						</div>
						<div class="h2wp-modal-changelog-content h2wp-hidden"></div>
						<div class="h2wp-modal-installation-content h2wp-hidden">
							' . $instructions . '
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
								<p><a href="#" target="_blank" class="h2wp-modal-github-link">' . esc_html__( 'GitHub Plugin Page &raquo;', 'hub2wp' ) . '</a></p>
								<p><a href="#" target="_blank" class="h2wp-modal-homepage-link">' . esc_html__( 'Plugin Homepage &raquo;', 'hub2wp' ) . '</a></p>
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
					<a href="#" class="h2wp-button h2wp-button-secondary h2wp-install-plugin h2wp-modal-install-button" data-owner="" data-repo="">' . esc_html__( 'Install Now', 'hub2wp' ) . '</a>
					<a href="#" class="h2wp-button h2wp-button-secondary h2wp-activate-plugin h2wp-modal-activate-button h2wp-hidden" data-owner="" data-repo="">' . esc_html__( 'Activate', 'hub2wp' ) . '</a>
				</div>
			</div>
			<div class="h2wp-modal-spinner"><img src="' . esc_url( admin_url( 'images/spinner.gif' ) ) . '" alt="" /></div>
		</div>';
	}

	/**
	 * Enqueue admin assets.
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'plugins_page_h2wp-plugin-browser' === $hook ) {
			wp_enqueue_style( 'h2wp-admin-styles', H2WP_PLUGIN_URL . 'assets/css/admin-styles.css', array(), H2WP_VERSION );
			wp_enqueue_script( 'h2wp-admin-scripts', H2WP_PLUGIN_URL . 'assets/js/admin-scripts.js', array( 'jquery' ), H2WP_VERSION, true );

			// Localize script with AJAX URL and nonce.
			wp_localize_script( 'h2wp-admin-scripts', 'h2wp_ajax_object', array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'h2wp_plugin_details_nonce' ),
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
