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

		update_option( 'telex_version', TELEX_PLUGIN_VERSION, false );
	}
}
