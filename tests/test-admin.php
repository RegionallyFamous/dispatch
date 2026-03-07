<?php
/**
 * Tests for Telex_Admin — screen options, transient notices, Heartbeat, and Site Health.
 *
 * @package Dispatch_For_Telex
 */

/**
 * Unit tests for the Telex_Admin class.
 */
class Test_Telex_Admin extends WP_UnitTestCase {

	/**
	 * Reset state before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'telex_auth_token' );
		wp_set_current_user( 0 );
	}

	// -------------------------------------------------------------------------
	// save_screen_option
	// -------------------------------------------------------------------------

	/**
	 * Asserts save_screen_option() returns the value when saving the Telex per-page option.
	 *
	 * @return void
	 */
	public function test_save_screen_option_saves_telex_option(): void {
		$result = Telex_Admin::save_screen_option( false, 'telex_projects_per_page', 48 );
		$this->assertSame( 48, $result );
	}

	/**
	 * Asserts save_screen_option() passes through the status unchanged for other options.
	 *
	 * @return void
	 */
	public function test_save_screen_option_ignores_other_options(): void {
		$result = Telex_Admin::save_screen_option( false, 'some_other_option', 10 );
		$this->assertFalse( $result );
	}

	/**
	 * Asserts save_screen_option() preserves a non-false status for other options.
	 *
	 * @return void
	 */
	public function test_save_screen_option_preserves_existing_status_for_other_options(): void {
		$result = Telex_Admin::save_screen_option( 20, 'another_option', 5 );
		$this->assertSame( 20, $result );
	}

	// -------------------------------------------------------------------------
	// Transient notices
	// -------------------------------------------------------------------------

	/**
	 * Asserts set_notice() stores a transient retrievable with the expected keys.
	 *
	 * @return void
	 */
	public function test_set_notice_stores_transient(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		Telex_Admin::set_notice( 'success', 'Operation succeeded.' );

		$notice_key = 'telex_admin_notice_' . $user_id;
		$notice     = get_transient( $notice_key );

		$this->assertIsArray( $notice );
		$this->assertSame( 'success', $notice['type'] );
		$this->assertSame( 'Operation succeeded.', $notice['message'] );
	}

	/**
	 * Asserts set_notice() supports all valid notice types.
	 *
	 * @return void
	 */
	public function test_set_notice_supports_all_valid_types(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		foreach ( [ 'success', 'error', 'warning', 'info' ] as $type ) {
			Telex_Admin::set_notice( $type, 'Test message.' );
			$notice = get_transient( 'telex_admin_notice_' . $user_id );
			$this->assertSame( $type, $notice['type'], "Notice type '$type' should be stored correctly." );
		}
	}

	/**
	 * Asserts notices are scoped per-user — different users get independent notices.
	 *
	 * @return void
	 */
	public function test_set_notice_is_scoped_per_user(): void {
		$user_a = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user_b = self::factory()->user->create( [ 'role' => 'administrator' ] );

		wp_set_current_user( $user_a );
		Telex_Admin::set_notice( 'info', 'Notice for user A.' );

		// User B should have no notice.
		wp_set_current_user( $user_b );
		$this->assertFalse( get_transient( 'telex_admin_notice_' . $user_b ) );
	}

	// -------------------------------------------------------------------------
	// heartbeat_received
	// -------------------------------------------------------------------------

	/**
	 * Asserts heartbeat_received() passes the response through when telex_poll is absent.
	 *
	 * @return void
	 */
	public function test_heartbeat_received_passthrough_when_no_telex_poll(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$response = Telex_Admin::heartbeat_received( [ 'existing' => 'data' ], [] );

		$this->assertArrayNotHasKey( 'telex', $response );
		$this->assertSame( [ 'existing' => 'data' ], $response );
	}

	/**
	 * Asserts heartbeat_received() adds Telex connection status when telex_poll is true.
	 *
	 * @return void
	 */
	public function test_heartbeat_received_adds_connection_status_for_admin(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$response = Telex_Admin::heartbeat_received( [], [ 'telex_poll' => true ] );

		$this->assertArrayHasKey( 'telex', $response );
		$this->assertArrayHasKey( 'is_connected', $response['telex'] );
		$this->assertArrayHasKey( 'circuit_status', $response['telex'] );
	}

