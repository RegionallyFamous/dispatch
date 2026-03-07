<?php
/**
 * Tests for Telex_Cache — project list caching, single project caching,
 * stale-while-revalidate, and stampede protection.
 *
 * @package Dispatch_For_Telex
 */

/**
 * Unit tests for the Telex_Cache class.
 */
class Test_Telex_Cache extends WP_UnitTestCase {

	/**
	 * Sample projects array used across multiple tests.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $sample_projects;

	/**
	 * Reset all Telex cache transients before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		delete_transient( 'telex_projects_list' );
		delete_transient( 'telex_projects_stale' );
		delete_transient( 'telex_cache_refresh_lock' );
		// Clear any per-project transients too.
		$this->sample_projects = [
			[
				'publicId'       => 'proj-1',
				'name'           => 'Alpha',
				'currentVersion' => 3,
			],
			[
				'publicId'       => 'proj-2',
				'name'           => 'Beta',
				'currentVersion' => 7,
			],
		];
	}

	// -------------------------------------------------------------------------
	// Project list — get / set
	// -------------------------------------------------------------------------

	/**
	 * Asserts get_projects() returns null when no cache has been populated.
	 *
	 * @return void
	 */
	public function test_get_projects_returns_null_when_empty(): void {
		$this->assertNull( Telex_Cache::get_projects() );
	}

	/**
	 * Asserts set_projects() followed by get_projects() returns the original data.
	 *
	 * @return void
	 */
	public function test_set_and_get_projects_round_trips(): void {
		Telex_Cache::set_projects( $this->sample_projects );
		$this->assertSame( $this->sample_projects, Telex_Cache::get_projects() );
	}

	/**
	 * Asserts set_projects() also writes a stale copy.
	 *
	 * @return void
	 */
	public function test_set_projects_also_sets_stale_copy(): void {
		Telex_Cache::set_projects( $this->sample_projects );
		$this->assertSame( $this->sample_projects, Telex_Cache::get_projects_stale() );
	}

	/**
	 * Asserts get_projects_stale() returns null when no cache exists at all.
	 *
	 * @return void
	 */
	public function test_get_projects_stale_returns_null_when_empty(): void {
		$this->assertNull( Telex_Cache::get_projects_stale() );
	}

	/**
	 * Asserts get_projects_stale() can return data when the live cache has expired.
	 *
	 * @return void
	 */
	public function test_get_projects_stale_survives_live_cache_expiry(): void {
		Telex_Cache::set_projects( $this->sample_projects );
		// Manually delete the live cache, leaving only the stale copy.
		delete_transient( 'telex_projects_list' );

		$this->assertNull( Telex_Cache::get_projects() );
		$this->assertSame( $this->sample_projects, Telex_Cache::get_projects_stale() );
	}

	// -------------------------------------------------------------------------
	// Single project — get / set
	// -------------------------------------------------------------------------

	/**
	 * Asserts get_project() returns null when the project is not cached.
	 *
	 * @return void
	 */
	public function test_get_project_returns_null_when_not_cached(): void {
		$this->assertNull( Telex_Cache::get_project( 'proj-unknown' ) );
	}

	/**
	 * Asserts set_project() followed by get_project() returns the cached data.
	 *
	 * @return void
	 */
	public function test_set_and_get_project_round_trips(): void {
		$data = [
			'publicId'       => 'proj-abc',
			'name'           => 'My Block',
			'currentVersion' => 2,
		];
		Telex_Cache::set_project( 'proj-abc', $data );
		$this->assertSame( $data, Telex_Cache::get_project( 'proj-abc' ) );
	}

	/**
	 * Asserts different project IDs are cached independently.
	 *
	 * @return void
	 */
	public function test_different_projects_cached_independently(): void {
		$a = [
			'publicId' => 'proj-a',
			'name'     => 'A',
		];
		$b = [
			'publicId' => 'proj-b',
			'name'     => 'B',
		];

		Telex_Cache::set_project( 'proj-a', $a );
		Telex_Cache::set_project( 'proj-b', $b );

		$this->assertSame( $a, Telex_Cache::get_project( 'proj-a' ) );
		$this->assertSame( $b, Telex_Cache::get_project( 'proj-b' ) );
		$this->assertNull( Telex_Cache::get_project( 'proj-c' ) );
	}

	// -------------------------------------------------------------------------
	// Cache busting
	// -------------------------------------------------------------------------

	/**
	 * Asserts bust_all() removes the live project list.
	 *
	 * @return void
	 */
	public function test_bust_all_removes_live_cache(): void {
		Telex_Cache::set_projects( $this->sample_projects );
		Telex_Cache::bust_all();
		$this->assertNull( Telex_Cache::get_projects() );
	}

