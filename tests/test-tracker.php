<?php
/**
 * Tests for Telex_Tracker.
 */
class Test_Telex_Tracker extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( 'telex_installed_projects' );
		wp_cache_flush();
	}

	public function test_get_all_returns_empty_array_when_no_data(): void {
		$this->assertSame( [], Telex_Tracker::get_all() );
	}

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

	public function test_untrack_removes_entry(): void {
		Telex_Tracker::track( 'proj-3', 1, 'block', 'some-block' );
		Telex_Tracker::untrack( 'proj-3' );

		$this->assertNull( Telex_Tracker::get( 'proj-3' ) );
	}

	public function test_needs_update_returns_true_when_remote_is_newer(): void {
		Telex_Tracker::track( 'proj-4', 2, 'block', 'block-a' );
		$this->assertTrue( Telex_Tracker::needs_update( 'proj-4', 3 ) );
	}

	public function test_needs_update_returns_false_when_up_to_date(): void {
		Telex_Tracker::track( 'proj-5', 5, 'block', 'block-b' );
		$this->assertFalse( Telex_Tracker::needs_update( 'proj-5', 5 ) );
	}

	public function test_is_installed(): void {
		$this->assertFalse( Telex_Tracker::is_installed( 'proj-x' ) );
		Telex_Tracker::track( 'proj-x', 1, 'block', 'some-slug' );
		$this->assertTrue( Telex_Tracker::is_installed( 'proj-x' ) );
	}
}
