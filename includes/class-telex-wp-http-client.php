<?php
/**
 * PSR-18 HTTP client adapter over the WordPress HTTP API.
 *
 * @package Dispatch_For_Telex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PSR-18 HTTP client adapter over WordPress HTTP API.
 *
 * Injects into TelexClient so all SDK requests honour WP proxy settings,
 * SSL verification config, and the pre_http_request filter (testable mocking).
 *
 * Security hardening applied:
 *  - sslverify always true (never disabled by caller)
 *  - Max 3 redirects to prevent open-redirect chains
 *  - Response size capped at 10 MB to prevent memory exhaustion
 *  - Separate timeout constants for metadata vs file-download requests
 *  - SSRF: wp_remote_request already respects WP's allowed URL filters
 */
final class Telex_WP_Http_Client implements \Psr\Http\Client\ClientInterface {

	/** Metadata requests (project list, build manifest). */
	public const TIMEOUT_METADATA = 10;

	/** File download requests — larger binaries may need more time. */
	public const TIMEOUT_DOWNLOAD = 60;

	/** Auth token exchange requests. */
	public const TIMEOUT_AUTH = 20;

	/** Maximum response body size in bytes (10 MB). */
	private const MAX_RESPONSE_BYTES = 10 * 1024 * 1024;

	/**
	 * HTTP User-Agent header value sent with every request.
	 *
	 * @var string
	 */
	private readonly string $user_agent;

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	private readonly int $timeout;

	/**
	 * Creates a new HTTP client instance.
	 *
	 * @param string|null $user_agent Custom User-Agent string, or null to use the default.
	 * @param int         $timeout    Request timeout in seconds.
	 */
	public function __construct( ?string $user_agent = null, int $timeout = self::TIMEOUT_METADATA ) {
		$wp_version       = get_bloginfo( 'version' );
		$this->user_agent = $user_agent ?? sprintf(
			'Telex/%s; WordPress/%s; +https://telex.automattic.ai',
			TELEX_PLUGIN_VERSION,
			'' !== $wp_version ? $wp_version : 'unknown'
		);
		$this->timeout    = $timeout;
	}

	/**
	 * Sends a PSR-7 request and returns a PSR-7 response.
	 *
	 * @param \Psr\Http\Message\RequestInterface $request The outgoing request.
	 * @return \Psr\Http\Message\ResponseInterface
	 * @throws Telex_Http_Exception On network error, SSRF attempt, or oversized response.
	 */
	public function sendRequest( \Psr\Http\Message\RequestInterface $request ): \Psr\Http\Message\ResponseInterface {
		$uri    = (string) $request->getUri();
		$method = $request->getMethod();

		// SSRF guard: only allow https:// targets.
		if ( ! str_starts_with( $uri, 'https://' ) ) {
			// Exception messages are internal developer errors — never displayed to end users.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new Telex_Http_Exception( sprintf( 'Telex HTTP client only supports HTTPS. Rejected: %s', $uri ) );
		}

		$headers = [];
		foreach ( $request->getHeaders() as $name => $values ) {
			$headers[ $name ] = implode( ', ', $values );
		}
		$headers['User-Agent'] = $this->user_agent;

		$body = (string) $request->getBody();

		$wp_response = wp_remote_request(
			$uri,
			[
				'method'              => $method,
				'headers'             => $headers,
				'body'                => $body,
				'timeout'             => $this->timeout,
				'sslverify'           => true,           // Never allow SSL bypass.
				'redirection'         => 3,              // Limit redirect chains.
				'limit_response_size' => self::MAX_RESPONSE_BYTES,
			]
		);

		if ( is_wp_error( $wp_response ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new Telex_Http_Exception( $wp_response->get_error_message() );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $wp_response );

		// Guard against empty/missing status code (network layer error).
		if ( $status_code < 100 || $status_code > 599 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new Telex_Http_Exception( 'Invalid HTTP response status code: ' . $status_code );
		}

		$headers_raw      = wp_remote_retrieve_headers( $wp_response );
		$response_headers = $headers_raw instanceof \WpOrg\Requests\Utility\CaseInsensitiveDictionary
			? $headers_raw->getAll()
			: (array) $headers_raw;
		$response_body    = wp_remote_retrieve_body( $wp_response );

		// Enforce response size cap (belt-and-suspenders; limit_response_size above handles it at transport level).
		if ( strlen( $response_body ) > self::MAX_RESPONSE_BYTES ) {
			throw new Telex_Http_Exception( 'Response body exceeds maximum allowed size.' );
		}

		return new \Nyholm\Psr7\Response(
			$status_code,
			$response_headers,
			$response_body
		);
	}
}

/**
 * Wraps WP_Error from wp_remote_request as a PSR-18 ClientException.
 */
class Telex_Http_Exception extends \RuntimeException implements \Psr\Http\Client\ClientExceptionInterface {}
