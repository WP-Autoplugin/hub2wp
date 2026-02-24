<?php
/**
 * Handles interaction with the GitHub API.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles interaction with the GitHub API.
 */
class H2WP_GitHub_API {

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
		if ( ! is_array( $data ) || ! isset( $data['items'] ) ) {
			return new WP_Error( 'h2wp_api_error', __( 'Invalid response from GitHub API.', 'hub2wp' ) );
		}

		H2WP_Cache::set( $cache_key, $data );
		return $data;
	}

	/**
	 * Get zipball URL for the main branch of a repository.
	 *
	 * @param string $owner Owner of the repo.
	 * @param string $repo  Repo name.
	 * @return string Zipball URL.
	 */
	public function get_download_url( $owner, $repo ) {
		$branch_url = $this->base_url . '/repos/' . $owner . '/' . $repo . '/zipball';
		return $branch_url;
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

		// Verify access before fetching full details
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

		$url = $this->base_url . '/repos/' . $owner . '/' . $repo;
		$response = $this->request( $url, array( 'method' => 'HEAD' ) );

		if ( is_wp_error( $response ) ) {
			$error_code = $response->get_error_code();
			
			// Provide more specific error messages based on HTTP status
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

		// Check if the repository is actually private
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( is_array( $body ) && isset( $body['private'] ) && false === $body['private'] ) {
			// Repository is public, warn the user but still allow it
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

		$url      = $this->base_url . '/repos/' . $owner . '/' . $repo . '/branches/' . $branch;
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
	 * @return string|WP_Error Rendered README HTML or error.
	 */
	public function get_readme_html( $owner, $repo ) {
		$cache_key = 'readme_html_' . $owner . '_' . $repo;
		$cached    = H2WP_Cache::get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$url     = $this->base_url . '/repos/' . $owner . '/' . $repo . '/readme';
		$args    = array(
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
		$allowed_html    = wp_kses_allowed_html( 'post' );
		$sanitized_html  = wp_kses( $html, $allowed_html );

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

		// Fetch and cache repository HTML
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

		// Fetch and cache repository HTML
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

		// Fetch and cache repository HTML
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
		$cached = H2WP_Cache::get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$url = $this->base_url . '/repos/' . $owner . '/' . $repo . '/contributors';
		$response = $this->request( $url );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$contributors = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $contributors ) ) {
			return new WP_Error( 'h2wp_api_error', __( 'Invalid contributors data from GitHub API.', 'hub2wp' ) );
		}

		// Limit to 5 contributors
		$contributors = array_slice( $contributors, 0, 5 );

		// Prepare data for each contributor
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
		if ( isset( $headers['x-ratelimit-remaining'] ) && (int) $headers['x-ratelimit-remaining'] === 0 ) {
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
	 * @return array Compatibility data (is_compatible, reason) or error.
	 */
	public function check_compatibility( $owner, $repo, $repo_type = 'plugin' ) {
		$repo_type = in_array( $repo_type, array( 'plugin', 'theme' ), true ) ? $repo_type : 'plugin';
		$cache_key = 'compatibility_' . $repo_type . '_' . $owner . '_' . $repo;
		$cached = H2WP_Cache::get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		if ( 'theme' === $repo_type ) {
			$style_content = $this->fetch_theme_style_content( $owner, $repo );
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
			$readme_content = $this->fetch_readme_content( $owner, $repo );
			if ( is_wp_error( $readme_content ) ) {
				$error_data = array(
					'is_compatible' => false,
					'reason'        => __( 'No valid readme file found.', 'hub2wp' ),
				);
				H2WP_Cache::set( $cache_key, $error_data );
				return $error_data;
			}

			$headers = $this->extract_headers_from_readme( $readme_content );
			if ( empty( $headers['stable tag'] ) ) {
				return array(
					'is_compatible' => false,
					'reason'        => __( 'No valid readme file found.', 'hub2wp' ),
				);
			}

			// Match modal field naming.
			$headers['version'] = $headers['stable tag'];
		}

		$compatibility = $this->evaluate_compatibility( $headers, $repo_type );
		$compatibility['headers'] = $headers;
		H2WP_Cache::set( $cache_key, $compatibility );
		return $compatibility;
	}

	/**
	 * Fetch the content of the readme file from the repository.
	 *
	 * @param string $owner Owner of the repo.
	 * @param string $repo  Repo name.
	 * @return string|WP_Error Readme content or error.
	 */
	private function fetch_readme_content( $owner, $repo ) {
		$filenames = array( 'readme.txt', 'README.txt' );

		foreach ( $filenames as $filename ) {
			$url = $this->base_url . "/repos/{$owner}/{$repo}/contents/{$filename}";
			$response = $this->request( $url );

			if ( ! is_wp_error( $response ) ) {
				$data = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( isset( $data['content'] ) ) {
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- We need to decode that base64.
					return base64_decode( $data['content'] );
				}
			}

			if ( $response->get_error_code() !== 'h2wp_api_error_404' ) {
				return $response;
			}
		}

		// Fall back to the readme endpoint which will find README.md/readme.md/README etc.
		$url = $this->base_url . "/repos/{$owner}/{$repo}/readme";
		$response = $this->request( $url );

		if ( ! is_wp_error( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( isset( $data['content'] ) ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- We need to decode that base64.
				return base64_decode( $data['content'] );
			}
		}

		return new WP_Error( 'h2wp_readme_not_found', __( 'No valid readme file found.', 'hub2wp' ) );
	}

	/**
	 * Fetch style.css content for a theme repository.
	 *
	 * @param string $owner Owner of the repo.
	 * @param string $repo  Repo name.
	 * @return string|WP_Error Theme style.css content or error.
	 */
	private function fetch_theme_style_content( $owner, $repo ) {
		$filenames = array( 'style.css', 'STYLE.CSS' );

		foreach ( $filenames as $filename ) {
			$url      = $this->base_url . "/repos/{$owner}/{$repo}/contents/{$filename}";
			$response = $this->request( $url );

			if ( ! is_wp_error( $response ) ) {
				$data = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( isset( $data['content'] ) ) {
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- We need to decode that base64.
					return base64_decode( $data['content'] );
				}
			}

			if ( ! is_wp_error( $response ) || $response->get_error_code() !== 'h2wp_api_error_404' ) {
				return $response;
			}
		}

		return new WP_Error( 'h2wp_style_not_found', __( 'No valid theme style.css file found.', 'hub2wp' ) );
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
	 * Extract headers from a theme style.css file.
	 *
	 * @param string $style_content style.css content.
	 * @return array Extracted headers.
	 */
	private function extract_headers_from_style( $style_content ) {
		$fields = array(
			'requires at least' => '',
			'tested up to'      => '',
			'requires php'      => '',
			'version'           => '',
		);

		$style_headers_map = array(
			'requires at least' => 'Requires at least',
			'tested up to'      => 'Tested up to',
			'requires php'      => 'Requires PHP',
			'version'           => 'Version',
		);

		foreach ( $style_headers_map as $key => $label ) {
			if ( preg_match( '/^\s*' . preg_quote( $label, '/' ) . ':\s*(.+)$/mi', $style_content, $matches ) ) {
				$fields[ $key ] = trim( $matches[1] );
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
	 * Get parsed headers from the readme.txt file.
	 *
	 * @param string $owner Owner of the repo.
	 * @param string $repo  Repo name.
	 * @return array|WP_Error Parsed headers or error.
	 */
	public function get_readme_headers( $owner, $repo ) {
		$cache_key = 'readme_headers_' . $owner . '_' . $repo;
		$cached = H2WP_Cache::get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$readme_content = $this->fetch_readme_content( $owner, $repo );
		if ( is_wp_error( $readme_content ) ) {
			return $readme_content;
		}

		$headers = $this->extract_headers_from_readme( $readme_content );
		H2WP_Cache::set( $cache_key, $headers );
		return $headers;
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

		$args     = wp_parse_args( $args, $default_args );
		$response = wp_remote_get( $url, $args );

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
	 * @return array|WP_Error Array of releases or error object.
	 */
	public function get_changelog( $owner, $repo ) {
		$cache_key = 'changelog_' . sanitize_key( $owner . '_' . $repo );
		$cached    = H2WP_Cache::get( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$url = sprintf(
			'https://api.github.com/repos/%s/%s/releases',
			urlencode( $owner ),
			urlencode( $repo )
		);

		$response = $this->request( $url );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$releases = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $releases ) ) {
			return new WP_Error(
				'h2wp_invalid_response',
				__( 'Invalid response from GitHub API', 'hub2wp' )
			);
		}

		$changelog = array_map(
			function( $release ) {
				return array(
					'version'     => ltrim( $release['tag_name'], 'v' ),
					'title'       => sanitize_text_field( $release['name'] ),
					'description' => wp_kses_post( $release['body'] ),
					'date'        => sanitize_text_field( $release['published_at'] ),
					'url'         => esc_url_raw( $release['html_url'] ),
				);
			},
			$releases
		);

		H2WP_Cache::set( $cache_key, $changelog, HOUR_IN_SECONDS );

		return $changelog;
	}
}
