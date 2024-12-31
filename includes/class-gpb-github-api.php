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
class GPB_GitHub_API {

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
		$cached    = GPB_Cache::get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$url = add_query_arg(
			array(
				'q'        => $query,
				'page'     => $page,
				'per_page' => 12,
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
			return new WP_Error( 'gpb_api_error', __( 'Invalid response from GitHub API.', 'github-plugin-browser' ) );
		}

		GPB_Cache::set( $cache_key, $data );
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
	 * @param string $owner Owner of the repo.
	 * @param string $repo  Repo name.
	 * @return array|WP_Error Repository details or error.
	 */
	public function get_repo_details( $owner, $repo ) {
		$cache_key = 'repo_details_' . $owner . '_' . $repo;
		$cached    = GPB_Cache::get( $cache_key );
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
			return new WP_Error( 'gpb_api_error', __( 'Invalid repository data from GitHub API.', 'github-plugin-browser' ) );
		}

		GPB_Cache::set( $cache_key, $data );
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
		$cached    = GPB_Cache::get( $cache_key );
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
			return new WP_Error( 'gpb_readme_error', __( 'Unable to retrieve README.', 'github-plugin-browser' ) );
		}

		// Sanitize HTML for safe output.
		$allowed_html    = wp_kses_allowed_html( 'post' );
		$sanitized_html  = wp_kses( $html, $allowed_html );

		GPB_Cache::set( $cache_key, $sanitized_html );
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
		$cached    = GPB_Cache::get( $cache_key );
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
			GPB_Cache::set( $cache_key, $og_image );
			return $og_image;
		}

		// Fallback to owner's avatar.
		$repo_details = $this->get_repo_details( $owner, $repo );
		if ( is_wp_error( $repo_details ) ) {
			return $repo_details;
		}

		if ( isset( $repo_details['owner']['avatar_url'] ) ) {
			$avatar_url = esc_url_raw( $repo_details['owner']['avatar_url'] );
			GPB_Cache::set( $cache_key, $avatar_url );
			return $avatar_url;
		}

		return new WP_Error( 'gpb_image_error', __( 'No image available.', 'github-plugin-browser' ) );
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
		$cached    = GPB_Cache::get( $cache_key );
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
			GPB_Cache::set( $cache_key, $count );
			return $count;
		}

		return new WP_Error( 'gpb_watchers_error', __( 'Unable to determine watchers count.', 'github-plugin-browser' ) );
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
		$cached    = GPB_Cache::get( $cache_key );
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
			GPB_Cache::set( $cache_key, $language );
			return $language;
		}

		return new WP_Error( 'gpb_language_error', __( 'Unable to determine primary language.', 'github-plugin-browser' ) );
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
		$cached    = GPB_Cache::get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$url      = 'https://github.com/' . $owner . '/' . $repo;
		$response = wp_remote_get( $url, array( 'timeout' => 10 ) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'gpb_scrape_error', __( 'Unable to fetch repository page.', 'github-plugin-browser' ) );
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return new WP_Error( 'gpb_scrape_error', __( 'Empty repository page.', 'github-plugin-browser' ) );
		}

		GPB_Cache::set( $cache_key, $body );
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
		$cached = GPB_Cache::get( $cache_key );
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
			return new WP_Error( 'gpb_api_error', __( 'Invalid contributors data from GitHub API.', 'github-plugin-browser' ) );
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

		GPB_Cache::set( $cache_key, $data );
		return $data;
	}

	/**
	 * Check rate limits and handle them.
	 *
	 * @param array $headers Response headers.
	 */
	private function handle_rate_limits( $headers ) {
		if ( isset( $headers['x-ratelimit-remaining'] ) && (int) $headers['x-ratelimit-remaining'] === 0 ) {
			set_transient( 'gpb_rate_limit_reached', 1, HOUR_IN_SECONDS );
		} else {
			delete_transient( 'gpb_rate_limit_reached' );
		}
	}

	/**
	 * Check if plugin is compatible with the current WordPress environment.
	 *
	 * @param string $owner Owner of the repo.
	 * @param string $repo  Repo name.
	 * @return array Compatibility data (is_compatible, reason) or error.
	 */
	public function check_compatibility( $owner, $repo ) {
		$cache_key = 'compatibility_' . $owner . '_' . $repo;
		$cached = GPB_Cache::get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$readme_content = $this->fetch_readme_content( $owner, $repo );
		if ( is_wp_error( $readme_content ) ) {
			$error_data = array(
				'is_compatible' => false,
				'reason'        => __( 'No valid readme file found.', 'github-plugin-browser' ),
			);
			GPB_Cache::set( $cache_key, $error_data );
			return $error_data;
		}

		$headers = $this->extract_headers_from_readme( $readme_content );
		if ( empty( $headers['stable tag'] ) ) {
			return array(
				'is_compatible' => false,
				'reason'        => __( 'No valid readme file found.', 'github-plugin-browser' ),
			);
		}

		$compatibility = $this->evaluate_compatibility( $headers );
		$compatibility['headers'] = $headers;
		GPB_Cache::set( $cache_key, $compatibility );
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

			if ( $response->get_error_code() !== 'gpb_api_error_404' ) {
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

		return new WP_Error( 'gpb_readme_not_found', __( 'No valid readme file found.', 'github-plugin-browser' ) );
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
	 * Evaluate compatibility based on parsed headers.
	 *
	 * @param array $headers Parsed readme headers.
	 * @return array Compatibility data.
	 */
	private function evaluate_compatibility( $headers ) {
		if ( ! empty( $headers['requires at least'] ) && version_compare( get_bloginfo( 'version' ), $headers['requires at least'], '<' ) ) {
			return array(
				'is_compatible' => false,
				'reason'        => sprintf(
					__( 'This plugin requires WordPress version %s or higher.', 'github-plugin-browser' ),
					$headers['requires at least']
				),
			);
		}

		if ( ! empty( $headers['requires php'] ) && version_compare( PHP_VERSION, $headers['requires php'], '<' ) ) {
			return array(
				'is_compatible' => false,
				'reason'        => sprintf(
					__( 'This plugin requires PHP version %s or higher.', 'github-plugin-browser' ),
					$headers['requires php']
				),
			);
		}

		if ( ! empty( $headers['tested up to'] ) && version_compare( get_bloginfo( 'version' ), $headers['tested up to'], '>' ) ) {
			return array(
				'is_compatible' => true,
				'reason'        => __( 'This plugin has not been tested with your WordPress version.', 'github-plugin-browser' ),
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
		$cached = GPB_Cache::get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$readme_content = $this->fetch_readme_content( $owner, $repo );
		if ( is_wp_error( $readme_content ) ) {
			return $readme_content;
		}

		$headers = $this->extract_headers_from_readme( $readme_content );
		GPB_Cache::set( $cache_key, $headers );
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
			'user-agent' => 'WordPress/GPB',
		);

		if ( ! empty( $this->access_token ) ) {
			$default_args['headers']['Authorization'] = 'token ' . $this->access_token;
		}

		$args = wp_parse_args( $args, $default_args );
		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( "gpb_api_error_$code", sprintf( __( 'GitHub API HTTP error: %s', 'github-plugin-browser' ), $code ) );
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
		$cached    = GPB_Cache::get( $cache_key );

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
				'gpb_invalid_response',
				__( 'Invalid response from GitHub API', 'github-plugin-browser' )
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

		GPB_Cache::set( $cache_key, $changelog, HOUR_IN_SECONDS );

		return $changelog;
	}
}
