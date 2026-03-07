<?php
/**
 * Unit tests for Telex_WP_Http_Client — SSRF guard, WP_Error conversion,
 * status code validation, header forwarding, and User-Agent.
 *
 * Uses the pre_http_request filter to intercept wp_remote_request().
 *
 * @package Dispatch_For_Telex
 */

/**
 * Unit tests for the Telex_WP_Http_Client class.
 */
class Test_Telex_WP_Http_Client extends WP_UnitTestCase {

	/**
	 * Remove any pre_http_request filters between tests.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * Remove any pre_http_request filters after tests.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns a mock wp_remote_request response array.
	 *
	 * @param int                   $code    HTTP status code.
	 * @param string                $body    Response body.
	 * @param array<string, string> $headers Additional response headers.
	 * @return array<string, mixed>
	 */
	private function make_wp_response( int $code, string $body = '', array $headers = [] ): array {
		$dict_class = class_exists( 'WpOrg\Requests\Utility\CaseInsensitiveDictionary' )
			? 'WpOrg\Requests\Utility\CaseInsensitiveDictionary'
			: 'Requests_Utility_CaseInsensitiveDictionary';

		return [
			'headers'       => new $dict_class( $headers ),
			'body'          => $body,
			'response'      => [
				'code'    => $code,
				'message' => 'OK',
			],
			'cookies'       => [],
			'http_response' => null,
		];
	}

	// -------------------------------------------------------------------------
	// SSRF guard
	// -------------------------------------------------------------------------

	/**
	 * Asserts sendRequest() throws Telex_Http_Exception for http:// URIs.
	 *
	 * @return void
	 */
	public function test_http_uri_throws_ssrf_exception(): void {
		$this->expectException( Telex_Http_Exception::class );

		$client  = new Telex_WP_Http_Client();
		$request = new \Nyholm\Psr7\Request( 'GET', 'http://example.com/api' );
		$client->sendRequest( $request );
	}

	/**
	 * Asserts sendRequest() does not throw for https:// URIs.
	 *
	 * @return void
	 */
	public function test_https_uri_does_not_trigger_ssrf_guard(): void {
		add_filter(
			'pre_http_request',
			fn() => $this->make_wp_response( 200, '{}' ),
			10,
			3
		);

		$client  = new Telex_WP_Http_Client();
		$request = new \Nyholm\Psr7\Request( 'GET', 'https://telex.app/api/v1/projects' );

		// Should not throw.
		$response = $client->sendRequest( $request );
		$this->assertSame( 200, $response->getStatusCode() );
	}

	// -------------------------------------------------------------------------
	// WP_Error conversion
	// -------------------------------------------------------------------------

	/**
	 * Asserts a WP_Error from wp_remote_request is converted to Telex_Http_Exception.
	 *
	 * @return void
	 */
	public function test_wp_error_is_converted_to_telex_http_exception(): void {
		$this->expectException( Telex_Http_Exception::class );

		add_filter(
			'pre_http_request',
			fn() => new WP_Error( 'http_request_failed', 'cURL error: Connection refused' ),
			10,
			3
		);

		$client  = new Telex_WP_Http_Client();
		$request = new \Nyholm\Psr7\Request( 'GET', 'https://telex.app/api/v1/projects' );
		$client->sendRequest( $request );
	}

	// -------------------------------------------------------------------------
	// Valid response
	// -------------------------------------------------------------------------

	/**
	 * Asserts sendRequest() returns a ResponseInterface with the correct status and body.
	 *
	 * @return void
	 */
	public function test_valid_response_returns_correct_status_and_body(): void {
		$expected_body = '{"projects":[]}';

		add_filter(
			'pre_http_request',
			fn() => $this->make_wp_response( 200, $expected_body ),
			10,
			3
		);

		$client   = new Telex_WP_Http_Client();
		$request  = new \Nyholm\Psr7\Request( 'GET', 'https://telex.app/api/v1/projects' );
		$response = $client->sendRequest( $request );

		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertSame( $expected_body, (string) $response->getBody() );
	}

