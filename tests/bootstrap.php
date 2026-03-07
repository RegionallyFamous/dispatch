<?php
/**
 * PHPUnit bootstrap for the Telex plugin test suite.
 *
 * @package Dispatch_For_Telex
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' ) !== false ? getenv( 'WP_TESTS_DIR' ) : '/tmp/wordpress-tests-lib';

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find $_tests_dir/includes/functions.php\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	echo "Run: bash bin/install-wp-tests.sh wordpress_test root '' localhost latest\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	exit( 1 );
}

// Load WP test helpers (defines tests_add_filter, etc.) before using them.
require $_tests_dir . '/includes/functions.php';

// Register the plugin for loading during the muplugins_loaded action.
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/telex.php';
	}
);

// Boot the WordPress test environment.
require $_tests_dir . '/includes/bootstrap.php';
