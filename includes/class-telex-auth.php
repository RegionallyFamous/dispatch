<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles OAuth 2.0 Device Authorization Grant (RFC 8628) and token storage.
 *
 * Encryption: AES-256-GCM with a per-site key derived from wp_salt('auth').
 * Stored format: "v2:{base64_iv}.{base64_tag}.{base64_ciphertext}"
 * The '.' separator cannot appear in base64, so splitting is always unambiguous.
 */
class Telex_Auth {

	private const OPTION_TOKEN         = 'telex_auth_token';
	public const  TRANSIENT_DEVICE     = 'telex_device_code';
	private const CIPHER               = 'aes-256-gcm';
	private const GCM_IV_LENGTH        = 12; // 96-bit nonce — NIST recommended for GCM.
	private const GCM_TAG_LENGTH       = 16;
	private const RATE_LIMIT_MAX       = 10;
	private const RATE_LIMIT_WINDOW    = MINUTE_IN_SECONDS;

	/**
	 * Register action hooks. Called from plugins_loaded.
	 * AJAX handlers are intentionally omitted — all auth flows use the REST API.
	 */
	public static function init(): void {
		// Multisite: use network-wide option when applicable.
		if ( is_multisite() ) {
			add_filter( 'pre_option_' . self::OPTION_TOKEN, static fn() => get_site_option( self::OPTION_TOKEN, '' ) );
			add_filter( 'pre_update_option_' . self::OPTION_TOKEN, static function ( $value ) {
				update_site_option( self::OPTION_TOKEN, $value );
				return $value;
			} );
		}
	}

	// -------------------------------------------------------------------------
	// Rate limiting — fixed-window counter
	// -------------------------------------------------------------------------

	/**
	 * Checks a fixed-window rate limit for the current user + action.
	 *
	 * Unlike a sliding window, the fixed window resets at a predictable boundary,
	 * which makes the Retry-After value accurate.
	 *
	 * Returns an integer seconds-until-reset on violation, or 0 on success.
	 */
	public static function check_rate_limit( string $action ): int {
		$user_id    = get_current_user_id();
		$window_key = 'telex_rl_w_' . md5( (string) $user_id . '_' . $action );
		$count_key  = 'telex_rl_c_' . md5( (string) $user_id . '_' . $action );

		$window_start = (int) get_transient( $window_key );
		$now          = time();

		if ( ! $window_start || ( $now - $window_start ) >= self::RATE_LIMIT_WINDOW ) {
			// Start a new window.
			set_transient( $window_key, $now, self::RATE_LIMIT_WINDOW + 5 );
			set_transient( $count_key, 1, self::RATE_LIMIT_WINDOW + 5 );
			return 0;
		}

		$count = (int) get_transient( $count_key );
		if ( $count >= self::RATE_LIMIT_MAX ) {
			// Return seconds remaining in this window.
			return self::RATE_LIMIT_WINDOW - ( $now - $window_start );
		}

		set_transient( $count_key, $count + 1, self::RATE_LIMIT_WINDOW + 5 );
		return 0;
	}

	// -------------------------------------------------------------------------
	// Connection state
	// -------------------------------------------------------------------------

	public static function is_connected(): bool {
		return '' !== self::get_token();
	}

	/** @return AuthStatus */
	public static function get_status(): AuthStatus {
		return self::is_connected() ? AuthStatus::Connected : AuthStatus::Disconnected;
	}

	// -------------------------------------------------------------------------
	// Token access
	// -------------------------------------------------------------------------

	public static function get_token(): string {
		$encrypted = is_multisite()
			? (string) get_site_option( self::OPTION_TOKEN, '' )
			: (string) get_option( self::OPTION_TOKEN, '' );

		if ( '' === $encrypted ) {
			return '';
		}

		return self::decrypt( $encrypted );
	}

