<?php
/**
 * Tests for Telex_Updater — update injection and plugins_api integration.
 *
 * @package Dispatch_For_Telex
 */

/**
 * Unit tests for the Telex_Updater class.
 */
class Test_Telex_Updater extends WP_UnitTestCase {

	/**
	 * Reset state before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'telex_auth_token' );
		delete_option( 'telex_installed_projects' );
		wp_cache_flush();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns a minimal update transient object.
	 *
	 * @return object
	 */
	private function make_transient(): object {
		return (object) [
			'response'  => [],
			'no_update' => [],
		];
	}

	// -------------------------------------------------------------------------
	// inject_plugin_updates
	// -------------------------------------------------------------------------

	/**
	 * Asserts inject_plugin_updates() returns the transient unchanged when not connected.
	 *
	 * @return void
	 */
	public function test_inject_plugin_updates_passthrough_when_not_connected(): void {
		$transient = $this->make_transient();
		$result    = Telex_Updater::inject_plugin_updates( $transient );

		$this->assertSame( $transient, $result );
		$this->assertEmpty( $result->response );
	}

	/**
	 * Asserts inject_plugin_updates() returns the transient unchanged when no projects are tracked.
	 *
	 * @return void
	 */
	public function test_inject_plugin_updates_passthrough_when_no_tracked_projects(): void {
		// Connected but nothing tracked.
		Telex_Auth::store_token( 'tok' );

		$transient = $this->make_transient();
		$result    = Telex_Updater::inject_plugin_updates( $transient );

		// No client will be available in tests since the SDK cannot be constructed
		// without a real network, so the circuit breaker returns false. The
		// transient should pass through unmodified.
		$this->assertEmpty( $result->response );

		Telex_Auth::disconnect();
	}

	/**
	 * Asserts inject_plugin_updates() skips entries whose type is Theme.
	 *
	 * @return void
	 */
	public function test_inject_plugin_updates_skips_theme_entries(): void {
		// Track a theme — inject_plugin_updates should skip it.
		Telex_Tracker::track( 'proj-theme', 1, 'theme', 'my-theme' );

		$transient = $this->make_transient();
		$result    = Telex_Updater::inject_plugin_updates( $transient );

		$this->assertEmpty( $result->response );
	}

	// -------------------------------------------------------------------------
	// inject_theme_updates
	// -------------------------------------------------------------------------

	/**
	 * Asserts inject_theme_updates() returns the transient unchanged when not connected.
	 *
	 * @return void
	 */
	public function test_inject_theme_updates_passthrough_when_not_connected(): void {
		$transient = $this->make_transient();
		$result    = Telex_Updater::inject_theme_updates( $transient );

		$this->assertSame( $transient, $result );
		$this->assertEmpty( $result->response );
	}

	/**
	 * Asserts inject_theme_updates() returns the transient unchanged when no projects are tracked.
	 *
	 * @return void
	 */
	public function test_inject_theme_updates_passthrough_when_no_tracked_projects(): void {
		Telex_Auth::store_token( 'tok' );

		$transient = $this->make_transient();
		$result    = Telex_Updater::inject_theme_updates( $transient );

		$this->assertEmpty( $result->response );

		Telex_Auth::disconnect();
	}

	/**
	 * Asserts inject_theme_updates() skips entries whose type is Block.
	 *
	 * @return void
	 */
	public function test_inject_theme_updates_skips_block_entries(): void {
		Telex_Tracker::track( 'proj-block', 1, 'block', 'my-block' );

		$transient = $this->make_transient();
		$result    = Telex_Updater::inject_theme_updates( $transient );

		$this->assertEmpty( $result->response );
	}

	// -------------------------------------------------------------------------
	// plugins_api_info
	// -------------------------------------------------------------------------

	/**
	 * Asserts plugins_api_info() passes through for non plugin_information actions.
	 *
	 * @return void
	 */
	public function test_plugins_api_info_passthrough_for_wrong_action(): void {
		$result = Telex_Updater::plugins_api_info( false, 'query_plugins', (object) [] );
		$this->assertFalse( $result );
	}

	/**
	 * Asserts plugins_api_info() passes through when the slug is empty.
	 *
	 * @return void
	 */
	public function test_plugins_api_info_passthrough_for_empty_slug(): void {
		$result = Telex_Updater::plugins_api_info( false, 'plugin_information', (object) [ 'slug' => '' ] );
		$this->assertFalse( $result );
	}

	/**
	 * Asserts plugins_api_info() passes through for a slug not matching any tracked project.
	 *
	 * @return void
	 */
	public function test_plugins_api_info_passthrough_for_unrecognized_slug(): void {
		$result = Telex_Updater::plugins_api_info( false, 'plugin_information', (object) [ 'slug' => 'unknown-plugin' ] );
		$this->assertFalse( $result );
	}

	/**
	 * Asserts plugins_api_info() returns false for a tracked slug when not connected.
	 *
	 * @return void
	 */
	public function test_plugins_api_info_returns_false_for_tracked_slug_when_not_connected(): void {
		Telex_Tracker::track( 'proj-x', 2, 'block', 'my-block' );

		$result = Telex_Updater::plugins_api_info( false, 'plugin_information', (object) [ 'slug' => 'my-block' ] );
		$this->assertFalse( $result );
	}

	/**
	 * Asserts plugins_api_info() preserves a pre-populated $result object passed in.
	 *
	 * @return void
	 */
	public function test_plugins_api_info_preserves_existing_result(): void {
		$existing = (object) [ 'name' => 'Some Other Plugin' ];
		$result   = Telex_Updater::plugins_api_info( $existing, 'query_plugins', (object) [] );
		$this->assertSame( $existing, $result );
	}
}
