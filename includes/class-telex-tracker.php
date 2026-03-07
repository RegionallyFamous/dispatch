<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks locally installed Telex projects in wp_options.
 *
 * Schema per entry:
 *   version      int     Remote version number at time of install/update.
 *   type         string  ProjectType enum value ('block' or 'theme').
 *   slug         string  WordPress plugin/theme directory slug.
 *   installed_at string  ISO-8601 UTC timestamp of first install.
 *   updated_at   string  ISO-8601 UTC timestamp of most recent install/update.
 */
class Telex_Tracker {

	private const OPTION_KEY  = 'telex_installed_projects';
	private const CACHE_GROUP = 'telex_tracker';
	private const CACHE_KEY   = 'all';

	// -------------------------------------------------------------------------
	// Read
	// -------------------------------------------------------------------------

	/**
	 * @return array<string, array{version: int, type: string, slug: string, installed_at: string, updated_at: string}>
	 */
	public static function get_all(): array {
		$cached = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$data    = get_option( self::OPTION_KEY, '{}' );
		$decoded = json_decode( (string) $data, true );
		$result  = is_array( $decoded ) ? $decoded : [];

		wp_cache_set( self::CACHE_KEY, $result, self::CACHE_GROUP );

		return $result;
	}

	/**
	 * @return array{version: int, type: string, slug: string, installed_at: string, updated_at: string}|null
	 */
	public static function get( string $public_id ): ?array {
		$all = self::get_all();
		return $all[ $public_id ] ?? null;
	}

	public static function is_installed( string $public_id ): bool {
		return null !== self::get( $public_id );
	}

	public static function needs_update( string $public_id, int $remote_version ): bool {
		$info = self::get( $public_id );
		return null !== $info && $remote_version > $info['version'];
	}

	// -------------------------------------------------------------------------
	// Write
	// -------------------------------------------------------------------------

	public static function track( string $public_id, int $version, string $type, string $slug ): void {
		$all      = self::get_all();
		$now      = gmdate( 'c' );
		$existing = $all[ $public_id ] ?? null;

		$all[ $public_id ] = [
			'version'      => $version,
			'type'         => $type,
			'slug'         => $slug,
			'installed_at' => $existing['installed_at'] ?? $now,
			'updated_at'   => $now,
		];

		self::save( $all );
	}

	public static function untrack( string $public_id ): void {
		$all = self::get_all();
		unset( $all[ $public_id ] );
		self::save( $all );
	}

	// -------------------------------------------------------------------------
	// Reconciliation — remove entries whose files no longer exist on disk
	// -------------------------------------------------------------------------

	/**
	 * Removes stale tracker entries where the plugin/theme directory is gone.
	 * Safe to call on `admin_init` or from WP-CLI.
	 */
	public static function reconcile(): void {
		$all     = self::get_all();
		$changed = false;

		foreach ( $all as $public_id => $info ) {
			$type = ProjectType::from( $info['type'] );
			$slug = $info['slug'];

			$exists = match ( $type ) {
				ProjectType::Block => is_dir( WP_PLUGIN_DIR . '/' . $slug ),
				ProjectType::Theme => is_dir( get_theme_root() . '/' . $slug ),
			};

			if ( ! $exists ) {
				unset( $all[ $public_id ] );
				$changed = true;
			}
		}

		if ( $changed ) {
			self::save( $all );
		}
	}

	// -------------------------------------------------------------------------
	// Internal
	// -------------------------------------------------------------------------

	/** @param array<string, mixed> $data */
	private static function save( array $data ): void {
		update_option( self::OPTION_KEY, wp_json_encode( $data ), false );
		wp_cache_delete( self::CACHE_KEY, self::CACHE_GROUP );
	}
}
