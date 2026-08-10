<?php
/**
 * Handles interaction with the GitHub API.
 *
 * @package hub2wp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles interaction with the GitHub API.
 */
class H2WP_GitHub_API {

	/**
	 * Plugin repositories that should not appear when browsing or searching for plugins.
	 *
	 * These repos may use WordPress-related topics but are not installable plugins.
	 * We checked the top 200 search results for "topic:wordpress-plugin" and added known incompatible repositories to this list.
	 * By incompatible we mean repositories that cannot be installed through hub2wp, either because they are not actually plugins, or because they lack the necessary headers.
	 * The list can be updated over time. If your repository is on this list and you want it to be discoverable through hub2wp, please make sure it has the appropriate WordPress plugin headers (see "Plugin and Theme Eligibility" section in README.md), and open an issue or a PR to remove it from this list.
	 *
	 * Use the {@see 'hub2wp_excluded_plugin_repositories'} filter to modify this list.
	 *
	 * @var string[]
	 */
	private $excluded_plugin_repositories = array(
		'lukecav/awesome-woocommerce',
		'wp-graphql/wp-graphql',
		'humanmade/s3-uploads',
		'ampproject/amp-wp',
		'automattic/jetpack',
		'ahmadawais/wpgulp',
		'roots/acorn',
		'wp-media/wp-rocket',
		'wp-graphql/wp-graphql-woocommerce',
		'lukecav/awesome-wp-speed-up',
		'wenpai-org/wp-china-yes',
		'maadhattah/wordpress-github-sync',
		'zhuige-com/jiangqie_kafei',
		'lukecav/awesome-wp-developer-tools',
		'rarst/laps',
		'alleyinteractive/wordpress-fieldmanager',
		'humanmade/cavalcade',
		'xiaofaye/woocommerce.net',
		'gatographql/gatographql',
		'roots/wp-stage-switcher',
		'automattic/edit-flow',
		'wp-graphql/wp-graphql-jwt-authentication',
		'humanmade/wordpress-importer',
		'codesnippetspro/code-snippets',
		'lukecav/awesome-elementor',
		'lukecav/awesome-gravity-forms',
		'automattic/co-authors-plus',
		'pristas-peter/wp-graphql-gutenberg',
		'codestar/codestar-framework',
		'tareq1988/vue-wp-starter',
		'garyjones/gamajo-template-loader',
		'webdevstudios/generator-plugin-wp',
		'moeplayer/hermit-x',
		'shovoalways/plugin-development',
		'kasparsd/minit',
		'yeswework/fabrica-dev-kit',
		'wp-papi/papi',
		'buddypress/buddypress',
		'ajaxloadmore/ajax-load-more',
		'bueltge/multisite-global-media',
		'youknowriad/wp-js-plugin-starter',
		'serpwings/static-wordpress',
		'gocodebox/lifterlms',
		'wordpress/wp-feature-notifications',
		'automattic/custom-metadata',
		'presslabs/gitium',
		'torounit/custom-post-type-permalinks',
		'auth0/wordpress',
		'pluginkollektiv/antispam-bee',
		'wpbones/wpbones',
		'webdevstudios/wp-search-with-algolia',
		'hasinhayder/themeforest-wp-theme-approval-checklist',
		'lesterchan/wp-sweep',
		'tainacan/tainacan',
		'cedaro/gravity-forms-iframe',
		'mailpoet/mailpoet',
		'trewknowledge/gdpr',
		'wponion/wponion',
		'valu-digital/wp-graphql-polylang',
		'stevegrunwell/wp-cache-remember',
		'rarst/fragment-cache',
		'lesterchan/wp-pagenavi',
		'webdevstudios/wds-blocks',
		'cedaro/woocommerce-coupon-links',
		'svandragt/htmxpress',
		'automattic/rewrite-rules-inspector',
		'devgeniem/acf-codifier',
		'rarst/wps',
		'lesterchan/wp-postratings',
		'lukecav/awesome-blocks',
		'westonruter/syntax-highlighting-code-block',
		'enlighterjs/plugin.wordpress',
		'nlemoine/acf-country',
	);

	/**
	 * Theme repositories that should not appear when browsing or searching for themes.
	 *
	 * These repos may use WordPress-related topics but are not installable themes.
	 * We checked the top 200 search results for "topic:wordpress-theme" and added known incompatible repositories to this list.
	 * By incompatible we mean repositories that cannot be installed through hub2wp, either because they are not actually themes, or because they lack the necessary headers.
	 * The list can be updated over time. If your repository is on this list and you want it to be discoverable through hub2wp, please make sure it has the appropriate WordPress theme headers (see "Plugin and Theme Eligibility" section in README.md), and open an issue or a PR to remove it from this list.
	 *
	 * Use the {@see 'hub2wp_excluded_theme_repositories'} filter to modify this list.
	 *
	 * @var string[]
	 */
	private $excluded_theme_repositories = array(
		'wp-bootstrap/wp-bootstrap-navwalker',
		'woocommerce/storefront',
		'timber/starter-theme',
		'tokinx/adams',
		'bstavroulakis/vue-wordpress-pwa',
		'tokinx/wing',
		'weipxiu/art_blog',
		'braginteractive/materialwp',
		'devloco/create-react-wptheme',
		'imranhsayed/gatsby-wordpress-themes',
		'godaddy-wordpress/go',
		'd-xuanmo/nuxtjs-wordpress',
		'alexweblab/bootstrap-5-wordpress-navbar-walker',
		'zackha/nuxtcommerce',
		'wptt/wpthemereview',
		'cjkoepke/wp-tailwind',
		'billerickson/be-starter',
		'pfefferle/sempress',
		'webredone/theme-redone',
		'terrylinooo/mynote',
		'shovoalways/wordpress-theme-development',
		'cearls/timberland',
		'lyzs90/vuewp',
		'mwdelaney/sage-advanced-custom-fields',
		'livecanvas-team/picostrap5',
		'pfefferle/autonomie',
		'onixaz/nextjs-woocommerce-storefront',
		'infinum/eightshift-docs',
		'devinwalker/wp-rollback',
		'greenpeace/planet4-master-theme',
		'makeitworkpress/wp-custom-fields',
		'aduth/dones',
		'imranhsayed/react-wordpress-theme',
		'x3p0-dev/x3p0-ideas',
		'bueltge/wordpress-basis-theme',
		'8bit-echo/sage-vite',
		'alwaysblank/blade-generate',
		'andersnoren/bjork',
		'denoland/fresh-wordpress-themes',
		'scottsweb/v1.scott.ee',
		'digitalcube/iemoto',
		'amnestywebsite/humanity-theme',
		'rvsanches/bs4-wp',
		'asuh/html5boilerplate-starkers-wordpress-theme',
		'beats0/mygalgame',
		'italystrap/italystrap',
		'secretpizzaparty/huh',
		'twistedandy/wp-theme',
		'thundernet8/tint-pro',
		'kevinlearynet/basic-wp',
		'cipherdevgroup/alpha',
		'samikeijonen/uuups',
		'inc2734/mimizuku',
		'wplemon/gridd',
		'michaelsoriano/barebones',
		'michealpearce/wp-svelte-theme-boilerplate',
		'aminbenselim/wp-react-redux',
		'troy-yang/hexo-theme-twentyfifteen-wordpress',
		'd-xuanmo/xm-vue-wordpress-theme',
		'huangguorui/smile_blog',
		'mindkomm/theme-lib-mix',
		'murielk/androidwptemplate',
		'blockifywp/theme',
		'robbinjohansson/sage-laravel-mix',
		'jongrover/building-a-wordpress-theme-from-scratch',
		'isakfagerlund/wordpress-webpack-starter',
		'19h47/19h47.fr',
		'ebisucom/wp-blocktheme',
		'mirucon/coldbox',
		'piperhaywood/commonplace-wp-theme',
		'shockdesign/terminal-wordpress-theme',
		'andersnoren/beaumont',
		'italystrap/theme-json-generator',
		'knowthecode/genesis-developer-starter-lab',
		'brettsmason/luxe',
		'jessehanley/wordpress-amp-theme',
	);

	/**
	 * The personal access token.
	 *
	 * @var string
	 */
	private $access_token;

	/**
	 * Base GitHub API URL.
	 *
	 * @var string
	 */
	private $base_url = 'https://api.github.com';

	/**
	 * Constructor.
	 *
	 * @param string $access_token Optional personal access token.
	 */
	public function __construct( $access_token = '' ) {
		$this->access_token = $access_token;
	}

