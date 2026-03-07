<?php
/**
 * Tests for the webhook_deploy() endpoint.
 *
 * Covers HMAC-SHA256 signature validation, replay-window protection,
 * per-IP rate limiting, and the happy path through to the installer.
 *
 * @package Dispatch_For_Telex
 */

/**
 * Integration tests for the POST /telex/v1/deploy webhook endpoint.
 */
class Test_Telex_REST_Webhook extends WP_UnitTestCase {

	/**
	 * REST server instance under test.
	 *
	 * @var WP_REST_Server
	 */
	private WP_REST_Server $server;

	/**
	 * Known deploy secret seeded for each test.
	 *
	 * @var string
	 */
	private string $secret = 'test-webhook-secret-64hex-value-here1234567890abcdef1234567890abc';

	/**
	 * IP address used for rate-limit tests.
	 *
	 * @var string
	 */
	private string $test_ip = '10.0.0.77';

	/**
	 * Initialise a fresh REST server, seed the deploy secret, and reset state.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );

		// Seed a known deploy secret so tests can compute correct HMACs.
		update_option( 'dispatch_deploy_secret', $this->secret, false );

		// Set a deterministic remote IP for rate-limit tests.
		$_SERVER['REMOTE_ADDR'] = $this->test_ip;

		// Clear any leftover rate-limit transients for this IP.
		$this->clear_rate_limit_transient();
		delete_option( 'telex_auth_token' );
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Destroy the REST server and clean up after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		$this->clear_rate_limit_transient();
		delete_option( 'dispatch_deploy_secret' );
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Builds a signed POST /telex/v1/deploy request.
	 *
	 * @param array<string,mixed> $body_params  JSON body fields.
	 * @param string|null         $override_sig Replace the computed signature with this value.
	 * @return WP_REST_Request
	 */
	private function make_signed_request( array $body_params, ?string $override_sig = null ): WP_REST_Request {
		$body = (string) wp_json_encode( $body_params );
		$sig  = $override_sig ?? hash_hmac( 'sha256', $body, $this->secret );

		$request = new WP_REST_Request( 'POST', '/telex/v1/deploy' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-Telex-Signature', 'sha256=' . $sig );
		$request->set_body( $body );

		return $request;
	}

	/**
	 * Returns the transient key used for webhook per-IP rate limiting.
	 *
	 * @return string
	 */
	private function rate_limit_key(): string {
		return 'telex_wh_rl_' . substr( hash( 'sha256', $this->test_ip ), 0, 32 );
	}

	/**
	 * Removes the per-IP rate-limit transient seeded during tests.
	 *
	 * @return void
	 */
	private function clear_rate_limit_transient(): void {
		delete_transient( $this->rate_limit_key() );
	}

	// -------------------------------------------------------------------------
	// Signature validation
	// -------------------------------------------------------------------------

	/**
	 * Asserts 401 is returned when the X-Telex-Signature header is absent.
	 *
	 * @return void
	 */
	public function test_missing_signature_returns_401(): void {
		$request = new WP_REST_Request( 'POST', '/telex/v1/deploy' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				[
					'project_id' => 'p1',
					'timestamp'  => time(),
				]
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'telex_no_signature', $response->get_data()['code'] );
	}

	/**
	 * Asserts 401 is returned when the signature header is present but not sha256= prefixed.
	 *
	 * @return void
	 */
	public function test_malformed_signature_returns_401(): void {
		$request = new WP_REST_Request( 'POST', '/telex/v1/deploy' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-Telex-Signature', 'md5=abc123' );
		$request->set_body(
			(string) wp_json_encode(
				[
					'project_id' => 'p1',
					'timestamp'  => time(),
				]
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'telex_no_signature', $response->get_data()['code'] );
	}

	/**
	 * Asserts 401 is returned when the HMAC digest does not match the expected value.
	 *
	 * @return void
	 */
	public function test_incorrect_hmac_returns_401(): void {
		$body_params = [
			'project_id' => 'p1',
			'timestamp'  => time(),
		];
		$request     = $this->make_signed_request( $body_params, str_repeat( 'a', 64 ) );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'telex_bad_signature', $response->get_data()['code'] );
	}

	// -------------------------------------------------------------------------
	// Replay protection
	// -------------------------------------------------------------------------

	/**
	 * Asserts 400 is returned when the timestamp field is absent from the body.
	 *
	 * @return void
	 */
	public function test_missing_timestamp_returns_400(): void {
		$request = $this->make_signed_request( [ 'project_id' => 'p1' ] );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'telex_replay', $response->get_data()['code'] );
	}

	/**
	 * Asserts 400 is returned when the timestamp is outside the 5-minute replay window.
	 *
	 * @return void
	 */
	public function test_expired_timestamp_returns_400(): void {
		$stale_timestamp = time() - 400; // Beyond the 300-second window.
		$request         = $this->make_signed_request(
			[
				'project_id' => 'p1',
				'timestamp'  => $stale_timestamp,
			]
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'telex_replay', $response->get_data()['code'] );
	}

	/**
	 * Asserts 400 is returned when project_id is absent despite a valid signature.
	 *
	 * @return void
	 */
	public function test_missing_project_id_returns_400(): void {
		$request = $this->make_signed_request( [ 'timestamp' => time() ] );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'telex_missing_id', $response->get_data()['code'] );
	}

	// -------------------------------------------------------------------------
	// Happy path — reaches installer
	// -------------------------------------------------------------------------

	/**
	 * Asserts a fully valid request passes all security checks and reaches the installer.
	 *
	 * When not connected to Telex, the installer returns telex_not_connected (401).
	 * The important assertion is that the error code is NOT from the signature/replay
	 * checks, proving those checks passed cleanly.
	 *
	 * @return void
	 */
	public function test_valid_request_passes_security_checks_and_reaches_installer(): void {
		$request = $this->make_signed_request(
			[
				'project_id' => 'my-project-123',
				'timestamp'  => time(),
			]
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		// Must not be a signature or replay error.
		$this->assertNotEquals( 'telex_no_signature', $data['code'] ?? '' );
		$this->assertNotEquals( 'telex_bad_signature', $data['code'] ?? '' );
		$this->assertNotEquals( 'telex_replay', $data['code'] ?? '' );

		// With no Telex connection the installer returns not_connected (mapped to 401).
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'telex_not_connected', $data['code'] );
	}

	// -------------------------------------------------------------------------
	// Per-IP rate limiting
	// -------------------------------------------------------------------------

	/**
	 * Asserts 429 is returned when the per-IP request count exceeds the limit.
	 *
	 * The rate-limit transient is seeded directly to avoid making 10 real requests.
	 *
	 * @return void
	 */
	public function test_rate_limit_returns_429_when_count_exceeds_threshold(): void {
		// Seed the transient to the maximum allowed count (10).
		set_transient( $this->rate_limit_key(), 10, 60 );

		$request = $this->make_signed_request(
			[
				'project_id' => 'any',
				'timestamp'  => time(),
			]
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'telex_rate_limit', $response->get_data()['code'] );
	}
}
