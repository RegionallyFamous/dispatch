<?php
/**
 * Per-project freeform tags for the Dispatch plugin.
 *
 * Tags are short labels (e.g. "client-a", "beta", "core") stored per-project
 * in wp_options. They are site-wide (not per-user) so all admins see the same
 * labels, and they survive user deletion.
 *
 * @package Dispatch_For_Telex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages freeform tags for projects.
 */
final class Telex_Tags {

	private const OPTION_PREFIX = 'telex_tags_';
	private const MAX_TAGS      = 20;
	private const MAX_TAG_LEN   = 32;

	/**
	 * Return the tag list for a project.
	 *
	 * @param string $public_id Project public ID.
	 * @return string[]
	 */
	public static function get( string $public_id ): array {
		$raw  = get_option( self::OPTION_PREFIX . sanitize_key( $public_id ), '' );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $data ) ? $data : [];
	}

	/**
	 * Replace the full tag list for a project.
	 *
	 * @param string   $public_id Project public ID.
	 * @param string[] $tags      New list of tags (sanitized and deduplicated internally).
	 * @return string[] Saved tag list.
	 */
	public static function set( string $public_id, array $tags ): array {
		$sanitized = [];
		foreach ( $tags as $tag ) {
			$clean = sanitize_text_field( (string) $tag );
			$clean = substr( $clean, 0, self::MAX_TAG_LEN );
			if ( '' !== $clean && ! in_array( $clean, $sanitized, true ) ) {
				$sanitized[] = $clean;
			}
			if ( count( $sanitized ) >= self::MAX_TAGS ) {
				break;
			}
		}

		update_option( self::OPTION_PREFIX . sanitize_key( $public_id ), wp_json_encode( $sanitized ), false );
		return $sanitized;
	}

	/**
	 * Return every unique tag in use across all projects.
	 *
	 * Scans the options table; suitable for the filter dropdown and autocomplete.
	 * Result is cached for 5 minutes.
	 *
	 * @return string[]
	 */
	public static function all_in_use(): array {
		$cached = get_transient( 'telex_all_tags' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( self::OPTION_PREFIX ) . '%'
			)
		);

		$all = [];
		foreach ( $rows as $row ) {
			$tags = json_decode( (string) $row, true );
			if ( is_array( $tags ) ) {
				$all = array_merge( $all, $tags );
			}
		}
		$all = array_values( array_unique( $all ) );
		sort( $all );

		set_transient( 'telex_all_tags', $all, 5 * MINUTE_IN_SECONDS );
		return $all;
	}

	/**
	 * Bust the cached "all tags" list. Call after any set().
	 *
	 * @return void
	 */
	public static function bust_cache(): void {
		delete_transient( 'telex_all_tags' );
	}
}