	// -------------------------------------------------------------------------
	// Invalid status code
	// -------------------------------------------------------------------------

	/**
	 * Asserts sendRequest() throws Telex_Http_Exception for status code 0.
	 *
	 * @return void
	 */
	public function test_status_code_zero_throws_exception(): void {
		$this->expectException( Telex_Http_Exception::class );

		add_filter(
			'pre_http_request',
			function () {
				$dict_class = class_exists( 'WpOrg\Requests\Utility\CaseInsensitiveDictionary' )
					? 'WpOrg\Requests\Utility\CaseInsensitiveDictionary'
					: 'Requests_Utility_CaseInsensitiveDictionary';
				return [
					'headers'       => new $dict_class( [] ),
					'body'          => '',
					'response'      => [
						'code'    => 0,
						'message' => '',
					],
					'cookies'       => [],
					'http_response' => null,
				];
			},
			10,
			3
		);

		$client  = new Telex_WP_Http_Client();
		$request = new \Nyholm\Psr7\Request( 'GET', 'https://telex.app/api/v1/projects' );
		$client->sendRequest( $request );
	}

	// -------------------------------------------------------------------------
	// Header forwarding
	// -------------------------------------------------------------------------

	/**
	 * Asserts the Authorization header from the PSR-7 request is forwarded to wp_remote_request.
	 *
	 * @return void
	 */
	public function test_authorization_header_is_forwarded(): void {
		$captured_headers = [];

		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args ) use ( &$captured_headers ) {
				$captured_headers = $args['headers'] ?? [];
				$dict_class       = class_exists( 'WpOrg\Requests\Utility\CaseInsensitiveDictionary' )
					? 'WpOrg\Requests\Utility\CaseInsensitiveDictionary'
					: 'Requests_Utility_CaseInsensitiveDictionary';
				return [
					'headers'       => new $dict_class( [] ),
					'body'          => '{}',
					'response'      => [
						'code'    => 200,
						'message' => 'OK',
					],
					'cookies'       => [],
					'http_response' => null,
				];
			},
			10,
			3
		);

		$client  = new Telex_WP_Http_Client();
		$request = new \Nyholm\Psr7\Request(
			'GET',
			'https://telex.app/api',
			[ 'Authorization' => 'Bearer my-token' ]
		);
		$client->sendRequest( $request );

		$this->assertArrayHasKey( 'Authorization', $captured_headers );
		$this->assertSame( 'Bearer my-token', $captured_headers['Authorization'] );
	}

	// -------------------------------------------------------------------------
	// User-Agent
	// -------------------------------------------------------------------------

	/**
	 * Asserts the default User-Agent header is set on outgoing requests.
	 *
	 * @return void
	 */
	public function test_user_agent_is_set_on_outgoing_requests(): void {
		$captured_headers = [];

		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args ) use ( &$captured_headers ) {
				$captured_headers = $args['headers'] ?? [];
				$dict_class       = class_exists( 'WpOrg\Requests\Utility\CaseInsensitiveDictionary' )
					? 'WpOrg\Requests\Utility\CaseInsensitiveDictionary'
					: 'Requests_Utility_CaseInsensitiveDictionary';
				return [
					'headers'       => new $dict_class( [] ),
					'body'          => '{}',
					'response'      => [
						'code'    => 200,
						'message' => 'OK',
					],
					'cookies'       => [],
					'http_response' => null,
				];
			},
			10,
			3
		);

		$client  = new Telex_WP_Http_Client();
		$request = new \Nyholm\Psr7\Request( 'GET', 'https://telex.app/api' );
		$client->sendRequest( $request );

		$this->assertArrayHasKey( 'User-Agent', $captured_headers );
		$this->assertStringStartsWith( 'Telex/', $captured_headers['User-Agent'] );
		$this->assertStringContainsString( 'WordPress/', $captured_headers['User-Agent'] );
	}
}
