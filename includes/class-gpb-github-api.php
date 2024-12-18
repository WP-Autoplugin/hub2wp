<?php
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
	 * @return array Results or WP_Error.
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
				'per_page' => 10,
				'sort'     => $sort,
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

		GPB_Cache::set( $cache_key, $data, HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * Get latest release download URL for a repository.
	 *
	 * @param string $owner Owner of the repo.
	 * @param string $repo  Repo name.
	 * @return string|WP_Error URL or error.
	 */
	public function get_latest_release_zip( $owner, $repo ) {
		$cache_key = 'release_' . $owner . '_' . $repo;
		$cached    = GPB_Cache::get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$url      = $this->base_url . '/repos/' . $owner . '/' . $repo . '/releases/latest';
		$response = $this->request( $url );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( isset( $data['zipball_url'] ) ) {
			GPB_Cache::set( $cache_key, $data['zipball_url'], HOUR_IN_SECONDS );
			return $data['zipball_url'];
		}

		// If no release found, try default branch archive.
		$url      = $this->base_url . '/repos/' . $owner . '/' . $repo;
		$response = $this->request( $url );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( isset( $data['default_branch'] ) ) {
			$branch_url = $this->base_url . '/repos/' . $owner . '/' . $repo . '/zipball/' . $data['default_branch'];
			GPB_Cache::set( $cache_key, $branch_url, HOUR_IN_SECONDS );
			return $branch_url;
		}

		return new WP_Error( 'gpb_no_release', __( 'No release or default branch found.', 'github-plugin-browser' ) );
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

		GPB_Cache::set( $cache_key, $data, HOUR_IN_SECONDS );
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

		GPB_Cache::set( $cache_key, $sanitized_html, HOUR_IN_SECONDS );
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
			GPB_Cache::set( $cache_key, $og_image, DAY_IN_SECONDS );
			return $og_image;
		}

		// Fallback to owner's avatar.
		$repo_details = $this->get_repo_details( $owner, $repo );
		if ( is_wp_error( $repo_details ) ) {
			return $repo_details;
		}

		if ( isset( $repo_details['owner']['avatar_url'] ) ) {
			$avatar_url = esc_url_raw( $repo_details['owner']['avatar_url'] );
			GPB_Cache::set( $cache_key, $avatar_url, DAY_IN_SECONDS );
			return $avatar_url;
		}

		return new WP_Error( 'gpb_image_error', __( 'No image available.', 'github-plugin-browser' ) );
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
			GPB_Cache::set( $cache_key, $language, DAY_IN_SECONDS );
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

		GPB_Cache::set( $cache_key, $body, HOUR_IN_SECONDS );
		return $body;
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
			return new WP_Error( 'gpb_api_error', __( 'GitHub API returned an error.', 'github-plugin-browser' ) );
		}

		$headers = wp_remote_retrieve_headers( $response );
		$this->handle_rate_limits( $headers );

		return $response;
	}
}
