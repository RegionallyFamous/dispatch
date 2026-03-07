<?php
/**
 * Tests for Telex_Auth — encryption, connection state, rate limiting, and device flow.
 *
 * @package Dispatch_For_Telex
 */

/**
 * Unit tests for the Telex_Auth class.
 */
class Test_Telex_Auth extends WP_UnitTestCase {

	/**
	 * Reset auth state before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'telex_auth_token' );
		delete_transient( Telex_Auth::TRANSIENT_DEVICE );
		// Remove any lingering pre_http_request filters added by device flow tests.
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Asserts is_connected() returns false with no token stored.
	 *
	 * @return void
	 */
	public function test_not_connected_when_no_token(): void {
		$this->assertFalse( Telex_Auth::is_connected() );
	}

	/**
	 * Asserts token can be stored and retrieved.
	 *
	 * @return void
	 */
	public function test_store_and_retrieve_token(): void {
		Telex_Auth::store_token( 'test-access-token-123' );
		$this->assertTrue( Telex_Auth::is_connected() );
		$this->assertSame( 'test-access-token-123', Telex_Auth::get_token() );
	}

	/**
	 * Asserts the original token is faithfully returned after encrypt/decrypt.
	 *
	 * @return void
	 */
	public function test_token_survives_round_trip_encryption(): void {
		$original = 'Bearer eyJhbGciOiJSUzI1NiJ9.test.signature';
		Telex_Auth::store_token( $original );
		$retrieved = Telex_Auth::get_token();
		$this->assertSame( $original, $retrieved );
	}

	/**
	 * Asserts the stored option value is AES-GCM ciphertext, not plaintext.
	 *
	 * @return void
	 */
	public function test_stored_value_is_not_plaintext(): void {
		Telex_Auth::store_token( 'secret-token' );
		$stored = get_option( 'telex_auth_token' );
		$this->assertStringStartsWith( 'v2:', $stored, 'Token must be stored in v2: GCM format.' );
		$this->assertStringNotContainsString( 'secret-token', $stored );
	}

	/**
	 * Asserts disconnect() clears the stored token.
	 *
	 * @return void
	 */
	public function test_disconnect_clears_token(): void {
		Telex_Auth::store_token( 'some-token' );
		Telex_Auth::disconnect();
		$this->assertFalse( Telex_Auth::is_connected() );
		$this->assertSame( '', Telex_Auth::get_token() );
	}

	/**
	 * Asserts get_status() returns the correct AuthStatus enum value.
	 *
	 * @return void
	 */
	public function test_get_status_returns_enum(): void {
		$this->assertSame( AuthStatus::Disconnected, Telex_Auth::get_status() );
		Telex_Auth::store_token( 'tok' );
		$this->assertSame( AuthStatus::Connected, Telex_Auth::get_status() );
	}

	/**
	 * Asserts get_client() returns null when not connected.
	 *
	 * @return void
	 */
	public function test_get_client_returns_null_when_disconnected(): void {
		$this->assertNull( Telex_Auth::get_client() );
	}

	/**
	 * Encryption produces different ciphertext each time (random IV).
	 *
	 * @return void
	 */
	public function test_each_encryption_produces_unique_ciphertext(): void {
		$plaintext = 'same-token-value';
		Telex_Auth::store_token( $plaintext );
		$first = get_option( 'telex_auth_token' );

		Telex_Auth::store_token( $plaintext );
		$second = get_option( 'telex_auth_token' );

		$this->assertNotSame( $first, $second, 'GCM should produce unique ciphertexts due to random IVs.' );
	}

	/**
	 * Asserts get_token() returns empty string for a corrupted stored value.
	 *
	 * @return void
	 */
	public function test_returns_empty_string_for_corrupted_token(): void {
		update_option( 'telex_auth_token', 'not-valid-encrypted-data', false );
		$this->assertSame( '', Telex_Auth::get_token() );
	}

	/**
	 * Asserts disconnect() also deletes the device code transient.
	 *
	 * @return void
	 */
	public function test_disconnect_clears_device_transient(): void {
		set_transient( Telex_Auth::TRANSIENT_DEVICE, 'device-code-456', 300 );
		Telex_Auth::disconnect();
		$this->assertFalse( get_transient( Telex_Auth::TRANSIENT_DEVICE ) );
	}

	// -------------------------------------------------------------------------
	// Rate limiting
	// -------------------------------------------------------------------------

