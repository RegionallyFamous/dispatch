<?php
/**
 * PHPUnit bootstrap for the Telex plugin test suite.
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';
$_wp_dir    = getenv( 'WP_CORE_DIR' ) ?: '/tmp/wordpress';

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find $_tests_dir/includes/functions.php\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	echo "Run: bash bin/install-wp-tests.sh wordpress_test root '' localhost latest\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	exit( 1 );
}

// Load the plugin before WP loads.
$GLOBALS['wp_tests_options'] = [
	'active_plugins' => [ 'telex/telex.php' ],
];

tests_add_filter( 'muplugins_loaded', static function (): void {
	require dirname( __DIR__ ) . '/telex.php';
} );

require $_tests_dir . '/includes/bootstrap.php';
