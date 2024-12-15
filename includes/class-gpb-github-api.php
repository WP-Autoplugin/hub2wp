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
	 * @return array Results or WP_Error.
	 */
	public function search_plugins( $query = 'topic:wordpress-plugin', $page = 1 ) {
		$cache_key = 'search_' . md5( $query . $page . $this->access_token );
		$cached    = GPB_Cache::get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$url = add_query_arg(
			array(
				'q'     => $query,
				'page'  => $page,
				'per_page' => 10,
				'sort'  => 'stars',
				'order' => 'desc',
			),
			$this->base_url . '/search/repositories'
		);

		$response = $this->request( $url );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['items'] ) ) {
			return new WP_Error( 'gpb_api_error', __( 'Invalid response from GitHub API.', 'github-plugin-installer' ) );
		}

		GPB_Cache::set( $cache_key, $data );
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
			GPB_Cache::set( $cache_key, $data['zipball_url'] );
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
			GPB_Cache::set( $cache_key, $branch_url );
			return $branch_url;
		}

		return new WP_Error( 'gpb_no_release', __( 'No release or default branch found.', 'github-plugin-installer' ) );
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
	 * @param string $url Request URL.
	 * @return array|WP_Error
	 */
	private function request( $url ) {
		$args = array(
			'user-agent' => 'WordPress/GPB',
		);

		if ( ! empty( $this->access_token ) ) {
			$args['headers']['Authorization'] = 'token ' . $this->access_token;
		}

		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'gpb_api_error', __( 'GitHub API returned an error.', 'github-plugin-installer' ) );
		}

		$headers = wp_remote_retrieve_headers( $response );
		$this->handle_rate_limits( $headers );

		return $response;
	}
}
