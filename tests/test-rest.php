<?php
/**
 * Tests for Telex_REST endpoints.
 *
 * Uses WP_REST_Server for integration-level endpoint testing.
 * External HTTP calls are blocked via pre_http_request.
 *
 * @package Dispatch_For_Telex
 */

/**
 * Integration tests for the Telex_REST class.
 */
class Test_Telex_REST extends WP_UnitTestCase {

	/**
	 * REST server instance under test.
	 *
	 * @var WP_REST_Server
	 */
	private WP_REST_Server $server;

	/**
	 * Initialise a fresh REST server and register Telex routes.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );

		delete_option( 'telex_auth_token' );
		delete_option( 'telex_installed_projects' );
		delete_transient( Telex_Auth::TRANSIENT_DEVICE );
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Destroy the REST server after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Auth status
	// -------------------------------------------------------------------------

	/**
	 * Asserts auth/status returns 401 for unauthenticated requests.
	 *
	 * @return void
	 */
	public function test_auth_status_requires_authentication(): void {
		$request  = new WP_REST_Request( 'GET', '/telex/v1/auth/status' );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Asserts auth/status returns disconnected state when no token is stored.
	 *
	 * @return void
	 */
	public function test_auth_status_returns_disconnected(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request  = new WP_REST_Request( 'GET', '/telex/v1/auth/status' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $data['is_connected'] );
		$this->assertSame( AuthStatus::Disconnected->value, $data['status'] );
	}

	/**
	 * Asserts auth/status returns connected state after storing a token.
	 *
	 * @return void
	 */
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

	/**
	 * Asserts DELETE auth clears the stored token.
	 *
	 * @return void
	 */
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

	/**
	 * Asserts GET projects returns 401 when not logged in.
	 *
	 * @return void
	 */
	public function test_get_projects_returns_401_when_not_logged_in(): void {
		$request  = new WP_REST_Request( 'GET', '/telex/v1/projects' );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Asserts GET projects returns 401 when logged in but not connected to Telex.
	 *
	 * @return void
	 */
	public function test_get_projects_returns_401_when_not_connected(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request  = new WP_REST_Request( 'GET', '/telex/v1/projects' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Namespace registration
	// -------------------------------------------------------------------------

	/**
	 * Asserts all expected Telex routes are registered.
	 *
	 * @return void
	 */
	public function test_routes_are_registered(): void {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/telex/v1/projects', $routes );
		$this->assertArrayHasKey( '/telex/v1/auth/device', $routes );
		$this->assertArrayHasKey( '/telex/v1/auth', $routes );
		$this->assertArrayHasKey( '/telex/v1/auth/status', $routes );
		$this->assertArrayHasKey( '/telex/v1/installed', $routes );
	}

	// -------------------------------------------------------------------------
	// GET /installed
	// -------------------------------------------------------------------------

	/**
	 * Asserts GET /installed returns an empty array when no projects are tracked.
	 *
	 * @return void
	 */
	public function test_get_installed_returns_empty_when_nothing_tracked(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request  = new WP_REST_Request( 'GET', '/telex/v1/installed' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'installed', $data );
		$this->assertSame( [], $data['installed'] );
	}

	/**
	 * Asserts GET /installed returns tracked projects for an authenticated admin.
	 *
	 * @return void
	 */
	public function test_get_installed_returns_tracked_projects(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		Telex_Tracker::track( 'proj-test', 1, 'block', 'my-block' );

		$request  = new WP_REST_Request( 'GET', '/telex/v1/installed' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'proj-test', $data['installed'] );
	}

	/**
	 * Asserts GET /installed returns 401 for unauthenticated requests.
	 *
	 * @return void
	 */
	public function test_get_installed_returns_401_when_not_logged_in(): void {
		$request  = new WP_REST_Request( 'GET', '/telex/v1/installed' );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 401, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Cancel device flow
	// -------------------------------------------------------------------------

	/**
	 * Asserts DELETE /auth/device cancels an in-progress device flow.
	 *
	 * @return void
	 */
	public function test_cancel_device_flow_clears_transient(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		set_transient( Telex_Auth::TRANSIENT_DEVICE, 'active-device-code', 300 );

		$request  = new WP_REST_Request( 'DELETE', '/telex/v1/auth/device' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['cancelled'] );
		$this->assertFalse( get_transient( Telex_Auth::TRANSIENT_DEVICE ) );
	}

	/**
	 * Asserts DELETE /auth/device returns 401 for unauthenticated requests.
	 *
	 * @return void
	 */
	public function test_cancel_device_flow_returns_401_when_not_logged_in(): void {
		$request  = new WP_REST_Request( 'DELETE', '/telex/v1/auth/device' );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 401, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Permission callbacks — 403 scenarios
	// -------------------------------------------------------------------------

	/**
	 * Asserts GET /projects returns 403 for a logged-in subscriber (no manage_options).
	 *
	 * @return void
	 */
	public function test_get_projects_returns_403_for_subscriber(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$request  = new WP_REST_Request( 'GET', '/telex/v1/projects' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Asserts DELETE /auth returns 403 for a logged-in subscriber.
	 *
	 * @return void
	 */
	public function test_disconnect_returns_403_for_subscriber(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$request  = new WP_REST_Request( 'DELETE', '/telex/v1/auth' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Asserts GET /auth/status returns 403 for a logged-in subscriber.
	 *
	 * @return void
	 */
	public function test_auth_status_returns_403_for_subscriber(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$request  = new WP_REST_Request( 'GET', '/telex/v1/auth/status' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// ETag / 304 conditional GET
	// -------------------------------------------------------------------------

	/**
	 * Asserts GET /projects includes an ETag header in the response.
	 *
	 * @return void
	 */
	public function test_get_projects_includes_etag_header(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		Telex_Auth::store_token( 'test-token' );

		// Seed the cache so no API call is made.
		Telex_Cache::set_projects( [] );

		$request  = new WP_REST_Request( 'GET', '/telex/v1/projects' );
		$response = $this->server->dispatch( $request );

		$etag = $response->get_headers()['ETag'] ?? '';
		$this->assertNotEmpty( $etag, 'Response must include an ETag header.' );
		$this->assertStringStartsWith( '"', $etag );

		Telex_Auth::disconnect();
	}

	/**
	 * Asserts GET /projects returns 304 when the If-None-Match ETag matches.
	 *
	 * @return void
	 */
	public function test_get_projects_returns_304_when_etag_matches(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		Telex_Auth::store_token( 'test-token' );
		Telex_Cache::set_projects( [] );

		// First request to get the ETag.
		$first_request  = new WP_REST_Request( 'GET', '/telex/v1/projects' );
		$first_response = $this->server->dispatch( $first_request );
		$etag           = $first_response->get_headers()['ETag'] ?? '';

		$this->assertNotEmpty( $etag );

		// Second request with matching ETag.
		$second_request = new WP_REST_Request( 'GET', '/telex/v1/projects' );
		$second_request->set_header( 'If-None-Match', $etag );
		$second_response = $this->server->dispatch( $second_request );

		$this->assertSame( 304, $second_response->get_status() );

		Telex_Auth::disconnect();
	}

	// -------------------------------------------------------------------------
	// Poll device flow — REST integration
	// -------------------------------------------------------------------------

	/**
	 * Asserts GET /auth/device returns 400 when no device flow is active.
	 *
	 * @return void
	 */
	public function test_poll_device_flow_returns_400_when_no_active_flow(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request  = new WP_REST_Request( 'GET', '/telex/v1/auth/device' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Asserts GET /auth/device returns pending status when the device code has not been approved.
	 *
	 * @return void
	 */
	public function test_poll_device_flow_returns_pending(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		set_transient( Telex_Auth::TRANSIENT_DEVICE, 'dev-code-123', 300 );

		add_filter(
			'pre_http_request',
			static fn() => [
				'headers'       => new \Requests_Utility_CaseInsensitiveDictionary( [] ),
				'body'          => (string) wp_json_encode( [ 'error' => 'authorization_pending' ] ),
				'response'      => [
					'code'    => 400,
					'message' => 'Bad Request',
				],
				'cookies'       => [],
				'http_response' => null,
			],
			10,
			3
		);

		$request  = new WP_REST_Request( 'GET', '/telex/v1/auth/device' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $data['authorized'] );
		$this->assertSame( 'pending', $data['status'] );
	}

	/**
	 * Asserts GET /auth/device returns authorized:true when the device code is approved.
	 *
	 * @return void
	 */
	public function test_poll_device_flow_returns_authorized_on_success(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		set_transient( Telex_Auth::TRANSIENT_DEVICE, 'dev-code-ok', 300 );

		add_filter(
			'pre_http_request',
			static fn() => [
				'headers'       => new \Requests_Utility_CaseInsensitiveDictionary( [] ),
				'body'          => (string) wp_json_encode( [ 'access_token' => 'shiny-new-token' ] ),
				'response'      => [
					'code'    => 200,
					'message' => 'OK',
				],
				'cookies'       => [],
				'http_response' => null,
			],
			10,
			3
		);

		$request  = new WP_REST_Request( 'GET', '/telex/v1/auth/device' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['authorized'] );
		$this->assertTrue( Telex_Auth::is_connected() );

		Telex_Auth::disconnect();
	}

	// -------------------------------------------------------------------------
	// Start device flow — REST integration
	// -------------------------------------------------------------------------

	/**
	 * Asserts POST /auth/device returns device flow data on success.
	 *
	 * @return void
	 */
	public function test_start_device_flow_returns_200_on_success(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$payload = wp_json_encode(
			[
				'device_code'               => 'dc-rest',
				'user_code'                 => 'REST-CODE',
				'verification_uri'          => 'https://telex.automattic.ai/activate',
				'verification_uri_complete' => 'https://telex.automattic.ai/activate?code=REST-CODE',
				'expires_in'                => 300,
				'interval'                  => 5,
			]
		);

		add_filter(
			'pre_http_request',
			static fn() => [
				'headers'       => new \Requests_Utility_CaseInsensitiveDictionary( [] ),
				'body'          => (string) $payload,
				'response'      => [
					'code'    => 200,
					'message' => 'OK',
				],
				'cookies'       => [],
				'http_response' => null,
			],
			10,
			3
		);

		$request  = new WP_REST_Request( 'POST', '/telex/v1/auth/device' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'REST-CODE', $data['user_code'] );
	}

	/**
	 * Asserts POST /auth/device returns 502 when the device server is unreachable.
	 *
	 * @return void
	 */
	public function test_start_device_flow_returns_502_on_upstream_failure(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		add_filter(
			'pre_http_request',
			static fn() => [
				'headers'       => new \Requests_Utility_CaseInsensitiveDictionary( [] ),
				'body'          => (string) wp_json_encode( [ 'message' => 'Server error' ] ),
				'response'      => [
					'code'    => 500,
					'message' => 'Internal Server Error',
				],
				'cookies'       => [],
				'http_response' => null,
			],
			10,
			3
		);

		$request  = new WP_REST_Request( 'POST', '/telex/v1/auth/device' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 502, $response->get_status() );
	}
}