	/**
	 * Asserts check_rate_limit() returns 0 on the first call for a given action.
	 *
	 * @return void
	 */
	public function test_rate_limit_returns_zero_on_first_call(): void {
		wp_set_current_user( self::factory()->user->create() );
		$result = Telex_Auth::check_rate_limit( 'test_action_' . wp_generate_uuid4() );
		$this->assertSame( 0, $result );
	}

	/**
	 * Asserts check_rate_limit() returns 0 when under the 10-request threshold.
	 *
	 * @return void
	 */
	public function test_rate_limit_returns_zero_within_window(): void {
		wp_set_current_user( self::factory()->user->create() );
		$action = 'test_under_limit_' . wp_generate_uuid4();

		for ( $i = 0; $i < 9; $i++ ) {
			$result = Telex_Auth::check_rate_limit( $action );
			$this->assertSame( 0, $result, "Call $i should be within limit." );
		}
	}

	/**
	 * Asserts check_rate_limit() returns a positive integer on the 11th call (violation).
	 *
	 * @return void
	 */
	public function test_rate_limit_returns_positive_on_violation(): void {
		wp_set_current_user( self::factory()->user->create() );
		$action = 'test_over_limit_' . wp_generate_uuid4();

		// Exhaust the 10-request allowance.
		for ( $i = 0; $i < 10; $i++ ) {
			Telex_Auth::check_rate_limit( $action );
		}

		// The 11th call should be rejected.
		$retry_after = Telex_Auth::check_rate_limit( $action );
		$this->assertGreaterThan( 0, $retry_after );
	}

	/**
	 * Asserts rate limits are scoped per user — different users have independent windows.
	 *
	 * @return void
	 */
	public function test_rate_limit_is_scoped_per_user(): void {
		$user_a = self::factory()->user->create();
		$user_b = self::factory()->user->create();
		$action = 'test_per_user_' . wp_generate_uuid4();

		// Exhaust user A's limit.
		wp_set_current_user( $user_a );
		for ( $i = 0; $i < 11; $i++ ) {
			Telex_Auth::check_rate_limit( $action );
		}

		// User B should still be within their own limit.
		wp_set_current_user( $user_b );
		$result = Telex_Auth::check_rate_limit( $action );
		$this->assertSame( 0, $result );
	}

	// -------------------------------------------------------------------------
	// start_device_flow — HTTP mocking via pre_http_request
	// -------------------------------------------------------------------------

	/**
	 * Returns a mock wp_remote_request response array.
	 *
	 * @param int    $code HTTP status code.
	 * @param string $body JSON-encoded response body.
	 * @return array<string, mixed>
	 */
	private function make_http_response( int $code, string $body ): array {
		// WP 6.4+ moved the Requests library to the WpOrg namespace.
		$dict_class = class_exists( 'WpOrg\Requests\Utility\CaseInsensitiveDictionary' )
			? 'WpOrg\Requests\Utility\CaseInsensitiveDictionary'
			: 'Requests_Utility_CaseInsensitiveDictionary';

		return [
			'headers'       => new $dict_class( [] ),
			'body'          => $body,
			'response'      => [
				'code'    => $code,
				'message' => 'OK',
			],
			'cookies'       => [],
			'http_response' => null,
		];
	}

	/**
	 * Asserts start_device_flow() returns the expected array on a successful HTTP response.
	 *
	 * @return void
	 */
	public function test_start_device_flow_returns_array_on_success(): void {
		$payload = wp_json_encode(
			[
				'device_code'               => 'dev-code-abc',
				'user_code'                 => 'ABCD-1234',
				'verification_uri'          => 'https://telex.automattic.ai/activate',
				'verification_uri_complete' => 'https://telex.automattic.ai/activate?code=ABCD-1234',
				'expires_in'                => 300,
				'interval'                  => 5,
			]
		);

		add_filter(
			'pre_http_request',
			fn() => $this->make_http_response( 200, (string) $payload ),
			10,
			3
		);

		$result = Telex_Auth::start_device_flow();

		$this->assertIsArray( $result );
		$this->assertSame( 'ABCD-1234', $result['user_code'] );
		$this->assertSame( 'https://telex.automattic.ai/activate', $result['verification_uri'] );
		$this->assertSame( 300, $result['expires_in'] );
		$this->assertSame( 5, $result['interval'] );
	}

