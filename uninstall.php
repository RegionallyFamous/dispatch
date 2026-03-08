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
	$telex_sites = get_sites(
		[
			'number'  => 0, // 0 = no limit.
			'fields'  => 'ids',
			'deleted' => 0,
		]
	);

	$telex_per_site_options = [
		'telex_installed_projects',
		'telex_installed_at',
		'telex_version',
	];

	foreach ( $telex_sites as $telex_blog_id ) {
		switch_to_blog( (int) $telex_blog_id );

		foreach ( $telex_per_site_options as $telex_opt ) {
			delete_option( $telex_opt );
		}

		// Remove telex_* transients for this subsite.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
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

		// Drop the audit log table for this specific subsite.
		// Each subsite has its own prefixed table (e.g. wp_2_telex_audit_log).
		$telex_site_audit_table = $wpdb->get_blog_prefix( (int) $telex_blog_id ) . 'telex_audit_log';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . esc_sql( $telex_site_audit_table ) );

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
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
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

// Drop audit log table for single-site installs.
// On multisite, the table was already dropped per-subsite in the loop above.
if ( ! is_multisite() ) {
	$telex_audit_table = $wpdb->prefix . 'telex_audit_log';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( 'DROP TABLE IF EXISTS ' . esc_sql( $telex_audit_table ) );
}

// Remove the fatal error log directory if present.
$telex_log_dir = WP_CONTENT_DIR . '/telex-logs';
if ( is_dir( $telex_log_dir ) ) {
	wp_delete_file( $telex_log_dir . '/telex-fatal.log' );
	wp_delete_file( $telex_log_dir . '/telex-fatal.log.1' );
	wp_delete_file( $telex_log_dir . '/telex-fatal.log.2' );
	wp_delete_file( $telex_log_dir . '/telex-fatal.log.3' );
	wp_delete_file( $telex_log_dir . '/.htaccess' );
	wp_delete_file( $telex_log_dir . '/index.php' );
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	@rmdir( $telex_log_dir );
}
