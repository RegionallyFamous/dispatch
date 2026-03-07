<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles plugin activation and version-upgrade migrations.
 */
class Telex_Activator {

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
		if ( $stored === TELEX_PLUGIN_VERSION ) {
			return;
		}

		// Ensure audit table exists after manual file copy upgrades.
		Telex_Audit_Log::create_table();

		// Schedule warmup in case it was unregistered.
		Telex_Cache::schedule_warmup();

		update_option( 'telex_version', TELEX_PLUGIN_VERSION, false );
	}
}
