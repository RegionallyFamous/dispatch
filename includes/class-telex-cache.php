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
	 * @param string $public_id The Telex project public ID.
	 * @return void
	 */
	public static function bust_project( string $public_id ): void {
		delete_transient( self::TRANSIENT_PROJECT . md5( $public_id ) );
		self::bust_all();
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
	 * protected by a short lock transient to prevent stampedes.
	 *
	 * Uses add_transient() instead of get_transient() + set_transient() so that
	 * the lock acquisition is atomic on object-cache backends (Redis/Memcached).
	 * add_transient returns false if the key already exists, ensuring only one
	 * background refresh is queued at a time.
	 */
	public static function schedule_background_refresh(): void {
		// add_transient() is atomic: it only sets if the key does not exist.
		// On a database transient backend it is still a single INSERT … IGNORE.
		if ( ! add_transient( self::TRANSIENT_REFRESH_LOCK, 1, 30 ) ) {
			return; // A refresh is already scheduled/in-progress.
		}

		wp_schedule_single_event( time(), self::CRON_HOOK );
	}

	/**
	 * Cron callback: refresh the project list in the background.
	 */
	public static function warm(): void {
		delete_transient( self::TRANSIENT_REFRESH_LOCK );

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
