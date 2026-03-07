<?php

namespace Telex\Sdk\Resources;

use Telex\Sdk\Http\HttpClient;

class ProjectResource
{
    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * @param array{page?: int, perPage?: int} $params
     * @return array{projects: array<int, array{publicId: string, name: string, slug: string, projectType: ?string}>, page: int, perPage: int, total: int, totalPages: int}
     */
    public function list(array $params = []): array
    {
        $query = [];
        if (isset($params['page'])) {
            $query['page'] = (string) $params['page'];
        }
        if (isset($params['perPage'])) {
            $query['per_page'] = (string) $params['perPage'];
        }

        return $this->http->get('/api/v1/projects', $query);
    }

    /**
     * @return array{publicId: string, name: string, slug: string, projectType: ?string, currentVersion: int, createdAt: ?string, updatedAt: ?string, artefactXML: string, isShared: bool, isOwner: bool, images: array<int, mixed>}
     */
    public function get(string $publicId): array
    {
        $id = rawurlencode($publicId);
        return $this->http->get("/api/v1/projects/$id");
    }

    /**
     * @return array{files?: array<int, array{path: string, size: int}>, totalFiles?: int, totalSize?: int, status?: string}
     */
    public function getBuild(string $publicId): array
    {
        $id = rawurlencode($publicId);
        return $this->http->get("/api/v1/projects/$id/build");
    }

    public function getBuildFile(string $publicId, string $path): string
    {
        $id = rawurlencode($publicId);
        return $this->http->getRaw("/api/v1/projects/$id/build/file", ['path' => $path]);
    }
}
