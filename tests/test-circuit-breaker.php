<?php
/**
 * Tests for Telex_Circuit_Breaker — state machine, transitions, and probe logic.
 *
 * @package Dispatch_For_Telex
 */

/**
 * Unit tests for the Telex_Circuit_Breaker class.
 */
class Test_Telex_Circuit_Breaker extends WP_UnitTestCase {

	/**
	 * Reset the circuit breaker to a clean state before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		Telex_Circuit_Breaker::reset();
	}

	// -------------------------------------------------------------------------
	// CLOSED state
	// -------------------------------------------------------------------------

	/**
	 * Asserts is_available() returns true when the circuit is freshly initialised.
	 *
	 * @return void
	 */
	public function test_is_available_returns_true_when_closed(): void {
		$this->assertTrue( Telex_Circuit_Breaker::is_available() );
	}

	/**
	 * Asserts status() reports 'closed' when no failures have been recorded.
	 *
	 * @return void
	 */
	public function test_status_is_closed_initially(): void {
		$this->assertSame( 'closed', Telex_Circuit_Breaker::status() );
	}

	/**
	 * Asserts failures below the threshold keep the circuit closed.
	 *
	 * @return void
	 */
	public function test_failures_below_threshold_keep_circuit_closed(): void {
		// Threshold is 5; record 4 failures.
		for ( $i = 0; $i < 4; $i++ ) {
			Telex_Circuit_Breaker::record_failure();
		}
		$this->assertTrue( Telex_Circuit_Breaker::is_available() );
		$this->assertSame( 'closed', Telex_Circuit_Breaker::status() );
	}

	// -------------------------------------------------------------------------
	// OPEN state
	// -------------------------------------------------------------------------

	/**
	 * Asserts is_available() returns false after hitting the failure threshold.
	 *
	 * @return void
	 */
	public function test_is_available_returns_false_after_threshold(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			Telex_Circuit_Breaker::record_failure();
		}
		$this->assertFalse( Telex_Circuit_Breaker::is_available() );
	}

	/**
	 * Asserts status() reports 'open' after hitting the failure threshold.
	 *
	 * @return void
	 */
	public function test_status_is_open_after_threshold(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			Telex_Circuit_Breaker::record_failure();
		}
		$this->assertSame( 'open', Telex_Circuit_Breaker::status() );
	}

	/**
	 * Asserts the circuit does not re-open on additional failures once already open.
	 *
	 * @return void
	 */
	public function test_additional_failures_while_open_keep_circuit_open(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			Telex_Circuit_Breaker::record_failure();
		}
		$this->assertFalse( Telex_Circuit_Breaker::is_available() );
		$this->assertSame( 'open', Telex_Circuit_Breaker::status() );
	}

	// -------------------------------------------------------------------------
	// HALF-OPEN state
	// -------------------------------------------------------------------------

	/**
	 * Asserts status() reports 'half-open' once the reset timeout has elapsed.
	 *
	 * @return void
	 */
	public function test_status_is_half_open_after_reset_timeout(): void {
		// Open the circuit.
		for ( $i = 0; $i < 5; $i++ ) {
			Telex_Circuit_Breaker::record_failure();
		}

		// Backdate the opened_at transient to simulate reset timeout elapsed.
		set_transient( 'telex_cb_opened', time() - 61, 300 );

		$this->assertSame( 'half-open', Telex_Circuit_Breaker::status() );
	}

	/**
	 * Asserts is_available() returns true for the single probe once reset timeout has elapsed.
	 *
	 * @return void
	 */
	public function test_probe_allowed_after_reset_timeout(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			Telex_Circuit_Breaker::record_failure();
		}

		// Simulate reset timeout having elapsed.
		set_transient( 'telex_cb_opened', time() - 61, 300 );

		$this->assertTrue( Telex_Circuit_Breaker::is_available() );
	}

	/**
	 * Asserts only one probe is allowed through when in half-open state.
	 *
	 * @return void
	 */
	public function test_only_one_probe_allowed_in_half_open(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			Telex_Circuit_Breaker::record_failure();
		}
		set_transient( 'telex_cb_opened', time() - 61, 300 );

		// First call should be allowed (probe).
		$first = Telex_Circuit_Breaker::is_available();
		// Second call should be denied — probe is already in flight.
		$second = Telex_Circuit_Breaker::is_available();

		$this->assertTrue( $first );
		$this->assertFalse( $second );
	}

	/**
	 * Asserts record_failure() while half-open keeps the circuit open.
	 *
	 * @return void
	 */
	public function test_failure_in_half_open_keeps_circuit_open(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			Telex_Circuit_Breaker::record_failure();
		}
		set_transient( 'telex_cb_opened', time() - 61, 300 );

		// Allow probe through, then fail it.
		Telex_Circuit_Breaker::is_available();
		Telex_Circuit_Breaker::record_failure();

		$this->assertFalse( Telex_Circuit_Breaker::is_available() );
	}

	// -------------------------------------------------------------------------
	// record_success
	// -------------------------------------------------------------------------

	/**
	 * Asserts record_success() closes an open circuit.
	 *
	 * @return void
	 */
	public function test_record_success_closes_open_circuit(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			Telex_Circuit_Breaker::record_failure();
		}
		$this->assertFalse( Telex_Circuit_Breaker::is_available() );

		Telex_Circuit_Breaker::record_success();

		$this->assertTrue( Telex_Circuit_Breaker::is_available() );
		$this->assertSame( 'closed', Telex_Circuit_Breaker::status() );
	}

	/**
	 * Asserts record_success() also clears partial failure counts.
	 *
	 * @return void
	 */
	public function test_record_success_resets_failure_count(): void {
		// Record 4 failures (one below threshold).
		for ( $i = 0; $i < 4; $i++ ) {
			Telex_Circuit_Breaker::record_failure();
		}
		Telex_Circuit_Breaker::record_success();

		// After success, 5 more failures should still open the circuit.
		for ( $i = 0; $i < 5; $i++ ) {
			Telex_Circuit_Breaker::record_failure();
		}
		$this->assertFalse( Telex_Circuit_Breaker::is_available() );
	}

	// -------------------------------------------------------------------------
	// reset
	// -------------------------------------------------------------------------

	/**
	 * Asserts reset() restores a fully open circuit to closed.
	 *
	 * @return void
	 */
	public function test_reset_restores_open_circuit_to_closed(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			Telex_Circuit_Breaker::record_failure();
		}
		Telex_Circuit_Breaker::reset();

		$this->assertTrue( Telex_Circuit_Breaker::is_available() );
		$this->assertSame( 'closed', Telex_Circuit_Breaker::status() );
	}

	/**
	 * Asserts reset() clears all circuit breaker transients.
	 *
	 * @return void
	 */
	public function test_reset_clears_all_transients(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			Telex_Circuit_Breaker::record_failure();
		}
		set_transient( 'telex_cb_opened', time() - 61, 300 );
		Telex_Circuit_Breaker::is_available(); // Sets probe transient.

		Telex_Circuit_Breaker::reset();

		$this->assertFalse( get_transient( 'telex_cb_failures' ) );
		$this->assertFalse( get_transient( 'telex_cb_opened' ) );
		$this->assertFalse( get_transient( 'telex_cb_probe' ) );
	}
}
