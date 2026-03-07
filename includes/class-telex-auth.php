<?php
/**
 * OAuth 2.0 authentication and AES-256-GCM token storage.
 *
 * @package Dispatch_For_Telex
 */

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

	private const OPTION_TOKEN      = 'telex_auth_token';
	public const  TRANSIENT_DEVICE  = 'telex_device_code';
	private const CIPHER            = 'aes-256-gcm';
	private const GCM_IV_LENGTH     = 12; // 96-bit nonce — NIST recommended for GCM.
	private const GCM_TAG_LENGTH    = 16;
	private const RATE_LIMIT_MAX    = 10;
	private const RATE_LIMIT_WINDOW = MINUTE_IN_SECONDS;

	/**
	 * Register action hooks. Called from plugins_loaded.
	 * AJAX handlers are intentionally omitted — all auth flows use the REST API.
	 */
	public static function init(): void {
		// Multisite: use network-wide option when applicable.
		if ( is_multisite() ) {
			add_filter( 'pre_option_' . self::OPTION_TOKEN, static fn() => get_site_option( self::OPTION_TOKEN, '' ) );
			add_filter(
				'pre_update_option_' . self::OPTION_TOKEN,
				static function ( $value ) {
					update_site_option( self::OPTION_TOKEN, $value );
					return $value;
				}
			);
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
	 *
	 * @param string $action A unique identifier for the action being rate-limited.
	 * @return int Seconds remaining in the current window, or 0 if under the limit.
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

	/**
	 * Returns true if a valid OAuth token is stored.
	 *
	 * @return bool
	 */
	public static function is_connected(): bool {
		return '' !== self::get_token();
	}

	/**
	 * Returns the current connection status as an AuthStatus enum.
	 *
	 * @return AuthStatus
	 */
	public static function get_status(): AuthStatus {
		return self::is_connected() ? AuthStatus::Connected : AuthStatus::Disconnected;
	}

	// -------------------------------------------------------------------------
	// Token access
	// -------------------------------------------------------------------------

	/**
	 * Retrieves and decrypts the stored OAuth token.
	 *
	 * @return string The plaintext token, or empty string if not connected.
	 */
	public static function get_token(): string {
		$encrypted = is_multisite()
			? (string) get_site_option( self::OPTION_TOKEN, '' )
			: (string) get_option( self::OPTION_TOKEN, '' );

		if ( '' === $encrypted ) {
			return '';
		}

		return self::decrypt( $encrypted );
	}

	/**
	 * Encrypts and persists an OAuth token.
	 *
	 * @param string $token The plaintext OAuth access token.
	 * @return void
	 */
	public static function store_token( string $token ): void {
		$encrypted = self::encrypt( $token );

		if ( is_multisite() ) {
			update_site_option( self::OPTION_TOKEN, $encrypted );
		} else {
			update_option( self::OPTION_TOKEN, $encrypted, false );
		}
	}

	/**
	 * Removes the stored token and cancels any in-progress device flow.
	 *
	 * @return void
	 */
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

	/**
	 * Returns an authenticated TelexClient instance, or null if not connected or circuit is open.
	 *
	 * @return \Telex\Sdk\TelexClient|null
	 */
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
			return new \Telex\Sdk\TelexClient(
				[
					'token'      => $token,
					'baseUrl'    => TELEX_API_BASE_URL,
					'httpClient' => new Telex_WP_Http_Client(
						timeout: Telex_WP_Http_Client::TIMEOUT_METADATA
					),
				]
			);
		} catch ( \Exception ) {
			return null;
		}
	}

	// -------------------------------------------------------------------------
	// Device Authorization Grant — HTTP helpers (RFC 8628)
	// -------------------------------------------------------------------------

	/**
	 * Sends a POST to the device authorization server to start a new device flow.
	 *
	 * Stores the device code in a transient on success and returns the data
	 * needed to display the user code / verification URI to the user.
	 *
	 * @return array{user_code: string, verification_uri: string, verification_uri_complete: string, expires_in: int, interval: int}|\WP_Error
	 */
	public static function start_device_flow(): array|\WP_Error {
		$client  = new Telex_WP_Http_Client( timeout: Telex_WP_Http_Client::TIMEOUT_AUTH );
		$request = new \Nyholm\Psr7\Request(
			'POST',
			TELEX_DEVICE_AUTH_URL . '/code',
			[ 'Content-Type' => 'application/json' ],
			'{}'
		);

		try {
			$response = $client->sendRequest( $request );
		} catch ( \Psr\Http\Client\ClientExceptionInterface $e ) {
			return new \WP_Error( 'telex_network', $e->getMessage() );
		}

		$body = json_decode( (string) $response->getBody(), true );

		if ( 200 !== $response->getStatusCode() || empty( $body['device_code'] ) ) {
			return new \WP_Error(
				'telex_device_start',
				$body['message'] ?? __( 'Failed to start device flow.', 'telex' )
			);
		}

		set_transient( self::TRANSIENT_DEVICE, $body['device_code'], (int) $body['expires_in'] );

		return [
			'user_code'                 => $body['user_code'],
			'verification_uri'          => $body['verification_uri'],
			'verification_uri_complete' => $body['verification_uri_complete'],
			'expires_in'                => (int) $body['expires_in'],
			'interval'                  => (int) ( $body['interval'] ?? 5 ),
		];
	}

	/**
	 * Polls the device authorization server to check whether the user has authorized.
	 *
	 * On authorization: stores the token, deletes the device code transient, returns true.
	 * On pending: returns an array with 'status' (pending or slow_down) and an optional
	 *   'interval' (adjusted polling interval in seconds, when slow_down is signalled).
	 * On terminal failure: returns a WP_Error (expired_token, access_denied, network error).
	 *
	 * @param string $device_code The device code returned by start_device_flow().
	 * @return true|array{status: string, interval?: int}|\WP_Error
	 */
	public static function poll_device_flow( string $device_code ): true|array|\WP_Error {
		$client  = new Telex_WP_Http_Client( timeout: Telex_WP_Http_Client::TIMEOUT_AUTH );
		$request = new \Nyholm\Psr7\Request(
			'POST',
			TELEX_DEVICE_AUTH_URL . '/token',
			[ 'Content-Type' => 'application/json' ],
			(string) wp_json_encode( [ 'device_code' => $device_code ] )
		);

		try {
			$response = $client->sendRequest( $request );
		} catch ( \Psr\Http\Client\ClientExceptionInterface $e ) {
			return new \WP_Error( 'telex_network', $e->getMessage() );
		}

		$http_code = $response->getStatusCode();
		$body      = json_decode( (string) $response->getBody(), true );
		$error     = $body['error'] ?? '';

		if ( 200 === $http_code && ! empty( $body['access_token'] ) ) {
			self::store_token( $body['access_token'] );
			delete_transient( self::TRANSIENT_DEVICE );
			return true;
		}

		// RFC 8628 §3.5: terminal errors — clean up the transient.
		if ( in_array( $error, [ 'expired_token', 'access_denied' ], true ) ) {
			delete_transient( self::TRANSIENT_DEVICE );
			return new \WP_Error(
				'telex_device_' . $error,
				$body['error_description'] ?? $error
			);
		}

		// RFC 8628 §3.5: slow_down — client MUST increase interval by 5 seconds.
		if ( 'slow_down' === $error ) {
			return [
				'status'   => 'slow_down',
				'interval' => ( (int) ( $body['interval'] ?? 5 ) ) + 5,
			];
		}

		// authorization_pending or any other non-terminal pending state.
		return [ 'status' => 'pending' ];
	}

	// -------------------------------------------------------------------------
	// Encryption — AES-256-GCM
	// -------------------------------------------------------------------------

	/**
	 * Derives the 32-byte AES-256-GCM encryption key from the site auth salt.
	 *
	 * @return string 32-byte raw binary key.
	 */
	private static function get_encryption_key(): string {
		// SHA-256 of the site auth salt produces a 32-byte raw key.
		return hash( 'sha256', wp_salt( 'auth' ), true );
	}

	/**
	 * Encrypts a plaintext string using AES-256-GCM.
	 *
	 * @param string $plaintext The value to encrypt.
	 * @return string Encoded ciphertext in v2 format, or empty string on failure.
	 */
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

	/**
	 * Decrypts a stored token. Handles both v2 (GCM) and legacy CBC formats.
	 *
	 * @param string $data The encrypted token string from the database.
	 * @return string The plaintext token, or empty string on failure.
	 */
	private static function decrypt( string $data ): string {
		$key = self::get_encryption_key();

		// Current format: v2:{iv_b64}.{tag_b64}.{ct_b64}.
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
