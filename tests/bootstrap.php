<?php
/**
 * PHPUnit bootstrap for the Telex plugin test suite.
 *
 * @package Dispatch_For_Telex
 */

$telex_tests_dir = getenv( 'WP_TESTS_DIR' ) !== false ? getenv( 'WP_TESTS_DIR' ) : '/tmp/wordpress-tests-lib';

// PHPUnit Polyfills are required by the WP test bootstrap.
// Prefer an explicit env/constant override; fall back to the Composer-installed copy.
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	$_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
	define(
		'WP_TESTS_PHPUNIT_POLYFILLS_PATH',
		false !== $_polyfills_path
			? $_polyfills_path
			: dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills'
	);
}

if ( ! file_exists( $telex_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find {$telex_tests_dir}/includes/functions.php\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	echo "Run: bash bin/install-wp-tests.sh wordpress_test root '' localhost latest\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	exit( 1 );
}

// Load WP test helpers (defines tests_add_filter, etc.) before using them.
require $telex_tests_dir . '/includes/functions.php';

// Register the plugin for loading during the muplugins_loaded action.
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/telex.php';
	}
);

// Boot the WordPress test environment.
require $telex_tests_dir . '/includes/bootstrap.php';
