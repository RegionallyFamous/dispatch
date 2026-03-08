<?php
/**
 * Tests for Telex_Failed_Installs.
 *
 * @package Dispatch_For_Telex
 */

/**
 * Unit tests for the Telex_Failed_Installs class.
 */
class Test_Telex_Failed_Installs extends WP_UnitTestCase {

	/**
	 * Clean up options created during tests.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		Telex_Failed_Installs::clear( 'proj-1' );
		Telex_Failed_Installs::clear( 'proj-2' );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// record() / has_failure()
	// -------------------------------------------------------------------------

	/**
	 * A recorded failure is detectable via has_failure().
	 *
	 * @return void
	 */
	public function test_record_and_has_failure(): void {
		$this->assertFalse( Telex_Failed_Installs::has_failure( 'proj-1' ) );

		Telex_Failed_Installs::record( 'proj-1', 'My Project', 'Download timed out.' );

		$this->assertTrue( Telex_Failed_Installs::has_failure( 'proj-1' ) );
	}

	/**
	 * Recorded data contains expected fields.
	 *
	 * @return void
	 */
	public function test_record_stores_all_fields(): void {
		Telex_Failed_Installs::record( 'proj-1', 'My Project', 'Checksum mismatch.' );

		$all = Telex_Failed_Installs::get_all();
		$this->assertCount( 1, $all );

		$entry = $all[0];
		$this->assertSame( 'proj-1', $entry['public_id'] );
		$this->assertSame( 'My Project', $entry['project_name'] );
		$this->assertSame( 'Checksum mismatch.', $entry['error'] );
		$this->assertArrayHasKey( 'failed_at', $entry );
	}

	// -------------------------------------------------------------------------
	// clear()
	// -------------------------------------------------------------------------

	/**
	 * Clearing a failure removes it from get_all() and has_failure().
	 *
	 * @return void
	 */
	public function test_clear_removes_entry(): void {
		Telex_Failed_Installs::record( 'proj-1', 'My Project', 'Error.' );
		$this->assertTrue( Telex_Failed_Installs::has_failure( 'proj-1' ) );

		Telex_Failed_Installs::clear( 'proj-1' );

		$this->assertFalse( Telex_Failed_Installs::has_failure( 'proj-1' ) );
		$this->assertEmpty( Telex_Failed_Installs::get_all() );
	}

	/**
	 * Clearing a non-existent entry is a no-op.
	 *
	 * @return void
	 */
	public function test_clear_nonexistent_is_noop(): void {
		Telex_Failed_Installs::clear( 'does-not-exist' );
		$this->assertEmpty( Telex_Failed_Installs::get_all() );
	}

	// -------------------------------------------------------------------------
	// get_all() — ordering
	// -------------------------------------------------------------------------

	/**
	 * Get_all() returns failures sorted newest-first.
	 *
	 * @return void
	 */
	public function test_get_all_sorted_newest_first(): void {
		Telex_Failed_Installs::record( 'proj-1', 'First', 'Error A.' );
		// Ensure a later timestamp by sleeping 1 second.
		sleep( 1 );
		Telex_Failed_Installs::record( 'proj-2', 'Second', 'Error B.' );

		$all = Telex_Failed_Installs::get_all();
		$this->assertCount( 2, $all );
		$this->assertSame( 'proj-2', $all[0]['public_id'] );
		$this->assertSame( 'proj-1', $all[1]['public_id'] );
	}

	/**
	 * Overwriting a failure for the same project updates the record.
	 *
	 * @return void
	 */
	public function test_record_overwrites_existing_entry(): void {
		Telex_Failed_Installs::record( 'proj-1', 'My Project', 'First error.' );
		Telex_Failed_Installs::record( 'proj-1', 'My Project', 'Second error.' );

		$all = Telex_Failed_Installs::get_all();
		$this->assertCount( 1, $all );
		$this->assertSame( 'Second error.', $all[0]['error'] );
	}
}
