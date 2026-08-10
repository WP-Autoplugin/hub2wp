<?php
/**
 * Lightweight regression tests for monorepo release selection.
 *
 * Run with: php tests/github-release-test.php
 *
 * @package hub2wp
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code, $message ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

class H2WP_Cache {
	public static $values = array();

	public static function get( $key ) {
		return array_key_exists( $key, self::$values ) ? self::$values[ $key ] : false;
	}

	public static function set( $key, $value ) {
		self::$values[ $key ] = $value;
	}
}

function __( $text ) {
	return $text;
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function esc_url_raw( $value ) {
	return (string) $value;
}

function wp_kses_post( $value ) {
	return (string) $value;
}

function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, $args );
}

function apply_filters( $hook, $value ) {
	return $value;
}

function add_query_arg( $key, $value = null, $url = null ) {
	if ( is_array( $key ) ) {
		$args = $key;
		$url  = $value;
	} else {
		$args = array( $key => $value );
	}
	$separator = false === strpos( $url, '?' ) ? '?' : '&';
	return $url . $separator . http_build_query( $args );
}

function wp_remote_request( $url, $args ) {
	global $h2wp_test_releases;
	return array(
		'response' => array( 'code' => 200 ),
		'headers'  => array(),
		'body'     => wp_json_encode( $h2wp_test_releases ),
	);
}

function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? $response['response']['code'] : 0;
}

function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? $response['body'] : '';
}

function wp_remote_retrieve_headers( $response ) {
	return isset( $response['headers'] ) ? $response['headers'] : array();
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function delete_transient() {}

require_once dirname( __DIR__ ) . '/includes/class-h2wp-github-api.php';

function h2wp_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

$legacy_release = array(
	'tag_name'     => 'sample/v9.0.0',
	'draft'        => false,
	'prerelease'   => false,
	'zipball_url'  => 'https://api.github.com/repos/acme/mono/zipball/sample/v9.0.0',
	'published_at' => '2026-08-10T10:00:00Z',
	'assets'       => array(),
);
$path_release   = array(
	'tag_name'     => 'packages/sample/v2.0.0',
	'draft'        => false,
	'prerelease'   => false,
	'zipball_url'  => 'https://api.github.com/repos/acme/mono/zipball/packages/sample/v2.0.0',
	'published_at' => '2026-08-09T10:00:00Z',
	'assets'       => array(
		array(
			'name'                 => 'sample.zip',
			'content_type'         => 'application/zip',
			'url'                  => 'https://api.github.com/repos/acme/mono/releases/assets/22',
			'browser_download_url' => 'https://github.com/acme/mono/releases/download/packages/sample/v2.0.0/sample.zip',
		),
	),
);
$h2wp_test_releases = array( $legacy_release, $path_release );

$public_api = new H2WP_GitHub_API();
$release    = $public_api->get_monorepo_release_details( 'acme', 'mono', 'packages/sample' );
h2wp_assert_same( 'packages/sample/v2.0.0', $release['tag_name'], 'The complete path tag must take priority over the legacy basename tag.' );
h2wp_assert_same( $path_release['assets'][0]['browser_download_url'], $release['download_url'], 'Public release assets must use their browser download URL.' );
h2wp_assert_same( 'extension', $release['package_scope'], 'A release asset should be marked as an extension-scoped package.' );

$authenticated_api = new H2WP_GitHub_API( 'test-token' );
$release           = $authenticated_api->get_monorepo_release_details( 'acme', 'mono', 'packages/sample' );
h2wp_assert_same( $path_release['assets'][0]['url'], $release['download_url'], 'Authenticated release assets must use the GitHub API asset URL.' );

H2WP_Cache::$values = array();
$changelog          = $public_api->get_changelog( 'acme', 'mono', 'packages/sample' );
h2wp_assert_same( 1, count( $changelog ), 'A project changelog must exclude releases for other monorepo projects.' );
h2wp_assert_same( '2.0.0', $changelog[0]['version'], 'A project changelog must remove the complete project tag prefix.' );

fwrite( STDOUT, "github release tests passed\n" );