	/**
	 * Asserts bust_all() does not remove the stale copy.
	 *
	 * @return void
	 */
	public function test_bust_all_preserves_stale_copy(): void {
		Telex_Cache::set_projects( $this->sample_projects );
		Telex_Cache::bust_all();
		$this->assertSame( $this->sample_projects, Telex_Cache::get_projects_stale() );
	}

	/**
	 * Asserts bust_project() removes the specific project from cache.
	 *
	 * @return void
	 */
	public function test_bust_project_removes_specific_project(): void {
		$data = [
			'publicId' => 'proj-x',
			'name'     => 'X',
		];
		Telex_Cache::set_project( 'proj-x', $data );
		Telex_Cache::bust_project( 'proj-x' );
		$this->assertNull( Telex_Cache::get_project( 'proj-x' ) );
	}

	/**
	 * Asserts bust_project() also busts the project list (to force a fresh fetch).
	 *
	 * @return void
	 */
	public function test_bust_project_also_busts_project_list(): void {
		Telex_Cache::set_projects( $this->sample_projects );
		Telex_Cache::bust_project( 'proj-1' );
		$this->assertNull( Telex_Cache::get_projects() );
	}

	/**
	 * Asserts bust_project() does not affect other independently-cached projects.
	 *
	 * @return void
	 */
	public function test_bust_project_does_not_affect_other_projects(): void {
		$a = [
			'publicId' => 'proj-a',
			'name'     => 'A',
		];
		$b = [
			'publicId' => 'proj-b',
			'name'     => 'B',
		];

		Telex_Cache::set_project( 'proj-a', $a );
		Telex_Cache::set_project( 'proj-b', $b );
		Telex_Cache::bust_project( 'proj-a' );

		$this->assertNull( Telex_Cache::get_project( 'proj-a' ) );
		$this->assertSame( $b, Telex_Cache::get_project( 'proj-b' ) );
	}

	// -------------------------------------------------------------------------
	// get_or_revalidate — stale-while-revalidate logic
	// -------------------------------------------------------------------------

	/**
	 * Asserts get_or_revalidate() returns the live cache when it is available.
	 *
	 * @return void
	 */
	public function test_get_or_revalidate_returns_live_when_available(): void {
		Telex_Cache::set_projects( $this->sample_projects );
		$result = Telex_Cache::get_or_revalidate();
		$this->assertSame( $this->sample_projects, $result );
	}

	/**
	 * Asserts get_or_revalidate() returns null when no data exists at all.
	 *
	 * @return void
	 */
	public function test_get_or_revalidate_returns_null_when_completely_empty(): void {
		$this->assertNull( Telex_Cache::get_or_revalidate() );
	}

	/**
	 * Asserts get_or_revalidate() returns the stale copy when the live cache is gone.
	 *
	 * @return void
	 */
	public function test_get_or_revalidate_returns_stale_when_live_expired(): void {
		Telex_Cache::set_projects( $this->sample_projects );
		delete_transient( 'telex_projects_list' );

		$result = Telex_Cache::get_or_revalidate();
		$this->assertSame( $this->sample_projects, $result );
	}

	/**
	 * Asserts get_or_revalidate() schedules a background refresh when serving stale data.
	 *
	 * @return void
	 */
	public function test_get_or_revalidate_schedules_refresh_when_serving_stale(): void {
		Telex_Cache::set_projects( $this->sample_projects );
		delete_transient( 'telex_projects_list' );

		Telex_Cache::get_or_revalidate();

		// The lock transient should have been set.
		$this->assertNotFalse( get_transient( 'telex_cache_refresh_lock' ) );
	}

	/**
	 * Asserts get_or_revalidate() does NOT schedule a refresh when the live cache is fresh.
	 *
	 * @return void
	 */
	public function test_get_or_revalidate_does_not_schedule_refresh_for_live_data(): void {
		Telex_Cache::set_projects( $this->sample_projects );
		Telex_Cache::get_or_revalidate();
		$this->assertFalse( get_transient( 'telex_cache_refresh_lock' ) );
	}

	// -------------------------------------------------------------------------
	// schedule_background_refresh — stampede protection
	// -------------------------------------------------------------------------

	/**
	 * Asserts schedule_background_refresh() sets the lock transient.
	 *
	 * @return void
	 */
	public function test_schedule_background_refresh_sets_lock(): void {
		Telex_Cache::schedule_background_refresh();
		$this->assertNotFalse( get_transient( 'telex_cache_refresh_lock' ) );
	}

	/**
	 * Asserts schedule_background_refresh() is a no-op when a lock already exists.
	 *
	 * @return void
	 */
	public function test_schedule_background_refresh_is_noop_when_locked(): void {
		set_transient( 'telex_cache_refresh_lock', 1, 30 );
		$before = wp_next_scheduled( 'telex_cache_warm' );

		Telex_Cache::schedule_background_refresh();

		$after = wp_next_scheduled( 'telex_cache_warm' );
		$this->assertSame( $before, $after, 'No additional cron event should be scheduled when lock exists.' );
	}
}
