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

        if (null === $psrClient) {
            throw new InvalidArgumentException('An httpClient implementing Psr\Http\Client\ClientInterface is required.');
        }

        $http           = new HttpClient($baseUrl, $token, $psrClient);
        $this->projects = new ProjectResource($http);
    }
}
