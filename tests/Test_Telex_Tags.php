<?php
/**
 * Tests for Telex_Tags.
 *
 * @package Dispatch_For_Telex
 */

/**
 * Unit tests for the Telex_Tags class.
 */
class Test_Telex_Tags extends WP_UnitTestCase {

	/**
	 * Clean up options and transients created during tests.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		delete_option( 'telex_tags_proj-abc' );
		delete_option( 'telex_tags_proj-xyz' );
		delete_transient( 'telex_all_tags' );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// get() / set()
	// -------------------------------------------------------------------------

	/**
	 * Returns an empty array when no tags have been saved.
	 *
	 * @return void
	 */
	public function test_get_returns_empty_by_default(): void {
		$this->assertSame( [], Telex_Tags::get( 'proj-abc' ) );
	}

	/**
	 * Tags can be set and retrieved.
	 *
	 * @return void
	 */
	public function test_set_and_get(): void {
		$saved = Telex_Tags::set( 'proj-abc', [ 'client-a', 'beta' ] );

		$this->assertSame( [ 'client-a', 'beta' ], $saved );
		$this->assertSame( [ 'client-a', 'beta' ], Telex_Tags::get( 'proj-abc' ) );
	}

	/**
	 * Duplicate tags are deduplicated by set().
	 *
	 * @return void
	 */
	public function test_set_deduplicates_tags(): void {
		$saved = Telex_Tags::set( 'proj-abc', [ 'beta', 'beta', 'core' ] );
		$this->assertSame( [ 'beta', 'core' ], $saved );
	}

	/**
	 * Tags exceeding MAX_TAG_LEN are truncated to 32 characters.
	 *
	 * @return void
	 */
	public function test_set_truncates_long_tags(): void {
		$long_tag = str_repeat( 'x', 40 );
		$saved    = Telex_Tags::set( 'proj-abc', [ $long_tag ] );
		$this->assertSame( str_repeat( 'x', 32 ), $saved[0] );
	}

	/**
	 * Set() accepts at most 20 tags; additional entries are silently dropped.
	 *
	 * @return void
	 */
	public function test_set_enforces_max_tag_count(): void {
		$tags  = array_map( fn( $i ) => "tag-{$i}", range( 1, 25 ) );
		$saved = Telex_Tags::set( 'proj-abc', $tags );
		$this->assertCount( 20, $saved );
	}

	/**
	 * Saving an empty array clears the tag list.
	 *
	 * @return void
	 */
	public function test_set_empty_clears_tags(): void {
		Telex_Tags::set( 'proj-abc', [ 'core' ] );
		Telex_Tags::set( 'proj-abc', [] );
		$this->assertSame( [], Telex_Tags::get( 'proj-abc' ) );
	}

	// -------------------------------------------------------------------------
	// all_in_use() / bust_cache()
	// -------------------------------------------------------------------------

	/**
	 * All_in_use() aggregates tags across all projects.
	 *
	 * @return void
	 */
	public function test_all_in_use_aggregates(): void {
		Telex_Tags::set( 'proj-abc', [ 'client-a', 'beta' ] );
		Telex_Tags::set( 'proj-xyz', [ 'beta', 'core' ] );

		$all = Telex_Tags::all_in_use();
		sort( $all );
		$this->assertSame( [ 'beta', 'client-a', 'core' ], $all );
	}

	/**
	 * All_in_use() returns a sorted, deduplicated list.
	 *
	 * @return void
	 */
	public function test_all_in_use_is_sorted_and_unique(): void {
		Telex_Tags::set( 'proj-abc', [ 'zebra', 'alpha' ] );
		Telex_Tags::set( 'proj-xyz', [ 'alpha', 'middle' ] );

		$all = Telex_Tags::all_in_use();
		$this->assertSame( [ 'alpha', 'middle', 'zebra' ], $all );
	}

	/**
	 * Bust_cache() causes all_in_use() to re-query rather than serve stale data.
	 *
	 * @return void
	 */
	public function test_bust_cache_invalidates_transient(): void {
		Telex_Tags::set( 'proj-abc', [ 'initial' ] );
		Telex_Tags::all_in_use(); // Populate cache.

		Telex_Tags::set( 'proj-abc', [ 'updated' ] );
		Telex_Tags::bust_cache();

		$fresh = Telex_Tags::all_in_use();
		$this->assertContains( 'updated', $fresh );
		$this->assertNotContains( 'initial', $fresh );
	}
}