	public static function store_token( string $token ): void {
		$encrypted = self::encrypt( $token );

		if ( is_multisite() ) {
			update_site_option( self::OPTION_TOKEN, $encrypted );
		} else {
			update_option( self::OPTION_TOKEN, $encrypted, false );
		}
	}

	public static function disconnect(): void {
		if ( is_multisite() ) {
			delete_site_option( self::OPTION_TOKEN );
		} else {
			delete_option( self::OPTION_TOKEN );
		}
		delete_transient( self::TRANSIENT_DEVICE );
	}

	// -------------------------------------------------------------------------
	// SDK client factory
	// -------------------------------------------------------------------------

	public static function get_client(): ?\Telex\Sdk\TelexClient {
		$token = self::get_token();
		if ( '' === $token ) {
			return null;
		}

		// Respect the circuit breaker — don't create a client when the API is known-down.
		if ( ! Telex_Circuit_Breaker::is_available() ) {
			return null;
		}

		try {
			return new \Telex\Sdk\TelexClient( [
				'token'      => $token,
				'baseUrl'    => TELEX_API_BASE_URL,
				'httpClient' => new Telex_WP_Http_Client(
					timeout: Telex_WP_Http_Client::TIMEOUT_METADATA
				),
			] );
		} catch ( \Exception ) {
			return null;
		}
	}

	// -------------------------------------------------------------------------
	// Encryption — AES-256-GCM
	// -------------------------------------------------------------------------

	private static function get_encryption_key(): string {
		// SHA-256 of the site auth salt produces a 32-byte raw key.
		return hash( 'sha256', wp_salt( 'auth' ), true );
	}

	private static function encrypt( string $plaintext ): string {
		$key = self::get_encryption_key();
		$iv  = openssl_random_pseudo_bytes( self::GCM_IV_LENGTH );
		$tag = '';

		$ciphertext = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			self::GCM_TAG_LENGTH
		);

		if ( false === $ciphertext ) {
			wp_trigger_error( __METHOD__, 'AES-256-GCM encryption failed.', E_USER_WARNING );
			return '';
		}

		// '.' cannot appear in base64 — no ambiguous splitting possible.
		return 'v2:' . base64_encode( $iv ) . '.' . base64_encode( $tag ) . '.' . base64_encode( $ciphertext );
	}

	private static function decrypt( string $data ): string {
		$key = self::get_encryption_key();

		// Current format: v2:{iv_b64}.{tag_b64}.{ct_b64}
		if ( str_starts_with( $data, 'v2:' ) ) {
			$parts = explode( '.', substr( $data, 3 ), 3 );
			if ( 3 !== count( $parts ) ) {
				return '';
			}

			$iv  = base64_decode( $parts[0], true );
			$tag = base64_decode( $parts[1], true );
			$ct  = base64_decode( $parts[2], true );

			if ( false === $iv || false === $tag || false === $ct ) {
				return '';
			}

			$plaintext = openssl_decrypt(
				$ct,
				self::CIPHER,
				$key,
				OPENSSL_RAW_DATA,
				$iv,
				$tag
			);

			return false !== $plaintext ? $plaintext : '';
		}

		// Legacy CBC format — best-effort migration.
		// The binary IV + '::' separator format had a rare parsing bug.
		// Attempt to decode and, if successful, immediately re-encrypt as v2.
		$raw = base64_decode( $data, true );
		if ( false === $raw ) {
			return '';
		}

		$sep_pos = strpos( $raw, '::' );
		if ( false === $sep_pos ) {
			return '';
		}

		$iv_legacy = substr( $raw, 0, $sep_pos );
		$ct_legacy = substr( $raw, $sep_pos + 2 );

		$plaintext = openssl_decrypt( $ct_legacy, 'aes-256-cbc', $key, 0, $iv_legacy );
		if ( false === $plaintext ) {
			return '';
		}

		// Silently upgrade to GCM on next access.
		self::store_token( $plaintext );

		return $plaintext;
	}
}
