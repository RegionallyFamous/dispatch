<?php
/**
 * Version pinning — lock a project at a specific build to prevent updates.
 *
 * @package Dispatch_For_Telex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages per-project version pins stored in wp_options.
 *
 * A pin record: { version: int, reason: string, pinned_by: int, pinned_at: string }
 * Pinned projects are excluded from "Update All" and auto-updates.
 */
class Telex_Version_Pin {

	private const OPTION_PREFIX = 'telex_pin_';

	/**
	 * Returns the option key for a given project.
	 *
	 * @param string $public_id Project public ID.
	 * @return string
	 */
	private static function key( string $public_id ): string {
		return self::OPTION_PREFIX . sanitize_key( $public_id );
	}

	/**
	 * Pins a project at a specific version.
	 *
	 * @param string $public_id Project public ID.
	 * @param int    $version   Build/version number to pin at.
	 * @param string $reason    Human-readable reason required for team context.
	 * @return void
	 */
	public static function pin( string $public_id, int $version, string $reason ): void {
		update_option(
			self::key( $public_id ),
			[
				'version'   => $version,
				'reason'    => sanitize_text_field( $reason ),
				'pinned_by' => get_current_user_id(),
				'pinned_at' => gmdate( 'c' ),
			],
			false
		);
	}

	/**
	 * Removes the version pin for a project.
	 *
	 * @param string $public_id Project public ID.
	 * @return void
	 */
	public static function unpin( string $public_id ): void {
		delete_option( self::key( $public_id ) );
	}

	/**
	 * Returns whether a project is currently pinned.
	 *
	 * @param string $public_id Project public ID.
	 * @return bool
	 */
	public static function is_pinned( string $public_id ): bool {
		return false !== get_option( self::key( $public_id ) );
	}

	/**
	 * Returns the pin record for a project, or null if unpinned.
	 *
	 * @param string $public_id Project public ID.
	 * @return array{version: int, reason: string, pinned_by: int, pinned_at: string}|null
	 */
	public static function get( string $public_id ): ?array {
		$data = get_option( self::key( $public_id ), null );
		if ( ! is_array( $data ) ) {
			return null;
		}

		return [
			'version'   => (int) ( $data['version'] ?? 0 ),
			'reason'    => (string) ( $data['reason'] ?? '' ),
			'pinned_by' => (int) ( $data['pinned_by'] ?? 0 ),
			'pinned_at' => (string) ( $data['pinned_at'] ?? '' ),
		];
	}
}
