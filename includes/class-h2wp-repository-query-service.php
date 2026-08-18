<?php
/**
 * Shared repository lookup and normalization helpers.
 *
 * @package hub2wp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides normalized repository details and compatibility results.
 */
class H2WP_Repository_Query_Service {

	/**
	 * GitHub API client.
	 *
	 * @var H2WP_GitHub_API
	 */
	private $api;

	/**
	 * Saved GitHub access token used by this service.
	 *
	 * @var string
	 */
	private $access_token;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->access_token = H2WP_Settings::get_access_token();
		$this->api          = new H2WP_GitHub_API( $this->access_token );
	}

	/**
	 * Get repository details.
	 *
	 * @param string $owner Repository owner.
	 * @param string $repo Repository name.
	 * @param string $repo_type Repository type.
	 * @param string $subdirectory Optional project subdirectory.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_repository_details( $owner, $repo, $repo_type = 'plugin', $subdirectory = '' ) {
		if ( ! H2WP_Settings::validate_repo_format( $owner . '/' . $repo ) ) {
			return new WP_Error( 'h2wp_invalid_repository', __( 'A valid GitHub repository is required.', 'hub2wp' ) );
		}
		$repo_type    = $this->normalize_repo_type( $repo_type );
		$subdirectory = H2WP_Settings::normalize_subdirectory( $subdirectory );
		if ( is_wp_error( $subdirectory ) ) {
			return $subdirectory;
		}
		if ( '' !== $subdirectory && '' === $this->access_token ) {
			return new WP_Error(
				'h2wp_monorepo_token_required',
				__( 'Monorepo support requires a saved GitHub access token. Add one in hub2wp Settings and try again.', 'hub2wp' )
			);
		}
		$tracking       = H2WP_Settings::get_repo_tracking_preferences( $owner, $repo, $repo_type, $subdirectory );
		$source_context = $this->api->resolve_version_source( $owner, $repo, $tracking['branch'], $tracking['prioritize_releases'], $subdirectory );
		$repo_details   = $this->api->get_repo_details( $owner, $repo );

		if ( is_wp_error( $repo_details ) ) {
			return $repo_details;
		}

		$readme_html = $this->api->get_readme_html( $owner, $repo, $source_context['ref'], $subdirectory );
		if ( is_wp_error( $readme_html ) ) {
			$readme_html = __( 'No README available.', 'hub2wp' );
		}
		$readme_html = $this->strip_plugin_headers( $readme_html );

		$og_image = $this->api->get_og_image( $owner, $repo );
		if ( is_wp_error( $og_image ) ) {
			$og_image = isset( $repo_details['owner']['avatar_url'] ) ? $repo_details['owner']['avatar_url'] : '';
		}

		$watchers = $this->api->get_watchers_count( $owner, $repo );
		if ( is_wp_error( $watchers ) ) {
			$watchers = 0;
		}

		$project_slug = '' === $subdirectory ? $repo : basename( $subdirectory );
		$project_url  = isset( $repo_details['html_url'] ) ? $repo_details['html_url'] : '';
		if ( '' !== $subdirectory && '' !== $project_url ) {
			$source_ref   = ! empty( $source_context['ref'] ) ? $source_context['ref'] : ( isset( $repo_details['default_branch'] ) ? $repo_details['default_branch'] : 'HEAD' );
			$project_url .= '/tree/' . rawurlencode( $source_ref ) . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $subdirectory ) ) );
		}

		return array(
			'name'                => $project_slug,
			'display_name'        => $this->format_display_name( $project_slug ),
			'owner'               => isset( $repo_details['owner']['login'] ) ? sanitize_text_field( $repo_details['owner']['login'] ) : '',
			'repo'                => isset( $repo_details['name'] ) ? sanitize_text_field( $repo_details['name'] ) : '',
			'repo_type'           => $repo_type,
			'subdirectory'        => $subdirectory,
			'description'         => isset( $repo_details['description'] ) ? esc_html( $repo_details['description'] ) : '',
			'readme'              => $readme_html,
			'stargazers'          => isset( $repo_details['stargazers_count'] ) ? number_format_i18n( $repo_details['stargazers_count'] ) : '0',
			'forks'               => isset( $repo_details['forks_count'] ) ? number_format_i18n( $repo_details['forks_count'] ) : '0',
			'watchers'            => number_format_i18n( (int) $watchers ),
			'open_issues'         => isset( $repo_details['open_issues_count'] ) ? number_format_i18n( $repo_details['open_issues_count'] ) : '0',
			'html_url'            => esc_url_raw( $project_url ),
			'homepage'            => isset( $repo_details['homepage'] ) ? esc_url_raw( $repo_details['homepage'] ) : '',
			'og_image'            => esc_url_raw( $og_image ),
			'owner_avatar_url'    => isset( $repo_details['owner']['avatar_url'] ) ? esc_url_raw( $repo_details['owner']['avatar_url'] ) : '',
			'author'              => isset( $repo_details['owner']['login'] ) ? sanitize_text_field( $repo_details['owner']['login'] ) : '',
			'author_url'          => isset( $repo_details['owner']['html_url'] ) ? esc_url_raw( $repo_details['owner']['html_url'] ) : '',
			'updated_at'          => $this->calculate_last_updated( $owner, $repo, $repo_type, $tracking, $repo_details, $source_context ),
			'topics'              => isset( $repo_details['topics'] ) && is_array( $repo_details['topics'] ) ? $this->extract_topics( $repo_details['topics'], $repo_type ) : array(),
			'is_installed'        => H2WP_Admin_Page::is_repo_installed( $owner, $project_slug, $repo_type ),
			'prioritize_releases' => ! empty( $tracking['prioritize_releases'] ),
			'uses_releases'       => ! empty( $source_context['uses_releases'] ),
			'version_source'      => isset( $source_context['source'] ) ? $source_context['source'] : 'branch',
		);
	}

	/**
	 * Check repository compatibility.
	 *
	 * @param string $owner Repository owner.
	 * @param string $repo Repository name.
	 * @param string $repo_type Repository type.
	 * @param string $subdirectory Optional project subdirectory.
	 * @return array<string, mixed>
	 */
	public function check_repository_compatibility( $owner, $repo, $repo_type = 'plugin', $subdirectory = '' ) {
		if ( ! H2WP_Settings::validate_repo_format( $owner . '/' . $repo ) ) {
			return array(
				'is_compatible'  => false,
				'reason'         => __( 'A valid GitHub repository is required.', 'hub2wp' ),
				'headers'        => array(),
				'source_context' => array(),
			);
		}
		$repo_type    = $this->normalize_repo_type( $repo_type );
		$subdirectory = H2WP_Settings::normalize_subdirectory( $subdirectory );
		if ( is_wp_error( $subdirectory ) ) {
			return array(
				'is_compatible'  => false,
				'reason'         => $subdirectory->get_error_message(),
				'headers'        => array(),
				'source_context' => array(),
			);
		}
		if ( '' !== $subdirectory && '' === $this->access_token ) {
			return array(
				'is_compatible'  => false,
				'reason'         => __( 'Monorepo support requires a saved GitHub access token. Add one in hub2wp Settings and try again.', 'hub2wp' ),
				'headers'        => array(),
				'source_context' => array(),
			);
		}
		$tracking       = H2WP_Settings::get_repo_tracking_preferences( $owner, $repo, $repo_type, $subdirectory );
		$source_context = $this->api->resolve_version_source( $owner, $repo, $tracking['branch'], $tracking['prioritize_releases'], $subdirectory );
		$compatibility  = $this->api->check_compatibility( $owner, $repo, $repo_type, $tracking['branch'], $tracking['prioritize_releases'], $source_context, $subdirectory );
		if ( is_wp_error( $compatibility ) ) {
			return array(
				'is_compatible'  => false,
				'reason'         => $compatibility->get_error_message(),
				'headers'        => array(),
				'source_context' => $source_context,
			);
		}
		$compatibility = is_array( $compatibility ) ? $compatibility : array();

		return array(
			'is_compatible'  => ! empty( $compatibility['is_compatible'] ),
			'reason'         => isset( $compatibility['reason'] ) ? $compatibility['reason'] : '',
			'headers'        => isset( $compatibility['headers'] ) && is_array( $compatibility['headers'] ) ? $compatibility['headers'] : array(),
			'source_context' => $source_context,
		);
	}

	/**
	 * Get changelog.
	 *
	 * @param string $owner Repository owner.
	 * @param string $repo Repository name.
	 * @param string $subdirectory Optional project subdirectory.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function get_changelog( $owner, $repo, $subdirectory = '' ) {
		if ( ! H2WP_Settings::validate_repo_format( $owner . '/' . $repo ) ) {
			return new WP_Error( 'h2wp_invalid_repository', __( 'A valid GitHub repository is required.', 'hub2wp' ) );
		}
		$subdirectory = H2WP_Settings::normalize_subdirectory( $subdirectory );
		if ( is_wp_error( $subdirectory ) ) {
			return $subdirectory;
		}
		if ( '' !== $subdirectory && '' === $this->access_token ) {
			return new WP_Error(
				'h2wp_monorepo_token_required',
				__( 'Monorepo support requires a saved GitHub access token. Add one in hub2wp Settings and try again.', 'hub2wp' )
			);
		}

		return $this->api->get_changelog( $owner, $repo, $subdirectory );
	}

	/**
	 * Render changelog HTML for admin AJAX responses.
	 *
	 * @param array<int, array<string, mixed>> $changelog Changelog items.
	 * @return string
	 */
	public function render_changelog_html( $changelog ) {
		$changelog_html = '<ul class="h2wp-changelog">';
		foreach ( $changelog as $release ) {
			$changelog_html .= '<li>';
			$changelog_html .= '<h4>' . esc_html( $release['version'] ) . ( ! empty( $release['title'] ) ? ' (' . esc_html( $release['title'] ) . ')' : '' ) . '</h4>';
			$changelog_html .= '<p><strong>' . __( 'Released:', 'hub2wp' ) . '</strong> ' . date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $release['date'] ) ) . '</p>';
			$changelog_html .= '<p>' . nl2br( esc_html( $release['description'] ) ) . '</p>';
			$changelog_html .= '<p><a href="' . esc_url( $release['url'] ) . '" target="_blank">' . __( 'View on GitHub', 'hub2wp' ) . '</a></p>';
			$changelog_html .= '</li>';
		}
		$changelog_html .= '</ul>';

		return $changelog_html;
	}

	/**
	 * Normalize repository type.
	 *
	 * @param string $repo_type Repository type.
	 * @return string
	 */
	private function normalize_repo_type( $repo_type ) {
		return in_array( $repo_type, array( 'plugin', 'theme' ), true ) ? $repo_type : 'plugin';
	}

	/**
	 * Format the display name for a repository.
	 *
	 * @param string $name Repository name.
	 * @return string
	 */
	private function format_display_name( $name ) {
		return ucwords( str_replace( array( '-', 'wp', 'wordpress', 'seo' ), array( ' ', 'WP', 'WordPress', 'SEO' ), $name ) );
	}

	/**
	 * Calculate the last updated string.
	 *
	 * @param string $owner Repository owner.
	 * @param string $repo Repository name.
	 * @param string $repo_type Repository type.
	 * @param array  $tracking Tracking preferences.
	 * @param array  $repo_details Repository details.
	 * @param array  $source_context Source context.
	 * @return string
	 */
	private function calculate_last_updated( $owner, $repo, $repo_type, $tracking, $repo_details, $source_context ) {
		$last_updated = '';

		if ( isset( $repo_details['pushed_at'] ) ) {
			$last_updated = sprintf(
				/* translators: %s: human-readable time difference */
				__( '%s ago', 'hub2wp' ),
				human_time_diff( strtotime( $repo_details['pushed_at'] ) )
			);
		}

		if ( ! empty( $source_context['uses_releases'] ) && ! empty( $source_context['release_published_at'] ) ) {
			return sprintf(
				/* translators: %s: human-readable time difference */
				__( '%s ago', 'hub2wp' ),
				human_time_diff( strtotime( $source_context['release_published_at'] ) )
			);
		}

		$branch_for_updated_at = ! empty( $tracking['branch'] ) ? $tracking['branch'] : ( isset( $repo_details['default_branch'] ) ? $repo_details['default_branch'] : '' );
		if ( ! empty( $branch_for_updated_at ) ) {
			$branch_details = $this->api->get_branch_details( $owner, $repo, $branch_for_updated_at );
			if ( ! is_wp_error( $branch_details ) && isset( $branch_details['commit']['commit']['author']['date'] ) ) {
				$last_updated = sprintf(
					/* translators: %s: human-readable time difference */
					__( '%s ago', 'hub2wp' ),
					human_time_diff( strtotime( $branch_details['commit']['commit']['author']['date'] ) )
				);
			}
		}

		return $last_updated;
	}

	/**
	 * Strip repeated plugin headers from README HTML.
	 *
	 * @param string $readme Readme HTML.
	 * @return string
	 */
	private function strip_plugin_headers( $readme ) {
		$skip = array(
			'contributors',
			'donate link',
			'tags',
			'requires at least',
			'tested up to',
			'stable tag',
			'requires php',
			'license',
			'license uri',
		);

		$lines          = explode( "\n", $readme );
		$filtered_lines = array();
		$header_section = true;

		foreach ( $lines as $index => $line ) {
			if ( $index >= 40 ) {
				$header_section = false;
			}

			if ( $header_section ) {
				$skip_line = false;
				foreach ( $skip as $header ) {
					if ( stripos( $line, $header . ':' ) === 0 ) {
						$skip_line = true;
						break;
					}
				}
				if ( $skip_line ) {
					continue;
				}
			}

			$filtered_lines[] = $line;
		}

		return implode( "\n", $filtered_lines );
	}

	/**
	 * Extract filtered topics.
	 *
	 * @param array  $topics Topic names.
	 * @param string $repo_type Repository type.
	 * @return array<int, array<string, string>>
	 */
	private function extract_topics( $topics, $repo_type = 'plugin' ) {
		$topics   = is_array( $topics ) ? $topics : array();
		$base_url = ( 'theme' === $repo_type )
			? admin_url( 'themes.php?page=h2wp-theme-browser' )
			: admin_url( 'plugins.php?page=h2wp-plugin-browser' );

		return array_values(
			array_filter(
				array_map(
					function ( $topic ) use ( $repo_type, $base_url ) {
						$skip = ( 'theme' === $repo_type )
							? array( 'wordpress-theme', 'wordpress-themes', 'wordpress', 'theme', 'wp-theme', 'wp' )
							: array( 'wordpress-plugin', 'wordpress-plugins', 'wordpress', 'plugin', 'wp-plugin', 'wp' );
						if ( in_array( strtolower( $topic ), $skip, true ) ) {
							return null;
						}

						return array(
							'name' => $topic,
							'url'  => add_query_arg( 'tag', $topic, $base_url ),
						);
					},
					$topics
				)
			)
		);
	}
}
