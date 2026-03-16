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

    // -- document views -----------------------------------------------------

    /**
     * Extract available document views from a record.
     *
     * Works with both public_view (new) and view_token (legacy) formats.
     * Returns a list of arrays with keys: label, token, type, variations,
     * default_variation.
     */
    public function getDocumentViews(array $record): array
    {
        $views = [];
        foreach (($record['public_view'] ?? []) as $entry) {
            $views[] = [
                'label' => $entry['label'] ?? '',
                'token' => $entry['view_token'] ?? '',
                'type' => 'public_view',
                'variations' => $entry['variations'] ?? null,
                'default_variation' => $entry['default_variation'] ?? null,
            ];
        }
        foreach (($record['view_token'] ?? []) as $entry) {
            $views[] = [
                'label' => $entry['label'] ?? '',
                'token' => $entry['view_token'] ?? '',
                'type' => 'view_token',
                'variations' => null,
                'default_variation' => null,
            ];
        }
        return $views;
    }

    /**
     * Build a document render/download URL from a record.
     *
     * Works with both public_view and view_token formats.
     * Prefers public_view when both are present on a record.
     *
     * @param array $record Entity from list() or get().
     * @param string $output Desired format: "html", "pdf", "jpg", or "context".
     * @param string|null $label Select a specific view by label (first if null).
     * @param string|null $variation Variation code for public_view entries (e.g. "v4").
     * @return string|null URL string, or null if the record has no view tokens.
     */
    public function getDocumentUrl(array $record, string $output = 'html', ?string $label = null, ?string $variation = null, bool $download = false): ?string
    {
        $views = $this->getDocumentViews($record);
        if (empty($views)) {
            return null;
        }

        $view = null;
        if ($label !== null) {
            foreach ($views as $v) {
                if ($v['label'] === $label) {
                    $view = $v;
                    break;
                }
            }
        }
        if ($view === null) {
            $view = $views[0];
        }

        $token = $view['token'];
        if (empty($token)) {
            return null;
        }

        if ($view['type'] === 'public_view') {
            $url = "{$this->host}/render/{$token}?output={$output}";
            $v = $variation ?? ($view['default_variation'] ?? null);
            if ($v !== null) {
                $url .= "&variation={$v}";
            }
            if ($download) {
                $url .= '&download=true';
            }
            return $url;
        }

        // Legacy view_token
        if ($output === 'pdf') {
            $action = $download ? 'pdf.download' : 'pdf.view';
        } elseif ($output === 'context') {
            $action = 'pdf.context';
        } else {
            $action = 'pdf.preview';
        }
        return "{$this->host}/documents/{$action}?token={$token}";
    }

    /**
     * Fetch document content (PDF, HTML, JPG, etc.) as a string of bytes.
     *
     * Builds the URL via getDocumentUrl() and performs a plain GET request.
     *
     * @param array $record Entity from list() or get().
     * @param string $output Desired format: "html", "pdf", "jpg", or "context".
     * @param string|null $label Select a specific view by label.
     * @param string|null $variation Variation code for public_view entries.
     * @return string|null Raw content bytes, or null if no view tokens.
     */
    public function getDocumentContent(array $record, string $output = 'html', ?string $label = null, ?string $variation = null, bool $download = false): ?string
    {
        $url = $this->getDocumentUrl($record, $output, $label, $variation, $download);
        if ($url === null) {
            return null;
        }
        $response = $this->http->get($url);
        return $response->getBody()->getContents();
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
