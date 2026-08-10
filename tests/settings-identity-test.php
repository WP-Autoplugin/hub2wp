<?php
/**
 * Lightweight regression tests for tracked monorepo identity helpers.
 *
 * Run with: php tests/settings-identity-test.php
 *
 * @package hub2wp
 */

define( 'ABSPATH', __DIR__ . '/' );

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

function __( $text ) {
	return $text;
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function get_option( $name, $default = false ) {
	global $h2wp_test_options;
	return isset( $h2wp_test_options[ $name ] ) ? $h2wp_test_options[ $name ] : $default;
}

function apply_filters( $hook, $value ) {
	return $value;
}

require_once dirname( __DIR__ ) . '/includes/class-h2wp-settings.php';

function h2wp_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

function h2wp_assert_error( $actual, $message ) {
	if ( ! is_wp_error( $actual ) ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}

h2wp_assert_same( 'packages/acme-plugin', H2WP_Settings::normalize_subdirectory( '/packages/acme-plugin/' ), 'Subdirectories should be normalized.' );
h2wp_assert_error( H2WP_Settings::normalize_subdirectory( 'packages/../acme-plugin' ), 'Traversal segments must be rejected.' );
h2wp_assert_same( 'owner/repo/packages/acme-plugin', H2WP_Settings::get_tracked_repo_key( 'Owner', 'Repo', 'packages/acme-plugin' ), 'Canonical keys must preserve the complete project path.' );

$identity = H2WP_Settings::get_tracked_repo_identity( 'owner/repo/packages/acme-plugin', array() );
h2wp_assert_same( 'packages/acme-plugin', $identity['subdirectory'], 'Canonical keys should recover missing legacy subdirectory metadata.' );

$identity = H2WP_Settings::get_tracked_repo_identity(
	'hub2wp',
	array(
		'owner' => 'WP-Autoplugin',
		'repo'  => 'hub2wp',
	)
);
h2wp_assert_same( 'wp-autoplugin/hub2wp', $identity['owner'] . '/' . $identity['repo'], 'Special option keys should use their stored repository identity.' );

$legacy = array(
	'owner/repo/acme-plugin' => array(
		'owner'        => 'owner',
		'repo'         => 'repo',
		'subdirectory' => 'packages/acme-plugin',
	),
);
h2wp_assert_same( 'owner/repo/acme-plugin', H2WP_Settings::find_tracked_repo_key( $legacy, 'owner', 'repo', 'packages/acme-plugin' ), 'Legacy basename keys should remain readable when their stored path matches.' );
h2wp_assert_same( '', H2WP_Settings::find_tracked_repo_key( $legacy, 'owner', 'repo', 'other/acme-plugin' ), 'A legacy basename collision must not resolve to the wrong project.' );

$h2wp_test_options = array(
	'h2wp_plugins' => array(
		'owner/repo'                     => array( 'branch' => 'main', 'prioritize_releases' => false ),
		'owner/repo/packages/acme-plugin' => array(
			'owner'               => 'owner',
			'repo'                => 'repo',
			'subdirectory'        => 'packages/acme-plugin',
			'branch'              => 'develop',
			'prioritize_releases' => true,
		),
	),
);

$preferences = H2WP_Settings::get_repo_tracking_preferences( 'owner', 'repo', 'plugin', 'packages/acme-plugin' );
h2wp_assert_same( 'develop', $preferences['branch'], 'Tracking preferences must resolve the exact project.' );

$preferences = H2WP_Settings::get_repo_tracking_preferences( 'owner', 'repo', 'plugin', 'packages/other-plugin' );
h2wp_assert_same( '', $preferences['branch'], 'An untracked project must not inherit root-project preferences.' );
h2wp_assert_same( true, $preferences['prioritize_releases'], 'Untracked projects should use default release preferences.' );

fwrite( STDOUT, "settings identity tests passed\n" );
