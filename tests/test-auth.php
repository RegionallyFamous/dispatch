<?php
/**
 * Tests for Telex_Auth — encryption and connection state.
 */
class Test_Telex_Auth extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( 'telex_auth_token' );
		delete_transient( Telex_Auth::TRANSIENT_DEVICE );
	}

	public function test_not_connected_when_no_token(): void {
		$this->assertFalse( Telex_Auth::is_connected() );
	}

	public function test_store_and_retrieve_token(): void {
		Telex_Auth::store_token( 'test-access-token-123' );
		$this->assertTrue( Telex_Auth::is_connected() );
		$this->assertSame( 'test-access-token-123', Telex_Auth::get_token() );
	}

	public function test_token_survives_round_trip_encryption(): void {
		$original = 'Bearer eyJhbGciOiJSUzI1NiJ9.test.signature';
		Telex_Auth::store_token( $original );
		$retrieved = Telex_Auth::get_token();
		$this->assertSame( $original, $retrieved );
	}

	public function test_stored_value_is_not_plaintext(): void {
		Telex_Auth::store_token( 'secret-token' );
		$stored = get_option( 'telex_auth_token' );
		$this->assertStringStartsWith( 'v2:', $stored, 'Token must be stored in v2: GCM format.' );
		$this->assertStringNotContainsString( 'secret-token', $stored );
	}

	public function test_disconnect_clears_token(): void {
		Telex_Auth::store_token( 'some-token' );
		Telex_Auth::disconnect();
		$this->assertFalse( Telex_Auth::is_connected() );
		$this->assertSame( '', Telex_Auth::get_token() );
	}

	public function test_get_status_returns_enum(): void {
		$this->assertSame( AuthStatus::Disconnected, Telex_Auth::get_status() );
		Telex_Auth::store_token( 'tok' );
		$this->assertSame( AuthStatus::Connected, Telex_Auth::get_status() );
	}

	public function test_get_client_returns_null_when_disconnected(): void {
		$this->assertNull( Telex_Auth::get_client() );
	}

	/**
	 * Encryption produces different ciphertext each time (random IV).
	 */
	public function test_each_encryption_produces_unique_ciphertext(): void {
		$plaintext = 'same-token-value';
		Telex_Auth::store_token( $plaintext );
		$first = get_option( 'telex_auth_token' );

		Telex_Auth::store_token( $plaintext );
		$second = get_option( 'telex_auth_token' );

		$this->assertNotSame( $first, $second, 'GCM should produce unique ciphertexts due to random IVs.' );
	}

	public function test_returns_empty_string_for_corrupted_token(): void {
		update_option( 'telex_auth_token', 'not-valid-encrypted-data', false );
		$this->assertSame( '', Telex_Auth::get_token() );
	}
}
