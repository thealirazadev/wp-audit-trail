<?php
/**
 * PHPUnit bootstrap.
 *
 * Requires the WordPress core test library: point `WP_TESTS_DIR` at a checkout of it (see
 * docs/testing.md for the provisioning commands) or install it at /tmp/wordpress-tests-lib.
 *
 * @package WP_Audit_Trail
 */

$wpat_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $wpat_tests_dir ) {
	$wpat_tests_dir = '/tmp/wordpress-tests-lib';
}

if ( ! file_exists( $wpat_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Could not find the WordPress test library at {$wpat_tests_dir}. See docs/testing.md.\n" );
	exit( 1 );
}

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
}

require_once $wpat_tests_dir . '/includes/functions.php';

/**
 * Loads the plugin into the test WordPress install.
 *
 * @return void
 */
function wpat_manually_load_plugin() {
	require dirname( __DIR__ ) . '/wp-audit-trail.php';
}
tests_add_filter( 'muplugins_loaded', 'wpat_manually_load_plugin' );

require $wpat_tests_dir . '/includes/bootstrap.php';
