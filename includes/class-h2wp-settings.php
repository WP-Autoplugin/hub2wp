<?php
/**
 * Settings and tracked-repository management.
 *
 * @package hub2wp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the settings page and options.
 */
class H2WP_Settings {

	/**
	 * Option name for settings.
	 */
	const OPTION_NAME = 'h2wp_settings';

	/**
	 * Initialize the settings.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_private_repo_actions' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_run_update_check_action' ) );
		add_action( 'admin_notices', array( __CLASS__, 'display_plugins_added_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'display_private_repo_notices' ) );
		add_action( 'admin_post_h2wp_clear_cache', array( __CLASS__, 'handle_clear_cache' ) );
		add_action( 'wp_ajax_h2wp_clear_cache', array( __CLASS__, 'ajax_clear_cache' ) );
	}

	/**
	 * Register settings.
	 */
	public static function register_settings() {
		register_setting( 'h2wp_settings_group', self::OPTION_NAME, array( __CLASS__, 'sanitize_settings' ) );

		add_settings_section(
			'h2wp_settings_section',
			'', // No title.
			'__return_false',
			'h2wp_settings_page'
		);

		add_settings_field(
			'h2wp_access_token',
			__( 'Personal Access Token', 'hub2wp' ),
			array( __CLASS__, 'access_token_field' ),
			'h2wp_settings_page',
			'h2wp_settings_section'
		);

		add_settings_field(
			'h2wp_cache_duration',
			__( 'Cache Duration (Hours)', 'hub2wp' ),
			array( __CLASS__, 'cache_duration_field' ),
			'h2wp_settings_page',
			'h2wp_settings_section'
		);
	}