	/**
	 * Asserts heartbeat_received() reports the correct connection state.
	 *
	 * @return void
	 */
	public function test_heartbeat_received_reports_correct_connection_state(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$response_disconnected = Telex_Admin::heartbeat_received( [], [ 'telex_poll' => true ] );
		$this->assertFalse( $response_disconnected['telex']['is_connected'] );

		Telex_Auth::store_token( 'test-token' );
		$response_connected = Telex_Admin::heartbeat_received( [], [ 'telex_poll' => true ] );
		$this->assertTrue( $response_connected['telex']['is_connected'] );

		Telex_Auth::disconnect();
	}

	/**
	 * Asserts heartbeat_received() ignores telex_poll for non-admin users.
	 *
	 * @return void
	 */
	public function test_heartbeat_received_ignores_telex_poll_for_non_admin(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );

		$response = Telex_Admin::heartbeat_received( [], [ 'telex_poll' => true ] );

		$this->assertArrayNotHasKey( 'telex', $response );
	}

	// -------------------------------------------------------------------------
	// heartbeat_nopriv_deny
	// -------------------------------------------------------------------------

	/**
	 * Asserts heartbeat_nopriv_deny() removes any telex key from the response.
	 *
	 * @return void
	 */
	public function test_heartbeat_nopriv_deny_removes_telex_data(): void {
		$response = Telex_Admin::heartbeat_nopriv_deny(
			[
				'telex' => [ 'is_connected' => true ],
				'other' => 'data',
			],
			[]
		);

		$this->assertArrayNotHasKey( 'telex', $response );
		$this->assertArrayHasKey( 'other', $response );
	}

	/**
	 * Asserts heartbeat_nopriv_deny() is a no-op when telex key is absent.
	 *
	 * @return void
	 */
	public function test_heartbeat_nopriv_deny_is_noop_when_no_telex_key(): void {
		$input    = [ 'other' => 'data' ];
		$response = Telex_Admin::heartbeat_nopriv_deny( $input, [] );

		$this->assertSame( $input, $response );
	}

	// -------------------------------------------------------------------------
	// Site Health
	// -------------------------------------------------------------------------

	/**
	 * Asserts site_health_info() adds a 'telex' section to the debug info array.
	 *
	 * @return void
	 */
	public function test_site_health_info_adds_telex_section(): void {
		$info = Telex_Admin::site_health_info( [] );
		$this->assertArrayHasKey( 'telex', $info );
	}

	/**
	 * Asserts site_health_info() includes the required debug fields.
	 *
	 * @return void
	 */
	public function test_site_health_info_includes_required_fields(): void {
		$info   = Telex_Admin::site_health_info( [] );
		$fields = $info['telex']['fields'];

		$this->assertArrayHasKey( 'version', $fields );
		$this->assertArrayHasKey( 'connected', $fields );
		$this->assertArrayHasKey( 'installed', $fields );
		$this->assertArrayHasKey( 'circuit_breaker', $fields );
	}

	/**
	 * Asserts site_health_info() reports correct connection status values.
	 *
	 * @return void
	 */
	public function test_site_health_info_reports_correct_connection_status(): void {
		$info_disconnected = Telex_Admin::site_health_info( [] );
		$this->assertStringContainsString( 'Not connected', (string) $info_disconnected['telex']['fields']['connected']['value'] );

		Telex_Auth::store_token( 'tok' );
		$info_connected = Telex_Admin::site_health_info( [] );
		$this->assertStringContainsString( 'Connected', (string) $info_connected['telex']['fields']['connected']['value'] );

		Telex_Auth::disconnect();
	}

	/**
	 * Asserts site_health_info() preserves existing info sections.
	 *
	 * @return void
	 */
	public function test_site_health_info_preserves_existing_sections(): void {
		$existing = [ 'wordpress' => [ 'label' => 'WordPress' ] ];
		$info     = Telex_Admin::site_health_info( $existing );

		$this->assertArrayHasKey( 'wordpress', $info );
		$this->assertArrayHasKey( 'telex', $info );
	}

	/**
	 * Asserts site_health_tests() adds the API reachability async test.
	 *
	 * @return void
	 */
	public function test_site_health_tests_adds_reachability_test(): void {
		$tests = Telex_Admin::site_health_tests( [] );

		$this->assertArrayHasKey( 'async', $tests );
		$this->assertArrayHasKey( 'telex_api_reachable', $tests['async'] );
	}

	/**
	 * Asserts site_health_tests() preserves existing tests.
	 *
	 * @return void
	 */
	public function test_site_health_tests_preserves_existing_tests(): void {
		$existing = [ 'async' => [ 'other_test' => [] ] ];
		$tests    = Telex_Admin::site_health_tests( $existing );

		$this->assertArrayHasKey( 'other_test', $tests['async'] );
		$this->assertArrayHasKey( 'telex_api_reachable', $tests['async'] );
	}
}
