<?php
/**
 * Uninstall — runs when the plugin is deleted via the WordPress admin.
 * Does NOT run on deactivation.
 *
 * @package Dispatch_For_Telex
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// On multisite, clean every subsite before touching network/main options.
if ( is_multisite() ) {
	$sites = get_sites(
		[
			'number'  => 0, // 0 = no limit.
			'fields'  => 'ids',
			'deleted' => 0,
		]
	);

	$per_site_options = [
		'telex_installed_projects',
		'telex_installed_at',
		'telex_version',
	];

	foreach ( $sites as $telex_blog_id ) {
		switch_to_blog( (int) $telex_blog_id );

		foreach ( $per_site_options as $opt ) {
			delete_option( $opt );
		}

		// Remove telex_* transients for this subsite.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				 WHERE option_name LIKE %s
				    OR option_name LIKE %s",
				'_transient_telex_%',
				'_transient_timeout_telex_%'
			)
		);

		wp_clear_scheduled_hook( 'telex_cache_warm' );

		restore_current_blog();
	}

	// Network-level options.
	delete_site_option( 'telex_auth_token' );
	delete_site_option( 'dispatch_deploy_secret' );
} else {
	// Single-site options.
	delete_option( 'telex_auth_token' );
	delete_option( 'telex_installed_projects' );
	delete_option( 'telex_installed_at' );
	delete_option( 'telex_version' );
	delete_option( 'dispatch_deploy_secret' );

	// Remove all telex_* transients.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE %s
			    OR option_name LIKE %s",
			'_transient_telex_%',
			'_transient_timeout_telex_%'
		)
	);

	wp_clear_scheduled_hook( 'telex_cache_warm' );
}

// Drop audit log table (uses main site prefix).
// Table name is built entirely from $wpdb->prefix — never user input.
$telex_audit_table = $wpdb->prefix . 'telex_audit_log';
$wpdb->query( 'DROP TABLE IF EXISTS ' . esc_sql( $telex_audit_table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

// Remove the fatal error log directory if present.
$telex_log_dir = WP_CONTENT_DIR . '/telex-logs';
if ( is_dir( $telex_log_dir ) ) {
	wp_delete_file( $telex_log_dir . '/telex-fatal.log' );
	wp_delete_file( $telex_log_dir . '/.htaccess' );
	wp_delete_file( $telex_log_dir . '/index.php' );
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	@rmdir( $telex_log_dir );
}