	/**
	 * Search plugins by query.
	 *
	 * @param string $query Search query.
	 * @param int    $page  Page number.
	 * @param string $sort  Sort parameter.
	 * @param string $order Order parameter.
	 *
	 * @return array|WP_Error Search results or error.
	 */
	public function search_plugins( $query = 'topic:wordpress-plugin', $page = 1, $sort = 'stars', $order = 'desc' ) {

		$type  = ( false !== strpos( strtolower( $query ), 'topic:wordpress-theme' ) ) ? 'theme' : 'plugin';
		$query = $this->append_excluded_repository_qualifiers( $query, $type );

		$cache_key = 'search_' . md5( $query . $page . $sort . $order . $this->access_token );
		$cached    = H2WP_Cache::get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		// Exclude archived repositories if not already included in the query.
		if ( false === strpos( $query, 'archived:' ) ) {
			$query .= ' archived:false';
		}

		$url = add_query_arg(
			array(
				'q'        => $query,
				'page'     => $page,
				'per_page' => H2WP_RESULTS_PER_PAGE,
				'sort'     => $sort, // Can be one of: stars, forks, help-wanted-issues, updated.
				'order'    => $order,
			),
			$this->base_url . '/search/repositories'
		);

		$response = $this->request( $url );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['items'] ) || ! is_array( $data['items'] ) ) {
			return new WP_Error( 'h2wp_api_error', __( 'Invalid response from GitHub API.', 'hub2wp' ) );
		}

		$excluded      = $this->get_excluded_repositories( $type );
		$data['items'] = array_values(
			array_filter(
				$data['items'],
				function ( $item ) use ( $excluded ) {
					if ( ! is_array( $item ) || empty( $item['full_name'] ) || empty( $item['name'] ) ) {
						return false;
					}
					return ! in_array( strtolower( (string) $item['full_name'] ), $excluded, true );
				}
			)
		);

		H2WP_Cache::set( $cache_key, $data );
		return $data;
	}

	/**
	 * Return the excluded-repository list for the given type, after applying filters.
	 *
	 * @param string $type Repository type: plugin|theme.
	 * @return string[]
	 */
	private function get_excluded_repositories( $type = 'plugin' ) {
		if ( 'theme' === $type ) {
			/**
			 * Filter the list of theme repositories excluded from search results.
			 *
			 * Each entry must be a lowercase "owner/repo" string.
			 *
			 * @param string[] $excluded Default list of excluded theme repositories.
			 */
			$excluded = apply_filters( 'hub2wp_excluded_theme_repositories', $this->excluded_theme_repositories );
			return is_array( $excluded ) ? $excluded : $this->excluded_theme_repositories;
		}

		/**
		 * Filter the list of plugin repositories excluded from search results.
		 *
		 * Each entry must be a lowercase "owner/repo" string.
		 *
		 * @param string[] $excluded Default list of excluded plugin repositories.
		 */
		$excluded = apply_filters( 'hub2wp_excluded_plugin_repositories', $this->excluded_plugin_repositories );
		return is_array( $excluded ) ? $excluded : $this->excluded_plugin_repositories;
	}

	/**
	 * Add hardcoded excluded repositories to a GitHub search query.
	 *
	 * @param string $query Search query.
	 * @param string $type  Repository type: plugin|theme.
	 * @return string
	 */
	private function append_excluded_repository_qualifiers( $query, $type = 'plugin' ) {
		$normalized_query = strtolower( $query );

		foreach ( $this->get_excluded_repositories( $type ) as $repository ) {
			$repository = strtolower( trim( (string) $repository ) );
			if ( '' === $repository ) {
				continue;
			}

			if ( false !== strpos( $normalized_query, 'repo:' . $repository ) ) {
				continue;
			}

			$query           .= ' -repo:' . $repository;
			$normalized_query = strtolower( $query );
		}

		return trim( $query );
	}

	/**
	 * Get zipball URL for a repository.
	 *
	 * @param string $owner  Owner of the repo.
	 * @param string $repo   Repo name.
	 * @param string $branch Optional branch name.
	 * @return string Zipball URL.
	 */
	public function get_download_url( $owner, $repo, $branch = '' ) {
		$url = $this->base_url . '/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo ) . '/zipball';
		if ( ! empty( $branch ) ) {
			$url .= '/' . rawurlencode( $branch );
		}
		return $url;
	}

	/**
	 * Get details for the latest release.
	 *
	 * @param string $owner Owner of the repo.
	 * @param string $repo  Repo name.
	 * @return array|WP_Error
	 */
	public function get_latest_release_details( $owner, $repo ) {
		$cache_key = 'latest_release_' . $owner . '_' . $repo;
		$cached    = H2WP_Cache::get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$url      = $this->base_url . '/repos/' . $owner . '/' . $repo . '/releases/latest';
		$response = $this->request( $url );

		if ( is_wp_error( $response ) ) {
			if ( 'h2wp_api_error_404' === $response->get_error_code() ) {
				$no_release = array(
					'uses_releases' => false,
					'tag_name'      => '',
					'zipball_url'   => '',
					'published_at'  => '',
				);
				H2WP_Cache::set( $cache_key, $no_release );
				return $no_release;
			}

			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'h2wp_api_error', __( 'Invalid release data from GitHub API.', 'hub2wp' ) );
		}

		$latest_release = array(
			'uses_releases' => ! empty( $data['tag_name'] ),
			'tag_name'      => isset( $data['tag_name'] ) ? sanitize_text_field( $data['tag_name'] ) : '',
			'zipball_url'   => isset( $data['zipball_url'] ) ? esc_url_raw( $data['zipball_url'] ) : '',
			'published_at'  => isset( $data['published_at'] ) ? sanitize_text_field( $data['published_at'] ) : '',
		);

		H2WP_Cache::set( $cache_key, $latest_release );
		return $latest_release;
	}

	/**
	 * Resolve whether branch files or latest release files should be used for version tracking.
	 *
	 * @param string $owner               Owner of the repo.
	 * @param string $repo                Repo name.
	 * @param string $branch              Optional branch name.
	 * @param bool   $prioritize_releases Whether release files should be preferred.
	 * @param string $subdirectory        Optional project subdirectory for a monorepo.
	 * @return array
	 */
	public function resolve_version_source( $owner, $repo, $branch = '', $prioritize_releases = true, $subdirectory = '' ) {
		$context = array(
			'prioritize_releases'  => (bool) $prioritize_releases,
			'uses_releases'        => false,
			'source'               => 'branch',
			'ref'                  => $branch,
			'release_tag'          => '',
			'release_published_at' => '',
			'download_url'         => $this->get_download_url( $owner, $repo, $branch ),
			'package_scope'        => 'repository',
		);

		if ( ! $prioritize_releases ) {
			return $this->filter_install_source_context( $context, $owner, $repo, $subdirectory );
		}

		$release_details = empty( $subdirectory )
			? $this->get_latest_release_details( $owner, $repo )
			: $this->get_monorepo_release_details( $owner, $repo, $subdirectory );
		if ( is_wp_error( $release_details ) || empty( $release_details['uses_releases'] ) || empty( $release_details['tag_name'] ) ) {
			return $this->filter_install_source_context( $context, $owner, $repo, $subdirectory );
		}

		$context['uses_releases']        = true;
		$context['source']               = 'release';
		$context['ref']                  = $release_details['tag_name'];
		$context['release_tag']          = $release_details['tag_name'];
		$context['release_published_at'] = $release_details['published_at'];
		$context['download_url']         = ! empty( $release_details['download_url'] )
			? $release_details['download_url']
			: ( ! empty( $release_details['zipball_url'] )
				? $release_details['zipball_url']
				: $this->get_download_url( $owner, $repo, $release_details['tag_name'] ) );
		$context['package_scope']        = isset( $release_details['package_scope'] ) ? $release_details['package_scope'] : 'repository';

		return $this->filter_install_source_context( $context, $owner, $repo, $subdirectory );
	}

	/**
	 * Filter the resolved source context used for installs and version checks.
	 *
	 * @param array  $context Resolved source context.
	 * @param string $owner   Owner of the repo.
	 * @param string $repo    Repo name.
	 * @param string $subdirectory Optional project subdirectory.
	 * @return array
	 */
	private function filter_install_source_context( $context, $owner, $repo, $subdirectory = '' ) {
		/**
		 * Filter the resolved install/version source context for a repository.
		 *
		 * @param array           $context Source context including ref, source, and download_url.
		 * @param string          $owner   Repository owner.
		 * @param string          $repo    Repository name.
		 * @param H2WP_GitHub_API $this    GitHub API client instance.
		 * @param string          $subdirectory Optional project subdirectory.
		 */
		$filtered_context = apply_filters( 'hub2wp_install_source_context', $context, $owner, $repo, $this, $subdirectory );
		if ( is_array( $filtered_context ) ) {
			$context = $filtered_context;
		}

		return array(
			'prioritize_releases'  => ! empty( $context['prioritize_releases'] ),
			'uses_releases'        => ! empty( $context['uses_releases'] ),
			'source'               => isset( $context['source'] ) ? (string) $context['source'] : 'branch',
			'ref'                  => isset( $context['ref'] ) ? (string) $context['ref'] : '',
			'release_tag'          => isset( $context['release_tag'] ) ? (string) $context['release_tag'] : '',
			'release_published_at' => isset( $context['release_published_at'] ) ? (string) $context['release_published_at'] : '',
			'download_url'         => isset( $context['download_url'] ) ? esc_url_raw( $context['download_url'] ) : '',
			'package_scope'        => isset( $context['package_scope'] ) && 'extension' === $context['package_scope'] ? 'extension' : 'repository',
		);
	}

	/**
	 * Get repository details.
	 *
	 * This method works for both public and private repositories
	 * when an access token with appropriate permissions is provided.
	 *
	 * @param string $owner Owner of the repo.
	 * @param string $repo  Repo name.
	 * @return array|WP_Error Repository details or error.
	 */
	public function get_repo_details( $owner, $repo ) {
		$cache_key = 'repo_details_' . $owner . '_' . $repo;
		$cached    = H2WP_Cache::get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$url      = $this->base_url . '/repos/' . $owner . '/' . $repo;
		$response = $this->request( $url );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'h2wp_api_error', __( 'Invalid repository data from GitHub API.', 'hub2wp' ) );
		}

		H2WP_Cache::set( $cache_key, $data );
		return $data;
	}

	/**
	 * Get private repository details.
	 *
	 * This is a wrapper around get_repo_details() specifically for private repositories.
	 * It verifies that an access token exists before making the request.
	 *
	 * @param string $owner Owner of the repo.
	 * @param string $repo  Repo name.
	 * @return array|WP_Error Repository details or error.
	 */
	public function get_private_repo_details( $owner, $repo ) {
		if ( empty( $this->access_token ) ) {
			return new WP_Error(
				'h2wp_missing_token',
				__( 'Access token is required to fetch private repository details.', 'hub2wp' )
			);
		}

		// Verify access before fetching full details.
		$access_check = $this->verify_private_repo_access( $owner, $repo );
		if ( is_wp_error( $access_check ) ) {
			return $access_check;
		}

		return $this->get_repo_details( $owner, $repo );
	}

	/**
	 * Verify that the access token can access a private repository.
	 *
	 * This method makes a lightweight API call to verify access permissions.
	 *
	 * @param string $owner Owner of the repo.
	 * @param string $repo  Repo name.
	 * @return bool|WP_Error True if accessible, WP_Error otherwise.
	 */
	public function verify_private_repo_access( $owner, $repo ) {
		if ( empty( $this->access_token ) ) {
			return new WP_Error(
				'h2wp_missing_token',
				__( 'Access token is required to verify private repository access.', 'hub2wp' )
			);
		}

		$url      = $this->base_url . '/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo );
		$response = $this->request( $url );

		if ( is_wp_error( $response ) ) {
			$error_code = $response->get_error_code();

			// Provide more specific error messages based on HTTP status.
			if ( 'h2wp_api_error_404' === $error_code ) {
				return new WP_Error(
					'h2wp_repo_not_found',
					sprintf(
						/* translators: %s: repository owner/repo */
						__( 'Repository "%s" not found or you do not have access to it. Please verify the repository name and ensure your access token has the "repo" scope.', 'hub2wp' ),
						$owner . '/' . $repo
					)
				);
			}

			if ( 'h2wp_api_error_401' === $error_code ) {
				return new WP_Error(
					'h2wp_unauthorized',
					__( 'Your access token is invalid or does not have permission to access this repository. Please check your token and ensure it has the "repo" scope.', 'hub2wp' )
				);
			}

			if ( 'h2wp_api_error_403' === $error_code ) {
				return new WP_Error(
					'h2wp_forbidden',
					__( 'Your access token does not have permission to access this repository. Please ensure it has the "repo" scope.', 'hub2wp' )
				);
			}

			return $response;
		}

		// Check if the repository is actually private.
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'h2wp_api_error', __( 'Invalid repository data from GitHub API.', 'hub2wp' ) );
		}
		H2WP_Cache::set( 'repo_details_' . $owner . '_' . $repo, $body );

		if ( isset( $body['private'] ) && false === $body['private'] ) {
			// Repository is public, warn the user but still allow it.
			return new WP_Error(
				'h2wp_repo_is_public',
				sprintf(
					/* translators: %s: repository owner/repo */
					__( 'Note: Repository "%s" is public. It will work, but you may want to use the regular search instead.', 'hub2wp' ),
					$owner . '/' . $repo
				),
				array( 'is_public' => true )
			);
		}

		return true;
	}

	/**
	 * Get branch details.
	 *
	 * @param string $owner Owner of the repo.
	 * @param string $repo  Repo name.
	 * @param string $branch Branch name.
	 * @return array|WP_Error Branch details or error.
	 */
	public function get_branch_details( $owner, $repo, $branch ) {
		$cache_key = 'branch_details_' . $owner . '_' . $repo . '_' . $branch;
		$cached    = H2WP_Cache::get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$url      = $this->base_url . '/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo ) . '/branches/' . rawurlencode( $branch );
		$response = $this->request( $url );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'h2wp_api_error', __( 'Invalid branch data from GitHub API.', 'hub2wp' ) );
		}

		H2WP_Cache::set( $cache_key, $data );
		return $data;
	}

	/**
	 * Get rendered README in HTML format.
	 *
	 * @param string $owner Owner of the repo.
	 * @param string $repo  Repo name.
	 * @param string $branch       Optional branch name.
	 * @param string $subdirectory Optional project subdirectory.
	 * @return string|WP_Error Rendered README HTML or error.
	 */
	public function get_readme_html( $owner, $repo, $branch = '', $subdirectory = '' ) {
		$subdir_key = empty( $subdirectory ) ? '' : '_' . md5( $subdirectory );
		$cache_key  = 'readme_html_' . $owner . '_' . $repo . '_' . $this->get_branch_cache_key_segment( $branch ) . $subdir_key;
		$cached     = H2WP_Cache::get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$url = $this->base_url . '/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo ) . '/readme';
		if ( ! empty( $subdirectory ) ) {
			$encoded_path = implode( '/', array_map( 'rawurlencode', explode( '/', trim( $subdirectory, '/' ) ) ) );
			$url         .= '/' . $encoded_path;
		}
		if ( ! empty( $branch ) ) {
			$url = add_query_arg( 'ref', $branch, $url );
		}
		$args     = array(
			'headers' => array(
				'Accept' => 'application/vnd.github.v3.html',
			),
		);
		$response = $this->request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$html = wp_remote_retrieve_body( $response );
		if ( empty( $html ) ) {
			return new WP_Error( 'h2wp_readme_error', __( 'Unable to retrieve README.', 'hub2wp' ) );
		}

		// Sanitize HTML for safe output.
		$allowed_html   = wp_kses_allowed_html( 'post' );
		$sanitized_html = wp_kses( $html, $allowed_html );

		H2WP_Cache::set( $cache_key, $sanitized_html );
		return $sanitized_html;
	}

	/**
	 * Get og:image from repository HTML or fallback to owner avatar.
	 *
	 * @param string $owner Owner of the repo.
	 * @param string $repo  Repo name.
	 * @return string|WP_Error Image URL or error.
	 */
	public function get_og_image( $owner, $repo ) {
		$cache_key = 'og_image_' . $owner . '_' . $repo;
		$cached    = H2WP_Cache::get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		// Fetch and cache repository HTML.
		$repo_html = $this->get_repo_html( $owner, $repo );
		if ( is_wp_error( $repo_html ) ) {
			return $repo_html;
		}

		// Attempt to extract og:image using regex.
		if ( preg_match( '/<meta property="og:image" content="([^"]+)"/i', $repo_html, $matches ) ) {
			$og_image = esc_url_raw( $matches[1] );
			H2WP_Cache::set( $cache_key, $og_image );
			return $og_image;
		}

		// Fallback to owner's avatar.
		$repo_details = $this->get_repo_details( $owner, $repo );
		if ( is_wp_error( $repo_details ) ) {
			return $repo_details;
		}

		if ( isset( $repo_details['owner']['avatar_url'] ) ) {
			$avatar_url = esc_url_raw( $repo_details['owner']['avatar_url'] );
			H2WP_Cache::set( $cache_key, $avatar_url );
			return $avatar_url;
		}

		return new WP_Error( 'h2wp_image_error', __( 'No image available.', 'hub2wp' ) );
	}

	/**
	 * Get watchers count. This is not available in the repository details, so we need to fetch it separately.
	 * It's included in the repository HTML page:
	 * <a href="/WordPress/gutenberg/watchers" data-view-component="true" class="Link Link--muted"><svg aria-hidden="true" height="16" viewBox="0 0 16 16" version="1.1" width="16" data-view-component="true" class="octicon octicon-eye mr-2"><strong>348</strong> watching</a>
	 *
	 * @param string $owner Owner of the repo.
	 * @param string $repo  Repo name.
	 * @return int|WP_Error Watchers count or error.
	 */
	public function get_watchers_count( $owner, $repo ) {
		$cache_key = 'watchers_count_' . $owner . '_' . $repo;
		$cached    = H2WP_Cache::get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		// Fetch and cache repository HTML.
		$repo_html = $this->get_repo_html( $owner, $repo );
		if ( is_wp_error( $repo_html ) ) {
			return $repo_html;
		}

		// Attempt to extract watchers count using regex or DOM parsing.
		if ( preg_match( '/<strong>(\d+)<\/strong>\s+watching/', $repo_html, $matches ) ) {
			$count = absint( $matches[1] );
			H2WP_Cache::set( $cache_key, $count );
			return $count;
		}

		return new WP_Error( 'h2wp_watchers_error', __( 'Unable to determine watchers count.', 'hub2wp' ) );
	}

	/**
	 * Get primary language of the repository by scraping HTML.
	 *
	 * @param string $owner Owner of the repo.
	 * @param string $repo  Repo name.
	 * @return string|WP_Error Primary language or error.
	 */
	public function get_primary_language( $owner, $repo ) {
		$cache_key = 'primary_language_' . $owner . '_' . $repo;
		$cached    = H2WP_Cache::get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		// Fetch and cache repository HTML.
		$repo_html = $this->get_repo_html( $owner, $repo );
		if ( is_wp_error( $repo_html ) ) {
			return $repo_html;
		}

		// Attempt to extract primary language using regex or DOM parsing.
		if ( preg_match( '/<span class="color-fg-default text-bold mr-1">([A-Za-z]+)<\/span>/', $repo_html, $matches ) ) {
			$language = sanitize_text_field( $matches[1] );
			H2WP_Cache::set( $cache_key, $language );
			return $language;
		}

		return new WP_Error( 'h2wp_language_error', __( 'Unable to determine primary language.', 'hub2wp' ) );
	}

	/**
	 * Get repository HTML page and cache it.
	 *
	 * @param string $owner Owner of the repo.
	 * @param string $repo  Repo name.
	 * @return string|WP_Error HTML content or error.
	 */
	public function get_repo_html( $owner, $repo ) {
		$cache_key = 'repo_html_' . $owner . '_' . $repo;
		$cached    = H2WP_Cache::get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$url      = 'https://github.com/' . $owner . '/' . $repo;
		$response = wp_remote_get( $url, array( 'timeout' => 10 ) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'h2wp_scrape_error', __( 'Unable to fetch repository page.', 'hub2wp' ) );
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return new WP_Error( 'h2wp_scrape_error', __( 'Empty repository page.', 'hub2wp' ) );
		}

		H2WP_Cache::set( $cache_key, $body );
		return $body;
	}

	/**
	 * Get contributors for a repository.
	 *
	 * @param string $owner Owner of the repo.
	 * @param string $repo  Repo name.
	 * @return array|WP_Error Contributors or error.
	 */
	public function get_contributors( $owner, $repo ) {
		$cache_key = 'contributors_' . $owner . '_' . $repo;
		$cached    = H2WP_Cache::get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$url      = $this->base_url . '/repos/' . $owner . '/' . $repo . '/contributors';
		$response = $this->request( $url );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$contributors = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $contributors ) ) {
			return new WP_Error( 'h2wp_api_error', __( 'Invalid contributors data from GitHub API.', 'hub2wp' ) );
		}

		// Limit to 5 contributors.
		$contributors = array_slice( $contributors, 0, 5 );

		// Prepare data for each contributor.
		$data = array();
		foreach ( $contributors as $contributor ) {
			$data[] = array(
				'login'      => isset( $contributor['login'] ) ? sanitize_text_field( $contributor['login'] ) : '',
				'html_url'   => isset( $contributor['html_url'] ) ? esc_url_raw( $contributor['html_url'] ) : '',
				'avatar_url' => isset( $contributor['avatar_url'] ) ? esc_url_raw( $contributor['avatar_url'] ) : '',
			);
		}

		H2WP_Cache::set( $cache_key, $data );
		return $data;
	}

	/**
	 * Check rate limits and handle them.
	 *
	 * @param array $headers Response headers.
	 */
	private function handle_rate_limits( $headers ) {
		if ( isset( $headers['x-ratelimit-remaining'] ) && 0 === (int) $headers['x-ratelimit-remaining'] ) {
			set_transient( 'h2wp_rate_limit_reached', 1, HOUR_IN_SECONDS );
		} else {
			delete_transient( 'h2wp_rate_limit_reached' );
		}
	}

	/**
	 * Check if a repository is compatible with the current WordPress environment.
	 *
	 * @param string $owner Owner of the repo.
	 * @param string $repo  Repo name.
	 * @param string $repo_type Repository type: plugin|theme.
	 * @param string $branch Optional branch name.
	 * @param bool   $prioritize_releases Whether releases are preferred.
	 * @param array  $source_context      Optional pre-resolved source context.
	 * @param string $subdirectory        Optional project subdirectory.
	 * @return array Compatibility data (is_compatible, reason) or error.
	 */
	public function check_compatibility( $owner, $repo, $repo_type = 'plugin', $branch = '', $prioritize_releases = true, $source_context = null, $subdirectory = '' ) {
		$repo_type      = in_array( $repo_type, array( 'plugin', 'theme' ), true ) ? $repo_type : 'plugin';
		$source_context = is_array( $source_context ) ? $source_context : $this->resolve_version_source( $owner, $repo, $branch, $prioritize_releases, $subdirectory );
		$ref            = isset( $source_context['ref'] ) ? (string) $source_context['ref'] : $branch;
		$subdir_key     = ! empty( $subdirectory ) ? '_' . md5( $subdirectory ) : '';
		$cache_key      = 'compatibility_' . $repo_type . '_' . $owner . '_' . $repo . '_' . $this->get_branch_cache_key_segment( $ref ) . '_' . ( ! empty( $source_context['source'] ) ? $source_context['source'] : 'branch' ) . $subdir_key;
		$cached         = H2WP_Cache::get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		if ( 'theme' === $repo_type ) {
			$style_content = $this->fetch_theme_style_content( $owner, $repo, $ref, $subdirectory );
			if ( is_wp_error( $style_content ) ) {
				$error_data = array(
					'is_compatible' => false,
					'reason'        => __( 'No valid theme style.css file found.', 'hub2wp' ),
				);
				H2WP_Cache::set( $cache_key, $error_data );
				return $error_data;
			}
			$headers = $this->extract_headers_from_style( $style_content );
		} else {
			$plugin_content = $this->fetch_plugin_header_content( $owner, $repo, $ref, $subdirectory );
			if ( is_wp_error( $plugin_content ) ) {
				$error_data = array(
					'is_compatible' => false,
					'reason'        => __( 'No valid WordPress plugin file found.', 'hub2wp' ),
					'headers'       => array(),
				);
				H2WP_Cache::set( $cache_key, $error_data );
				return $error_data;
			}

			$headers = $this->extract_headers_from_plugin( $plugin_content );

			// readme.txt is optional. When present, use it only to supplement
			// compatibility fields missing from the authoritative plugin header.
			$readme_content = $this->fetch_readme_content( $owner, $repo, $ref, $subdirectory );
			if ( ! is_wp_error( $readme_content ) ) {
				$readme_headers = $this->extract_headers_from_readme( $readme_content );
				foreach ( array( 'requires at least', 'tested up to', 'requires php' ) as $field ) {
					if ( empty( $headers[ $field ] ) && ! empty( $readme_headers[ $field ] ) ) {
						$headers[ $field ] = $readme_headers[ $field ];
					}
				}
			}
		}
		if ( empty( $headers['version'] ) ) {
			$error_data = array(
				'is_compatible' => false,
				'reason'        => __( 'The extension header does not declare a version.', 'hub2wp' ),
				'headers'       => $headers,
			);
			H2WP_Cache::set( $cache_key, $error_data );
			return $error_data;
		}

		$compatibility                   = $this->evaluate_compatibility( $headers, $repo_type );
		$compatibility['headers']        = $headers;
		$compatibility['source_context'] = $source_context;
		/**
		 * Filter the resolved compatibility result for a repository.
		 *
		 * @param array           $compatibility Compatibility payload.
		 * @param array           $headers       Parsed plugin/theme headers.
		 * @param string          $owner         Repository owner.
		 * @param string          $repo          Repository name.
		 * @param string          $repo_type     Repository type: plugin|theme.
		 * @param array           $source_context Resolved source context.
		 * @param H2WP_GitHub_API $this          GitHub API client instance.
		 */
		$filtered_compatibility = apply_filters( 'hub2wp_compatibility_result', $compatibility, $headers, $owner, $repo, $repo_type, $source_context, $this );
		if ( is_array( $filtered_compatibility ) ) {
			$compatibility = $filtered_compatibility;
		}
		H2WP_Cache::set( $cache_key, $compatibility );
		return $compatibility;
	}

	/**
	 * Fetch the content of the readme file from the repository.
	 *
	 * @param string $owner Owner of the repo.
	 * @param string $repo  Repo name.
	 * @param string $branch Optional branch name.
	 * @param string $path_prefix Optional project subdirectory.
	 * @return string|WP_Error Readme content or error.
	 */
	private function fetch_readme_content( $owner, $repo, $branch = '', $path_prefix = '' ) {
		$filenames = array( 'readme.txt', 'README.txt' );

		if ( ! empty( $path_prefix ) ) {
			$filenames = array_map(
				function ( $f ) use ( $path_prefix ) {
					return trailingslashit( $path_prefix ) . $f;
				},
				$filenames
			);
		}

		foreach ( $filenames as $filename ) {
			$url = $this->base_url . '/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo ) . '/contents/' . $this->encode_repository_path( $filename );
			if ( ! empty( $branch ) ) {
				$url = add_query_arg( 'ref', $branch, $url );
			}
			$response = $this->request( $url );

			if ( ! is_wp_error( $response ) ) {
				$data = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( isset( $data['content'] ) ) {
					return $this->decode_file_content( $data['content'] );
				}
			}

			if ( is_wp_error( $response ) && 'h2wp_api_error_404' !== $response->get_error_code() ) {
				return $response;
			}
		}

		// Fall back to the readme endpoint - only for single-repo plugins which will find README.md/readme.md/README etc. (always fetches root).
		if ( ! empty( $path_prefix ) ) {
			return new WP_Error( 'h2wp_readme_not_found', __( 'No valid readme file found.', 'hub2wp' ) );
		}
		$url = $this->base_url . '/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo ) . '/readme';
		if ( ! empty( $branch ) ) {
			$url = add_query_arg( 'ref', $branch, $url );
		}
		$response = $this->request( $url );

		if ( ! is_wp_error( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( isset( $data['content'] ) ) {
				return $this->decode_file_content( $data['content'] );
			}
		}

		return new WP_Error( 'h2wp_readme_not_found', __( 'No valid readme file found.', 'hub2wp' ) );
	}

	/**
	 * Fetch style.css content for a theme repository.
	 *
	 * @param string $owner Owner of the repo.
	 * @param string $repo  Repo name.
	 * @param string $branch Optional branch name.
	 * @param string $path_prefix Optional project subdirectory.
	 * @return string|WP_Error Theme style.css content or error.
	 */
	private function fetch_theme_style_content( $owner, $repo, $branch = '', $path_prefix = '' ) {
		$filenames = array( 'style.css', 'STYLE.CSS' );

		if ( ! empty( $path_prefix ) ) {
			$filenames = array_map(
				function ( $f ) use ( $path_prefix ) {
					return trailingslashit( $path_prefix ) . $f;
				},
				$filenames
			);
		}

		foreach ( $filenames as $filename ) {
			$url = $this->base_url . '/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo ) . '/contents/' . $this->encode_repository_path( $filename );
			if ( ! empty( $branch ) ) {
				$url = add_query_arg( 'ref', $branch, $url );
			}
			$response = $this->request( $url );

			if ( ! is_wp_error( $response ) ) {
				$data = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( isset( $data['content'] ) ) {
					return $this->decode_file_content( $data['content'] );
				}
			}

			if ( is_wp_error( $response ) && 'h2wp_api_error_404' !== $response->get_error_code() ) {
				return $response;
			}
		}

		return new WP_Error( 'h2wp_style_not_found', __( 'No valid theme style.css file found.', 'hub2wp' ) );
	}

	/**
	 * Fetch the main plugin file content from a repository project.
	 *
	 * Only PHP files directly inside the project directory are candidates, which
	 * matches WordPress's normal plugin layout and keeps API usage bounded.
	 *
	 * @param string $owner       Repository owner.
	 * @param string $repo        Repository name.
	 * @param string $branch      Optional branch or tag.
	 * @param string $path_prefix Optional project subdirectory.
	 * @return string|WP_Error Plugin file content or error.
	 */
	private function fetch_plugin_header_content( $owner, $repo, $branch = '', $path_prefix = '' ) {
		$contents = $this->get_directory_contents( $owner, $repo, $path_prefix, $branch );
		if ( is_wp_error( $contents ) ) {
			return $contents;
		}

		$files_checked = 0;
		foreach ( $contents as $item ) {
			if ( $files_checked >= 20 ) {
				break;
			}
			if ( ! is_array( $item ) || 'file' !== ( isset( $item['type'] ) ? $item['type'] : '' ) || empty( $item['path'] ) ) {
				continue;
			}
			if ( '.php' !== strtolower( substr( isset( $item['name'] ) ? $item['name'] : '', -4 ) ) ) {
				continue;
			}

			++$files_checked;
			$content = $this->get_file_content( $owner, $repo, $item['path'], $branch );
			if ( ! is_wp_error( $content ) && $this->has_plugin_header( $content ) ) {
				return $content;
			}
		}

		return new WP_Error( 'h2wp_plugin_file_not_found', __( 'No valid WordPress plugin file found.', 'hub2wp' ) );
	}

	/**
	 * Extract headers from readme content using regex.
	 *
	 * @param string $readme_content Readme file content.
	 * @return array Extracted headers.
	 */
	private function extract_headers_from_readme( $readme_content ) {
		$fields = array(
			'requires at least' => '',
			'tested up to'      => '',
			'requires php'      => '',
			'stable tag'        => '',
		);

		foreach ( $fields as $field => &$value ) {
			if ( preg_match( '/^' . preg_quote( $field, '/' ) . ':\s*(.+)$/mi', $readme_content, $matches ) ) {
				$value = trim( $matches[1] );
			}
		}

		return $fields;
	}

	/**
	 * Extract metadata from a WordPress plugin header.
	 *
	 * @param string $plugin_content Plugin PHP file content.
	 * @return array Parsed headers.
	 */
	private function extract_headers_from_plugin( $plugin_content ) {
		$fields = array(
			'name'              => '',
			'version'           => '',
			'requires at least' => '',
			'tested up to'      => '',
			'requires php'      => '',
		);
		$labels = array(
			'name'              => 'Plugin Name',
			'version'           => 'Version',
			'requires at least' => 'Requires at least',
			'tested up to'      => 'Tested up to',
			'requires php'      => 'Requires PHP',
		);

		foreach ( $labels as $key => $label ) {
			if ( preg_match( '/^\s*(?:\*\s*)?' . preg_quote( $label, '/' ) . ':\s*(.+)$/mi', $plugin_content, $matches ) ) {
				$fields[ $key ] = trim( preg_replace( '/\s*\*\/\s*$/', '', $matches[1] ) );
			}
		}

		return $fields;
	}

	/**
	 * Extract headers from a theme style.css file.
	 *
	 * @param string $style_content style.css content.
	 * @return array Extracted headers.
	 */
	private function extract_headers_from_style( $style_content ) {
		$fields = array(
			'name'              => '',
			'requires at least' => '',
			'tested up to'      => '',
			'requires php'      => '',
			'version'           => '',
		);

		$style_headers_map = array(
			'name'              => 'Theme Name',
			'requires at least' => 'Requires at least',
			'tested up to'      => 'Tested up to',
			'requires php'      => 'Requires PHP',
			'version'           => 'Version',
		);

		foreach ( $style_headers_map as $key => $label ) {
			if ( preg_match( '/^\s*(?:\*\s*)?' . preg_quote( $label, '/' ) . ':\s*(.+)$/mi', $style_content, $matches ) ) {
				$fields[ $key ] = trim( preg_replace( '/\s*\*\/\s*$/', '', $matches[1] ) );
			}
		}

		return $fields;
	}

	/**
	 * Evaluate compatibility based on parsed headers.
	 *
	 * @param array  $headers   Parsed headers.
	 * @param string $repo_type Repository type.
	 * @return array Compatibility data.
	 */
	private function evaluate_compatibility( $headers, $repo_type = 'plugin' ) {
		$entity = 'theme' === $repo_type ? __( 'theme', 'hub2wp' ) : __( 'plugin', 'hub2wp' );

		if ( ! empty( $headers['requires at least'] ) && version_compare( get_bloginfo( 'version' ), $headers['requires at least'], '<' ) ) {
			return array(
				'is_compatible' => false,
				'reason'        => sprintf(
					// translators: 1: extension type (plugin/theme), 2: required WordPress version.
					__( 'This %1$s requires WordPress version %2$s or higher.', 'hub2wp' ),
					$entity,
					$headers['requires at least']
				),
			);
		}

		if ( ! empty( $headers['requires php'] ) && version_compare( PHP_VERSION, $headers['requires php'], '<' ) ) {
			return array(
				'is_compatible' => false,
				'reason'        => sprintf(
					// translators: 1: extension type (plugin/theme), 2: required PHP version.
					__( 'This %1$s requires PHP version %2$s or higher.', 'hub2wp' ),
					$entity,
					$headers['requires php']
				),
			);
		}

		if ( ! empty( $headers['tested up to'] ) && version_compare( get_bloginfo( 'version' ), $headers['tested up to'], '>' ) ) {
			return array(
				'is_compatible' => true,
				'reason'        => sprintf(
					// translators: %s: extension type (plugin/theme).
					__( 'This %s has not been tested with your WordPress version.', 'hub2wp' ),
					$entity
				),
			);
		}

		return array(
			'is_compatible' => true,
			'reason'        => '',
		);
	}

	/**
	 * Get parsed headers from a plugin's main PHP file.
	 *
	 * @param string $owner               Repository owner.
	 * @param string $repo                Repository name.
	 * @param string $branch              Optional branch name.
	 * @param bool   $prioritize_releases Whether releases are preferred.
	 * @param array  $source_context      Optional pre-resolved source context.
	 * @param string $subdirectory        Optional project subdirectory.
	 * @return array|WP_Error Parsed headers or error.
	 */
	public function get_plugin_headers( $owner, $repo, $branch = '', $prioritize_releases = true, $source_context = null, $subdirectory = '' ) {
		$source_context = is_array( $source_context ) ? $source_context : $this->resolve_version_source( $owner, $repo, $branch, $prioritize_releases, $subdirectory );
		$ref            = isset( $source_context['ref'] ) ? (string) $source_context['ref'] : $branch;
		$subdir_key     = ! empty( $subdirectory ) ? '_' . md5( $subdirectory ) : '';
		$cache_key      = 'plugin_headers_' . $owner . '_' . $repo . '_' . $this->get_branch_cache_key_segment( $ref ) . '_' . ( ! empty( $source_context['source'] ) ? $source_context['source'] : 'branch' ) . $subdir_key;
		$cached         = H2WP_Cache::get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$plugin_content = $this->fetch_plugin_header_content( $owner, $repo, $ref, $subdirectory );
		if ( is_wp_error( $plugin_content ) ) {
			return $plugin_content;
		}

		$headers = $this->extract_headers_from_plugin( $plugin_content );
		$readme  = $this->fetch_readme_content( $owner, $repo, $ref, $subdirectory );
		if ( ! is_wp_error( $readme ) ) {
			$readme_headers = $this->extract_headers_from_readme( $readme );
			foreach ( array( 'requires at least', 'tested up to', 'requires php' ) as $field ) {
				if ( empty( $headers[ $field ] ) && ! empty( $readme_headers[ $field ] ) ) {
					$headers[ $field ] = $readme_headers[ $field ];
				}
			}
		}

		H2WP_Cache::set( $cache_key, $headers );
		return $headers;
	}

	/**
	 * Get parsed headers from the readme.txt file.
	 *
	 * @param string $owner Owner of the repo.
	 * @param string $repo  Repo name.
	 * @param string $branch Optional branch name.
	 * @param bool   $prioritize_releases Whether releases are preferred.
	 * @param array  $source_context      Optional pre-resolved source context.
	 * @param string $subdirectory        Optional project subdirectory.
	 * @return array|WP_Error Parsed headers or error.
	 */
	public function get_readme_headers( $owner, $repo, $branch = '', $prioritize_releases = true, $source_context = null, $subdirectory = '' ) {
		$source_context = is_array( $source_context ) ? $source_context : $this->resolve_version_source( $owner, $repo, $branch, $prioritize_releases, $subdirectory );
		$ref            = isset( $source_context['ref'] ) ? (string) $source_context['ref'] : $branch;
		$subdir_key     = ! empty( $subdirectory ) ? '_' . md5( $subdirectory ) : '';
		$cache_key      = 'readme_headers_' . $owner . '_' . $repo . '_' . $this->get_branch_cache_key_segment( $ref ) . '_' . ( ! empty( $source_context['source'] ) ? $source_context['source'] : 'branch' ) . $subdir_key;
		$cached         = H2WP_Cache::get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$readme_content = $this->fetch_readme_content( $owner, $repo, $ref, $subdirectory );
		if ( is_wp_error( $readme_content ) ) {
			return $readme_content;
		}

		$headers = $this->extract_headers_from_readme( $readme_content );
		H2WP_Cache::set( $cache_key, $headers );
		return $headers;
	}

	/**
	 * Get parsed headers from a theme style.css file.
	 *
	 * @param string $owner Owner of the repo.
	 * @param string $repo  Repo name.
	 * @param string $branch Optional branch name.
	 * @param bool   $prioritize_releases Whether releases are preferred.
	 * @param array  $source_context      Optional pre-resolved source context.
	 * @param string $subdirectory        Optional project subdirectory.
	 * @return array|WP_Error Parsed headers or error.
	 */
	public function get_theme_headers( $owner, $repo, $branch = '', $prioritize_releases = true, $source_context = null, $subdirectory = '' ) {
		$source_context = is_array( $source_context ) ? $source_context : $this->resolve_version_source( $owner, $repo, $branch, $prioritize_releases, $subdirectory );
		$ref            = isset( $source_context['ref'] ) ? (string) $source_context['ref'] : $branch;
		$subdir_key     = ! empty( $subdirectory ) ? '_' . md5( $subdirectory ) : '';
		$cache_key      = 'theme_headers_' . $owner . '_' . $repo . '_' . $this->get_branch_cache_key_segment( $ref ) . '_' . ( ! empty( $source_context['source'] ) ? $source_context['source'] : 'branch' ) . $subdir_key;
		$cached         = H2WP_Cache::get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$style_content = $this->fetch_theme_style_content( $owner, $repo, $ref, $subdirectory );
		if ( is_wp_error( $style_content ) ) {
			return $style_content;
		}

		$headers = $this->extract_headers_from_style( $style_content );
		H2WP_Cache::set( $cache_key, $headers );
		return $headers;
	}

	/**
	 * Build a stable cache-key segment for a branch.
	 *
	 * @param string $branch Branch name.
	 * @return string
	 */
	private function get_branch_cache_key_segment( $branch ) {
		if ( empty( $branch ) ) {
			return 'default';
		}

		return 'branch_' . md5( $branch );
	}

	/**
	 * Make a request to the GitHub API.
	 *
	 * @param string $url  Request URL.
	 * @param array  $args Optional arguments.
	 * @return array|WP_Error
	 */
	private function request( $url, $args = array() ) {
		$default_args = array(
			'method'     => 'GET',
			'user-agent' => 'WordPress/hub2wp',
		);

		if ( ! empty( $this->access_token ) ) {
			$default_args['headers']['Authorization'] = 'token ' . $this->access_token;
		}

		// wp_parse_args() is a shallow merge, so a caller that passes custom
		// headers (e.g. Accept) would silently overwrite the entire headers array,
		// dropping Authorization. Deep-merge the headers sub-array manually first.
		if ( isset( $args['headers'] ) && isset( $default_args['headers'] ) ) {
			$args['headers'] = array_merge( $default_args['headers'], (array) $args['headers'] );
		}

		$args = wp_parse_args( $args, $default_args );
		/**
		 * Filter GitHub API request arguments before the request is sent.
		 *
		 * @param array           $args Request arguments for wp_remote_get().
		 * @param string          $url  Request URL.
		 * @param H2WP_GitHub_API $this GitHub API client instance.
		 */
		$filtered_args = apply_filters( 'hub2wp_github_request_args', $args, $url, $this );
		if ( is_array( $filtered_args ) ) {
			$args = $filtered_args;
		}
		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			// Translators: %s: HTTP status code.
			return new WP_Error( "h2wp_api_error_$code", sprintf( __( 'GitHub API HTTP error: %s', 'hub2wp' ), $code ) );
		}

		$headers = wp_remote_retrieve_headers( $response );
		$this->handle_rate_limits( $headers );

		return $response;
	}

	/**
	 * Get changelog from GitHub releases.
	 *
	 * @since 1.0.0
	 *
	 * @param string $owner Repository owner.
	 * @param string $repo  Repository name.
	 * @param string $subdirectory Optional project subdirectory.
	 * @return array|WP_Error Array of releases or error object.
	 */
	public function get_changelog( $owner, $repo, $subdirectory = '' ) {
		$subdirectory = trim( str_replace( '\\', '/', (string) $subdirectory ), '/' );
		$subdir_key   = empty( $subdirectory ) ? '' : '_' . md5( $subdirectory );
		$cache_key    = 'changelog_' . sanitize_key( $owner . '_' . $repo ) . $subdir_key;
		$cached       = H2WP_Cache::get( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$tag_prefixes = array();
		if ( '' !== $subdirectory ) {
			$tag_prefixes[] = $subdirectory . '/v';
			if ( basename( $subdirectory ) !== $subdirectory ) {
				$tag_prefixes[] = basename( $subdirectory ) . '/v';
			}
		}

		$releases = array();
		for ( $page = 1; $page <= 5; $page++ ) {
			$url      = add_query_arg(
				array(
					'per_page' => 100,
					'page'     => $page,
				),
				$this->base_url . '/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo ) . '/releases'
			);
			$response = $this->request( $url );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$page_releases = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $page_releases ) ) {
				return new WP_Error( 'h2wp_invalid_response', __( 'Invalid response from GitHub API', 'hub2wp' ) );
			}

			foreach ( $page_releases as $release ) {
				if ( ! is_array( $release ) || empty( $release['tag_name'] ) || ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ) {
					continue;
				}
				if ( ! empty( $tag_prefixes ) ) {
					$matches_project = false;
					foreach ( $tag_prefixes as $tag_prefix ) {
						if ( 0 === strpos( $release['tag_name'], $tag_prefix ) ) {
							$matches_project = true;
							break;
						}
					}
					if ( ! $matches_project ) {
						continue;
					}
				}
				$releases[] = $release;
			}

			if ( count( $page_releases ) < 100 ) {
				break;
			}
		}

		if ( ! empty( $tag_prefixes ) ) {
			$preferred_prefix = $tag_prefixes[0];
			$has_preferred    = false;
			foreach ( $releases as $release ) {
				if ( 0 === strpos( $release['tag_name'], $preferred_prefix ) ) {
					$has_preferred = true;
					break;
				}
			}
			if ( ! $has_preferred && isset( $tag_prefixes[1] ) ) {
				$preferred_prefix = $tag_prefixes[1];
			}
			$releases     = array_values(
				array_filter(
					$releases,
					function ( $release ) use ( $preferred_prefix ) {
						return 0 === strpos( $release['tag_name'], $preferred_prefix );
					}
				)
			);
			$tag_prefixes = array( $preferred_prefix );
		}

		$changelog = array_map(
			function ( $release ) use ( $tag_prefixes ) {
				$tag_name = isset( $release['tag_name'] ) ? (string) $release['tag_name'] : '';
				$version  = ltrim( $tag_name, 'v' );
				foreach ( $tag_prefixes as $tag_prefix ) {
					if ( 0 === strpos( $tag_name, $tag_prefix ) ) {
						$version = substr( $tag_name, strlen( $tag_prefix ) );
						break;
					}
				}
				return array(
					'version'     => sanitize_text_field( $version ),
					'title'       => isset( $release['name'] ) ? sanitize_text_field( $release['name'] ) : '',
					'description' => isset( $release['body'] ) ? wp_kses_post( $release['body'] ) : '',
					'date'        => isset( $release['published_at'] ) ? sanitize_text_field( $release['published_at'] ) : '',
					'url'         => isset( $release['html_url'] ) ? esc_url_raw( $release['html_url'] ) : '',
				);
			},
			$releases
		);

		H2WP_Cache::set( $cache_key, $changelog, HOUR_IN_SECONDS );

		return $changelog;
	}

	/**
	 * Detect whether a repository is a single plugin or a monorepo.
	 *
	 * Checks the repo root for a WordPress plugin header. If none is found,
	 * scans one level of subdirectories. Returns 'single' or 'monorepo' with
	 * a list of discovered plugins.
	 *
	 * @param string $owner  Repository owner.
	 * @param string $repo   Repository name.
	 * @param string $branch Optional branch.
	 * @param string $repo_type Extension type: plugin|theme.
	 * @return array|WP_Error {
	 *   type: 'single'|'monorepo',
	 *   plugins: array of { slug, subdirectory, main_file } (monorepo only)
	 * }
	 */
	public function detect_repo_type( $owner, $repo, $branch = '', $repo_type = 'plugin' ) {
		$repo_type = in_array( $repo_type, array( 'plugin', 'theme' ), true ) ? $repo_type : 'plugin';
		$cache_key = 'repo_type_' . $repo_type . '_' . $owner . '_' . $repo . '_' . $this->get_branch_cache_key_segment( $branch );
		$cached    = H2WP_Cache::get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$root_contents = $this->get_directory_contents( $owner, $repo, '', $branch );
		if ( is_wp_error( $root_contents ) ) {
			return $root_contents;
		}

		$found_root           = null;
		$files_scanned        = 0;
		$directory_checks     = 1;
		$max_file_checks      = empty( $this->access_token ) ? 30 : 100;
		$max_directory_checks = empty( $this->access_token ) ? 20 : 50;

		// Record a root extension, but continue scanning so hybrid repositories
		// containing both a root project and nested projects are not misclassified.
		foreach ( $root_contents as $item ) {
			if ( ! is_array( $item ) || 'file' !== ( isset( $item['type'] ) ? $item['type'] : '' ) || empty( $item['name'] ) || empty( $item['path'] ) || $files_scanned >= $max_file_checks ) {
				continue;
			}

			$is_match = ( 'theme' === $repo_type )
				? 'style.css' === strtolower( $item['name'] )
				: '.php' === strtolower( substr( $item['name'], -4 ) );
			if ( ! $is_match ) {
				continue;
			}

			++$files_scanned;
			$content = $this->get_file_content( $owner, $repo, $item['path'], $branch );
			if ( is_wp_error( $content ) ) {
				continue;
			}
			$has_header = ( 'theme' === $repo_type ) ? $this->has_theme_header( $content ) : $this->has_plugin_header( $content );
			if ( $has_header ) {
				$found_root = array(
					'slug'         => $repo,
					'subdirectory' => '',
					'main_file'    => $item['path'],
				);
				break;
			}
		}

		// Scan one level of subdirectories.
		// If a subdirectory contains no PHP files but only more subdirectories,
		// treat it as a container folder (e.g. /plugins/) and scan one level deeper.
		// Hard caps on directory and candidate-file requests keep API usage bounded
		// even on large monorepos and account for the lower anonymous rate limit.
		$found_plugins     = array();
		$top_level_scanned = 0;
		$max_top_level     = empty( $this->access_token ) ? 20 : 50;

		foreach ( $root_contents as $item ) {
			if ( $files_scanned >= $max_file_checks || $directory_checks >= $max_directory_checks ) {
				break;
			}
			if ( ! is_array( $item ) || 'dir' !== ( isset( $item['type'] ) ? $item['type'] : '' ) || empty( $item['path'] ) || empty( $item['name'] ) ) {
				continue;
			}
			if ( $top_level_scanned >= $max_top_level ) {
				break;
			}
			++$top_level_scanned;

			++$directory_checks;
			$subdir_contents = $this->get_directory_contents( $owner, $repo, $item['path'], $branch );
			if ( is_wp_error( $subdir_contents ) ) {
				continue;
			}

			// Check whether this subdir directly contains a plugin/theme file.
			$found_here = false;
			foreach ( $subdir_contents as $file ) {
				if ( ! is_array( $file ) || 'file' !== ( isset( $file['type'] ) ? $file['type'] : '' ) || empty( $file['name'] ) || empty( $file['path'] ) ) {
					continue;
				}
				$is_match = ( 'theme' === $repo_type )
					? strtolower( $file['name'] ) === 'style.css'
					: strtolower( substr( $file['name'], -4 ) ) === '.php';
				if ( ! $is_match ) {
					continue;
				}
				if ( $files_scanned >= $max_file_checks ) {
					break;
				}
				++$files_scanned;
				$content = $this->get_file_content( $owner, $repo, $file['path'], $branch );
				if ( is_wp_error( $content ) ) {
					continue;
				}
				$has_header = ( 'theme' === $repo_type )
					? $this->has_theme_header( $content )
					: $this->has_plugin_header( $content );
				if ( $has_header ) {
					$found_plugins[] = array(
						'slug'         => $item['name'],
						'subdirectory' => $item['path'],
						'main_file'    => $file['path'],
					);
					$found_here      = true;
					break;
				}
			}

			if ( $found_here ) {
				continue;
			}

			// No plugin PHP file found directly — check if this is a container
			// folder (like /plugins/) by scanning its subdirectories one level deeper.
			$container_scanned = 0;
			$max_container     = 10;
			foreach ( $subdir_contents as $subitem ) {
				if ( ! is_array( $subitem ) || 'dir' !== ( isset( $subitem['type'] ) ? $subitem['type'] : '' ) || empty( $subitem['path'] ) || empty( $subitem['name'] ) ) {
					continue;
				}
				if ( $container_scanned >= $max_container || $files_scanned >= $max_file_checks || $directory_checks >= $max_directory_checks ) {
					break;
				}
				++$container_scanned;

				++$directory_checks;
				$deep_contents = $this->get_directory_contents( $owner, $repo, $subitem['path'], $branch );
				if ( is_wp_error( $deep_contents ) ) {
					continue;
				}

				foreach ( $deep_contents as $file ) {
					if ( ! is_array( $file ) || 'file' !== ( isset( $file['type'] ) ? $file['type'] : '' ) || empty( $file['name'] ) || empty( $file['path'] ) ) {
						continue;
					}
					$is_match = ( 'theme' === $repo_type )
						? strtolower( $file['name'] ) === 'style.css'
						: strtolower( substr( $file['name'], -4 ) ) === '.php';
					if ( ! $is_match ) {
						continue;
					}
					if ( $files_scanned >= $max_file_checks ) {
						break;
					}
					++$files_scanned;
					$content = $this->get_file_content( $owner, $repo, $file['path'], $branch );
					if ( is_wp_error( $content ) ) {
						continue;
					}
					$has_header = ( 'theme' === $repo_type )
						? $this->has_theme_header( $content )
						: $this->has_plugin_header( $content );
					if ( $has_header ) {
						$found_plugins[] = array(
							'slug'         => $subitem['name'],
							'subdirectory' => $subitem['path'],
							'main_file'    => $file['path'],
						);
						break;
					}
				}
			}
		}

		if ( ! empty( $found_plugins ) ) {
			if ( null !== $found_root ) {
				array_unshift( $found_plugins, $found_root );
			}
			$result = array(
				'type'    => 'monorepo',
				'plugins' => $found_plugins,
			);
			H2WP_Cache::set( $cache_key, $result );
			return $result;
		}

		if ( null !== $found_root ) {
			$result = array(
				'type'    => 'single',
				'plugins' => array(),
			);
			H2WP_Cache::set( $cache_key, $result );
			return $result;
		}

		$message = 'theme' === $repo_type
			? __( 'No WordPress themes found in this repository.', 'hub2wp' )
			: __( 'No WordPress plugins found in this repository.', 'hub2wp' );
		return new WP_Error( 'h2wp_no_extension_found', $message );
	}

	/**
	 * Get the contents listing of a directory in a repository.
	 *
	 * @param string $owner  Repository owner.
	 * @param string $repo   Repository name.
	 * @param string $path   Directory path (empty string for root).
	 * @param string $branch Optional branch/ref.
	 * @return array|WP_Error
	 */
	public function get_directory_contents( $owner, $repo, $path = '', $branch = '' ) {
		$url = $this->base_url . '/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo ) . '/contents';
		if ( '' !== trim( $path, '/' ) ) {
			$url .= '/' . $this->encode_repository_path( $path );
		}
		if ( ! empty( $branch ) ) {
			$url = add_query_arg( 'ref', $branch, $url );
		}
		$response = $this->request( $url );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$contents = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $contents ) ) {
			return new WP_Error( 'h2wp_api_error', __( 'Invalid directory contents from GitHub API.', 'hub2wp' ) );
		}
		return $contents;
	}

	/**
	 * Get the decoded text content of a file from a repository.
	 *
	 * @param string $owner  Repository owner.
	 * @param string $repo   Repository name.
	 * @param string $path   File path within the repo.
	 * @param string $branch Optional branch/ref.
	 * @return string|WP_Error
	 */
	public function get_file_content( $owner, $repo, $path, $branch = '' ) {
		$url = $this->base_url . '/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo ) . '/contents/' . $this->encode_repository_path( $path );
		if ( ! empty( $branch ) ) {
			$url = add_query_arg( 'ref', $branch, $url );
		}
		$response = $this->request( $url );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['content'] ) ) {
			return new WP_Error( 'h2wp_api_error', __( 'Invalid file data from GitHub API.', 'hub2wp' ) );
		}
		return $this->decode_file_content( $data['content'] );
	}

	/**
	 * Decode GitHub Contents API file data.
	 *
	 * @param mixed $encoded_content Base64-encoded content.
	 * @return string|WP_Error Decoded content or error.
	 */
	private function decode_file_content( $encoded_content ) {
		if ( ! is_string( $encoded_content ) ) {
			return new WP_Error( 'h2wp_api_error', __( 'Invalid file encoding from GitHub API.', 'hub2wp' ) );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$content = base64_decode( preg_replace( '/\s+/', '', $encoded_content ), true );
		if ( false === $content ) {
			return new WP_Error( 'h2wp_api_error', __( 'Invalid file encoding from GitHub API.', 'hub2wp' ) );
		}

		return $content;
	}

	/**
	 * URL-encode a repository path while preserving slash separators.
	 *
	 * @param string $path Repository-relative path.
	 * @return string Encoded path.
	 */
	private function encode_repository_path( $path ) {
		return implode( '/', array_map( 'rawurlencode', explode( '/', trim( (string) $path, '/' ) ) ) );
	}

	/**
	 * Check whether PHP file content contains a WordPress plugin header.
	 *
	 * @param string $content File content.
	 * @return bool
	 */
	public function has_plugin_header( $content ) {
		return is_string( $content ) && ! empty( $this->extract_headers_from_plugin( $content )['name'] );
	}

	/**
	 * Check whether CSS content contains a WordPress theme header.
	 *
	 * @param string $content File content.
	 * @return bool
	 */
	public function has_theme_header( $content ) {
		return is_string( $content ) && ! empty( $this->extract_headers_from_style( $content )['name'] );
	}

	/**
	 * Get release details for one project in a monorepo.
	 *
	 * Project releases use the conventional "{slug}/v*" tag prefix. The release
	 * tag, metadata ref, and package URL are resolved together so version checks
	 * cannot use one release while installation downloads another. Explicit ZIP
	 * assets are supported, with browser URLs for public repositories and API URLs
	 * for authenticated downloads. The full-repository zipball is the fallback.
	 *
	 * @param string $owner        Repository owner.
	 * @param string $repo         Repository name.
	 * @param string $project_path Project directory path.
	 * @return array|WP_Error Release details or an error.
	 */
	public function get_monorepo_release_details( $owner, $repo, $project_path ) {
		$project_path = trim( str_replace( '\\', '/', (string) $project_path ), '/' );
		if ( '' === $project_path ) {
			return new WP_Error( 'h2wp_invalid_project_path', __( 'A monorepo project path is required.', 'hub2wp' ) );
		}
		$project_slug = basename( $project_path );
		$cache_key    = 'monorepo_release_' . $owner . '_' . $repo . '_' . md5( $project_path ) . ( empty( $this->access_token ) ? '_public' : '_auth' );
		$cached       = H2WP_Cache::get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$tag_prefixes = array( $project_path . '/v' );
		if ( $project_slug !== $project_path ) {
			$tag_prefixes[] = $project_slug . '/v';
		}
		$max_pages        = 5;
		$fallback_release = null;

		for ( $page = 1; $page <= $max_pages; $page++ ) {
			$url      = add_query_arg(
				array(
					'per_page' => 100,
					'page'     => $page,
				),
				$this->base_url . '/repos/' . $owner . '/' . $repo . '/releases'
			);
			$response = $this->request( $url );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$releases = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $releases ) ) {
				return new WP_Error( 'h2wp_api_error', __( 'Invalid releases data from GitHub API.', 'hub2wp' ) );
			}

			foreach ( $releases as $release ) {
				if (
					! is_array( $release ) ||
					empty( $release['tag_name'] ) ||
					! empty( $release['draft'] ) ||
					! empty( $release['prerelease'] )
				) {
					continue;
				}

				if ( 0 === strpos( $release['tag_name'], $tag_prefixes[0] ) ) {
					$result = $this->format_monorepo_release_details( $release, $project_slug );
					H2WP_Cache::set( $cache_key, $result );
					return $result;
				}

				if (
					null === $fallback_release &&
					isset( $tag_prefixes[1] ) &&
					0 === strpos( $release['tag_name'], $tag_prefixes[1] )
				) {
					$fallback_release = $release;
				}
			}

			if ( count( $releases ) < 100 ) {
				break;
			}
		}

		if ( null !== $fallback_release ) {
			$result = $this->format_monorepo_release_details( $fallback_release, $project_slug );
			H2WP_Cache::set( $cache_key, $result );
			return $result;
		}

		$result = array(
			'uses_releases' => false,
			'tag_name'      => '',
			'zipball_url'   => '',
			'download_url'  => '',
			'package_scope' => 'repository',
			'published_at'  => '',
		);
		H2WP_Cache::set( $cache_key, $result );
		return $result;
	}

	/**
	 * Normalize one matching project release and select its package URL.
	 *
	 * @param array  $release      GitHub release payload.
	 * @param string $project_slug Project directory name.
	 * @return array Release details.
	 */
	private function format_monorepo_release_details( $release, $project_slug ) {
		$download_url  = '';
		$package_scope = 'repository';
		$zip_assets    = array();
		foreach ( isset( $release['assets'] ) && is_array( $release['assets'] ) ? $release['assets'] : array() as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}
			$name         = isset( $asset['name'] ) ? (string) $asset['name'] : '';
			$content_type = isset( $asset['content_type'] ) ? (string) $asset['content_type'] : '';
			if ( 'application/zip' === $content_type || '.zip' === strtolower( substr( $name, -4 ) ) ) {
				$zip_assets[] = $asset;
			}
		}

		if ( ! empty( $zip_assets ) ) {
			usort(
				$zip_assets,
				function ( $left, $right ) use ( $project_slug ) {
					$left_name  = isset( $left['name'] ) ? strtolower( $left['name'] ) : '';
					$right_name = isset( $right['name'] ) ? strtolower( $right['name'] ) : '';
					$slug       = strtolower( $project_slug );
					return (int) ( 0 !== strpos( $left_name, $slug ) ) <=> (int) ( 0 !== strpos( $right_name, $slug ) );
				}
			);

			$asset = $zip_assets[0];
			if ( ! empty( $this->access_token ) && ! empty( $asset['url'] ) ) {
				$download_url = $asset['url'];
			} elseif ( ! empty( $asset['browser_download_url'] ) ) {
				$download_url = $asset['browser_download_url'];
			}
			if ( '' !== $download_url ) {
				$package_scope = 'extension';
			}
		}

		if ( '' === $download_url && ! empty( $release['zipball_url'] ) ) {
			$download_url = $release['zipball_url'];
		}

		return array(
			'uses_releases' => true,
			'tag_name'      => sanitize_text_field( $release['tag_name'] ),
			'zipball_url'   => isset( $release['zipball_url'] ) ? esc_url_raw( $release['zipball_url'] ) : '',
			'download_url'  => esc_url_raw( $download_url ),
			'package_scope' => $package_scope,
			'published_at'  => isset( $release['published_at'] ) ? sanitize_text_field( $release['published_at'] ) : '',
		);
	}

	/**
	 * Backward-compatible project release URL lookup.
	 *
	 * @param string $owner        Repository owner.
	 * @param string $repo         Repository name.
	 * @param string $project_slug Project directory name or path.
	 * @return string|WP_Error Download URL or an error.
	 */
	public function get_monorepo_release_asset_url( $owner, $repo, $project_slug ) {
		$release = $this->get_monorepo_release_details( $owner, $repo, $project_slug );
		if ( is_wp_error( $release ) ) {
			return $release;
		}
		if ( empty( $release['uses_releases'] ) || empty( $release['download_url'] ) ) {
			return new WP_Error( 'h2wp_no_release', __( 'No matching project release found.', 'hub2wp' ) );
		}

		return $release['download_url'];
	}
}