	/**
	 * Asserts start_device_flow() stores the device code as a transient on success.
	 *
	 * @return void
	 */
	public function test_start_device_flow_stores_device_code_transient(): void {
		$payload = wp_json_encode(
			[
				'device_code'               => 'stored-dev-code',
				'user_code'                 => 'WXYZ-5678',
				'verification_uri'          => 'https://telex.automattic.ai/activate',
				'verification_uri_complete' => 'https://telex.automattic.ai/activate?code=WXYZ-5678',
				'expires_in'                => 300,
				'interval'                  => 5,
			]
		);

		add_filter(
			'pre_http_request',
			fn() => $this->make_http_response( 200, (string) $payload ),
			10,
			3
		);

		Telex_Auth::start_device_flow();

		$this->assertSame( 'stored-dev-code', get_transient( Telex_Auth::TRANSIENT_DEVICE ) );
	}

	/**
	 * Asserts start_device_flow() returns a WP_Error when the HTTP response is a failure.
	 *
	 * @return void
	 */
	public function test_start_device_flow_returns_wp_error_on_non_200(): void {
		add_filter(
			'pre_http_request',
			fn() => $this->make_http_response( 503, (string) wp_json_encode( [ 'message' => 'Service unavailable' ] ) ),
			10,
			3
		);

		$result = Telex_Auth::start_device_flow();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'telex_device_start', $result->get_error_code() );
	}

	/**
	 * Asserts start_device_flow() returns a WP_Error when the device_code is missing.
	 *
	 * @return void
	 */
	public function test_start_device_flow_returns_wp_error_when_device_code_missing(): void {
		add_filter(
			'pre_http_request',
			fn() => $this->make_http_response( 200, (string) wp_json_encode( [ 'some_other_field' => 'value' ] ) ),
			10,
			3
		);

		$result = Telex_Auth::start_device_flow();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'telex_device_start', $result->get_error_code() );
	}

	/**
	 * Asserts start_device_flow() returns a WP_Error on a network error.
	 *
	 * @return void
	 */
	public function test_start_device_flow_returns_wp_error_on_network_error(): void {
		add_filter(
			'pre_http_request',
			fn() => new WP_Error( 'http_request_failed', 'cURL error 28: Connection timed out' ),
			10,
			3
		);

		$result = Telex_Auth::start_device_flow();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'telex_network', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// poll_device_flow — RFC 8628 §3.5 response states
	// -------------------------------------------------------------------------

	/**
	 * Asserts poll_device_flow() returns true and stores the token on success.
	 *
	 * @return void
	 */
	public function test_poll_device_flow_returns_true_and_stores_token_on_authorized(): void {
		$payload = wp_json_encode( [ 'access_token' => 'my-access-tok' ] );

		add_filter(
			'pre_http_request',
			fn() => $this->make_http_response( 200, (string) $payload ),
			10,
			3
		);

		set_transient( Telex_Auth::TRANSIENT_DEVICE, 'dev-code', 300 );

		$result = Telex_Auth::poll_device_flow( 'dev-code' );

		$this->assertTrue( $result );
		$this->assertTrue( Telex_Auth::is_connected() );
		$this->assertSame( 'my-access-tok', Telex_Auth::get_token() );
	}

	/**
	 * Asserts poll_device_flow() clears the device transient on successful authorization.
	 *
	 * @return void
	 */
	public function test_poll_device_flow_clears_device_transient_on_success(): void {
		set_transient( Telex_Auth::TRANSIENT_DEVICE, 'dev-code', 300 );

		add_filter(
			'pre_http_request',
			fn() => $this->make_http_response( 200, (string) wp_json_encode( [ 'access_token' => 'tok' ] ) ),
			10,
			3
		);

		Telex_Auth::poll_device_flow( 'dev-code' );

		$this->assertFalse( get_transient( Telex_Auth::TRANSIENT_DEVICE ) );
	}

	/**
	 * Asserts poll_device_flow() returns a pending array for authorization_pending.
	 *
	 * @return void
	 */
	public function test_poll_device_flow_returns_pending_array(): void {
		$payload = wp_json_encode( [ 'error' => 'authorization_pending' ] );

		add_filter(
			'pre_http_request',
			fn() => $this->make_http_response( 400, (string) $payload ),
			10,
			3
		);

		$result = Telex_Auth::poll_device_flow( 'dev-code' );

		$this->assertIsArray( $result );
		$this->assertSame( 'pending', $result['status'] );
	}

	/**
	 * Asserts poll_device_flow() returns a slow_down array with an adjusted interval.
	 *
	 * @return void
	 */
	public function test_poll_device_flow_returns_slow_down_with_adjusted_interval(): void {
		$payload = wp_json_encode(
			[
				'error'    => 'slow_down',
				'interval' => 5,
			]
		);

		add_filter(
			'pre_http_request',
			fn() => $this->make_http_response( 400, (string) $payload ),
			10,
			3
		);

		$result = Telex_Auth::poll_device_flow( 'dev-code' );

		$this->assertIsArray( $result );
		$this->assertSame( 'slow_down', $result['status'] );
		// RFC 8628 §3.5: slow_down MUST increase interval by 5 seconds.
		$this->assertSame( 10, $result['interval'] );
	}

	/**
	 * Asserts poll_device_flow() returns WP_Error and clears transient on expired_token.
	 *
	 * @return void
	 */
	public function test_poll_device_flow_returns_wp_error_on_expired_token(): void {
		set_transient( Telex_Auth::TRANSIENT_DEVICE, 'dev-code', 300 );

		$payload = wp_json_encode(
			[
				'error'             => 'expired_token',
				'error_description' => 'Device code has expired.',
			]
		);

		add_filter(
			'pre_http_request',
			fn() => $this->make_http_response( 400, (string) $payload ),
			10,
			3
		);

		$result = Telex_Auth::poll_device_flow( 'dev-code' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'telex_device_expired_token', $result->get_error_code() );
		$this->assertFalse( get_transient( Telex_Auth::TRANSIENT_DEVICE ) );
	}

	/**
	 * Asserts poll_device_flow() returns WP_Error and clears transient on access_denied.
	 *
	 * @return void
	 */
	public function test_poll_device_flow_returns_wp_error_on_access_denied(): void {
		set_transient( Telex_Auth::TRANSIENT_DEVICE, 'dev-code', 300 );

		$payload = wp_json_encode(
			[
				'error'             => 'access_denied',
				'error_description' => 'User denied access.',
			]
		);

		add_filter(
			'pre_http_request',
			fn() => $this->make_http_response( 400, (string) $payload ),
			10,
			3
		);

		$result = Telex_Auth::poll_device_flow( 'dev-code' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'telex_device_access_denied', $result->get_error_code() );
		$this->assertFalse( get_transient( Telex_Auth::TRANSIENT_DEVICE ) );
	}

	/**
	 * Asserts poll_device_flow() returns WP_Error on a network error.
	 *
	 * @return void
	 */
	public function test_poll_device_flow_returns_wp_error_on_network_error(): void {
		add_filter(
			'pre_http_request',
			fn() => new WP_Error( 'http_request_failed', 'Connection refused' ),
			10,
			3
		);

		$result = Telex_Auth::poll_device_flow( 'dev-code' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'telex_network', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// CBC legacy migration
	// -------------------------------------------------------------------------

	/**
	 * Asserts get_token() migrates a legacy CBC-encrypted token to GCM format on first read.
	 *
	 * The legacy format stores base64( iv . '::' . ciphertext ) without the v2: prefix.
	 *
	 * @return void
	 */
	public function test_get_token_migrates_legacy_cbc_format(): void {
		$plaintext = 'legacy-access-token';

		// Derive the same key Telex_Auth uses internally.
		$key = hash( 'sha256', wp_salt( 'auth' ), true );

		// CBC requires a 16-byte IV.
		$iv         = openssl_random_pseudo_bytes( 16 );
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		$this->assertNotFalse( $ciphertext, 'Test prerequisite: CBC encryption must succeed.' );

		// Encode in legacy format: base64( iv . '::' . ciphertext ).
		$legacy_encoded = base64_encode( $iv . '::' . $ciphertext );

		// Store the legacy-format value directly in the option, bypassing encrypt().
		update_option( 'telex_auth_token', $legacy_encoded, false );

		// get_token() should decrypt the legacy format and return the plaintext.
		$retrieved = Telex_Auth::get_token();
		$this->assertSame( $plaintext, $retrieved );

		// After migration the stored value must be in v2: GCM format.
		$stored_after = get_option( 'telex_auth_token' );
		$this->assertStringStartsWith( 'v2:', $stored_after );

		Telex_Auth::disconnect();
	}

	// -------------------------------------------------------------------------
	// get_client — connected path
	// -------------------------------------------------------------------------

	/**
	 * Asserts get_client() returns a TelexClient instance when connected and circuit is closed.
	 *
	 * @return void
	 */
	public function test_get_client_returns_instance_when_connected_and_circuit_closed(): void {
		Telex_Auth::store_token( 'valid-access-token' );

		// Ensure the circuit breaker is in the closed (available) state.
		Telex_Circuit_Breaker::record_success();

		$client = Telex_Auth::get_client();

		$this->assertInstanceOf( \Telex\Sdk\TelexClient::class, $client );

		Telex_Auth::disconnect();
	}
}
