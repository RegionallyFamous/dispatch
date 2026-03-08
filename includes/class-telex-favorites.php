<?php
/**
 * Per-user project favorites for the Dispatch plugin.
 *
 * Stars are stored in user_meta as a JSON-encoded array of public IDs.
 * They are per-user (not site-wide) and have no effect on install state.
 *
 * @package Dispatch_For_Telex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages per-user starred/favorite projects.
 */
final class Telex_Favorites {

	private const META_KEY = 'telex_favorites';

	/**
	 * Return the list of starred public IDs for the current user.
	 *
	 * @param int $user_id WordPress user ID (0 = current user).
	 * @return string[]
	 */
	public static function get_for_user( int $user_id = 0 ): array {
		$uid  = $user_id > 0 ? $user_id : get_current_user_id();
		$raw  = get_user_meta( $uid, self::META_KEY, true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $data ) ? $data : [];
	}

	/**
	 * Return whether a project is starred for the current user.
	 *
	 * @param string $public_id Project public ID.
	 * @param int    $user_id   WordPress user ID (0 = current user).
	 * @return bool
	 */
	public static function is_starred( string $public_id, int $user_id = 0 ): bool {
		return in_array( $public_id, self::get_for_user( $user_id ), true );
	}

	/**
	 * Star a project for the current user.
	 *
	 * @param string $public_id Project public ID.
	 * @param int    $user_id   WordPress user ID (0 = current user).
	 * @return bool True on change, false if already starred.
	 */
	public static function add( string $public_id, int $user_id = 0 ): bool {
		$uid      = $user_id > 0 ? $user_id : get_current_user_id();
		$existing = self::get_for_user( $uid );
		if ( in_array( $public_id, $existing, true ) ) {
			return false;
		}
		$existing[] = $public_id;
		update_user_meta( $uid, self::META_KEY, wp_json_encode( $existing ) );
		return true;
	}

	/**
	 * Un-star a project for the current user.
	 *
	 * @param string $public_id Project public ID.
	 * @param int    $user_id   WordPress user ID (0 = current user).
	 * @return bool True on change, false if not starred.
	 */
	public static function remove( string $public_id, int $user_id = 0 ): bool {
		$uid      = $user_id > 0 ? $user_id : get_current_user_id();
		$existing = self::get_for_user( $uid );
		$filtered = array_values( array_filter( $existing, fn( $id ) => $id !== $public_id ) );
		if ( count( $filtered ) === count( $existing ) ) {
			return false;
		}
		update_user_meta( $uid, self::META_KEY, wp_json_encode( $filtered ) );
		return true;
	}
}
