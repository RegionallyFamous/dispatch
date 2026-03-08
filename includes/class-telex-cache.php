<?php
/**
 * Transient-based caching layer for Telex API responses.
 *
 * @package Dispatch_For_Telex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Caching layer for Telex API responses.
 *
 * Uses WordPress transients (backed by object-cache if available).
 * A scheduled WP-Cron event keeps the project list warm.
 */
class Telex_Cache {

	private const TRANSIENT_PROJECTS = 'telex_projects_list';
	private const TRANSIENT_PROJECT  = 'telex_project_'; // + md5(publicId)
	/** Stale copy kept longer than the live TTL for serve-stale-on-error. */
	private const TRANSIENT_STALE = 'telex_projects_stale';
	private const TTL_PROJECTS    = 5 * MINUTE_IN_SECONDS;
	private const TTL_PROJECT     = 5 * MINUTE_IN_SECONDS;
	private const TTL_STALE       = 24 * HOUR_IN_SECONDS;
	private const CRON_HOOK       = 'telex_cache_warm';
	private const CRON_RECURRENCE = 'hourly';

	// -------------------------------------------------------------------------
	// Project list
	// -------------------------------------------------------------------------

	/**
	 * Returns the cached project list, or null if the cache has expired.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	public static function get_projects(): ?array {
		$cached = get_transient( self::TRANSIENT_PROJECTS );
		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Stores the project list in the cache with a 1-hour TTL.
	 *
	 * @param array<int, array<string, mixed>> $projects Indexed array of project data.
	 * @return void
	 */
	public static function set_projects( array $projects ): void {
		set_transient( self::TRANSIENT_PROJECTS, $projects, self::TTL_PROJECTS );
		// Keep a longer-lived stale copy for serve-stale-on-error.
		set_transient( self::TRANSIENT_STALE, $projects, self::TTL_STALE );
	}

	/**
	 * Returns a stale copy of the project list (up to 24 h old) for graceful degradation.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	public static function get_projects_stale(): ?array {
		$cached = get_transient( self::TRANSIENT_STALE );
		return is_array( $cached ) ? $cached : null;
	}

	// -------------------------------------------------------------------------
	// Single project
	// -------------------------------------------------------------------------

	/**
	 * Returns a single cached project, or null if not cached.
	 *
	 * @param string $public_id The Telex project public ID.
	 * @return array<string, mixed>|null
	 */
	public static function get_project( string $public_id ): ?array {
		$cached = get_transient( self::TRANSIENT_PROJECT . md5( $public_id ) );
		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Stores a single project in the cache with a 5-minute TTL.
	 *
	 * @param string               $public_id The Telex project public ID.
	 * @param array<string, mixed> $project   Project data array.
	 * @return void
	 */
	public static function set_project( string $public_id, array $project ): void {
		set_transient( self::TRANSIENT_PROJECT . md5( $public_id ), $project, self::TTL_PROJECT );
	}

	// -------------------------------------------------------------------------
	// Cache busting
	// -------------------------------------------------------------------------

	/**
	 * Deletes the cached project list.
	 *
	 * @return void
	 */
	public static function bust_all(): void {
		delete_transient( self::TRANSIENT_PROJECTS );
	}

	/**
	 * Deletes the cached entry for a specific project.
	 *
	 * The project-list cache is intentionally left intact. The UI always calls
	 * force_refresh=1 after an install or removal, which busts the list explicitly.
	 * Busting the list here would cause a redundant cold-cache round-trip.
	 *
	 * @param string $public_id The Telex project public ID.
	 * @return void
	 */
	public static function bust_project( string $public_id ): void {
		delete_transient( self::TRANSIENT_PROJECT . md5( $public_id ) );
	}

	// -------------------------------------------------------------------------
	// WP-Cron warming + stale-while-revalidate
	// -------------------------------------------------------------------------

	/**
	 * Transient key for background-refresh lock (prevents stampede when multiple
	 * requests notice a stale cache at the same moment).
	 */
	private const TRANSIENT_REFRESH_LOCK = 'telex_cache_refresh_lock';

	/**
	 * Registers the cron warmup event and hooks the warm callback.
	 *
	 * @return void
	 */
	public static function schedule_warmup(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::CRON_RECURRENCE, self::CRON_HOOK );
		}
		add_action( self::CRON_HOOK, self::warm( ... ) );
	}

	/**
	 * Removes the cron warmup event.
	 *
	 * @return void
	 */
	public static function unschedule_warmup(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Stale-while-revalidate: if the live cache has expired but a stale copy exists,
	 * schedule an immediate background refresh and return the stale copy.
	 *
	 * Callers use this to avoid blocking a user request on an API call.
	 *
	 * @return array<int, array<string, mixed>>|null  Stale data, or null if no copy at all.
	 */
	public static function get_or_revalidate(): ?array {
		$live = self::get_projects();
		if ( null !== $live ) {
			return $live;
		}

		$stale = self::get_projects_stale();
		if ( null !== $stale ) {
			self::schedule_background_refresh();
		}

		return $stale;
	}

	/**
	 * Schedule an immediate one-off cron event for a background cache refresh,
	 * protected by a short lock to prevent stampedes.
	 *
	 * Uses wp_cache_add() for atomic "set only if absent" semantics.
	 * On Redis/Memcached this is a single atomic ADD; on the default runtime
	 * cache it is effectively atomic within the same PHP process. We also write
	 * a transient as a cross-process fallback so that separate requests don't
	 * both queue a refresh.
	 */
	public static function schedule_background_refresh(): void {
		// wp_cache_add() is atomic on persistent object-cache backends.
		if ( ! wp_cache_add( self::TRANSIENT_REFRESH_LOCK, 1, 'telex', 30 ) ) {
			return; // A refresh is already scheduled/in-progress.
		}

		// Belt-and-suspenders: also set a transient so a second PHP process
		// (where the in-memory cache is empty) won't queue a duplicate refresh.
		set_transient( self::TRANSIENT_REFRESH_LOCK, 1, 30 );

		wp_schedule_single_event( time(), self::CRON_HOOK );
	}

	/**
	 * Cron callback: refresh the project list in the background.
	 */
	public static function warm(): void {
		wp_cache_delete( self::TRANSIENT_REFRESH_LOCK, 'telex' );
		delete_transient( self::TRANSIENT_REFRESH_LOCK );

		// Bail early if a user request already refreshed the cache since the
		// cron event was scheduled — avoids a redundant API round-trip.
		if ( null !== self::get_projects() ) {
			return;
		}

		if ( ! Telex_Auth::is_connected() ) {
			return;
		}

		if ( ! Telex_Circuit_Breaker::is_available() ) {
			return;
		}

		$client = Telex_Auth::get_client();
		if ( ! $client ) {
			return;
		}

		try {
			$response = $client->projects->list( [ 'perPage' => 100 ] );
			$projects = $response['projects'] ?? [];
			// Cache even empty arrays (with a shorter TTL) so the stale-while-
			// revalidate loop terminates instead of scheduling refresh endlessly.
			$ttl = empty( $projects ) ? 5 * MINUTE_IN_SECONDS : self::TTL_PROJECTS;
			set_transient( self::TRANSIENT_PROJECTS, $projects, $ttl );
			set_transient( self::TRANSIENT_STALE, $projects, self::TTL_STALE );
			Telex_Circuit_Breaker::record_success();
		} catch ( \Exception ) {
			Telex_Circuit_Breaker::record_failure();
			// Silent failure — stale copy persists for graceful degradation.
		}
	}
}
