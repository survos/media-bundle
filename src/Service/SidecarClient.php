<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Service;

use Survos\DataContracts\Util\MediaKeyService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Read and write AI-task sidecars THROUGH mediary, over JSON-RPC.
 *
 * Replaces the old SidecarService, which apps injected directly. That made every client a second
 * writer into mediary's bucket namespace: its own S3 credentials, its own copy of the path
 * convention, its own idea of when a sidecar is stale. The same shape as an app keeping a Media
 * table beside mediary's Asset — two owners of one thing, each free to drift. mediary now owns
 * the store and apps ask for it, which also means caching, batching or a Redis layer can appear
 * on mediary's side without any client changing.
 *
 * The public API is deliberately identical to the service it replaces, so call sites change only
 * their injected type.
 *
 * {@see path()} stays local. It derives from MediaKeyService, which lives in data-contracts
 * precisely so both sides compute the same key without either depending on the other — so asking
 * mediary for a path would be a network round trip to learn something we can already compute, and
 * a chance for the two answers to disagree.
 */
final class SidecarClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(MEDIARY_ENDPOINT)%')] private readonly string $mediaServerBaseUrl,
        /** Must match mediary's SidecarService prefix, or both sides derive different keys. */
        private readonly string $prefix = 'o',
    ) {
    }

    /**
     * Whether sidecars are reachable at all. A client with no endpoint configured is a legitimate
     * setup (tests, an app that never uses AI results), so this is a question, not an assertion.
     */
    public function isAvailable(): bool
    {
        return $this->mediaServerBaseUrl !== '';
    }

    /** Storage key for the sidecar: <prefix>/a/bb/<id>.<task>.json — computed locally, no request. */
    public function path(string $id, string $task): string
    {
        return MediaKeyService::archivePathFromKey($id, $task . '.json', $this->prefix);
    }

    public function exists(string $id, string $task): bool
    {
        return ($this->rpc('sidecarGet', ['id' => $id, 'task' => $task])['found'] ?? false) === true;
    }

    /** The sidecar's contents, or null when it has not been produced yet (an ordinary miss). */
    public function read(string $id, string $task): ?array
    {
        $result = $this->rpc('sidecarGet', ['id' => $id, 'task' => $task]);

        return ($result['found'] ?? false) === true ? ($result['data'] ?? null) : null;
    }

    /**
     * Return the cached sidecar, or run $producer and cache its result.
     *
     * The compute-if-missing branch stays HERE rather than becoming an RPC method: $producer is a
     * callable and cannot cross the wire, and the work it represents — a paid AI call — belongs to
     * whoever wanted the answer, not to mediary.
     *
     * A failed write is not fatal. The value was computed and is being returned either way; all
     * that is lost is the cache, and the next caller will simply compute it again.
     *
     * @param callable(): array $producer
     */
    public function remember(string $id, string $task, callable $producer, bool $force = false): array
    {
        if (!$force) {
            $cached = $this->read($id, $task);
            if ($cached !== null) {
                return $cached;
            }
        }

        $value = $producer();
        $this->rpc('sidecarPut', ['id' => $id, 'task' => $task, 'data' => $value]);

        return $value;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function rpc(string $method, array $params): array
    {
        $options = [
            'json' => [
                'jsonrpc' => '2.0',
                'method' => $method,
                'params' => $params,
                'id' => uniqid($method, true),
            ],
        ];

        // Local mediary is served on a .wip host that resolves only through the Symfony CLI proxy;
        // same rule MediaBatchDispatcher applies, kept identical so one can't work while the
        // other silently cannot reach the server.
        if (str_contains($this->mediaServerBaseUrl, '.wip')) {
            $options['proxy'] = 'http://127.0.0.1:7080';
        }

        $response = $this->httpClient->request(
            'POST',
            rtrim($this->mediaServerBaseUrl, '/') . '/api/v1',
            $options,
        );

        /** @var array<string, mixed> $body */
        $body = $response->toArray(false);

        return is_array($body['result'] ?? null) ? $body['result'] : [];
    }
}
