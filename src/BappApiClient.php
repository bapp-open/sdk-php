<?php

namespace Bapp;

use GuzzleHttp\Client;

class PagedList implements \ArrayAccess, \Countable, \IteratorAggregate
{
    public int $count;
    public ?string $next;
    public ?string $previous;
    private array $results;

    public function __construct(array $results, int $count = 0, ?string $next = null, ?string $previous = null)
    {
        $this->results = $results;
        $this->count = $count;
        $this->next = $next;
        $this->previous = $previous;
    }

    public function toArray(): array { return $this->results; }
    public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->results); }
    public function count(): int { return count($this->results); }
    public function offsetExists(mixed $offset): bool { return isset($this->results[$offset]); }
    public function offsetGet(mixed $offset): mixed { return $this->results[$offset]; }
    public function offsetSet(mixed $offset, mixed $value): void { $this->results[$offset] = $value; }
    public function offsetUnset(mixed $offset): void { unset($this->results[$offset]); }
}

class BappApiClient
{
    public string $host;
    public ?string $tenant;
    public string $app;
    private ?string $authHeader = null;
    private Client $http;

    public function __construct(array $options = [])
    {
        $this->host = rtrim($options['host'] ?? 'https://panel.bapp.ro/api', '/');
        $this->tenant = $options['tenant'] ?? null;
        $this->app = $options['app'] ?? 'account';

        if (isset($options['bearer'])) {
            $this->authHeader = 'Bearer ' . $options['bearer'];
        } elseif (isset($options['token'])) {
            $this->authHeader = 'Token ' . $options['token'];
        }

        $this->http = new Client();
    }

    private function buildHeaders(array $extra = []): array
    {
        $h = [];
        if ($this->authHeader !== null) {
            $h['Authorization'] = $this->authHeader;
        }
        if ($this->tenant !== null) {
            $h['x-tenant-id'] = (string) $this->tenant;
        }
        if ($this->app !== null) {
            $h['x-app-slug'] = $this->app;
        }
        return array_merge($h, $extra);
    }

    private function hasFiles(?array $data): bool
    {
        if ($data === null) {
            return false;
        }
        foreach ($data as $v) {
            if ($v instanceof \CURLFile || $v instanceof \SplFileInfo || (is_resource($v) && get_resource_type($v) === 'stream')) {
                return true;
            }
        }
        return false;
    }

    private function request(string $method, string $path, array $options = []): mixed
    {
        $url = $this->host . $path;
        $reqOptions = ['headers' => $this->buildHeaders($options['headers'] ?? [])];

        if (isset($options['params'])) {
            $reqOptions['query'] = $options['params'];
        }
        if (isset($options['json']) && $this->hasFiles($options['json'])) {
            $multipart = [];
            foreach ($options['json'] as $k => $v) {
                if ($v instanceof \CURLFile) {
                    $multipart[] = [
                        'name' => $k,
                        'contents' => fopen($v->getFilename(), 'r'),
                        'filename' => $v->getPostFilename(),
                    ];
                } elseif ($v instanceof \SplFileInfo) {
                    $multipart[] = [
                        'name' => $k,
                        'contents' => fopen($v->getPathname(), 'r'),
                        'filename' => $v->getFilename(),
                    ];
                } elseif (is_resource($v)) {
                    $multipart[] = ['name' => $k, 'contents' => $v];
                } else {
                    $multipart[] = ['name' => $k, 'contents' => (string) $v];
                }
            }
            $reqOptions['multipart'] = $multipart;
        } elseif (isset($options['json'])) {
            $reqOptions['json'] = $options['json'];
        }

        $response = $this->http->request($method, $url, $reqOptions);

        if ($response->getStatusCode() === 204) {
            return null;
        }
        return json_decode($response->getBody()->getContents(), true);
    }

    // -- user ---------------------------------------------------------------

