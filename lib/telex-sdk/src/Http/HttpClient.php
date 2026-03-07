<?php

namespace Telex\Sdk\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Psr\Http\Client\ClientInterface;
use Nyholm\Psr7\Request;
use Telex\Sdk\Exceptions\AuthenticationException;
use Telex\Sdk\Exceptions\NotFoundException;
use Telex\Sdk\Exceptions\TelexException;

class HttpClient
{
    private string $baseUrl;
    private string $token;
    private ClientInterface $client;

    public function __construct(string $baseUrl, string $token, ClientInterface $client)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token   = $token;
        $this->client  = $client;
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    public function get(string $path, array $params = []): array
    {
        $response = $this->sendRequest($path, $params);
        $status   = (int) $response->getStatusCode();
        $body     = (string) $response->getBody();

        $this->throwOnError($status, $body);

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new TelexException('Invalid JSON response', $status); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output.
        }

        return $decoded;
    }

    /**
     * @param array<string, string> $params
     */
    public function getRaw(string $path, array $params = []): string
    {
        $response = $this->sendRequest($path, $params);
        $status   = (int) $response->getStatusCode();
        $body     = (string) $response->getBody();

        $this->throwOnError($status, $body);

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body = []): array
    {
        $url = $this->baseUrl . $path;

        $request = new Request('POST', $url, [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ], (string) (json_encode($body) ?: '{}'));

        $response = $this->client->sendRequest($request);
        $status   = (int) $response->getStatusCode();
        $raw      = (string) $response->getBody();

        $this->throwOnError($status, $raw);

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, string> $params
     */
    private function sendRequest(string $path, array $params): \Psr\Http\Message\ResponseInterface
    {
        $url = $this->baseUrl . $path;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $request = new Request('GET', $url, [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept'        => 'application/json',
        ]);

        return $this->client->sendRequest($request);
    }

    private function throwOnError(int $status, string $body): void
    {
        if ($status >= 200 && $status < 300) {
            return;
        }

        $message = $this->parseErrorMessage($body, $status);

        if ($status === 401) {
            throw new AuthenticationException($message); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output.
        }

        if ($status === 404) {
            throw new NotFoundException($message); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output.
        }

        throw new TelexException($message, $status); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output.
    }

    private function parseErrorMessage(string $body, int $status): string
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['message'])) {
            return (string) $decoded['message'];
        }

        return "HTTP $status";
    }
}
