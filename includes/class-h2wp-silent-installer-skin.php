<?php
/**
 * Handles silent plugin installation and activation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class H2WP_Silent_Installer_Skin extends WP_Upgrader_Skin {
	public function header() {}
	public function footer() {}
	public function error( $errors ) {}
	public function feedback( $string, ...$args ) {}
	public function before() {}
	public function after() {}
}
