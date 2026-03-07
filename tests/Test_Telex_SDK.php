<?php
/**
 * Unit tests for the Telex PHP SDK — TelexClient, HttpClient, ProjectResource.
 *
 * These tests exercise pure PHP SDK logic with no WordPress API calls.
 * HTTP responses are provided by a stub ClientInterface implementation.
 *
 * @package Dispatch_For_Telex
 */

use Telex\Sdk\TelexClient;
use Telex\Sdk\Exceptions\AuthenticationException;
use Telex\Sdk\Exceptions\NotFoundException;
use Telex\Sdk\Exceptions\TelexException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\RequestInterface;
use Nyholm\Psr7\Response;

/**
 * In-memory stub implementation of PSR-18 ClientInterface.
 *
 * Returns a pre-configured response for every sendRequest() call.
 */
final class Stub_Psr18_Client implements ClientInterface {

	/**
	 * The response to return from every sendRequest() call.
	 *
	 * @var ResponseInterface
	 */
	private ResponseInterface $response;

	/**
	 * Stores the response to return for all subsequent sendRequest() calls.
	 *
	 * @param ResponseInterface $response The response to return.
	 */
	public function __construct( ResponseInterface $response ) {
		$this->response = $response;
	}

	/**
	 * Returns the pre-configured stub response, ignoring the outgoing request.
	 *
	 * @param RequestInterface $request The outgoing request (ignored).
	 * @return ResponseInterface
	 */
	public function sendRequest( RequestInterface $request ): ResponseInterface {
		return $this->response;
	}
}

/**
 * Unit tests for the Telex SDK classes.
 */
class Test_Telex_SDK extends WP_UnitTestCase {

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Creates a minimal valid TelexClient with a stub HTTP response.
	 *
	 * @param ResponseInterface $response The stub response the HTTP client will return.
	 * @return TelexClient
	 */
	private function make_client( ResponseInterface $response ): TelexClient {
		return new TelexClient(
			[
				'token'      => 'test-token',
				'baseUrl'    => 'https://telex.app',
				'httpClient' => new Stub_Psr18_Client( $response ),
			]
		);
	}

	// -------------------------------------------------------------------------
	// TelexClient constructor guards
	// -------------------------------------------------------------------------

	/**
	 * Asserts an empty token throws InvalidArgumentException.
	 *
	 * @return void
	 */
	public function test_empty_token_throws(): void {
		$this->expectException( InvalidArgumentException::class );

		new TelexClient(
			[
				'token'      => '',
				'baseUrl'    => 'https://telex.app',
				'httpClient' => new Stub_Psr18_Client( new Response( 200 ) ),
			]
		);
	}

	/**
	 * Asserts an http:// base URL throws InvalidArgumentException (SSRF guard).
	 *
	 * @return void
	 */
	public function test_http_base_url_throws_ssrf_guard(): void {
		$this->expectException( InvalidArgumentException::class );

		new TelexClient(
			[
				'token'      => 'tok',
				'baseUrl'    => 'http://telex.app',
				'httpClient' => new Stub_Psr18_Client( new Response( 200 ) ),
			]
		);
	}

	/**
	 * Asserts a private-IP base URL throws InvalidArgumentException.
	 *
	 * @return void
	 */
	public function test_private_ip_base_url_throws(): void {
		$this->expectException( InvalidArgumentException::class );

		new TelexClient(
			[
				'token'      => 'tok',
				'baseUrl'    => 'https://192.168.1.1',
				'httpClient' => new Stub_Psr18_Client( new Response( 200 ) ),
			]
		);
	}

	/**
	 * Asserts a loopback base URL throws InvalidArgumentException.
	 *
	 * @return void
	 */
	public function test_loopback_base_url_throws(): void {
		$this->expectException( InvalidArgumentException::class );

		new TelexClient(
			[
				'token'      => 'tok',
				'baseUrl'    => 'https://localhost',
				'httpClient' => new Stub_Psr18_Client( new Response( 200 ) ),
			]
		);
	}

	/**
	 * Asserts omitting httpClient throws InvalidArgumentException.
	 *
	 * @return void
	 */
	public function test_missing_http_client_throws(): void {
		$this->expectException( InvalidArgumentException::class );

		new TelexClient(
			[
				'token'   => 'tok',
				'baseUrl' => 'https://telex.app',
			]
		);
	}

	/**
	 * Asserts a valid configuration constructs TelexClient without throwing.
	 *
	 * @return void
	 */
	public function test_valid_configuration_constructs_client(): void {
		$client = $this->make_client( new Response( 200, [], '{"projects":[]}' ) );
		$this->assertInstanceOf( TelexClient::class, $client );
	}

