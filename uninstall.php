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

// Remove options.
delete_option( 'telex_auth_token' );
delete_option( 'telex_installed_projects' );
delete_option( 'telex_installed_at' );
delete_option( 'telex_version' );

// Multisite network options.
if ( is_multisite() ) {
	delete_site_option( 'telex_auth_token' );
}

// Remove all telex_* transients.
global $wpdb;
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE %s
		    OR option_name LIKE %s",
		'_transient_telex_%',
		'_transient_timeout_telex_%'
	)
);

// Remove WP-Cron events.
wp_clear_scheduled_hook( 'telex_cache_warm' );

// Drop audit log table.
// Table name is built entirely from $wpdb->prefix — never user input.
$telex_audit_table = $wpdb->prefix . 'telex_audit_log';
$wpdb->query( 'DROP TABLE IF EXISTS ' . esc_sql( $telex_audit_table ) );

// Remove the fatal error log if present.
$telex_log_file = WP_CONTENT_DIR . '/telex-fatal.log';
if ( file_exists( $telex_log_file ) ) {
	wp_delete_file( $telex_log_file );
}
