<?php
/**
 * Build snapshots — capture and restore the full set of installed project versions.
 *
 * @package Dispatch_For_Telex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages build snapshots stored in wp_options.
 *
 * A snapshot records the public ID and installed version of every project on
 * the site at a point in time. Restoring a snapshot reinstalls each project
 * via Telex_Installer, returning to that known-good baseline.
 *
 * Storage: 'telex_snapshots' option →
 *   array<string, array{id: string, name: string, created_at: string, projects: list<array{publicId: string, version: int, slug: string}>}>
 */
class Telex_Snapshot {

	private const OPTION_KEY = 'telex_snapshots';

	/**
	 * Returns all stored snapshots, ordered newest-first.
	 *
	 * @return array<int, array{id: string, name: string, created_at: string, projects: list<array{publicId: string, version: int, slug: string}>}>
	 */
	public static function get_all(): array {
		$raw = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $raw ) ) {
			return [];
		}

		// Sort newest-first.
		usort( $raw, static fn( $a, $b ) => strcmp( (string) ( $b['created_at'] ?? '' ), (string) ( $a['created_at'] ?? '' ) ) );

		return array_values( $raw );
	}

	/**
	 * Returns a single snapshot by ID, or null if not found.
	 *
	 * @param string $id Snapshot UUID.
	 * @return array{id: string, name: string, created_at: string, projects: list<array{publicId: string, version: int, slug: string}>}|null
	 */
	public static function get( string $id ): ?array {
		foreach ( self::get_all() as $s ) {
			if ( $s['id'] === $id ) {
				return $s;
			}
		}
		return null;
	}

	/**
	 * Creates a new snapshot and returns its UUID.
	 *
	 * @param string                                                    $name     Human-readable label.
	 * @param list<array{publicId: string, version: int, slug: string}> $projects Captured project list.
	 * @return string UUID of the new snapshot.
	 */
	public static function create( string $name, array $projects ): string {
		$all = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $all ) ) {
			$all = [];
		}

		$id    = wp_generate_uuid4();
		$all[] = [
			'id'         => $id,
			'name'       => sanitize_text_field( $name ),
			'created_at' => gmdate( 'c' ),
			'projects'   => array_map(
				static fn( array $p ) => [
					'publicId' => sanitize_text_field( (string) ( $p['publicId'] ?? '' ) ),
					'version'  => (int) ( $p['version'] ?? 0 ),
					'slug'     => sanitize_text_field( (string) ( $p['slug'] ?? '' ) ),
				],
				$projects
			),
		];

		update_option( self::OPTION_KEY, $all, false );

		return $id;
	}

	/**
	 * Deletes a snapshot by ID. Returns true on success, false if not found.
	 *
	 * @param string $id Snapshot UUID.
	 * @return bool
	 */
	public static function delete( string $id ): bool {
		$all     = get_option( self::OPTION_KEY, [] );
		$initial = count( $all );

		if ( ! is_array( $all ) ) {
			return false;
		}

		$all = array_values( array_filter( $all, static fn( $s ) => $s['id'] !== $id ) );

		if ( count( $all ) === $initial ) {
			return false;
		}

		update_option( self::OPTION_KEY, $all, false );

		return true;
	}
}