	/**
	 * Add settings page to the menu.
	 */
	public static function add_settings_page() {
		add_options_page(
			__( 'hub2wp Settings', 'hub2wp' ),
			__( 'GitHub Plugins', 'hub2wp' ),
			'manage_options',
			'h2wp_settings_page',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Render settings page.
	 */
	public static function render_settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'hub2wp Settings', 'hub2wp' ); ?></h1>
			<p style="margin-top:0;">
				<?php esc_html_e( 'Configure your GitHub access token, cache settings, and manage monitored repositories.', 'hub2wp' ); ?><br>
				<?php esc_html_e( 'Public single-repository plugins and themes work without a token. Private repositories and monorepo support require a GitHub access token.', 'hub2wp' ); ?>
			</p>
			<form method="post" action="options.php" style="margin-bottom: 2em;">
				<?php settings_fields( 'h2wp_settings_group' ); ?>
				<?php do_settings_sections( 'h2wp_settings_page' ); ?>

				<div style="display:flex;align-items:center;gap:24px;flex-wrap:wrap;">
					<?php submit_button( null, 'primary', 'submit', false ); ?>
					<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
						<button type="button" id="h2wp-clear-cache-btn" class="button button-secondary">
							<?php esc_html_e( 'Clear Cache', 'hub2wp' ); ?>
						</button>
						<span id="h2wp-clear-cache-status" style="display:none;"></span>
						<p class="description" style="margin:0;"><?php esc_html_e( 'Delete all cached GitHub API responses. The cache will be rebuilt on the next request.', 'hub2wp' ); ?></p>
					</div>
				</div>
			</form>
			<?php if ( empty( self::get_access_token() ) && self::has_monitored_monorepo_projects() ) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'Monorepo update checks are paused because no GitHub access token is configured. Save a token above to resume them.', 'hub2wp' ); ?></p>
				</div>
			<?php endif; ?>
			<script>
			( function() {
				var btn    = document.getElementById( 'h2wp-clear-cache-btn' );
				var status = document.getElementById( 'h2wp-clear-cache-status' );
				if ( ! btn ) { return; }
				btn.addEventListener( 'click', function() {
					btn.disabled    = true;
					var originalText = btn.textContent.trim();
					btn.textContent = <?php echo wp_json_encode( __( 'Clearing…', 'hub2wp' ) ); ?>;
					status.style.display = 'none';

					var data = new FormData();
					data.append( 'action', 'h2wp_clear_cache' );
					data.append( 'nonce', <?php echo wp_json_encode( wp_create_nonce( 'h2wp_clear_cache' ) ); ?> );

					fetch( ajaxurl, { method: 'POST', body: data } )
						.then( function( r ) { return r.json(); } )
						.then( function( response ) {
							status.style.display = '';
							if ( response.success ) {
								status.style.color = '#00a32a';
								status.textContent = response.data.message;
							} else {
								status.style.color = '#d63638';
								status.textContent = ( response.data && response.data.message )
									? response.data.message
									: <?php echo wp_json_encode( __( 'An error occurred.', 'hub2wp' ) ); ?>;
							}
							btn.disabled    = false;
							btn.textContent = originalText;
						} )
						.catch( function() {
							status.style.display = '';
							status.style.color   = '#d63638';
							status.textContent   = <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'hub2wp' ) ); ?>;
							btn.disabled    = false;
							btn.textContent = originalText;
						} );
				} );
			} )();
			</script>

			<hr />

			<?php self::render_monitored_plugins_section(); ?>

			<hr />

			<?php self::render_monitored_themes_section(); ?>

			<hr />

			<?php self::next_run_schedule(); ?>
		</div>
		<?php
	}

	/**
	 * Render the monitored plugins section.
	 */
	public static function render_monitored_plugins_section() {
		$tracked_service   = new H2WP_Tracked_Repo_Service();
		$monitored_plugins = $tracked_service->get_tracked_plugins();
		$count             = count( $monitored_plugins );
		$monorepo_enabled  = '' !== self::get_access_token();
		// Auto-expand if there are form submission notices so feedback is visible.
		$expanded = ! empty( $_GET['h2wp_notice'] ) || ! empty( $_GET['h2wp_error'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<p style="margin:0;">
			<strong><?php esc_html_e( 'Monitored Plugins', 'hub2wp' ); ?></strong>
			<?php if ( $count > 0 ) : ?>
				<span style="color:#646970;">(<?php echo esc_html( $count ); ?>)</span>
			<?php endif; ?>
			&mdash;
			<a href="#" id="h2wp-toggle-monitored" aria-expanded="<?php echo $expanded ? 'true' : 'false'; ?>" aria-controls="h2wp-monitored-content" style="text-decoration: none;">
				<span class="dashicons <?php echo $expanded ? 'dashicons-arrow-up-alt2' : 'dashicons-arrow-down-alt2'; ?>" style="vertical-align:middle;font-size:16px;width:16px;height:16px;"></span>
				<span id="h2wp-toggle-monitored-label"><?php echo $expanded ? esc_html__( 'Hide', 'hub2wp' ) : esc_html__( 'Show', 'hub2wp' ); ?></span>
			</a>
		</p>

		<div id="h2wp-monitored-content" style="display:<?php echo $expanded ? '' : 'none'; ?>;">
			<p class="description" style="margin-top:1em;">
				<?php esc_html_e( 'Add GitHub repositories to monitor them for updates. Private repositories require a personal access token with "repo" scope.', 'hub2wp' ); ?>
			</p>

			<form method="post" action="">
				<?php wp_nonce_field( 'h2wp_add_private_repo', 'h2wp_private_repo_nonce' ); ?>
				<input type="hidden" name="h2wp_action" value="add_private_repo" />

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="h2wp_private_repo_input">
								<?php esc_html_e( 'Add Plugin Repository', 'hub2wp' ); ?>
							</label>
						</th>
						<td>
							<input
								type="text"
								id="h2wp_private_repo_input"
								name="h2wp_private_repo"
								value=""
								placeholder="owner/repo"
								size="30"
							/>
							<input
								type="text"
								id="h2wp_branch_input"
								name="h2wp_branch"
								value=""
								placeholder="main (default)"
								size="15"
							/>
							<label for="h2wp_prioritize_releases" style="margin-right:8px;display:inline-flex;align-items:center;gap:2px;">
								<input type="checkbox" id="h2wp_prioritize_releases" name="h2wp_prioritize_releases" value="1" checked="checked" />
								<?php esc_html_e( 'Prioritize releases', 'hub2wp' ); ?>
							</label>
							<label for="h2wp_scan_monorepo" style="margin-right:8px;display:inline-flex;align-items:center;gap:2px;">
								<input type="checkbox" id="h2wp_scan_monorepo" value="1" <?php disabled( ! $monorepo_enabled ); ?> />
								<?php esc_html_e( 'Scan as monorepo', 'hub2wp' ); ?>
							</label>
							<button type="submit" class="button button-secondary">
								<?php esc_html_e( 'Add Repository', 'hub2wp' ); ?>
							</button>
							<p class="description">
								<?php esc_html_e( 'Enter the repository in the format: owner/repo (e.g., mycompany/private-plugin). Optional: specify a branch (defaults to repository default branch).', 'hub2wp' ); ?>
							</p>
							<?php if ( ! $monorepo_enabled ) : ?>
								<p class="description"><?php esc_html_e( 'Save a GitHub access token above to enable monorepo scanning and monitoring.', 'hub2wp' ); ?></p>
							<?php endif; ?>
							<details style="margin-top:6px;">
								<summary><?php esc_html_e( 'What does "Prioritize releases" mean?', 'hub2wp' ); ?></summary>
								<p class="description" style="margin:6px 0 0;">
									<?php esc_html_e( 'When enabled, update/version checks use the latest GitHub release files (tag) when releases exist. When disabled, checks only use the selected branch or the default branch.', 'hub2wp' ); ?>
								</p>
							</details>
							<details style="margin-top:6px;">
								<summary><?php esc_html_e( 'What does "Scan as monorepo" mean?', 'hub2wp' ); ?></summary>
								<p class="description" style="margin:6px 0 0;">
									<?php esc_html_e( 'Use this when one GitHub repository contains multiple WordPress plugins in subdirectories. hub2wp scans the repository, lets you choose which plugins to add, and monitors each one separately. A saved GitHub access token is required because the scan may make several API requests. Leave this disabled for a normal repository containing one plugin.', 'hub2wp' ); ?>
								</p>
							</details>
						</td>
					</tr>
				</table>
			</form>

			<?php if ( ! empty( $monitored_plugins ) ) : ?>
				<h3><?php esc_html_e( 'Monitored Plugins', 'hub2wp' ); ?></h3>
				<form method="post" action="" id="h2wp-single-remove-form" style="display:none;">
					<?php wp_nonce_field( 'h2wp_remove_private_repo', 'h2wp_remove_repo_nonce' ); ?>
					<input type="hidden" name="h2wp_action" value="remove_private_repo" />
					<input type="hidden" name="h2wp_repo_key" id="h2wp-single-remove-key" value="" />
				</form>
				<form method="post" action="" id="h2wp-bulk-remove-form">
					<?php wp_nonce_field( 'h2wp_bulk_remove_repos', 'h2wp_bulk_remove_nonce' ); ?>
					<input type="hidden" name="h2wp_action" value="bulk_remove_repos" />
					<div class="tablenav top" style="margin-bottom:6px;">
						<div class="alignleft actions">
							<button type="submit" id="h2wp-bulk-remove-btn" class="button" disabled
								onclick="return confirm('<?php echo esc_js( __( 'Stop monitoring the selected repositories?', 'hub2wp' ) ); ?>');">
								<?php esc_html_e( 'Remove Selected', 'hub2wp' ); ?>
							</button>
						</div>
					</div>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col" class="manage-column column-cb check-column" style="padding: 18px 3px 17px;">
								<input type="checkbox" id="h2wp-select-all-monitored" />
							</th>
							<th><?php esc_html_e( 'Repository', 'hub2wp' ); ?></th>
							<th style="width:100px;max-width:100px;"><?php esc_html_e( 'Status', 'hub2wp' ); ?></th>
							<th style="width:80px;max-width:80px;"><?php esc_html_e( 'Actions', 'hub2wp' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $monitored_plugins as $repo_data ) : ?>
							<?php $repo_key = isset( $repo_data['repo'] ) ? $repo_data['repo'] : ''; ?>
							<tr>
								<th scope="row" class="check-column">
									<input type="checkbox" name="h2wp_repo_keys[]"
										value="<?php echo esc_attr( $repo_key ); ?>"
										class="h2wp-monitored-cb" />
								</th>
								<td>
									<strong><?php echo esc_html( isset( $repo_data['name'] ) ? $repo_data['name'] : $repo_key ); ?></strong>
									<br />
									<small>
										<a href="<?php echo esc_url( isset( $repo_data['github_url'] ) ? $repo_data['github_url'] : '' ); ?>" target="_blank" rel="noopener noreferrer">
										<?php echo esc_html( $repo_key ); ?>
										</a>
										<?php
										if ( ! empty( $repo_data['branch'] ) ) :
											?>
											(<?php echo esc_html( $repo_data['branch'] ); ?>)<?php endif; ?>
										<?php if ( array_key_exists( 'prioritize_releases', $repo_data ) && empty( $repo_data['prioritize_releases'] ) ) : ?>
											&mdash; <?php esc_html_e( 'branch only', 'hub2wp' ); ?>
										<?php endif; ?>
										<?php if ( ! empty( $repo_data['plugin_file'] ) ) : ?>
											&rarr; <code><?php echo esc_html( $repo_data['plugin_file'] ); ?></code>
										<?php endif; ?>
									</small>
								</td>
								<td>
									<?php
									if ( ! empty( $repo_data['installed'] ) ) {
										esc_html_e( 'Installed', 'hub2wp' );
									} else {
										esc_html_e( 'Not Installed', 'hub2wp' );
									}
									if ( ! empty( $repo_data['private'] ) ) {
										echo '<span class="dashicons dashicons-lock" title="' . esc_attr__( 'Private Repository', 'hub2wp' ) . '" style="font-size:14px;width:14px;height:16px;vertical-align:middle;margin-left:3px;"></span>';
									}
									?>
								</td>
								<td>
									<button type="button"
										class="button button-small h2wp-single-remove-btn"
										data-repo-key="<?php echo esc_attr( $repo_key ); ?>"
									data-confirm="<?php /* translators: %s: Repository key. */ echo esc_attr( sprintf( __( 'Stop monitoring "%s"?', 'hub2wp' ), $repo_key ) ); ?>">
										<?php esc_html_e( 'Remove', 'hub2wp' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</form>
				<p class="description">
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: URL to the Private tab */
							__( 'These repositories will be monitored for updates. Private repositories can be installed via the <a href="%s">Private tab</a> in Plugins > Add GitHub Plugin.', 'hub2wp' ),
							esc_url( admin_url( 'plugins.php?page=h2wp-plugin-browser&tab=private' ) )
						)
					);
					?>
				</p>
			<?php else : ?>
				<p class="description">
					<?php esc_html_e( 'No repositories added yet.', 'hub2wp' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<script>
		( function() {
			var btn     = document.getElementById( 'h2wp-toggle-monitored' );
			var content = document.getElementById( 'h2wp-monitored-content' );
			var label   = document.getElementById( 'h2wp-toggle-monitored-label' );
			var icon    = btn ? btn.querySelector( '.dashicons' ) : null;
			if ( ! btn || ! content ) { return; }

			// Bulk remove: select all / deselect all.
			var selectAll   = document.getElementById( 'h2wp-select-all-monitored' );
			var bulkBtn     = document.getElementById( 'h2wp-bulk-remove-btn' );
			var updateBulkBtn = function() {
				var checked = document.querySelectorAll( '.h2wp-monitored-cb:checked' ).length;
				if ( bulkBtn ) { bulkBtn.disabled = checked === 0; }
			};
			if ( selectAll ) {
				selectAll.addEventListener( 'change', function() {
					document.querySelectorAll( '.h2wp-monitored-cb' ).forEach( function( cb ) {
						cb.checked = selectAll.checked;
					} );
					updateBulkBtn();
				} );
			}
			// Individual remove buttons use the shared hidden form to avoid nested forms.
			document.querySelectorAll( '.h2wp-single-remove-btn' ).forEach( function( btn ) {
				btn.addEventListener( 'click', function() {
					var repoKey    = this.getAttribute( 'data-repo-key' );
					var confirmMsg = this.getAttribute( 'data-confirm' );
					if ( ! confirm( confirmMsg ) ) { return; }
					document.getElementById( 'h2wp-single-remove-key' ).value = repoKey;
					document.getElementById( 'h2wp-single-remove-form' ).submit();
				} );
			} );
			document.querySelectorAll( '.h2wp-monitored-cb' ).forEach( function( cb ) {
				cb.addEventListener( 'change', function() {
					var total   = document.querySelectorAll( '.h2wp-monitored-cb' ).length;
					var checked = document.querySelectorAll( '.h2wp-monitored-cb:checked' ).length;
					if ( selectAll ) {
						selectAll.checked       = checked === total;
						selectAll.indeterminate = checked > 0 && checked < total;
					}
					updateBulkBtn();
				} );
			} );
			btn.addEventListener( 'click', function( e ) {
				e.preventDefault();
				var isExpanded = this.getAttribute( 'aria-expanded' ) === 'true';
				if ( isExpanded ) {
					content.style.display = 'none';
					this.setAttribute( 'aria-expanded', 'false' );
					if ( icon )  { icon.classList.replace( 'dashicons-arrow-up-alt2',   'dashicons-arrow-down-alt2' ); }
					if ( label ) { label.textContent = <?php echo wp_json_encode( __( 'Show', 'hub2wp' ) ); ?>; }
				} else {
					content.style.display = '';
					this.setAttribute( 'aria-expanded', 'true' );
					if ( icon )  { icon.classList.replace( 'dashicons-arrow-down-alt2', 'dashicons-arrow-up-alt2' ); }
					if ( label ) { label.textContent = <?php echo wp_json_encode( __( 'Hide', 'hub2wp' ) ); ?>; }
				}
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Render the monitored themes section.
	 */
	public static function render_monitored_themes_section() {
		$tracked_service  = new H2WP_Tracked_Repo_Service();
		$monitored_themes = $tracked_service->get_tracked_themes();
		$count            = count( $monitored_themes );
		$expanded         = ! empty( $_GET['h2wp_notice'] ) || ! empty( $_GET['h2wp_error'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$monorepo_enabled = '' !== self::get_access_token();
		?>
		<p style="margin:0;">
			<strong><?php esc_html_e( 'Monitored Themes', 'hub2wp' ); ?></strong>
			<?php if ( $count > 0 ) : ?>
				<span style="color:#646970;">(<?php echo esc_html( $count ); ?>)</span>
			<?php endif; ?>
			&mdash;
			<a href="#" id="h2wp-toggle-monitored-themes" aria-expanded="<?php echo $expanded ? 'true' : 'false'; ?>" aria-controls="h2wp-monitored-themes-content" style="text-decoration: none;">
				<span class="dashicons <?php echo $expanded ? 'dashicons-arrow-up-alt2' : 'dashicons-arrow-down-alt2'; ?>" style="vertical-align:middle;font-size:16px;width:16px;height:16px;"></span>
				<span id="h2wp-toggle-monitored-themes-label"><?php echo $expanded ? esc_html__( 'Hide', 'hub2wp' ) : esc_html__( 'Show', 'hub2wp' ); ?></span>
			</a>
		</p>

		<div id="h2wp-monitored-themes-content" style="display:<?php echo $expanded ? '' : 'none'; ?>;">
			<p class="description" style="margin-top:1em;">
				<?php esc_html_e( 'Add GitHub repositories for themes. They will be stored for theme monitoring workflows.', 'hub2wp' ); ?>
			</p>

			<form method="post" action="">
				<?php wp_nonce_field( 'h2wp_add_private_theme_repo', 'h2wp_private_theme_repo_nonce' ); ?>
				<input type="hidden" name="h2wp_action" value="add_private_theme_repo" />

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="h2wp_private_theme_repo_input">
								<?php esc_html_e( 'Add Theme Repository', 'hub2wp' ); ?>
							</label>
						</th>
						<td>
							<input
								type="text"
								id="h2wp_private_theme_repo_input"
								name="h2wp_private_theme_repo"
								value=""
								placeholder="owner/repo"
								size="30"
							/>
							<input
								type="text"
								id="h2wp_theme_branch_input"
								name="h2wp_theme_branch"
								value=""
								placeholder="main (default)"
								size="15"
							/>
							<label for="h2wp_theme_prioritize_releases" style="margin-right:8px;display:inline-flex;align-items:center;gap:2px;">
								<input type="checkbox" id="h2wp_theme_prioritize_releases" name="h2wp_theme_prioritize_releases" value="1" checked="checked" />
								<?php esc_html_e( 'Prioritize releases', 'hub2wp' ); ?>
							</label>
							<label for="h2wp_scan_theme_monorepo" style="margin-right:8px;display:inline-flex;align-items:center;gap:2px;">
								<input type="checkbox" id="h2wp_scan_theme_monorepo" value="1" <?php disabled( ! $monorepo_enabled ); ?> />
								<?php esc_html_e( 'Scan as monorepo', 'hub2wp' ); ?>
							</label>
							<button type="submit" class="button button-secondary">
								<?php esc_html_e( 'Add Repository', 'hub2wp' ); ?>
							</button>
							<p class="description">
								<?php esc_html_e( 'Enter the repository in the format: owner/repo (e.g., mycompany/private-theme). Optional: specify a branch (defaults to repository default branch).', 'hub2wp' ); ?>
							</p>
							<?php if ( ! $monorepo_enabled ) : ?>
								<p class="description"><?php esc_html_e( 'Save a GitHub access token above to enable monorepo scanning and monitoring.', 'hub2wp' ); ?></p>
							<?php endif; ?>
							<details style="margin-top:6px;">
								<summary><?php esc_html_e( 'What does "Prioritize releases" mean?', 'hub2wp' ); ?></summary>
								<p class="description" style="margin:6px 0 0;">
									<?php esc_html_e( 'When enabled, update/version checks use the latest GitHub release files (tag) when releases exist. When disabled, checks only use the selected branch or the default branch.', 'hub2wp' ); ?>
								</p>
							</details>
							<details style="margin-top:6px;">
								<summary><?php esc_html_e( 'What does "Scan as monorepo" mean?', 'hub2wp' ); ?></summary>
								<p class="description" style="margin:6px 0 0;">
									<?php esc_html_e( 'Use this when one GitHub repository contains multiple WordPress themes in subdirectories. hub2wp scans the repository, lets you choose which themes to add, and monitors each one separately. A saved GitHub access token is required because the scan may make several API requests. Leave this disabled for a normal repository containing one theme.', 'hub2wp' ); ?>
								</p>
							</details>
						</td>
					</tr>
				</table>
			</form>

			<?php if ( ! empty( $monitored_themes ) ) : ?>
				<h3><?php esc_html_e( 'Monitored Themes', 'hub2wp' ); ?></h3>
				<form method="post" action="" id="h2wp-single-remove-theme-form" style="display:none;">
					<?php wp_nonce_field( 'h2wp_remove_private_theme_repo', 'h2wp_remove_theme_repo_nonce' ); ?>
					<input type="hidden" name="h2wp_action" value="remove_private_theme_repo" />
					<input type="hidden" name="h2wp_repo_key" id="h2wp-single-remove-theme-key" value="" />
				</form>
				<form method="post" action="" id="h2wp-bulk-remove-theme-form">
					<?php wp_nonce_field( 'h2wp_bulk_remove_theme_repos', 'h2wp_bulk_remove_theme_nonce' ); ?>
					<input type="hidden" name="h2wp_action" value="bulk_remove_theme_repos" />
					<div class="tablenav top" style="margin-bottom:6px;">
						<div class="alignleft actions">
							<button type="submit" id="h2wp-bulk-remove-theme-btn" class="button" disabled
								onclick="return confirm('<?php echo esc_js( __( 'Stop monitoring the selected theme repositories?', 'hub2wp' ) ); ?>');">
								<?php esc_html_e( 'Remove Selected', 'hub2wp' ); ?>
							</button>
						</div>
					</div>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th scope="col" class="manage-column column-cb check-column" style="padding: 18px 3px 17px;">
									<input type="checkbox" id="h2wp-select-all-monitored-themes" />
								</th>
								<th><?php esc_html_e( 'Repository', 'hub2wp' ); ?></th>
								<th style="width:100px;max-width:100px;"><?php esc_html_e( 'Status', 'hub2wp' ); ?></th>
								<th style="width:80px;max-width:80px;"><?php esc_html_e( 'Actions', 'hub2wp' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $monitored_themes as $repo_data ) : ?>
								<?php $repo_key = isset( $repo_data['repo'] ) ? $repo_data['repo'] : ''; ?>
								<tr>
									<th scope="row" class="check-column">
										<input type="checkbox" name="h2wp_repo_keys[]"
											value="<?php echo esc_attr( $repo_key ); ?>"
											class="h2wp-monitored-theme-cb" />
									</th>
									<td>
										<strong><?php echo esc_html( isset( $repo_data['name'] ) ? $repo_data['name'] : $repo_key ); ?></strong>
										<br />
										<small>
											<a href="<?php echo esc_url( isset( $repo_data['github_url'] ) ? $repo_data['github_url'] : '' ); ?>" target="_blank" rel="noopener noreferrer">
												<?php echo esc_html( $repo_key ); ?>
											</a>
											<?php
											if ( ! empty( $repo_data['branch'] ) ) :
												?>
												(<?php echo esc_html( $repo_data['branch'] ); ?>)<?php endif; ?>
											<?php if ( array_key_exists( 'prioritize_releases', $repo_data ) && empty( $repo_data['prioritize_releases'] ) ) : ?>
												&mdash; <?php esc_html_e( 'branch only', 'hub2wp' ); ?>
											<?php endif; ?>
											<?php if ( ! empty( $repo_data['stylesheet'] ) ) : ?>
												&rarr; <code><?php echo esc_html( $repo_data['stylesheet'] ); ?></code>
											<?php endif; ?>
										</small>
									</td>
									<td>
										<?php
										if ( ! empty( $repo_data['installed'] ) ) {
											esc_html_e( 'Installed', 'hub2wp' );
										} else {
											esc_html_e( 'Not Installed', 'hub2wp' );
										}
										if ( ! empty( $repo_data['private'] ) ) {
											echo '<span class="dashicons dashicons-lock" title="' . esc_attr__( 'Private Repository', 'hub2wp' ) . '" style="font-size:14px;width:14px;height:16px;vertical-align:middle;margin-left:3px;"></span>';
										}
										?>
									</td>
									<td>
										<button type="button"
											class="button button-small h2wp-single-remove-theme-btn"
											data-repo-key="<?php echo esc_attr( $repo_key ); ?>"
										data-confirm="<?php /* translators: %s: Repository key. */ echo esc_attr( sprintf( __( 'Stop monitoring "%s"?', 'hub2wp' ), $repo_key ) ); ?>">
											<?php esc_html_e( 'Remove', 'hub2wp' ); ?>
										</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</form>
			<?php else : ?>
				<p class="description">
					<?php esc_html_e( 'No theme repositories added yet.', 'hub2wp' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<script>
		( function() {
			var btn     = document.getElementById( 'h2wp-toggle-monitored-themes' );
			var content = document.getElementById( 'h2wp-monitored-themes-content' );
			var label   = document.getElementById( 'h2wp-toggle-monitored-themes-label' );
			var icon    = btn ? btn.querySelector( '.dashicons' ) : null;
			if ( ! btn || ! content ) { return; }
			// Individual remove.
			document.querySelectorAll( '.h2wp-single-remove-theme-btn' ).forEach( function( btn ) {
				btn.addEventListener( 'click', function() {
					var repoKey    = this.getAttribute( 'data-repo-key' );
					var confirmMsg = this.getAttribute( 'data-confirm' );
					if ( ! confirm( confirmMsg ) ) { return; }
					document.getElementById( 'h2wp-single-remove-theme-key' ).value = repoKey;
					document.getElementById( 'h2wp-single-remove-theme-form' ).submit();
				} );
			} );

			// Bulk remove select all.
			var selectAllThemes = document.getElementById( 'h2wp-select-all-monitored-themes' );
			var bulkThemeBtn    = document.getElementById( 'h2wp-bulk-remove-theme-btn' );
			var updateBulkThemeBtn = function() {
				var checked = document.querySelectorAll( '.h2wp-monitored-theme-cb:checked' ).length;
				if ( bulkThemeBtn ) { bulkThemeBtn.disabled = checked === 0; }
			};
			if ( selectAllThemes ) {
				selectAllThemes.addEventListener( 'change', function() {
					document.querySelectorAll( '.h2wp-monitored-theme-cb' ).forEach( function( cb ) {
						cb.checked = selectAllThemes.checked;
					} );
					updateBulkThemeBtn();
				} );
			}
			document.querySelectorAll( '.h2wp-monitored-theme-cb' ).forEach( function( cb ) {
				cb.addEventListener( 'change', function() {
					var total   = document.querySelectorAll( '.h2wp-monitored-theme-cb' ).length;
					var checked = document.querySelectorAll( '.h2wp-monitored-theme-cb:checked' ).length;
					if ( selectAllThemes ) {
						selectAllThemes.checked       = checked === total;
						selectAllThemes.indeterminate = checked > 0 && checked < total;
					}
					updateBulkThemeBtn();
				} );
			} );
			btn.addEventListener( 'click', function( e ) {
				e.preventDefault();
				var isExpanded = this.getAttribute( 'aria-expanded' ) === 'true';
				if ( isExpanded ) {
					content.style.display = 'none';
					this.setAttribute( 'aria-expanded', 'false' );
					if ( icon )  { icon.classList.replace( 'dashicons-arrow-up-alt2',   'dashicons-arrow-down-alt2' ); }
					if ( label ) { label.textContent = <?php echo wp_json_encode( __( 'Show', 'hub2wp' ) ); ?>; }
				} else {
					content.style.display = '';
					this.setAttribute( 'aria-expanded', 'true' );
					if ( icon )  { icon.classList.replace( 'dashicons-arrow-down-alt2', 'dashicons-arrow-up-alt2' ); }
					if ( label ) { label.textContent = <?php echo wp_json_encode( __( 'Hide', 'hub2wp' ) ); ?>; }
				}
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Add a repository to the monitored plugins list.
	 *
	 * Shared by both the PHP form handler and the AJAX endpoint so the
	 * logic lives in one place. Supports monorepo plugins via $subdirectory.
	 *
	 * @param string $owner        Repository owner.
	 * @param string $repo         Repository name.
	 * @param string $branch       Optional branch.
	 * @param bool   $prioritize   Whether to prioritize releases.
	 * @param string $subdirectory Subdirectory path for a monorepo project (empty for single repos).
	 * @param string $repo_type    Repository type: plugin|theme.
	 * @return string|WP_Error     The stored repo key on success, WP_Error on failure.
	 */
	public static function add_repo_to_monitored( $owner, $repo, $branch = '', $prioritize = true, $subdirectory = '', $repo_type = 'plugin' ) {
		$repo_type    = in_array( $repo_type, array( 'plugin', 'theme' ), true ) ? $repo_type : 'plugin';
		$owner        = strtolower( sanitize_text_field( trim( (string) $owner ) ) );
		$repo         = strtolower( sanitize_text_field( trim( (string) $repo ) ) );
		$subdirectory = self::normalize_subdirectory( $subdirectory );

		if ( is_wp_error( $subdirectory ) ) {
			return $subdirectory;
		}

		if ( ! self::validate_repo_format( $owner . '/' . $repo ) ) {
			return new WP_Error( 'h2wp_invalid_repo', __( 'Invalid repository owner or name.', 'hub2wp' ) );
		}

		$access_token  = self::get_access_token();
		$repo_key_base = $owner . '/' . $repo;
		if ( '' !== $subdirectory && '' === $access_token ) {
			return new WP_Error(
				'h2wp_monorepo_token_required',
				__( 'Monorepo support requires a saved GitHub access token. Add one in hub2wp Settings and try again.', 'hub2wp' )
			);
		}

		$repo_data = self::verify_repo( $repo_key_base, $access_token );
		if ( is_wp_error( $repo_data ) ) {
			return $repo_data;
		}

		$repo_key = self::get_tracked_repo_key( $owner, $repo, $subdirectory );
		if ( is_wp_error( $repo_key ) ) {
			return $repo_key;
		}

		$option_name       = ( 'theme' === $repo_type ) ? 'h2wp_themes' : 'h2wp_plugins';
		$monitored_plugins = self::get_monitored_repositories( $repo_type );

		$existing_key = self::find_tracked_repo_key( $monitored_plugins, $owner, $repo, $subdirectory );
		if ( '' !== $existing_key ) {
			return new WP_Error(
				'h2wp_repo_exists',
				// Translators: %s is the repository key.
				sprintf( __( 'Repository project "%s" is already being monitored.', 'hub2wp' ), $repo_key )
			);
		}

		// For monorepo entries, the installed folder matches the slug, not the repo name.
		$lookup_slug = ! empty( $subdirectory ) ? basename( $subdirectory ) : $repo;
		$plugin_file = false;
		$stylesheet  = false;

		if ( class_exists( 'H2WP_Admin_Page' ) ) {
			if ( 'theme' === $repo_type ) {
				$stylesheet = H2WP_Admin_Page::get_installed_theme_stylesheet( $owner, $lookup_slug );
			} else {
				$plugin_file = H2WP_Admin_Page::get_installed_plugin_file( $owner, $lookup_slug );
			}
		}

		$entry = array(
			'owner'               => $owner,
			'repo'                => $repo,
			'repo_type'           => $repo_type,
			'name'                => ! empty( $subdirectory ) ? basename( $subdirectory ) : ( isset( $repo_data['name'] ) ? $repo_data['name'] : $repo ),
			'private'             => isset( $repo_data['private'] ) ? (bool) $repo_data['private'] : false,
			'branch'              => (string) $branch,
			'prioritize_releases' => (bool) $prioritize,
			'subdirectory'        => $subdirectory,
			'added'               => time(),
			'added_by'            => get_current_user_id(),
			'last_checked'        => time(),
			'last_updated'        => time(),
		);

		if ( $plugin_file ) {
			$entry['plugin_file'] = $plugin_file;
		}
		if ( $stylesheet ) {
			$entry['stylesheet'] = $stylesheet;
		}

		$monitored_plugins[ $repo_key ] = $entry;

		if ( ! update_option( $option_name, $monitored_plugins ) ) {
			return new WP_Error( 'h2wp_add_failed', __( 'Failed to save repository. Please try again.', 'hub2wp' ) );
		}

		return $repo_key;
	}

	/**
	 * Handle private repository actions (add/remove).
	 */
	public static function handle_private_repo_actions() {
		// Verify at least one of the expected nonces before reading any POST data.
		$add_nonce_valid               = isset( $_POST['h2wp_private_repo_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['h2wp_private_repo_nonce'] ) ), 'h2wp_add_private_repo' );
		$remove_nonce_valid            = isset( $_POST['h2wp_remove_repo_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['h2wp_remove_repo_nonce'] ) ), 'h2wp_remove_private_repo' );
		$add_theme_nonce_valid         = isset( $_POST['h2wp_private_theme_repo_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['h2wp_private_theme_repo_nonce'] ) ), 'h2wp_add_private_theme_repo' );
		$remove_theme_nonce_valid      = isset( $_POST['h2wp_remove_theme_repo_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['h2wp_remove_theme_repo_nonce'] ) ), 'h2wp_remove_private_theme_repo' );
		$bulk_remove_nonce_valid       = isset( $_POST['h2wp_bulk_remove_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['h2wp_bulk_remove_nonce'] ) ), 'h2wp_bulk_remove_repos' );
		$bulk_remove_theme_nonce_valid = isset( $_POST['h2wp_bulk_remove_theme_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['h2wp_bulk_remove_theme_nonce'] ) ), 'h2wp_bulk_remove_theme_repos' );

		if ( ! $add_nonce_valid && ! $remove_nonce_valid && ! $add_theme_nonce_valid && ! $remove_theme_nonce_valid && ! $bulk_remove_nonce_valid && ! $bulk_remove_theme_nonce_valid ) {
			return;
		}

		if ( ! isset( $_POST['h2wp_action'] ) ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_POST['h2wp_action'] ) );

		if ( 'add_private_repo' === $action ) {
			self::handle_add_private_repo();
		} elseif ( 'remove_private_repo' === $action ) {
			self::handle_remove_private_repo();
		} elseif ( 'add_private_theme_repo' === $action ) {
			self::handle_add_private_theme_repo();
		} elseif ( 'remove_private_theme_repo' === $action ) {
			self::handle_remove_private_theme_repo();
		} elseif ( 'bulk_remove_repos' === $action ) {
			self::handle_bulk_remove_repos();
		} elseif ( 'bulk_remove_theme_repos' === $action ) {
			self::handle_bulk_remove_theme_repos();
		}
	}

	/**
	 * Handle the "Run now" update check action from the settings page.
	 */
	public static function handle_run_update_check_action() {
		if ( ! isset( $_GET['action'] ) || 'h2wp_run_update_check' !== sanitize_key( wp_unslash( $_GET['action'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'hub2wp' ) );
		}

		check_admin_referer( 'h2wp_run_update_check' );

		$service = new H2WP_System_Action_Service();
		$service->run_update_check();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                    => 'h2wp_settings_page',
					'h2wp_update_checked'     => '1',
					'h2wp_update_check_nonce' => wp_create_nonce( 'h2wp_update_checked' ),
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Handle adding a repository.
	 */
	private static function handle_add_private_repo() {
		if ( ! isset( $_POST['h2wp_private_repo_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['h2wp_private_repo_nonce'] ) ), 'h2wp_add_private_repo' ) ) {
			add_settings_error(
				'h2wp_private_repos',
				'h2wp_nonce_error',
				__( 'Security check failed. Please try again.', 'hub2wp' ),
				'error'
			);
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			add_settings_error(
				'h2wp_private_repos',
				'h2wp_permission_error',
				__( 'You do not have permission to add repositories.', 'hub2wp' ),
				'error'
			);
			return;
		}

		if ( ! isset( $_POST['h2wp_private_repo'] ) || empty( $_POST['h2wp_private_repo'] ) ) {
			add_settings_error(
				'h2wp_private_repos',
				'h2wp_empty_repo',
				__( 'Please enter a repository in the format owner/repo.', 'hub2wp' ),
				'error'
			);
			return;
		}

		$repo_input          = sanitize_text_field( wp_unslash( $_POST['h2wp_private_repo'] ) );
		$branch              = isset( $_POST['h2wp_branch'] ) ? sanitize_text_field( wp_unslash( $_POST['h2wp_branch'] ) ) : '';
		$prioritize_releases = isset( $_POST['h2wp_prioritize_releases'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['h2wp_prioritize_releases'] ) );

		// Validate format: owner/repo.
		if ( ! self::validate_repo_format( $repo_input ) ) {
			add_settings_error(
				'h2wp_private_repos',
				'h2wp_invalid_format',
				__( 'Invalid repository format. Please use "owner/repo" format (e.g., mycompany/private-plugin).', 'hub2wp' ),
				'error'
			);
			return;
		}

		$repo_key             = strtolower( $repo_input );
		list( $owner, $repo ) = explode( '/', $repo_key, 2 );
		$result               = self::add_repo_to_monitored( $owner, $repo, $branch, $prioritize_releases, '', 'plugin' );
		if ( is_wp_error( $result ) ) {
			add_settings_error(
				'h2wp_private_repos',
				$result->get_error_code(),
				$result->get_error_message(),
				'h2wp_repo_exists' === $result->get_error_code() ? 'warning' : 'error'
			);
			return;
		}

		add_settings_error(
			'h2wp_private_repos',
			'h2wp_repo_added',
			// Translators: %s is the repository name (owner/repo).
			sprintf( __( 'Repository "%s" has been added successfully.', 'hub2wp' ), $repo_key ),
			'success'
		);
	}

	/**
	 * Handle removing a repository.
	 */
	private static function handle_remove_private_repo() {
		if ( ! isset( $_POST['h2wp_remove_repo_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['h2wp_remove_repo_nonce'] ) ), 'h2wp_remove_private_repo' ) ) {
			add_settings_error(
				'h2wp_private_repos',
				'h2wp_nonce_error',
				__( 'Security check failed. Please try again.', 'hub2wp' ),
				'error'
			);
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			add_settings_error(
				'h2wp_private_repos',
				'h2wp_permission_error',
				__( 'You do not have permission to remove repositories.', 'hub2wp' ),
				'error'
			);
			return;
		}

		if ( ! isset( $_POST['h2wp_repo_key'] ) || empty( $_POST['h2wp_repo_key'] ) ) {
			add_settings_error(
				'h2wp_private_repos',
				'h2wp_missing_repo',
				__( 'No repository specified for removal.', 'hub2wp' ),
				'error'
			);
			return;
		}

		$repo_key = sanitize_text_field( wp_unslash( $_POST['h2wp_repo_key'] ) );

		$monitored_plugins = self::get_monitored_repositories( 'plugin' );
		if ( ! isset( $monitored_plugins[ $repo_key ] ) ) {
			add_settings_error(
				'h2wp_private_repos',
				'h2wp_remove_failed',
				// Translators: %s is the repository name (owner/repo).
				sprintf( __( 'Repository "%s" not found.', 'hub2wp' ), $repo_key ),
				'error'
			);
			return;
		}

		unset( $monitored_plugins[ $repo_key ] );
		$result = update_option( 'h2wp_plugins', $monitored_plugins );

		if ( ! $result ) {
			add_settings_error(
				'h2wp_private_repos',
				'h2wp_remove_failed',
				__( 'Failed to remove repository. Please try again.', 'hub2wp' ),
				'error'
			);
			return;
		}

		add_settings_error(
			'h2wp_private_repos',
			'h2wp_repo_removed',
			// Translators: %s is the repository name (owner/repo).
			sprintf( __( 'Repository "%s" has been removed.', 'hub2wp' ), $repo_key ),
			'success'
		);
	}

	/**
	 * Handle adding a theme repository.
	 */
	private static function handle_add_private_theme_repo() {
		if ( ! isset( $_POST['h2wp_private_theme_repo_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['h2wp_private_theme_repo_nonce'] ) ), 'h2wp_add_private_theme_repo' ) ) {
			add_settings_error( 'h2wp_theme_repos', 'h2wp_theme_nonce_error', __( 'Security check failed. Please try again.', 'hub2wp' ), 'error' );
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			add_settings_error( 'h2wp_theme_repos', 'h2wp_theme_permission_error', __( 'You do not have permission to add theme repositories.', 'hub2wp' ), 'error' );
			return;
		}
		if ( ! isset( $_POST['h2wp_private_theme_repo'] ) || empty( $_POST['h2wp_private_theme_repo'] ) ) {
			add_settings_error( 'h2wp_theme_repos', 'h2wp_theme_empty_repo', __( 'Please enter a repository in the format owner/repo.', 'hub2wp' ), 'error' );
			return;
		}

		$repo_input          = sanitize_text_field( wp_unslash( $_POST['h2wp_private_theme_repo'] ) );
		$branch              = isset( $_POST['h2wp_theme_branch'] ) ? sanitize_text_field( wp_unslash( $_POST['h2wp_theme_branch'] ) ) : '';
		$prioritize_releases = isset( $_POST['h2wp_theme_prioritize_releases'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['h2wp_theme_prioritize_releases'] ) );
		if ( ! self::validate_repo_format( $repo_input ) ) {
			add_settings_error( 'h2wp_theme_repos', 'h2wp_theme_invalid_format', __( 'Invalid repository format. Please use "owner/repo" format.', 'hub2wp' ), 'error' );
			return;
		}

		$repo_key             = strtolower( $repo_input );
		list( $owner, $repo ) = explode( '/', $repo_key, 2 );
		$result               = self::add_repo_to_monitored( $owner, $repo, $branch, $prioritize_releases, '', 'theme' );
		if ( is_wp_error( $result ) ) {
			add_settings_error( 'h2wp_theme_repos', $result->get_error_code(), $result->get_error_message(), 'h2wp_repo_exists' === $result->get_error_code() ? 'warning' : 'error' );
			return;
		}

		/* translators: %s: Repository name in owner/repo format. */
		add_settings_error( 'h2wp_theme_repos', 'h2wp_theme_repo_added', sprintf( __( 'Theme repository "%s" has been added successfully.', 'hub2wp' ), $repo_key ), 'success' );
	}

	/**
	 * Handle removing a theme repository.
	 */
	private static function handle_remove_private_theme_repo() {
		if ( ! isset( $_POST['h2wp_remove_theme_repo_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['h2wp_remove_theme_repo_nonce'] ) ), 'h2wp_remove_private_theme_repo' ) ) {
			add_settings_error( 'h2wp_theme_repos', 'h2wp_theme_nonce_error', __( 'Security check failed. Please try again.', 'hub2wp' ), 'error' );
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			add_settings_error( 'h2wp_theme_repos', 'h2wp_theme_permission_error', __( 'You do not have permission to remove theme repositories.', 'hub2wp' ), 'error' );
			return;
		}
		if ( ! isset( $_POST['h2wp_repo_key'] ) || empty( $_POST['h2wp_repo_key'] ) ) {
			add_settings_error( 'h2wp_theme_repos', 'h2wp_theme_missing_repo', __( 'No repository specified for removal.', 'hub2wp' ), 'error' );
			return;
		}

		$repo_key         = sanitize_text_field( wp_unslash( $_POST['h2wp_repo_key'] ) );
		$monitored_themes = self::get_monitored_repositories( 'theme' );
		if ( ! isset( $monitored_themes[ $repo_key ] ) ) {
			/* translators: %s: Repository name in owner/repo format. */
			add_settings_error( 'h2wp_theme_repos', 'h2wp_theme_remove_missing', sprintf( __( 'Theme repository "%s" not found.', 'hub2wp' ), $repo_key ), 'error' );
			return;
		}

		unset( $monitored_themes[ $repo_key ] );
		if ( ! update_option( 'h2wp_themes', $monitored_themes ) ) {
			add_settings_error( 'h2wp_theme_repos', 'h2wp_theme_remove_failed', __( 'Failed to remove theme repository. Please try again.', 'hub2wp' ), 'error' );
			return;
		}

		/* translators: %s: Repository name in owner/repo format. */
		add_settings_error( 'h2wp_theme_repos', 'h2wp_theme_repo_removed', sprintf( __( 'Theme repository "%s" has been removed.', 'hub2wp' ), $repo_key ), 'success' );
	}

	/**
	 * Handle bulk removal of monitored plugin repositories.
	 */
	private static function handle_bulk_remove_repos() {
		if ( ! isset( $_POST['h2wp_bulk_remove_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['h2wp_bulk_remove_nonce'] ) ), 'h2wp_bulk_remove_repos' ) ) {
			add_settings_error( 'h2wp_private_repos', 'h2wp_nonce_error', __( 'Security check failed. Please try again.', 'hub2wp' ), 'error' );
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			add_settings_error( 'h2wp_private_repos', 'h2wp_permission_error', __( 'You do not have permission to remove repositories.', 'hub2wp' ), 'error' );
			return;
		}

		if ( empty( $_POST['h2wp_repo_keys'] ) || ! is_array( $_POST['h2wp_repo_keys'] ) ) {
			add_settings_error( 'h2wp_private_repos', 'h2wp_no_selection', __( 'No repositories selected for removal.', 'hub2wp' ), 'warning' );
			return;
		}

		$repo_keys         = array_map( 'sanitize_text_field', wp_unslash( $_POST['h2wp_repo_keys'] ) );
		$monitored_plugins = self::get_monitored_repositories( 'plugin' );
		$removed           = 0;

		foreach ( $repo_keys as $repo_key ) {
			if ( isset( $monitored_plugins[ $repo_key ] ) ) {
				unset( $monitored_plugins[ $repo_key ] );
				++$removed;
			}
		}

		update_option( 'h2wp_plugins', $monitored_plugins );

		add_settings_error(
			'h2wp_private_repos',
			'h2wp_bulk_removed',
			sprintf(
				// Translators: %d is the number of repositories removed.
				_n( '%d repository removed from monitoring.', '%d repositories removed from monitoring.', $removed, 'hub2wp' ),
				$removed
			),
			'success'
		);
	}

	/**
	 * Handle bulk removal of monitored theme repositories.
	 */
	private static function handle_bulk_remove_theme_repos() {
		if ( ! isset( $_POST['h2wp_bulk_remove_theme_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['h2wp_bulk_remove_theme_nonce'] ) ), 'h2wp_bulk_remove_theme_repos' ) ) {
			add_settings_error( 'h2wp_theme_repos', 'h2wp_nonce_error', __( 'Security check failed. Please try again.', 'hub2wp' ), 'error' );
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			add_settings_error( 'h2wp_theme_repos', 'h2wp_permission_error', __( 'You do not have permission to remove repositories.', 'hub2wp' ), 'error' );
			return;
		}
		if ( empty( $_POST['h2wp_repo_keys'] ) || ! is_array( $_POST['h2wp_repo_keys'] ) ) {
			add_settings_error( 'h2wp_theme_repos', 'h2wp_no_selection', __( 'No repositories selected for removal.', 'hub2wp' ), 'warning' );
			return;
		}

		$repo_keys        = array_map( 'sanitize_text_field', wp_unslash( $_POST['h2wp_repo_keys'] ) );
		$monitored_themes = self::get_monitored_repositories( 'theme' );
		$removed          = 0;

		foreach ( $repo_keys as $repo_key ) {
			if ( isset( $monitored_themes[ $repo_key ] ) ) {
				unset( $monitored_themes[ $repo_key ] );
				++$removed;
			}
		}

		update_option( 'h2wp_themes', $monitored_themes );

		add_settings_error(
			'h2wp_theme_repos',
			'h2wp_bulk_theme_removed',
			sprintf(
				/* translators: %d: Number of repositories removed. */
				_n( '%d theme repository removed from monitoring.', '%d theme repositories removed from monitoring.', $removed, 'hub2wp' ),
				$removed
			),
			'success'
		);
	}

	/**
	 * Handle clearing all cached plugin data.
	 *
	 * Hooked to: admin_post_h2wp_clear_cache
	 */
	public static function handle_clear_cache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'hub2wp' ) );
		}

		check_admin_referer( 'h2wp_clear_cache', 'h2wp_clear_cache_nonce' );

		$service = new H2WP_System_Action_Service();
		$service->clear_cache();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'               => 'h2wp_settings_page',
					'h2wp_cleared'       => '1',
					'h2wp_cleared_nonce' => wp_create_nonce( 'h2wp_cleared' ),
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * AJAX handler for clearing the cache.
	 *
	 * Hooked to: wp_ajax_h2wp_clear_cache
	 */
	public static function ajax_clear_cache() {
		check_ajax_referer( 'h2wp_clear_cache', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'hub2wp' ) ) );
		}

		$service = new H2WP_System_Action_Service();
		wp_send_json_success( $service->clear_cache() );
	}

	/**
	 * Display notices for private repository actions.
	 */
	public static function display_private_repo_notices() {
		// Only show on our settings page.
		$screen = get_current_screen();
		if ( ! $screen || 'settings_page_h2wp_settings_page' !== $screen->id ) {
			return;
		}

		// Show a success notice after cache has been cleared.
		if ( isset( $_GET['h2wp_cleared'], $_GET['h2wp_cleared_nonce'] )
			&& '1' === $_GET['h2wp_cleared']
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['h2wp_cleared_nonce'] ) ), 'h2wp_cleared' ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Cache cleared successfully.', 'hub2wp' ) . '</p></div>';
		}

		if ( isset( $_GET['h2wp_update_checked'], $_GET['h2wp_update_check_nonce'] )
			&& '1' === $_GET['h2wp_update_checked']
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['h2wp_update_check_nonce'] ) ), 'h2wp_update_checked' ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Update check completed for monitored plugins and themes.', 'hub2wp' ) . '</p></div>';
		}

		// Note: settings_errors() is not called here because WordPress automatically
		// displays settings errors on options pages. Calling it manually would cause
		// duplicate notices. The add_settings_error() calls in handle_private_repo_actions()
		// are sufficient for notices to appear.
	}

	/**
	 * Display a success notice after monorepo projects are added via AJAX redirect.
	 */
	public static function display_plugins_added_notice() {
		if ( ! isset( $_GET['page'] ) || 'h2wp_settings_page' !== sanitize_key( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( empty( $_GET['h2wp_added'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$count = absint( $_GET['h2wp_added'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice.
		if ( ! $count ) {
			return;
		}
		$repo_type = isset( $_GET['h2wp_added_type'] ) ? sanitize_key( wp_unslash( $_GET['h2wp_added_type'] ) ) : 'plugin'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_theme  = 'theme' === $repo_type;
		if ( $is_theme ) {
			/* translators: %d: Number of themes added. */
			$message = _n( '%d theme added to monitoring successfully.', '%d themes added to monitoring successfully.', $count, 'hub2wp' );
		} else {
			/* translators: %d: Number of plugins added. */
			$message = _n( '%d plugin added to monitoring successfully.', '%d plugins added to monitoring successfully.', $count, 'hub2wp' );
		}

		echo '<div class="notice notice-success is-dismissible"><p>';
		echo esc_html( sprintf( $message, $count ) );
		echo '</p></div>';
	}

	/**
	 * Validate repository format (owner/repo).
	 *
	 * @param string $repo The repository string to validate.
	 * @return bool True if valid, false otherwise.
	 */
	public static function validate_repo_format( $repo ) {
		// Format: owner/repo where both parts contain only allowed characters.
		// GitHub usernames/repos can contain alphanumeric, hyphens, and underscores,
		// but cannot start or end with hyphens.
		$pattern = '/^[a-zA-Z0-9_-]+\/[a-zA-Z0-9_.-]+$/';
		return preg_match( $pattern, $repo ) === 1;
	}

	/**
	 * Verify a repository exists and is accessible.
	 *
	 * @param string $repo_key The repository in owner/repo format.
	 * @param string $access_token The GitHub access token.
	 * @return array|WP_Error Repo data if verified, WP_Error on failure.
	 */
	public static function verify_repo( $repo_key, $access_token ) {
		$auth_key  = empty( $access_token ) ? 'public' : 'auth_' . md5( $access_token );
		$cache_key = 'verified_repo_' . md5( strtolower( $repo_key ) . '|' . $auth_key );
		$cached    = H2WP_Cache::get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$url = 'https://api.github.com/repos/' . $repo_key;

		$headers = array(
			'Accept'     => 'application/vnd.github.v3+json',
			'User-Agent' => 'hub2wp/1.0',
		);

		if ( ! empty( $access_token ) ) {
			$headers['Authorization'] = 'Bearer ' . $access_token;
		}

		$response = wp_remote_get(
			$url,
			array(
				'headers' => $headers,
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'request_failed',
				// translators: %s is the error message from the HTTP request.
				sprintf( __( 'Failed to connect to GitHub: %s', 'hub2wp' ), $response->get_error_message() )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 404 === $status_code ) {
			return new WP_Error(
				'repo_not_found',
				// translators: %s is the repository name (owner/repo).
				sprintf( __( 'Repository "%s" not found or you do not have access to it. If it is a private repository, please ensure your access token has the "repo" scope.', 'hub2wp' ), $repo_key )
			);
		}

		if ( 401 === $status_code ) {
			return new WP_Error(
				'unauthorized',
				__( 'Your access token is invalid or does not have permission to access this repository. Please check your token and ensure it has the "repo" scope.', 'hub2wp' )
			);
		}

		if ( 403 === $status_code ) {
			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( isset( $data['message'] ) && is_string( $data['message'] ) && false !== strpos( $data['message'], 'rate limit' ) ) {
				return new WP_Error(
					'rate_limited',
					__( 'GitHub API rate limit exceeded. Please wait a few minutes before trying again.', 'hub2wp' )
				);
			}

			return new WP_Error(
				'forbidden',
				__( 'Your access token does not have permission to access this repository. Please ensure it has the "repo" scope.', 'hub2wp' )
			);
		}

		if ( 200 !== $status_code ) {
			return new WP_Error(
				'api_error',
				// translators: %d is the HTTP status code returned by the GitHub API.
				sprintf( __( 'GitHub API returned error code %d. Please try again later.', 'hub2wp' ), $status_code )
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid_response', __( 'GitHub returned an invalid repository response.', 'hub2wp' ) );
		}

		H2WP_Cache::set( $cache_key, $data );
		return $data;
	}

	/**
	 * Render the next scheduled update-check time.
	 *
	 * @return void
	 */
	public static function next_run_schedule() {
		?>
		<p>
			<?php
			$next_check = wp_next_scheduled( 'h2wp_daily_update_check' );
			printf(
				/* translators: 1: Human-readable time difference, 2: Link to run the update check. */
				esc_html__( 'The daily update check is scheduled to run in %1$s. %2$s', 'hub2wp' ),
				'<span>' . esc_html( $next_check ? human_time_diff( time(), $next_check ) : __( 'less than 1 minute', 'hub2wp' ) ) . '</span>',
				sprintf(
					'<a href="%s">%s</a>',
					esc_html( wp_nonce_url( admin_url( 'options-general.php?page=h2wp_settings_page&action=h2wp_run_update_check' ), 'h2wp_run_update_check' ) ),
					esc_html__( 'Run now', 'hub2wp' )
				)
			);
			?>
		</p>
		<?php
	}

	/**
	 * Access token field callback.
	 */
	public static function access_token_field() {
		$options      = get_option( self::OPTION_NAME, array() );
		$options      = is_array( $options ) ? $options : array();
		$access_token = isset( $options['access_token'] ) ? $options['access_token'] : '';
		?>
		<input type="password" id="h2wp_access_token" name="h2wp_settings[access_token]" value="<?php echo esc_attr( $access_token ); ?>" size="50" />
		<p class="description">
			<?php esc_html_e( 'Enter a GitHub personal access token for private repositories, monorepo support, and a higher API rate limit.', 'hub2wp' ); ?>
			<?php
			printf(
				/* translators: %s: URL to create a personal access token */
				esc_html__( 'Get a free token from %s.', 'hub2wp' ),
				'<a href="https://github.com/settings/tokens" target="_blank">GitHub</a>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Cache duration field callback.
	 */
	public static function cache_duration_field() {
		$options        = get_option( self::OPTION_NAME, array() );
		$options        = is_array( $options ) ? $options : array();
		$cache_duration = isset( $options['cache_duration'] ) ? (int) $options['cache_duration'] : 12;
		?>
		<input type="number" name="h2wp_settings[cache_duration]" value="<?php echo esc_attr( $cache_duration ); ?>" min="1" />
		<p class="description"><?php esc_html_e( 'How long to cache search results and plugin data in hours.', 'hub2wp' ); ?></p>
		<?php
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Input options.
	 * @return array Sanitized options.
	 */
	public static function sanitize_settings( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$output  = array();
		$current = get_option( self::OPTION_NAME, array() );
		$current = is_array( $current ) ? $current : array();

		if ( isset( $input['access_token'] ) ) {
			$output['access_token'] = sanitize_text_field( $input['access_token'] );
			if ( ( isset( $current['access_token'] ) ? $current['access_token'] : '' ) !== $output['access_token'] ) {
				delete_transient( 'h2wp_last_update_check' );
			}
		}

		if ( isset( $input['cache_duration'] ) ) {
			$output['cache_duration'] = absint( $input['cache_duration'] );
		}

		return $output;
	}

	/**
	 * Get access token.
	 *
	 * @return string Token.
	 */
	public static function get_access_token() {
		$options = get_option( self::OPTION_NAME, array() );
		$options = is_array( $options ) ? $options : array();
		return isset( $options['access_token'] ) ? $options['access_token'] : '';
	}

	/**
	 * Check whether any existing tracked entry represents a monorepo project.
	 *
	 * @return bool True when at least one monorepo project is monitored.
	 */
	private static function has_monitored_monorepo_projects() {
		foreach ( array( 'plugin', 'theme' ) as $repo_type ) {
			foreach ( self::get_monitored_repositories( $repo_type ) as $repo_key => $repo_data ) {
				$identity = self::get_tracked_repo_identity( $repo_key, $repo_data );
				if ( ! is_wp_error( $identity ) && '' !== $identity['subdirectory'] ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Get cache duration in seconds.
	 *
	 * @return int Duration in seconds.
	 */
	public static function get_cache_duration() {
		$options = get_option( self::OPTION_NAME, array() );
		$options = is_array( $options ) ? $options : array();
		$hours   = isset( $options['cache_duration'] ) ? (int) $options['cache_duration'] : 12;
		$hours   = $hours > 0 ? $hours : 12;
		return $hours * HOUR_IN_SECONDS;
	}

	/**
	 * Read monitored repository records defensively.
	 *
	 * @param string $repo_type Repository type: plugin|theme.
	 * @return array Tracked records.
	 */
	public static function get_monitored_repositories( $repo_type = 'plugin' ) {
		$option_name = 'theme' === $repo_type ? 'h2wp_themes' : 'h2wp_plugins';
		$monitored   = get_option( $option_name, array() );

		return is_array( $monitored ) ? $monitored : array();
	}

	/**
	 * Normalize and validate a repository subdirectory.
	 *
	 * GitHub paths are always forward-slash separated relative paths. Reject dot
	 * segments and empty path components before a value is used in an option key,
	 * API request, or filesystem path.
	 *
	 * @param mixed $subdirectory Candidate subdirectory.
	 * @return string|WP_Error Normalized path, an empty string, or an error.
	 */
	public static function normalize_subdirectory( $subdirectory ) {
		if ( null === $subdirectory ) {
			return '';
		}
		if ( ! is_string( $subdirectory ) && ! is_numeric( $subdirectory ) ) {
			return new WP_Error( 'h2wp_invalid_subdirectory', __( 'Invalid repository subdirectory.', 'hub2wp' ) );
		}

		$subdirectory = str_replace( '\\', '/', trim( (string) $subdirectory ) );
		$subdirectory = trim( $subdirectory, '/' );

		if ( '' === $subdirectory ) {
			return '';
		}

		$segments = explode( '/', $subdirectory );
		foreach ( $segments as $segment ) {
			if (
				'' === $segment ||
				'.' === $segment ||
				'..' === $segment ||
				false !== strpos( $segment, "\0" ) ||
				preg_match( '/[\x00-\x1F\x7F]/', $segment )
			) {
				return new WP_Error( 'h2wp_invalid_subdirectory', __( 'Invalid repository subdirectory.', 'hub2wp' ) );
			}
		}

		return implode( '/', $segments );
	}

	/**
	 * Build the canonical option key for a tracked repository project.
	 *
	 * The complete subdirectory is preserved so two projects with the same leaf
	 * directory name do not collide.
	 *
	 * @param string $owner        Repository owner.
	 * @param string $repo         Repository name.
	 * @param string $subdirectory Optional project subdirectory.
	 * @return string|WP_Error Canonical key or an error.
	 */
	public static function get_tracked_repo_key( $owner, $repo, $subdirectory = '' ) {
		$owner        = strtolower( sanitize_text_field( trim( (string) $owner ) ) );
		$repo         = strtolower( sanitize_text_field( trim( (string) $repo ) ) );
		$subdirectory = self::normalize_subdirectory( $subdirectory );

		if ( is_wp_error( $subdirectory ) || ! self::validate_repo_format( $owner . '/' . $repo ) ) {
			return new WP_Error( 'h2wp_invalid_repository', __( 'A valid GitHub repository and subdirectory are required.', 'hub2wp' ) );
		}

		$repo_key = $owner . '/' . $repo;
		return '' === $subdirectory ? $repo_key : $repo_key . '/' . $subdirectory;
	}

	/**
	 * Resolve a tracked record's repository identity without relying on its key.
	 *
	 * Stored owner/repo fields are authoritative. Parsing the first two key
	 * components is retained only for legacy records.
	 *
	 * @param string $repo_key  Stored option key.
	 * @param array  $repo_data Stored record.
	 * @return array|WP_Error Identity containing owner, repo, and subdirectory.
	 */
	public static function get_tracked_repo_identity( $repo_key, $repo_data ) {
		$repo_data  = is_array( $repo_data ) ? $repo_data : array();
		$parts      = explode( '/', trim( (string) $repo_key, '/' ) );
		$owner      = ! empty( $repo_data['owner'] ) ? $repo_data['owner'] : ( isset( $parts[0] ) ? $parts[0] : '' );
		$repo       = ! empty( $repo_data['repo'] ) ? $repo_data['repo'] : ( isset( $parts[1] ) ? $parts[1] : '' );
		$key_subdir = count( $parts ) > 2 ? implode( '/', array_slice( $parts, 2 ) ) : '';
		$subdir     = array_key_exists( 'subdirectory', $repo_data ) ? self::normalize_subdirectory( $repo_data['subdirectory'] ) : self::normalize_subdirectory( $key_subdir );

		$owner = strtolower( sanitize_text_field( trim( (string) $owner ) ) );
		$repo  = strtolower( sanitize_text_field( trim( (string) $repo ) ) );
		if ( is_wp_error( $subdir ) || ! self::validate_repo_format( $owner . '/' . $repo ) ) {
			return new WP_Error( 'h2wp_invalid_tracked_repository', __( 'Invalid tracked repository data.', 'hub2wp' ) );
		}

		return array(
			'owner'        => $owner,
			'repo'         => $repo,
			'subdirectory' => $subdir,
		);
	}

	/**
	 * Find a canonical or legacy option key for a tracked repository project.
	 *
	 * @param array  $monitored    Tracked option value.
	 * @param string $owner        Repository owner.
	 * @param string $repo         Repository name.
	 * @param string $subdirectory Optional project subdirectory.
	 * @return string Empty when no matching record exists.
	 */
	public static function find_tracked_repo_key( $monitored, $owner, $repo, $subdirectory = '' ) {
		$monitored = is_array( $monitored ) ? $monitored : array();
		$canonical = self::get_tracked_repo_key( $owner, $repo, $subdirectory );
		if ( is_wp_error( $canonical ) ) {
			return '';
		}

		if ( isset( $monitored[ $canonical ] ) ) {
			return $canonical;
		}

		$normalized_subdir = self::normalize_subdirectory( $subdirectory );
		if ( is_wp_error( $normalized_subdir ) ) {
			return '';
		}

		// PR #19 initially stored owner/repo/basename; keep it readable and
		// migrate it the next time the record is written.
		if ( '' !== $normalized_subdir ) {
			$legacy_key = strtolower( trim( (string) $owner ) ) . '/' . strtolower( trim( (string) $repo ) ) . '/' . basename( $normalized_subdir );
			if ( isset( $monitored[ $legacy_key ] ) ) {
				$identity = self::get_tracked_repo_identity( $legacy_key, $monitored[ $legacy_key ] );
				if ( ! is_wp_error( $identity ) && $normalized_subdir === $identity['subdirectory'] ) {
					return $legacy_key;
				}
			}
		}

		// Handle special and older keys (for example the built-in "hub2wp"
		// record) by comparing their stored identity instead of key shape.
		foreach ( $monitored as $candidate_key => $repo_data ) {
			$identity = self::get_tracked_repo_identity( $candidate_key, $repo_data );
			if (
				! is_wp_error( $identity ) &&
				strtolower( trim( (string) $owner ) ) === $identity['owner'] &&
				strtolower( trim( (string) $repo ) ) === $identity['repo'] &&
				$normalized_subdir === $identity['subdirectory']
			) {
				return (string) $candidate_key;
			}
		}

		return '';
	}

	/**
	 * Get the effective tracking preferences for a monitored repository.
	 *
	 * This allows code to override the stored branch and release-priority
	 * settings on a per-repository basis.
	 *
	 * @param string $owner     Repository owner.
	 * @param string $repo      Repository name.
	 * @param string $repo_type    Repository type: plugin|theme.
	 * @param string $subdirectory Optional project subdirectory.
	 * @return array
	 */
	public static function get_repo_tracking_preferences( $owner, $repo, $repo_type = 'plugin', $subdirectory = '' ) {
		$repo_type = in_array( $repo_type, array( 'plugin', 'theme' ), true ) ? $repo_type : 'plugin';
		$monitored = self::get_monitored_repositories( $repo_type );

		$lookup_key = self::find_tracked_repo_key( $monitored, $owner, $repo, $subdirectory );
		$repo_data  = isset( $monitored[ $lookup_key ] ) && is_array( $monitored[ $lookup_key ] ) ? $monitored[ $lookup_key ] : array();

		$preferences = array(
			'branch'              => isset( $repo_data['branch'] ) ? (string) $repo_data['branch'] : '',
			'prioritize_releases' => ! array_key_exists( 'prioritize_releases', $repo_data ) || ! empty( $repo_data['prioritize_releases'] ),
		);

		/**
		 * Filter tracking preferences for a specific monitored repository.
		 *
		 * Return an array with `branch` and/or `prioritize_releases` keys to
		 * override the stored settings for this repo.
		 *
		 * @param array  $preferences Current tracking preferences.
		 * @param string $owner       Repository owner.
		 * @param string $repo        Repository name.
		 * @param string $repo_type   Repository type: plugin|theme.
		 * @param array  $repo_data   Stored repository data.
		 */
		$filtered_preferences = apply_filters( 'hub2wp_repo_tracking_preferences', $preferences, $owner, $repo, $repo_type, $repo_data );
		if ( is_array( $filtered_preferences ) ) {
			$preferences = $filtered_preferences;
		}

		return array(
			'branch'              => isset( $preferences['branch'] ) ? (string) $preferences['branch'] : '',
			'prioritize_releases' => ! empty( $preferences['prioritize_releases'] ),
		);
	}
}
