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
            throw new TelexException('Invalid JSON response', absint($status));
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
            throw new AuthenticationException(esc_html($message));
        }

        if ($status === 404) {
            throw new NotFoundException(esc_html($message));
        }

        throw new TelexException(esc_html($message), absint($status));
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
