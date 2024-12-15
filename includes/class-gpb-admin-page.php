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
				self::install_plugin_from_github( $owner, $repo );
			}
		}
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
		$api          = new GPB_GitHub_API( $access_token );

		$query = 'topic:wordpress-plugin';
		if ( isset( $_GET['s'] ) && ! empty( $_GET['s'] ) ) {
			$user_query = sanitize_text_field( $_GET['s'] );
			$query      = $user_query . ' topic:wordpress-plugin';
		}

		$page      = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$results   = $api->search_plugins( $query, $page );
		$items     = array();
		$total     = 0;

		if ( ! is_wp_error( $results ) && isset( $results['items'] ) ) {
			$items = $results['items'];
			$total = isset( $results['total_count'] ) ? (int) $results['total_count'] : 0;
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'GitHub Plugin Browser', 'github-plugin-installer' ); ?></h1>
			<form method="get">
				<input type="hidden" name="page" value="gpb-plugin-browser" />
				<input type="search" name="s" value="<?php echo isset( $user_query ) ? esc_attr( $user_query ) : ''; ?>" placeholder="<?php esc_attr_e( 'Search plugins...', 'github-plugin-installer' ); ?>" />
				<?php submit_button( __( 'Search', 'github-plugin-installer' ), 'secondary', false ); ?>
			</form>
			<div class="gpb-plugin-grid">
				<?php if ( ! empty( $items ) ) : ?>
					<?php foreach ( $items as $item ) : ?>
						<div class="gpb-plugin-item">
							<h3><?php echo esc_html( $item['name'] ); ?></h3>
							<p><?php echo esc_html( $item['description'] ); ?></p>
							<p><?php printf( __( 'Stars: %d | Forks: %d', 'github-plugin-installer' ), $item['stargazers_count'], $item['forks_count'] ); ?></p>
							<?php
							$install_url = wp_nonce_url(
								add_query_arg(
									array(
										'page'        => 'gpb-plugin-browser',
										'gpb_install' => 1,
										'owner'       => $item['owner']['login'],
										'repo'        => $item['name'],
									),
									admin_url( 'admin.php' )
								),
								'gpb_install_plugin'
							);
							?>
							<a class="gpb-install-button" href="<?php echo esc_url( $install_url ); ?>"><?php esc_html_e( 'Install', 'github-plugin-installer' ); ?></a>
						</div>
					<?php endforeach; ?>
				<?php else : ?>
					<p><?php esc_html_e( 'No plugins found.', 'github-plugin-installer' ); ?></p>
				<?php endif; ?>
			</div>
			<?php
			// Simple pagination.
			if ( $total > 10 ) {
				$total_pages = ceil( $total / 10 );
				$current_url = add_query_arg(
					array(
						'page' => 'gpb-plugin-browser',
						's'    => isset( $user_query ) ? $user_query : '',
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
							'add_args'  => false,
							'type'      => 'plain',
						)
					);
					echo '<div class="tablenav"><div class="tablenav-pages">' . $links . '</div></div>';
				}
			}
			?>
		</div>
		<?php
	}
}
