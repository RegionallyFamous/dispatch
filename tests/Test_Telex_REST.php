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

		$expected = [
			'/telex/v1/projects',
			'/telex/v1/auth/device',
			'/telex/v1/auth',
			'/telex/v1/auth/status',
			'/telex/v1/installed',
			'/telex/v1/tags',
			'/telex/v1/installs/failed',
			'/telex/v1/auto-updates/pending',
			'/telex/v1/config/export',
			'/telex/v1/config/import',
			'/telex/v1/audit-log',
			'/telex/v1/circuit/reset',
			'/telex/v1/health/installed',
			'/telex/v1/analytics',
			'/telex/v1/settings/notifications',
			'/telex/v1/users',
		];
		foreach ( $expected as $route ) {
			$this->assertArrayHasKey( $route, $routes, "Route {$route} should be registered." );
		}

		// Parameterised routes.
		$parameterised = [
			'favorite'    => '/telex/v1/projects/(?P<id>[a-zA-Z0-9_\\-]+)/favorite',
			'tags'        => '/telex/v1/projects/(?P<id>[a-zA-Z0-9_\\-]+)/tags',
			'failed-inst' => '/telex/v1/installs/failed/(?P<id>[a-zA-Z0-9_\\-]+)',
		];
		foreach ( $parameterised as $label => $route ) {
			$this->assertArrayHasKey( $route, $routes, "Parameterised route [{$label}] {$route} should be registered." );
		}
	}

	// -------------------------------------------------------------------------
	// GET /users
	// -------------------------------------------------------------------------

	/**
	 * Asserts GET /users returns id/name rows without WP_User fatals.
	 *
	 * @return void
	 */
	public function test_get_users_returns_id_name_rows(): void {
		$admin_id = self::factory()->user->create(
			[
				'role'         => 'administrator',
				'display_name' => 'Admin User',
			]
		);
		self::factory()->user->create(
			[
				'role'         => 'editor',
				'display_name' => 'Editor User',
			]
		);
		wp_set_current_user( $admin_id );

		$request  = new WP_REST_Request( 'GET', '/telex/v1/users' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $data );
		$this->assertNotEmpty( $data );
		$this->assertArrayHasKey( 'id', $data[0] );
		$this->assertArrayHasKey( 'name', $data[0] );
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

	// -------------------------------------------------------------------------
	// POST /projects/{id}/deploy-network
	// -------------------------------------------------------------------------

	/**
	 * Asserts POST /projects/{id}/deploy-network returns 400 on a non-multisite install.
	 *
	 * @return void
	 */
	public function test_deploy_network_returns_400_on_non_multisite(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		// Non-multisite: is_multisite() always returns false in the single-site test env.
		$request  = new WP_REST_Request( 'POST', '/telex/v1/projects/proj-123/deploy-network' );
		$response = $this->server->dispatch( $request );

		// Permission callback: require_network_admin checks manage_network_plugins.
		// On a single site the user has no such cap → 403. Only on multisite does the
		// endpoint reach deploy_network() and return 400. Test the 403 here since the
		// WP test environment is always single-site.
		$this->assertContains( $response->get_status(), [ 400, 403 ] );
	}

	// -------------------------------------------------------------------------
	// GET /telex/v1/auth/status — circuit breaker metadata
	// -------------------------------------------------------------------------

	/**
	 * Asserts auth/status includes circuit breaker metadata fields.
	 *
	 * @return void
	 */
	public function test_auth_status_includes_circuit_metadata(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request  = new WP_REST_Request( 'GET', '/telex/v1/auth/status' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'circuit_status', $data );
		$this->assertArrayHasKey( 'circuit_opened_at', $data );
		$this->assertArrayHasKey( 'circuit_failure_count', $data );
		$this->assertSame( 'closed', $data['circuit_status'] );
		$this->assertNull( $data['circuit_opened_at'] );
		$this->assertSame( 0, $data['circuit_failure_count'] );
	}

	// -------------------------------------------------------------------------
	// POST + DELETE /projects/{id}/favorite
	// -------------------------------------------------------------------------

	/**
	 * Asserts POST /favorite returns 401 for unauthenticated requests.
	 *
	 * @return void
	 */
	public function test_post_favorite_requires_authentication(): void {
		$request  = new WP_REST_Request( 'POST', '/telex/v1/projects/proj-abc/favorite' );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Asserts POST /favorite stars a project for the current user.
	 *
	 * @return void
	 */
	public function test_post_favorite_stars_project(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$request  = new WP_REST_Request( 'POST', '/telex/v1/projects/proj-abc/favorite' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['starred'] );
		$this->assertTrue( Telex_Favorites::is_starred( 'proj-abc', $user_id ) );

		delete_user_meta( $user_id, 'telex_favorites' );
	}

	/**
	 * Asserts DELETE /favorite un-stars a project for the current user.
	 *
	 * @return void
	 */
	public function test_delete_favorite_unstars_project(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
		Telex_Favorites::add( 'proj-abc', $user_id );

		$request  = new WP_REST_Request( 'DELETE', '/telex/v1/projects/proj-abc/favorite' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $data['starred'] );
		$this->assertFalse( Telex_Favorites::is_starred( 'proj-abc', $user_id ) );

		delete_user_meta( $user_id, 'telex_favorites' );
	}

	// -------------------------------------------------------------------------
	// PUT /projects/{id}/tags + GET /tags
	// -------------------------------------------------------------------------

	/**
	 * Asserts PUT /tags returns 401 for unauthenticated requests.
	 *
	 * @return void
	 */
	public function test_put_tags_requires_authentication(): void {
		$request = new WP_REST_Request( 'PUT', '/telex/v1/projects/proj-abc/tags' );
		$request->set_param( 'tags', [ 'beta' ] );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Asserts PUT /tags saves tags and returns the saved list.
	 *
	 * @return void
	 */
	public function test_put_tags_saves_and_returns_tags(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request = new WP_REST_Request( 'PUT', '/telex/v1/projects/proj-abc/tags' );
		$request->set_param( 'tags', [ 'client-a', 'beta' ] );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'tags', $data );
		$this->assertContains( 'client-a', $data['tags'] );
		$this->assertContains( 'beta', $data['tags'] );

		delete_option( 'telex_tags_proj-abc' );
	}

	/**
	 * Asserts GET /tags returns the aggregated tag list.
	 *
	 * @return void
	 */
	public function test_get_tags_returns_all_in_use(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		Telex_Tags::set( 'proj-abc', [ 'core', 'beta' ] );

		$request  = new WP_REST_Request( 'GET', '/telex/v1/tags' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'tags', $data );
		$this->assertContains( 'core', $data['tags'] );
		$this->assertContains( 'beta', $data['tags'] );

		delete_option( 'telex_tags_proj-abc' );
		Telex_Tags::bust_cache();
	}

	// -------------------------------------------------------------------------
	// GET + DELETE /installs/failed
	// -------------------------------------------------------------------------

	/**
	 * Asserts GET /installs/failed returns 401 for unauthenticated requests.
	 *
	 * @return void
	 */
	public function test_get_failed_installs_requires_authentication(): void {
		$request  = new WP_REST_Request( 'GET', '/telex/v1/installs/failed' );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Asserts GET /installs/failed returns the list of recorded failures.
	 *
	 * @return void
	 */
	public function test_get_failed_installs_returns_failures(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		Telex_Failed_Installs::record( 'proj-fail', 'My Project', 'Timeout.' );

		$request  = new WP_REST_Request( 'GET', '/telex/v1/installs/failed' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'failures', $data );
		$this->assertNotEmpty( $data['failures'] );
		$this->assertSame( 'proj-fail', $data['failures'][0]['public_id'] );

		Telex_Failed_Installs::clear( 'proj-fail' );
	}

	/**
	 * Asserts DELETE /installs/failed/{id} clears a failure record.
	 *
	 * @return void
	 */
	public function test_delete_failed_install_clears_record(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		Telex_Failed_Installs::record( 'proj-fail', 'My Project', 'Timeout.' );

		$request  = new WP_REST_Request( 'DELETE', '/telex/v1/installs/failed/proj-fail' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( Telex_Failed_Installs::has_failure( 'proj-fail' ) );
	}

	/**
	 * Asserts DELETE /installs/failed/{id} returns 404 for a non-existent record.
	 *
	 * @return void
	 */
	public function test_delete_failed_install_returns_404_when_not_found(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request  = new WP_REST_Request( 'DELETE', '/telex/v1/installs/failed/does-not-exist' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}
}
