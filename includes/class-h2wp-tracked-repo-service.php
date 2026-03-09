<?php
/**
 * Shared tracked repository read helpers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class H2WP_Tracked_Repo_Service {

	/**
	 * Get tracked plugins.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_tracked_plugins() {
		return $this->get_tracked_items( 'plugin' );
	}

	/**
	 * Get tracked themes.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_tracked_themes() {
		return $this->get_tracked_items( 'theme' );
	}

	/**
	 * Get tracked repositories for a type.
	 *
	 * @param string $repo_type Repository type.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_tracked_items( $repo_type = 'plugin' ) {
		$repo_type = in_array( $repo_type, array( 'plugin', 'theme' ), true ) ? $repo_type : 'plugin';
		$option    = 'theme' === $repo_type ? 'h2wp_themes' : 'h2wp_plugins';
		$tracked   = get_option( $option, array() );
		$items     = array();

		foreach ( $tracked as $repo_key => $repo_data ) {
			if ( ! is_array( $repo_data ) ) {
				continue;
			}

			$items[] = $this->normalize_tracked_item( $repo_key, $repo_data, $repo_type );
		}

		return $items;
	}

	/**
	 * Normalize a tracked repository payload.
	 *
	 * @param string $repo_key Repository key.
	 * @param array  $repo_data Repository data.
	 * @param string $repo_type Repository type.
	 * @return array<string, mixed>
	 */
	public function normalize_tracked_item( $repo_key, $repo_data, $repo_type = 'plugin' ) {
		$repo_type = in_array( $repo_type, array( 'plugin', 'theme' ), true ) ? $repo_type : 'plugin';

		$normalized = array(
			'name'                => isset( $repo_data['name'] ) ? (string) $repo_data['name'] : $repo_key,
			'repo'                => $repo_key,
			'repo_type'           => $repo_type,
			'directory'           => $this->get_local_directory_name( $repo_data, $repo_type ),
			'installed'           => $this->is_tracked_item_installed( $repo_data, $repo_type ),
			'branch'              => isset( $repo_data['branch'] ) ? (string) $repo_data['branch'] : '',
			'prioritize_releases' => ! array_key_exists( 'prioritize_releases', $repo_data ) || ! empty( $repo_data['prioritize_releases'] ),
		);

		if ( 'theme' === $repo_type ) {
			$normalized['stylesheet'] = isset( $repo_data['stylesheet'] ) ? (string) $repo_data['stylesheet'] : '';
		} else {
			$normalized['plugin_file'] = isset( $repo_data['plugin_file'] ) ? (string) $repo_data['plugin_file'] : '';
		}

		return array_merge( $repo_data, $normalized );
	}

	/**
	 * Get local directory name for a tracked item.
	 *
	 * @param array  $repo_data Repository data.
	 * @param string $repo_type Repository type.
	 * @return string
	 */
	public function get_local_directory_name( $repo_data, $repo_type = 'plugin' ) {
		if ( 'theme' === $repo_type ) {
			if ( ! empty( $repo_data['stylesheet'] ) ) {
				return (string) $repo_data['stylesheet'];
			}

			if ( ! empty( $repo_data['directory'] ) ) {
				return (string) $repo_data['directory'];
			}

			return '';
		}

		if ( ! empty( $repo_data['plugin_file'] ) ) {
			return dirname( (string) $repo_data['plugin_file'] );
		}

		if ( ! empty( $repo_data['directory'] ) ) {
			return (string) $repo_data['directory'];
		}

		return '';
	}

	/**
	 * Check whether a tracked item is installed.
	 *
	 * @param array  $repo_data Repository data.
	 * @param string $repo_type Repository type.
	 * @return bool
	 */
	private function is_tracked_item_installed( $repo_data, $repo_type ) {
		if ( 'theme' === $repo_type ) {
			$stylesheet = isset( $repo_data['stylesheet'] ) ? (string) $repo_data['stylesheet'] : '';
			if ( '' === $stylesheet ) {
				return false;
			}

			$themes = wp_get_themes();
			return isset( $themes[ $stylesheet ] );
		}

		$plugin_file = isset( $repo_data['plugin_file'] ) ? (string) $repo_data['plugin_file'] : '';
		if ( '' === $plugin_file ) {
			return false;
		}

		return file_exists( WP_PLUGIN_DIR . '/' . $plugin_file );
	}
}
