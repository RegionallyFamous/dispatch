<?php
/**
 * Plugin activation and upgrade migration handler.
 *
 * @package Dispatch_For_Telex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles plugin activation and version-upgrade migrations.
 */
class Telex_Activator {

	/**
	 * Runs on first activation: creates the audit table, records install time,
	 * and schedules the cache warmup cron event.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Telex_Audit_Log::create_table();

		if ( false === get_option( 'telex_installed_at' ) ) {
			update_option( 'telex_installed_at', gmdate( 'c' ), false );
		}

		update_option( 'telex_version', TELEX_PLUGIN_VERSION, false );

		flush_rewrite_rules();

		// Schedule cache warmup.
		Telex_Cache::schedule_warmup();

		// Schedule auto-update cron (daily).
		if ( ! wp_next_scheduled( Telex_Auto_Update::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', Telex_Auto_Update::CRON_HOOK );
		}

		// Schedule block usage analytics scan (weekly).
		if ( ! wp_next_scheduled( Telex_Analytics::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'weekly', Telex_Analytics::CRON_HOOK );
		}
	}

	/**
	 * Runs on deactivation: clears scheduled cron events.
	 *
	 * Data is intentionally left intact — only uninstall.php removes data.
	 * This matches the WordPress convention: deactivate = pause, uninstall = erase.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'telex_cache_warm' );
		wp_clear_scheduled_hook( Telex_Auto_Update::CRON_HOOK );
		wp_clear_scheduled_hook( Telex_Analytics::CRON_HOOK );
	}

	/**
	 * Run on every plugins_loaded to apply migrations when the stored version differs.
	 */
	public static function maybe_upgrade(): void {
		$stored = (string) get_option( 'telex_version', '' );
		if ( TELEX_PLUGIN_VERSION === $stored ) {
			return;
		}

		// Ensure audit table exists after manual file copy upgrades.
		Telex_Audit_Log::create_table();

		// Schedule warmup in case it was unregistered.
		Telex_Cache::schedule_warmup();

		// Register new cron jobs added in later versions.
		if ( ! wp_next_scheduled( Telex_Auto_Update::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', Telex_Auto_Update::CRON_HOOK );
		}
		if ( ! wp_next_scheduled( Telex_Analytics::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'weekly', Telex_Analytics::CRON_HOOK );
		}

		update_option( 'telex_version', TELEX_PLUGIN_VERSION, false );
	}
}
