<?php

namespace Telex\Sdk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Telex\Sdk\Http\HttpClient;
use Telex\Sdk\Resources\ProjectResource;
use Psr\Http\Client\ClientInterface;
use InvalidArgumentException;

class TelexClient
{
    public readonly ProjectResource $projects;

    private const DEFAULT_BASE_URL = 'https://telex.app';

    /**
     * @param array{token: string, baseUrl?: string, httpClient?: ClientInterface} $options
     */
    public function __construct(array $options)
    {
        $token = $options['token'] ?? '';
        if ($token === '') {
            throw new InvalidArgumentException('token is required');
        }

        $baseUrl   = rtrim($options['baseUrl'] ?? self::DEFAULT_BASE_URL, '/');
        $psrClient = $options['httpClient'] ?? null;

        // SSRF guard: only allow HTTPS and reject private/loopback addresses.
        $parsed = parse_url($baseUrl);
        if (($parsed['scheme'] ?? '') !== 'https') {
            throw new InvalidArgumentException('baseUrl must use the https:// scheme.');
        }
        $host = strtolower($parsed['host'] ?? '');
        if (
            $host === 'localhost' ||
            preg_match('/^127\./', $host) ||
            preg_match('/^10\./', $host) ||
            preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $host) ||
            preg_match('/^192\.168\./', $host) ||
            preg_match('/^169\.254\./', $host) ||
            $host === '::1' ||
            $host === '[::1]'
        ) {
            throw new InvalidArgumentException('baseUrl must not point to a private or loopback address.');
        }

        if (null === $psrClient) {
            throw new InvalidArgumentException('An httpClient implementing Psr\Http\Client\ClientInterface is required.');
        }

        $http           = new HttpClient($baseUrl, $token, $psrClient);
        $this->projects = new ProjectResource($http);
    }
}
