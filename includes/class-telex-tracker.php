<?php
/**
 * Local project tracker — records installed Telex projects in wp_options.
 *
 * @package Dispatch_For_Telex
 */

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
	 * Returns all tracked projects keyed by public ID.
	 *
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
	 * Returns a single tracked project entry, or null if not found.
	 *
	 * @param string $public_id The Telex project public ID.
	 * @return array{version: int, type: string, slug: string, installed_at: string, updated_at: string}|null
	 */
	public static function get( string $public_id ): ?array {
		$all = self::get_all();
		return $all[ $public_id ] ?? null;
	}

	/**
	 * Returns true if the project is currently installed on this site.
	 *
	 * @param string $public_id The Telex project public ID.
	 * @return bool
	 */
	public static function is_installed( string $public_id ): bool {
		return null !== self::get( $public_id );
	}

	/**
	 * Returns true if the remote version is newer than the installed version.
	 *
	 * @param string $public_id      The Telex project public ID.
	 * @param int    $remote_version The latest version number from the API.
	 * @return bool
	 */
	public static function needs_update( string $public_id, int $remote_version ): bool {
		$info = self::get( $public_id );
		return null !== $info && $remote_version > $info['version'];
	}

	/**
	 * Returns the tracker entry for a project with the given WordPress slug,
	 * or null if no such project is tracked.
	 *
	 * Used when intercepting native WordPress upgrades to identify whether
	 * a plugin/theme is managed by Dispatch.
	 *
	 * @param string $slug WordPress plugin directory name or theme slug.
	 * @return array{version: int, type: string, slug: string, installed_at: string, updated_at: string}|null
	 */
	public static function get_by_slug( string $slug ): ?array {
		return self::get_all_by_slug()[ $slug ] ?? null;
	}

	/**
	 * Returns all tracked projects keyed by their WordPress slug.
	 *
	 * Builds the map in a single pass over get_all() so callers that need to
	 * look up multiple projects by slug pay only O(N) once rather than O(N²).
	 *
	 * @return array<string, array{version: int, type: string, slug: string, installed_at: string, updated_at: string}>
	 */
	public static function get_all_by_slug(): array {
		$map = [];
		foreach ( self::get_all() as $entry ) {
			$s = $entry['slug'] ?? '';
			if ( '' !== $s ) {
				$map[ $s ] = $entry;
			}
		}
		return $map;
	}

	// -------------------------------------------------------------------------
	// Write
	// -------------------------------------------------------------------------

	/**
	 * Records or updates a tracked project entry.
	 *
	 * @param string $public_id The Telex project public ID.
	 * @param int    $version   The installed version number.
	 * @param string $type      The project type ('block' or 'theme').
	 * @param string $slug      The WordPress plugin/theme directory slug.
	 * @return void
	 */
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

	/**
	 * Removes a project from the tracker.
	 *
	 * @param string $public_id The Telex project public ID.
	 * @return void
	 */
	public static function untrack( string $public_id ): void {
		$all = self::get_all();
		unset( $all[ $public_id ] );
		self::save( $all );
	}

	// -------------------------------------------------------------------------
	// Reconciliation — remove entries whose files no longer exist on disk
	// -------------------------------------------------------------------------

	/** Transient key used to rate-limit reconcile() to once per minute. */
	private const TRANSIENT_RECONCILE_LOCK = 'telex_reconcile_lock';

	/**
	 * Removes stale tracker entries where the plugin/theme directory is gone.
	 * Safe to call on `admin_init` or from WP-CLI.
	 *
	 * Rate-limited to once per minute via a short-lived transient so that
	 * rapid REST requests (e.g. React hot-reload) don't issue N filesystem
	 * stat calls for every admin page view.
	 */
	public static function reconcile(): void {
		if ( get_transient( self::TRANSIENT_RECONCILE_LOCK ) ) {
			return;
		}
		set_transient( self::TRANSIENT_RECONCILE_LOCK, 1, MINUTE_IN_SECONDS );

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

	/**
	 * Persists the tracker data to wp_options and busts the object cache.
	 *
	 * @param array<string, mixed> $data Tracker data keyed by public ID.
	 * @return void
	 */
	private static function save( array $data ): void {
		$encoded = wp_json_encode( $data );
		update_option( self::OPTION_KEY, false !== $encoded ? $encoded : '{}', false );
		wp_cache_delete( self::CACHE_KEY, self::CACHE_GROUP );
	}
}
