<?php
/**
 * Tests for Telex_REST endpoints.
 *
 * Uses WP_REST_Server for integration-level endpoint testing.
 * External HTTP calls are blocked via pre_http_request.
 */
class Test_Telex_REST extends WP_UnitTestCase {

	private WP_REST_Server $server;

	public function setUp(): void {
		parent::setUp();

		/** @var WP_REST_Server $wp_rest_server */
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );

		delete_option( 'telex_auth_token' );
	}

	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Auth status
	// -------------------------------------------------------------------------

	public function test_auth_status_requires_authentication(): void {
		$request  = new WP_REST_Request( 'GET', '/telex/v1/auth/status' );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_auth_status_returns_disconnected(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request  = new WP_REST_Request( 'GET', '/telex/v1/auth/status' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $data['is_connected'] );
		$this->assertSame( AuthStatus::Disconnected->value, $data['status'] );
	}

	public function test_auth_status_returns_connected(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		Telex_Auth::store_token( 'test-token' );

		$request  = new WP_REST_Request( 'GET', '/telex/v1/auth/status' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['is_connected'] );
		$this->assertSame( AuthStatus::Connected->value, $data['status'] );

		Telex_Auth::disconnect();
	}

	// -------------------------------------------------------------------------
	// Disconnect
	// -------------------------------------------------------------------------

	public function test_disconnect_clears_token(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		Telex_Auth::store_token( 'to-be-deleted' );

		$request  = new WP_REST_Request( 'DELETE', '/telex/v1/auth' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( Telex_Auth::is_connected() );
	}

	// -------------------------------------------------------------------------
	// Projects — unauthenticated
	// -------------------------------------------------------------------------

	public function test_get_projects_returns_401_when_not_logged_in(): void {
		$request  = new WP_REST_Request( 'GET', '/telex/v1/projects' );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_get_projects_returns_401_when_not_connected(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request  = new WP_REST_Request( 'GET', '/telex/v1/projects' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Namespace registration
	// -------------------------------------------------------------------------

	public function test_routes_are_registered(): void {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/telex/v1/projects', $routes );
		$this->assertArrayHasKey( '/telex/v1/auth/device', $routes );
		$this->assertArrayHasKey( '/telex/v1/auth', $routes );
		$this->assertArrayHasKey( '/telex/v1/auth/status', $routes );
	}
}
