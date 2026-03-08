<?php
/**
 * Persistent failed-install tracking for the Dispatch plugin.
 *
 * When an install fails, the failure is stored in wp_options so users can see
 * all failures in one place, retry them, and dismiss them.
 *
 * @package Dispatch_For_Telex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the failed-install retry queue.
 */
final class Telex_Failed_Installs {

	private const OPTION_PREFIX = 'telex_failed_';

	/**
	 * Record a failure for a project.
	 *
	 * @param string $public_id    Project public ID.
	 * @param string $project_name Project display name.
	 * @param string $error        Human-readable error message.
	 * @return void
	 */
	public static function record( string $public_id, string $project_name, string $error ): void {
		update_option(
			self::OPTION_PREFIX . sanitize_key( $public_id ),
			[
				'public_id'    => $public_id,
				'project_name' => $project_name,
				'error'        => $error,
				'failed_at'    => gmdate( 'c' ),
			],
			false
		);
	}

	/**
	 * Clear a failure record (after a successful retry or manual dismiss).
	 *
	 * @param string $public_id Project public ID.
	 * @return void
	 */
	public static function clear( string $public_id ): void {
		delete_option( self::OPTION_PREFIX . sanitize_key( $public_id ) );
	}

	/**
	 * Return all persisted failure records.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function get_all(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( self::OPTION_PREFIX ) . '%'
			),
			ARRAY_A
		);

		$out = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$data = maybe_unserialize( $row['option_value'] );
				if ( is_array( $data ) ) {
					$out[] = $data;
				}
			}
		}

		usort( $out, fn( $a, $b ) => strcmp( $b['failed_at'] ?? '', $a['failed_at'] ?? '' ) );
		return $out;
	}

	/**
	 * Check if a specific project has a recorded failure.
	 *
	 * @param string $public_id Project public ID.
	 * @return bool
	 */
	public static function has_failure( string $public_id ): bool {
		return (bool) get_option( self::OPTION_PREFIX . sanitize_key( $public_id ) );
	}
}
