<?php
/**
 * Per-project auto-update preferences and scheduled update runner.
 *
 * @package Dispatch_For_Telex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages per-project auto-update settings and the daily update cron.
 *
 * Auto-update modes:
 *   'off'         — no automatic updates (default).
 *   'immediate'   — install as soon as a new build is detected (daily cron).
 *   'delayed_24h' — install 24 hours after a new build is first detected (soak period).
 *
 * Mutual exclusion: a pinned project cannot have auto-updates enabled.
 * Managed at the REST layer — this class does not enforce that constraint.
 */
class Telex_Auto_Update {

	private const OPTION_PREFIX = 'telex_auto_update_';
	private const QUEUED_PREFIX = 'telex_update_queued_';
	public const CRON_HOOK      = 'telex_auto_update';

	/**
	 * Returns the option key for a project's auto-update mode.
	 *
	 * @param string $public_id Project public ID.
	 * @return string
	 */
	private static function mode_key( string $public_id ): string {
		return self::OPTION_PREFIX . sanitize_key( $public_id );
	}

	/**
	 * Returns the transient key for a project's queued-at timestamp (delayed mode).
	 *
	 * @param string $public_id Project public ID.
	 * @return string
	 */
	private static function queued_key( string $public_id ): string {
		// Truncate to stay within the 172-character transient key limit.
		return self::QUEUED_PREFIX . substr( sanitize_key( $public_id ), 0, 140 );
	}

	/**
	 * Persists the auto-update mode for a project.
	 *
	 * @param string $public_id Project public ID.
	 * @param string $mode      'off', 'immediate', or 'delayed_24h'.
	 * @return void
	 */
	public static function set_mode( string $public_id, string $mode ): void {
		$mode = in_array( $mode, [ 'off', 'immediate', 'delayed_24h' ], true ) ? $mode : 'off';

		if ( 'off' === $mode ) {
			delete_option( self::mode_key( $public_id ) );
			delete_transient( self::queued_key( $public_id ) );
		} else {
			update_option( self::mode_key( $public_id ), $mode, false );
		}
	}

	/**
	 * Returns the auto-update mode for a project.
	 *
	 * @param string $public_id Project public ID.
	 * @return string 'off' | 'immediate' | 'delayed_24h'
	 */
	public static function get_mode( string $public_id ): string {
		$mode = (string) get_option( self::mode_key( $public_id ), 'off' );
		return in_array( $mode, [ 'off', 'immediate', 'delayed_24h' ], true ) ? $mode : 'off';
	}

	/**
	 * Returns the timestamp (Unix) when an update was first queued for a project, or null.
	 *
	 * Used by the React UI to show a "Update in Xh" countdown.
	 *
	 * @param string $public_id Project public ID.
	 * @return int|null Unix timestamp, or null if not queued.
	 */
	public static function get_queued_at( string $public_id ): ?int {
		$ts = get_transient( self::queued_key( $public_id ) );
		return false !== $ts ? (int) $ts : null;
	}

	/**
	 * Cron callback: process auto-updates for all installed projects.
	 *
	 * Iterates every installed project, checks for a newer build in the cache,
	 * and installs immediately or waits for the 24-hour soak period.
	 *
	 * @return void
	 */
	public static function run(): void {
		if ( ! Telex_Auth::is_connected() ) {
			return;
		}

		$installed = Telex_Tracker::get_all();
		if ( empty( $installed ) ) {
			return;
		}

		$cached = Telex_Cache::get_projects();
		if ( ! is_array( $cached ) || empty( $cached ) ) {
			return;
		}

		// Build a quick lookup of remote versions from the cache.
		$remote = [];
		foreach ( $cached as $p ) {
			$id = $p['publicId'] ?? '';
			if ( '' !== $id ) {
				$remote[ $id ] = (int) ( $p['currentVersion'] ?? 0 );
			}
		}

		foreach ( $installed as $public_id => $info ) {
			$mode = self::get_mode( $public_id );
			if ( 'off' === $mode ) {
				continue;
			}

			// Skip pinned projects — mutual exclusion is set at the REST layer,
			// but this provides a defence-in-depth guard at the cron layer too.
			if ( Telex_Version_Pin::is_pinned( $public_id ) ) {
				continue;
			}

			$remote_version = $remote[ $public_id ] ?? 0;
			$local_version  = (int) $info['version'];

			if ( $remote_version <= $local_version ) {
				// No update available; clear any stale queued timestamp.
				delete_transient( self::queued_key( $public_id ) );
				continue;
			}

			// Update is available.
			if ( 'immediate' === $mode ) {
				self::install_and_log( $public_id );
				continue;
			}

			// delayed_24h: record the first detection time and install after 24 hours.
			$queued_at = self::get_queued_at( $public_id );
			if ( null === $queued_at ) {
				set_transient( self::queued_key( $public_id ), time(), 2 * DAY_IN_SECONDS );
				continue;
			}

			if ( ( time() - $queued_at ) >= DAY_IN_SECONDS ) {
				self::install_and_log( $public_id );
				delete_transient( self::queued_key( $public_id ) );
			}
		}
	}

	/**
	 * Installs a project and records an audit log entry with initiator=auto.
	 *
	 * @param string $public_id Project public ID.
	 * @return void
	 */
	private static function install_and_log( string $public_id ): void {
		$result = Telex_Installer::install( $public_id );
		if ( ! is_wp_error( $result ) ) {
			Telex_Audit_Log::log( AuditAction::Update, $public_id, [ 'initiator' => 'auto' ] );
			Telex_REST::bump_data_version();
		} else {
			wp_trigger_error( __CLASS__, 'Dispatch auto-update failed for ' . $public_id . ': ' . $result->get_error_message(), E_USER_WARNING );
		}
	}
}
