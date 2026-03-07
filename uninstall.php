<?php
/**
 * Uninstall — runs when the plugin is deleted via the WordPress admin.
 * Does NOT run on deactivation.
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
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_telex_%'
	    OR option_name LIKE '_transient_timeout_telex_%'"
);

// Remove WP-Cron events.
wp_clear_scheduled_hook( 'telex_cache_warm' );

// Drop audit log table.
$table = $wpdb->prefix . 'telex_audit_log';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

// Remove the fatal error log if present.
$log_file = WP_CONTENT_DIR . '/telex-fatal.log';
if ( file_exists( $log_file ) ) {
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	@unlink( $log_file );
}