    public function me(): mixed
    {
        return $this->request('GET', '/tasks/bapp_framework.me', [
            'headers' => ['x-app-slug' => ''],
        ]);
    }

    // -- app ----------------------------------------------------------------

    public function getApp(string $appSlug): mixed
    {
        return $this->request('GET', '/tasks/bapp_framework.getapp', [
            'headers' => ['x-app-slug' => $appSlug],
        ]);
    }

    // -- entity introspect --------------------------------------------------

    public function listIntrospect(string $contentType): mixed
    {
        return $this->request('GET', '/tasks/bapp_framework.listintrospect', [
            'params' => ['ct' => $contentType],
        ]);
    }

    public function detailIntrospect(string $contentType, ?string $pk = null): mixed
    {
        $params = ['ct' => $contentType];
        if ($pk !== null) {
            $params['pk'] = $pk;
        }
        return $this->request('GET', '/tasks/bapp_framework.detailintrospect', [
            'params' => $params,
        ]);
    }

    // -- entity CRUD --------------------------------------------------------

    public function list(string $contentType, array $filters = []): PagedList
    {
        $data = $this->request('GET', "/content-type/$contentType/", [
            'params' => $filters ?: null,
        ]);
        return new PagedList(
            $data['results'] ?? [],
            $data['count'] ?? 0,
            $data['next'] ?? null,
            $data['previous'] ?? null,
        );
    }

    public function get(string $contentType, string $id): mixed
    {
        return $this->request('GET', "/content-type/$contentType/$id/");
    }

    public function create(string $contentType, ?array $data = null): mixed
    {
        return $this->request('POST', "/content-type/$contentType/", [
            'json' => $data,
        ]);
    }

    public function update(string $contentType, string $id, ?array $data = null): mixed
    {
        return $this->request('PUT', "/content-type/$contentType/$id/", [
            'json' => $data,
        ]);
    }

    public function patch(string $contentType, string $id, ?array $data = null): mixed
    {
        return $this->request('PATCH', "/content-type/$contentType/$id/", [
            'json' => $data,
        ]);
    }

    public function delete(string $contentType, string $id): mixed
    {
        return $this->request('DELETE', "/content-type/$contentType/$id/");
    }

    // -- tasks --------------------------------------------------------------

    public function listTasks(): mixed
    {
        return $this->request('GET', '/tasks');
    }

    public function detailTask(string $code): mixed
    {
        return $this->request('OPTIONS', "/tasks/$code");
    }

    public function runTask(string $code, mixed $payload = null): mixed
    {
        if ($payload === null) {
            return $this->request('GET', "/tasks/$code");
        }
        return $this->request('POST', "/tasks/$code", ['json' => $payload]);
    }

    /**
     * Run a long-running task and poll until finished.
     *
     * @param string $code Task code.
     * @param mixed $payload Task payload (null for GET).
     * @param int $pollInterval Seconds between polls.
     * @param int $timeout Max seconds to wait.
     * @return mixed Final task data with 'file' key when applicable.
     * @throws \RuntimeException on failure or timeout.
     */
    public function runTaskAsync(string $code, mixed $payload = null, int $pollInterval = 1, int $timeout = 300): mixed
    {
        $result = $this->runTask($code, $payload);
        $taskId = $result['id'] ?? null;
        if ($taskId === null) {
            return $result;
        }

        $deadline = time() + $timeout;
        while (time() < $deadline) {
            sleep($pollInterval);
            $page = $this->list('bapp_framework.taskdata', ['id' => $taskId]);
            if (count($page) === 0) {
                continue;
            }
            $taskData = $page[0];
            if (!empty($taskData['failed'])) {
                throw new \RuntimeException("Task $code failed: " . ($taskData['message'] ?? ''));
            }
            if (!empty($taskData['finished'])) {
                return $taskData;
            }
        }
        throw new \RuntimeException("Task $code ($taskId) did not finish within {$timeout}s");
    }
}