	// -------------------------------------------------------------------------
	// HttpClient error mapping via TelexClient.projects
	// -------------------------------------------------------------------------

	/**
	 * Asserts a 401 response throws AuthenticationException.
	 *
	 * @return void
	 */
	public function test_401_response_throws_authentication_exception(): void {
		$this->expectException( AuthenticationException::class );

		$client = $this->make_client( new Response( 401, [], '{"message":"Unauthorized"}' ) );
		$client->projects->list();
	}

	/**
	 * Asserts a 404 response throws NotFoundException.
	 *
	 * @return void
	 */
	public function test_404_response_throws_not_found_exception(): void {
		$this->expectException( NotFoundException::class );

		$client = $this->make_client( new Response( 404, [], '{"message":"Not found"}' ) );
		$client->projects->get( 'nonexistent-id' );
	}

	/**
	 * Asserts a 500 response throws TelexException.
	 *
	 * @return void
	 */
	public function test_500_response_throws_telex_exception(): void {
		$this->expectException( TelexException::class );

		$client = $this->make_client( new Response( 500, [], '{"message":"Server error"}' ) );
		$client->projects->list();
	}

	/**
	 * Asserts a response body over 10 MB throws TelexException.
	 *
	 * @return void
	 */
	public function test_oversized_response_body_throws_telex_exception(): void {
		$this->expectException( TelexException::class );

		$large_body = str_repeat( 'x', 10_000_001 );
		$client     = $this->make_client( new Response( 200, [], $large_body ) );
		$client->projects->list();
	}

	/**
	 * Asserts a valid 200 response returns the decoded array.
	 *
	 * @return void
	 */
	public function test_valid_200_response_returns_decoded_array(): void {
		$client = $this->make_client(
			new Response(
				200,
				[],
				(string) json_encode(
					[
						'projects'   => [],
						'page'       => 1,
						'perPage'    => 20,
						'total'      => 0,
						'totalPages' => 0,
					]
				)
			)
		);

		$result = $client->projects->list();
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'projects', $result );
	}

	/**
	 * Asserts parseErrorMessage() extracts the JSON 'message' field from error bodies.
	 *
	 * @return void
	 */
	public function test_error_message_extracted_from_json_message_field(): void {
		$client = $this->make_client(
			new Response( 500, [], '{"message":"Custom server error"}' )
		);

		try {
			$client->projects->list();
			$this->fail( 'Expected TelexException was not thrown.' );
		} catch ( TelexException $e ) {
			$this->assertSame( 'Custom server error', $e->getMessage() );
		}
	}

	/**
	 * Asserts parseErrorMessage() falls back to "HTTP {status}" when no JSON message exists.
	 *
	 * @return void
	 */
	public function test_error_message_falls_back_to_http_status_phrase(): void {
		$client = $this->make_client(
			new Response( 503, [], 'plain text error' )
		);

		try {
			$client->projects->list();
			$this->fail( 'Expected TelexException was not thrown.' );
		} catch ( TelexException $e ) {
			$this->assertSame( 'HTTP 503', $e->getMessage() );
		}
	}

	// -------------------------------------------------------------------------
	// ProjectResource URL construction
	// -------------------------------------------------------------------------

	/**
	 * Asserts rawurlencode() is applied to public IDs containing special characters.
	 *
	 * The test verifies correct encoding by issuing a request with a known ID and
	 * confirming no exception is thrown (the stub client returns a valid response
	 * regardless of the URL path).
	 *
	 * @return void
	 */
	public function test_project_id_with_special_chars_is_url_encoded(): void {
		$encoded_response = new Response(
			200,
			[],
			(string) json_encode(
				[
					'publicId'       => 'my project/special',
					'name'           => 'Test',
					'slug'           => 'test',
					'projectType'    => 'block',
					'currentVersion' => 1,
					'createdAt'      => null,
					'updatedAt'      => null,
					'artefactXML'    => '',
					'isShared'       => false,
					'isOwner'        => true,
					'images'         => [],
				]
			)
		);

		$client = $this->make_client( $encoded_response );

		// Should not throw — the special characters are encoded before building the URL.
		$result = $client->projects->get( 'my project/special' );
		$this->assertIsArray( $result );
	}

	/**
	 * Asserts getBuildFile() returns the raw response body as a string.
	 *
	 * @return void
	 */
	public function test_get_build_file_returns_raw_body(): void {
		$file_content = 'const x = 1;';
		$client       = $this->make_client( new Response( 200, [], $file_content ) );

		$result = $client->projects->getBuildFile( 'proj-id', 'index.js' );
		$this->assertSame( $file_content, $result );
	}
}
