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
	}

	/**
	 * Add admin menu page.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'plugins.php',
			__( 'Add Plugins', 'github-plugin-browser' ),
			__( 'Add GitHub Plugin', 'github-plugin-browser' ),
			'install_plugins',
			'gpb-plugin-browser',
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
	 * Render the admin page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'github-plugin-browser' ) );
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
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'GitHub Plugin Browser', 'github-plugin-browser' ) . '</h1>';

		// Top bar with tags and search
		echo '<div class="gpb-top-bar">';
		echo '<div class="gpb-popular-tags">';
		echo '<a href="' . esc_url( remove_query_arg( 'tag' ) ) . '" class="gpb-tag ' . ( ! isset( $_GET['tag'] ) ? 'gpb-tag-active' : '' ) . '">' . esc_html__( 'All', 'github-plugin-browser' ) . '</a>';
		$popular_tags = array(
			'woocommerce'             => __( 'WooCommerce', 'github-plugin-browser' ),
			'seo'                     => __( 'SEO', 'github-plugin-browser' ),
			'artificial-intelligence' => __( 'AI', 'github-plugin-browser' ),
			'security'                => __( 'Security', 'github-plugin-browser' ),
			'social'                  => __( 'Social', 'github-plugin-browser' ),
			'forms'                   => __( 'Forms', 'github-plugin-browser' ),
			'gallery'                 => __( 'Gallery', 'github-plugin-browser' ),
			'caching'                 => __( 'Caching', 'github-plugin-browser' ),
		);

		foreach ( $popular_tags as $tag => $label ) {
			echo '<a href="' . esc_url( add_query_arg( 'tag', strtolower( $tag ), remove_query_arg( array( 'paged', 's' ) ) ) ) . '" class="gpb-tag ' . ( ( isset( $_GET['tag'] ) && strtolower( $tag ) === $_GET['tag'] ) ? 'gpb-tag-active' : '' ) . '">' . esc_html( $label ) . '</a>';
		}
		if ( ! empty( $queried_tag ) && ! in_array( $queried_tag, array_map( 'strtolower', array_keys( $popular_tags ) ) ) ) {
			echo '<a href="' . esc_url( add_query_arg( 'tag', $queried_tag, remove_query_arg( 'paged' ) ) ) . '" class="gpb-tag gpb-tag-active">' . esc_html( $queried_tag ) . '</a>';
		}
		echo '</div>';

		// Search form
		echo '<form method="get" class="gpb-search-form">';
		echo '<input type="hidden" name="page" value="gpb-plugin-browser" />';
		echo '<input type="search" name="s" value="' . esc_attr( $user_query ) . '" placeholder="' . esc_attr__( 'Search plugins...', 'github-plugin-browser' ) . '" />';
		submit_button( __( 'Search', 'github-plugin-browser' ), 'primary', 'search', false );
		echo '</form>';
		echo '</div>';

		if ( ! empty( $results['items'] ) ) {
			echo '<div class="gpb-plugins-grid">';
			foreach ( $results['items'] as $item ) {
				self::render_plugin_card( $item );
			}
			echo '</div>';

			// Pagination
			if ( $results['total_count'] > 10 ) {
				$total_pages = ceil( $results['total_count'] / 10 );
				echo '<div class="tablenav bottom">';
				echo '<div class="tablenav-pages gpb-pagination">';
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
			echo '<p>' . esc_html__( 'No plugins found. Try a different search.', 'github-plugin-browser' ) . '</p>';
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
		$description = isset( $item['description'] ) ? $item['description'] : '';
		$owner = isset( $item['owner']['login'] ) ? $item['owner']['login'] : '';
		$avatar = isset( $item['owner']['avatar_url'] ) ? $item['owner']['avatar_url'] : '';
		$stars = isset( $item['stargazers_count'] ) ? number_format( $item['stargazers_count'] ) : 0;
		$forks = isset( $item['forks_count'] ) ? number_format( $item['forks_count'] ) : 0;
		$updated = isset( $item['updated_at'] ) ? human_time_diff( strtotime( $item['updated_at'] ) ) . ' ago' : '';

		echo '<div class="gpb-plugin-card">';
		echo '<div class="gpb-plugin-header">';
		echo '<div class="gpb-plugin-icon">';
		if ( $avatar ) {
			// Add data attributes for AJAX.
			echo '<img src="' . esc_url( $avatar ) . '" alt="" class="gpb-plugin-thumbnail" data-owner="' . esc_attr( $owner ) . '" data-repo="' . esc_attr( $name ) . '" style="cursor:pointer;" />';
		} else {
			echo '<div class="gpb-plugin-icon-placeholder"></div>';
		}
		echo '</div>';

		echo '<div class="gpb-plugin-info">';
		// Make plugin name clickable for modal.
		echo '<h3 class="gpb-plugin-name" data-owner="' . esc_attr( $owner ) . '" data-repo="' . esc_attr( $name ) . '" style="cursor:pointer;">' . esc_html( $name ) . '</h3>';
		echo '<div class="gpb-plugin-author">By <a href="https://github.com/' . esc_attr( $owner ) . '">' . esc_html( $owner ) . '</a></div>';
		echo '</div>';
		echo '</div>';

		echo '<div class="gpb-plugin-description">' . esc_html( wp_trim_words( $description, 20 ) ) . '</div>';

		echo '<div class="gpb-plugin-actions">';
		if ( self::is_plugin_installed( $owner, $name ) ) {
			echo '<a href="#" class="gpb-button gpb-button-disabled gpb-install-plugin" data-owner="' . esc_attr( $owner ) . '" data-repo="' . esc_attr( $name ) . '" disabled>' . esc_html__( 'Installed', 'github-plugin-browser' ) . '</a>';
		} else {
			echo '<a href="#" class="gpb-button gpb-button-primary gpb-install-plugin" data-owner="' . esc_attr( $owner ) . '" data-repo="' . esc_attr( $name ) . '">' . esc_html__( 'Install Now', 'github-plugin-browser' ) . '</a>';
			echo '<a href="#" class="gpb-button gpb-button-primary gpb-activate-plugin gpb-hidden" data-owner="' . esc_attr( $owner ) . '" data-repo="' . esc_attr( $name ) . '">' . esc_html__( 'Activate', 'github-plugin-browser' ) . '</a>';
		}

		// Add data attributes for "More Details" link.
		echo '<a href="javascript:void(0);" class="gpb-more-details-link" data-owner="' . esc_attr( $owner ) . '" data-repo="' . esc_attr( $name ) . '">' . esc_html__( 'More Details', 'github-plugin-browser' ) . '</a>';
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

	/**
	 * Render the modal structure.
	 */
	private static function render_modal() {
		echo '
		<div id="gpb-plugin-modal" class="gpb-modal">
			<div class="gpb-modal-content">
				<span class="gpb-modal-close">×</span>
				<div class="gpb-modal-header">
					<div class="gpb-modal-header-text">
						<h2 class="gpb-modal-title"></h2>
					</div>
				</div>
				<div class="gpb-modal-body">
					<div class="gpb-modal-main">
						<div class="gpb-modal-readme-content"></div>
					</div>
					<div class="gpb-modal-sidebar">
						<div class="gpb-modal-meta">
							<ul class="gpb-modal-stats">
								<li class="gpb-loading"><span class="dashicons dashicons-info-outline"></span><span class="gpb-modal-compatibility">' . esc_html__( 'Checking compatibility...', 'github-plugin-browser' ) . '</span></li>
								<li><span class="dashicons dashicons-admin-users"></span>' . esc_html__( 'Author:', 'github-plugin-browser' ) . ' <span class="gpb-modal-author"></span></li>
								<li><span class="dashicons dashicons-star-filled"></span>' . esc_html__( 'Stars:', 'github-plugin-browser' ) . ' <span class="gpb-modal-stars"></span></li>
								<li><span class="dashicons dashicons-networking"></span>' . esc_html__( 'Forks:', 'github-plugin-browser' ) . ' <span class="gpb-modal-forks"></span></li>
								<li><span class="dashicons dashicons-visibility"></span>' . esc_html__( 'Watchers:', 'github-plugin-browser' ) . ' <span class="gpb-modal-watchers"></span></li>
								<li><span class="dashicons dashicons-warning"></span>' . esc_html__( 'Open Issues:', 'github-plugin-browser' ) . ' <span class="gpb-modal-issues"></span></li>
								<li><span class="dashicons dashicons-clock"></span>' . esc_html__( 'Last Updated:', 'github-plugin-browser' ) . ' <span class="gpb-modal-updated"></span></li>
							</ul>
							<p><strong>' . esc_html__( 'Topics:', 'github-plugin-browser' ) . '</strong></p>
							<div class="gpb-modal-topics"></div>
						</div>
					</div>
				</div>
				<div class="gpb-modal-footer">
					<div class="gpb-modal-links">
						<a href="#" target="_blank" class="gpb-button gpb-button-secondary gpb-modal-github-link">' . esc_html__( 'View on GitHub', 'github-plugin-browser' ) . '</a>
						<a href="#" target="_blank" class="gpb-button gpb-button-secondary gpb-modal-homepage-link">' . esc_html__( 'Visit Homepage', 'github-plugin-browser' ) . '</a>
					</div>
					<a href="#" class="gpb-button gpb-button-primary gpb-install-plugin gpb-modal-install-button" data-owner="" data-repo="">' . esc_html__( 'Install Now', 'github-plugin-browser' ) . '</a>
					<a href="#" class="gpb-button gpb-button-primary gpb-activate-plugin gpb-modal-activate-button gpb-hidden" data-owner="" data-repo="">' . esc_html__( 'Activate', 'github-plugin-browser' ) . '</a>
				</div>
			</div>
		</div>';
	}
}
