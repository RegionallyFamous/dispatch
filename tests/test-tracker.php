<?php
/**
 * Tests for Telex_Tracker.
 *
 * @package Dispatch_For_Telex
 */

/**
 * Unit tests for the Telex_Tracker class.
 */
class Test_Telex_Tracker extends WP_UnitTestCase {

	/**
	 * Reset tracker state before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'telex_installed_projects' );
		wp_cache_flush();
	}

	/**
	 * Asserts get_all() returns an empty array when no projects are tracked.
	 *
	 * @return void
	 */
	public function test_get_all_returns_empty_array_when_no_data(): void {
		$this->assertSame( [], Telex_Tracker::get_all() );
	}

	/**
	 * Asserts track() persists data that get() can retrieve.
	 *
	 * @return void
	 */
	public function test_track_and_get(): void {
		Telex_Tracker::track( 'proj-1', 3, 'block', 'my-block' );

		$info = Telex_Tracker::get( 'proj-1' );

		$this->assertIsArray( $info );
		$this->assertSame( 3, $info['version'] );
		$this->assertSame( 'block', $info['type'] );
		$this->assertSame( 'my-block', $info['slug'] );
		$this->assertArrayHasKey( 'installed_at', $info );
		$this->assertArrayHasKey( 'updated_at', $info );
	}

	/**
	 * Asserts installed_at timestamp is immutable across re-track calls.
	 *
	 * @return void
	 */
	public function test_installed_at_does_not_change_on_update(): void {
		Telex_Tracker::track( 'proj-2', 1, 'theme', 'my-theme' );
		$first = Telex_Tracker::get( 'proj-2' );

		// Small sleep to ensure timestamp difference is detectable.
		usleep( 1000 );
		Telex_Tracker::track( 'proj-2', 2, 'theme', 'my-theme' );
		$second = Telex_Tracker::get( 'proj-2' );

		$this->assertSame( $first['installed_at'], $second['installed_at'] );
		$this->assertSame( 2, $second['version'] );
	}

	/**
	 * Asserts untrack() removes the entry for the given project.
	 *
	 * @return void
	 */
	public function test_untrack_removes_entry(): void {
		Telex_Tracker::track( 'proj-3', 1, 'block', 'some-block' );
		Telex_Tracker::untrack( 'proj-3' );

		$this->assertNull( Telex_Tracker::get( 'proj-3' ) );
	}

	/**
	 * Asserts needs_update() returns true when the remote version is higher.
	 *
	 * @return void
	 */
	public function test_needs_update_returns_true_when_remote_is_newer(): void {
		Telex_Tracker::track( 'proj-4', 2, 'block', 'block-a' );
		$this->assertTrue( Telex_Tracker::needs_update( 'proj-4', 3 ) );
	}

	/**
	 * Asserts needs_update() returns false when versions match.
	 *
	 * @return void
	 */
	public function test_needs_update_returns_false_when_up_to_date(): void {
		Telex_Tracker::track( 'proj-5', 5, 'block', 'block-b' );
		$this->assertFalse( Telex_Tracker::needs_update( 'proj-5', 5 ) );
	}

	/**
	 * Asserts is_installed() reflects the current tracking state.
	 *
	 * @return void
	 */
	public function test_is_installed(): void {
		$this->assertFalse( Telex_Tracker::is_installed( 'proj-x' ) );
		Telex_Tracker::track( 'proj-x', 1, 'block', 'some-slug' );
		$this->assertTrue( Telex_Tracker::is_installed( 'proj-x' ) );
	}

	/**
	 * Asserts needs_update() returns false when the project is not tracked at all.
	 *
	 * @return void
	 */
	public function test_needs_update_returns_false_for_untracked_project(): void {
		$this->assertFalse( Telex_Tracker::needs_update( 'proj-unknown', 999 ) );
	}

	/**
	 * Asserts get_all() returns data from the object cache on the second call.
	 *
	 * @return void
	 */
	public function test_get_all_returns_cached_data_on_repeat_call(): void {
		Telex_Tracker::track( 'proj-cache', 1, 'block', 'cache-block' );

		// First call — populates the cache.
		$first = Telex_Tracker::get_all();

		// Corrupt the option to ensure a second call reads from cache, not the DB.
		update_option( 'telex_installed_projects', '{}', false );

		$second = Telex_Tracker::get_all();

		$this->assertArrayHasKey( 'proj-cache', $first );
		$this->assertArrayHasKey( 'proj-cache', $second );
	}

	/**
	 * Asserts track() busts the object cache so subsequent get_all() reads from the DB.
	 *
	 * @return void
	 */
	public function test_track_busts_object_cache(): void {
		// Populate and cache.
		Telex_Tracker::track( 'proj-a', 1, 'block', 'block-a' );
		Telex_Tracker::get_all(); // Primes cache.

		// Add another entry (should bust cache).
		Telex_Tracker::track( 'proj-b', 2, 'block', 'block-b' );

		$all = Telex_Tracker::get_all();

		$this->assertArrayHasKey( 'proj-a', $all );
		$this->assertArrayHasKey( 'proj-b', $all );
	}

	// -------------------------------------------------------------------------
	// reconcile
	// -------------------------------------------------------------------------

	/**
	 * Asserts reconcile() removes entries for block plugins whose directory is gone.
	 *
	 * @return void
	 */
	public function test_reconcile_removes_stale_block_entry(): void {
		// Track a plugin that definitely doesn't exist in WP_PLUGIN_DIR.
		Telex_Tracker::track( 'proj-ghost', 1, 'block', 'definitely-not-installed-plugin-' . wp_generate_uuid4() );

		Telex_Tracker::reconcile();

		$this->assertNull( Telex_Tracker::get( 'proj-ghost' ) );
	}

	/**
	 * Asserts reconcile() removes entries for themes whose directory is gone.
	 *
	 * @return void
	 */
	public function test_reconcile_removes_stale_theme_entry(): void {
		Telex_Tracker::track( 'proj-ghost-theme', 1, 'theme', 'definitely-not-installed-theme-' . wp_generate_uuid4() );

		Telex_Tracker::reconcile();

		$this->assertNull( Telex_Tracker::get( 'proj-ghost-theme' ) );
	}

	/**
	 * Asserts reconcile() preserves entries whose directory actually exists.
	 *
	 * @return void
	 */
	public function test_reconcile_preserves_existing_block_directory(): void {
		// Use a plugin slug that always exists in the WP test environment.
		$existing_slug = 'akismet'; // This may not exist, so let's use a real directory.
		// Find any existing plugin directory.
		$plugins = get_plugins();
		if ( empty( $plugins ) ) {
			$this->markTestSkipped( 'No plugins installed in test environment.' );
		}

		$first_file = array_key_first( $plugins );
		$slug       = explode( '/', $first_file )[0];

		Telex_Tracker::track( 'proj-real', 1, 'block', $slug );
		Telex_Tracker::reconcile();

		// The entry should remain because the directory exists.
		$this->assertNotNull( Telex_Tracker::get( 'proj-real' ) );
	}

	/**
	 * Asserts reconcile() is a no-op when no projects are tracked.
	 *
	 * @return void
	 */
	public function test_reconcile_is_noop_when_nothing_tracked(): void {
		// Should not throw or produce any side effects.
		Telex_Tracker::reconcile();
		$this->assertSame( [], Telex_Tracker::get_all() );
	}
}
