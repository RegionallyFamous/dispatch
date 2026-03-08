<?php
/**
 * Tests for Telex_Favorites.
 *
 * @package Dispatch_For_Telex
 */

/**
 * Unit tests for the Telex_Favorites class.
 */
class Test_Telex_Favorites extends WP_UnitTestCase {

	/**
	 * WordPress user ID used across tests.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Create a test user and switch to them.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->user_id );
	}

	/**
	 * Delete user_meta created during tests.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		delete_user_meta( $this->user_id, 'telex_favorites' );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// get_for_user()
	// -------------------------------------------------------------------------

	/**
	 * Returns an empty array when no favorites have been saved.
	 *
	 * @return void
	 */
	public function test_get_for_user_returns_empty_by_default(): void {
		$this->assertSame( [], Telex_Favorites::get_for_user( $this->user_id ) );
	}

	// -------------------------------------------------------------------------
	// add() / is_starred()
	// -------------------------------------------------------------------------

	/**
	 * A project is starred after calling add().
	 *
	 * @return void
	 */
	public function test_add_stars_project(): void {
		$result = Telex_Favorites::add( 'proj-abc', $this->user_id );

		$this->assertTrue( $result );
		$this->assertTrue( Telex_Favorites::is_starred( 'proj-abc', $this->user_id ) );
	}

	/**
	 * Adding the same project twice returns false on the second call.
	 *
	 * @return void
	 */
	public function test_add_duplicate_returns_false(): void {
		Telex_Favorites::add( 'proj-abc', $this->user_id );
		$result = Telex_Favorites::add( 'proj-abc', $this->user_id );

		$this->assertFalse( $result );
		$this->assertCount( 1, Telex_Favorites::get_for_user( $this->user_id ) );
	}

	// -------------------------------------------------------------------------
	// remove()
	// -------------------------------------------------------------------------

	/**
	 * A project is un-starred after calling remove().
	 *
	 * @return void
	 */
	public function test_remove_unstars_project(): void {
		Telex_Favorites::add( 'proj-abc', $this->user_id );
		$result = Telex_Favorites::remove( 'proj-abc', $this->user_id );

		$this->assertTrue( $result );
		$this->assertFalse( Telex_Favorites::is_starred( 'proj-abc', $this->user_id ) );
	}

	/**
	 * Removing a project that was never starred returns false.
	 *
	 * @return void
	 */
	public function test_remove_not_starred_returns_false(): void {
		$result = Telex_Favorites::remove( 'not-starred', $this->user_id );
		$this->assertFalse( $result );
	}

	// -------------------------------------------------------------------------
	// Multiple projects
	// -------------------------------------------------------------------------

	/**
	 * Multiple favorites are stored and retrieved correctly.
	 *
	 * @return void
	 */
	public function test_multiple_favorites(): void {
		Telex_Favorites::add( 'proj-a', $this->user_id );
		Telex_Favorites::add( 'proj-b', $this->user_id );
		Telex_Favorites::add( 'proj-c', $this->user_id );

		$favorites = Telex_Favorites::get_for_user( $this->user_id );
		$this->assertCount( 3, $favorites );
		$this->assertContains( 'proj-a', $favorites );
		$this->assertContains( 'proj-b', $favorites );
		$this->assertContains( 'proj-c', $favorites );
	}

	/**
	 * Favorites are scoped per-user: one user's favorites do not affect another's.
	 *
	 * @return void
	 */
	public function test_favorites_are_per_user(): void {
		$other_user = self::factory()->user->create( [ 'role' => 'administrator' ] );

		Telex_Favorites::add( 'proj-abc', $this->user_id );

		$this->assertTrue( Telex_Favorites::is_starred( 'proj-abc', $this->user_id ) );
		$this->assertFalse( Telex_Favorites::is_starred( 'proj-abc', $other_user ) );
	}
}
