<?php
/**
 * Transient-based circuit breaker for the Telex API.
 *
 * @package Dispatch_For_Telex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Simple transient-based circuit breaker for the Telex API.
 *
 * States:
 *   CLOSED  — normal operation; requests flow through.
 *   OPEN    — API is failing; requests are short-circuited immediately.
 *   HALF-OPEN — after reset TTL, one probe request is allowed through.
 *
 * The state is stored in a WordPress transient so it persists across requests
 * but resets cleanly via WP's transient GC.
 */
class Telex_Circuit_Breaker {

	private const TRANSIENT_FAILURES = 'telex_cb_failures';
	private const TRANSIENT_OPENED   = 'telex_cb_opened';
	private const TRANSIENT_PROBE    = 'telex_cb_probe';

	/** Consecutive failures before opening the circuit. */
	private const FAILURE_THRESHOLD = 5;

	/** Seconds to keep the circuit open before allowing a probe. */
	private const RESET_TIMEOUT = 60;

	/** How long a failure count window lasts (seconds). */
	private const FAILURE_WINDOW = 120;

	// -------------------------------------------------------------------------
	// Public interface
	// -------------------------------------------------------------------------

	/**
	 * Returns true if the circuit is closed (requests should proceed).
	 * Returns false if open (caller should short-circuit with a cached/error response).
	 */
	public static function is_available(): bool {
		if ( self::is_open() ) {
			// Allow a single probe request through when the reset timeout has elapsed.
			if ( self::reset_timeout_elapsed() && ! self::probe_in_flight() ) {
				self::mark_probe_in_flight();
				return true; // Half-open: let one request through.
			}
			return false;
		}
		return true;
	}

	/**
	 * Call after a successful API response.
	 */
	public static function record_success(): void {
		delete_transient( self::TRANSIENT_FAILURES );
		delete_transient( self::TRANSIENT_OPENED );
		delete_transient( self::TRANSIENT_PROBE );
	}

	/**
	 * Call after a failed API response.
	 */
	public static function record_failure(): void {
		$probe_was_in_flight = self::probe_in_flight();
		delete_transient( self::TRANSIENT_PROBE );

		$failures = (int) get_transient( self::TRANSIENT_FAILURES );
		++$failures;
		set_transient( self::TRANSIENT_FAILURES, $failures, self::FAILURE_WINDOW );

		if ( $failures >= self::FAILURE_THRESHOLD ) {
			if ( ! self::is_open() ) {
				// Open the circuit for the first time.
				set_transient( self::TRANSIENT_OPENED, time(), self::FAILURE_WINDOW + self::RESET_TIMEOUT );
			} elseif ( $probe_was_in_flight ) {
				// Half-open probe just failed — restart the reset timer so the circuit
				// stays OPEN for a full RESET_TIMEOUT before allowing another probe.
				set_transient( self::TRANSIENT_OPENED, time(), self::FAILURE_WINDOW + self::RESET_TIMEOUT );
			}
		}
	}

	/**
	 * Returns a human-readable status for Site Health and WP-CLI.
	 */
	public static function status(): string {
		if ( ! self::is_open() ) {
			return 'closed';
		}
		if ( self::reset_timeout_elapsed() ) {
			return 'half-open';
		}
		return 'open';
	}

	/**
	 * Manually reset the circuit (e.g., after operator intervention).
	 */
	public static function reset(): void {
		delete_transient( self::TRANSIENT_FAILURES );
		delete_transient( self::TRANSIENT_OPENED );
		delete_transient( self::TRANSIENT_PROBE );
	}

	// -------------------------------------------------------------------------
	// Internal
	// -------------------------------------------------------------------------

	/**
	 * Returns true if the circuit is currently open.
	 *
	 * @return bool
	 */
	private static function is_open(): bool {
		return (bool) get_transient( self::TRANSIENT_OPENED );
	}

	/**
	 * Returns true if the reset timeout has passed since the circuit opened.
	 *
	 * @return bool
	 */
	private static function reset_timeout_elapsed(): bool {
		$opened_at = (int) get_transient( self::TRANSIENT_OPENED );
		if ( ! $opened_at ) {
			return true;
		}
		return ( time() - $opened_at ) >= self::RESET_TIMEOUT;
	}

	/**
	 * Returns true if a half-open probe request is already in flight.
	 *
	 * @return bool
	 */
	private static function probe_in_flight(): bool {
		return (bool) get_transient( self::TRANSIENT_PROBE );
	}

	/**
	 * Marks a probe request as in-flight so only one can proceed at a time.
	 *
	 * @return void
	 */
	private static function mark_probe_in_flight(): void {
		// Probe expires quickly — if the request hangs, we don't want to block all probes.
		set_transient( self::TRANSIENT_PROBE, 1, 30 );
	}
}
