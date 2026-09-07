<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Service;

use Survos\DataContracts\Vocabulary\MediaSyncKeys;
use Survos\MediaBundle\Dto\BatchDispatchResult;
use Survos\MediaBundle\Dto\ImportEnrichmentContext;
use Survos\MediaBundle\Dto\MediaProbeResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use RuntimeException;

final class MediaBatchDispatcher
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(MEDIARY_ENDPOINT)%')] private readonly string $mediaServerBaseUrl,
        /**
         * Shared secret for mediary's JSON-RPC methods. /api/v1 is PUBLIC_ACCESS at mediary's
         * firewall by design -- that is what lets an unauthenticated client reach it at all --
         * so each method authenticates on this instead. Must match mediary's MEDIARY_API_TOKEN.
         */
        #[Autowire('%env(default::MEDIARY_API_TOKEN)%')] private readonly ?string $apiToken = null,
        /**
         * Absolute URL mediary POSTs to when an asset finishes analysis.
         *
         * THIS IS THE LINK THAT WAS MISSING. mediary has always fired a webhook
         * to context['callback_url'] on completion (AssetWorkflow::onCompleted)
         * and even ships ReplayWebhooksCommand for redelivering failed ones —
         * but no client ever sent a URL, so the notification had nowhere to go.
         * The synchronous dispatch response necessarily predates the S3 upload,
         * so without this every media row kept its origin external_url forever
         * and imgproxy re-fetched from the origin on every cache miss.
         *
         * Set MEDIA_CALLBACK_URL to the app's webhook endpoint, e.g.
         * https://md.wip/webhook/mediary (mediary proxies .wip through the
         * symfony proxy automatically). Null disables the callback, restoring
         * the old dispatch-response-only behaviour.
         *
         * The path changed from /media/callback when the receiver moved to
         * symfony/webhook — the old endpoint had no authentication at all. Any
         * asset registered before that move still carries the OLD url in
         * mediary's context['callback_url']; re-registering overwrites it
         * (last-writer-wins), and mediary ships `webhook:migrate-callback-urls`
         * for the backlog. See survos-sites/mediary#8.
         */
        #[Autowire('%env(default::MEDIA_CALLBACK_URL)%')]
        private readonly ?string $callbackUrl = null,
    ) {
    }

    /** Covers TLS handshake + a cold container waking, independent of how many URLs are sent. */
    private const int BATCH_TIMEOUT_BASE = 15;

    /** Measured ~60ms/URL against production; 0.5s/URL is ~8x headroom for a loaded server. */
    private const float BATCH_TIMEOUT_PER_URL = 0.5;

    /** A batch slower than this is a server problem worth surfacing, not worth waiting out. */
    private const int BATCH_TIMEOUT_CAP = 120;

    /** mediary fetches each image inline on a sync request — a different kind of wait. */
    private const int SYNC_TIMEOUT = 120;

    /**
     * Dispatch a batch of ImportEnrichmentContext DTOs to mediary.
     *
     * Sends the full toValueMap() output as context for each URL so mediary
     * stores dcterms:title, dcterms:subject, content_type, etc. in sourceMeta.
     *
     * Claims are intentionally not folded into raw AI result blobs here. Apps
     * should publish selected source/AI/human claims alongside media as a
     * separate projection once the claims publishing contract is wired.
     *
     * @param ImportEnrichmentContext[] $enrichments  keyed by image URL (or imageUrlForAi())
     */
    public function dispatchEnrichments(string $client, array $enrichments, array $extra = []): BatchDispatchResult
    {
        $urls       = [];
        $contextMap = [];

        foreach ($enrichments as $url => $enrichment) {
            $imageUrl = is_string($url) ? $url : $enrichment->imageUrlForAi();
            if (!$imageUrl) continue;

            $urls[] = $imageUrl;
            $meta   = $enrichment->toValueMap();

            // Also include non-DC fields mediary needs to direct AI pipeline
            if ($enrichment->contentType) {
                $meta['content_type']     = $enrichment->contentType;
            }
            if ($enrichment->aggregator) {
                $meta['aggregator']       = $enrichment->aggregator;
            }
            if ($enrichment->iiifBase) {
                $meta['iiif_base']        = $enrichment->iiifBase;
            }
            if ($enrichment->thumbUrl) {
                $meta['thumbnail_url']    = $enrichment->thumbUrl;
            }
            if ($enrichment->id) {
                $meta['source_id']        = $enrichment->id;
            }

            $contextMap[$imageUrl] = $meta;
        }

        return $this->dispatch($client, $urls, array_merge(['context' => $contextMap], $extra));
    }

    /**
     * @param array $extra Additional top-level payload keys forwarded to mediary,
     *                     e.g. ['context' => [...], 'callback_url' => 'https://...'].
     */
    public function dispatch(string $client, array $urls, array $extra = []): BatchDispatchResult
    {
        // Lift modeled source claims out of each URL's context onto a dedicated
        // top-level map — mediary ingests these as @import claims on the record,
        // not as context/source-meta.
        if (isset($extra['context']) && is_array($extra['context'])) {
            $sourceClaims = [];
            foreach ($extra['context'] as $contextUrl => $ctx) {
                if (is_array($ctx) && isset($ctx[MediaSyncKeys::SOURCE_CLAIMS])) {
                    $sourceClaims[$contextUrl] = $ctx[MediaSyncKeys::SOURCE_CLAIMS];
                    unset($extra['context'][$contextUrl][MediaSyncKeys::SOURCE_CLAIMS]);
                }
            }
            if ($sourceClaims !== []) {
                $extra[MediaSyncKeys::SOURCE_CLAIMS] = $sourceClaims;
            }
        }

        // Tell mediary where to publish completion. Caller-supplied wins, so a
        // one-off sync can redirect callbacks (e.g. at a tunnel) without
        // changing config.
        if ($this->callbackUrl !== null && $this->callbackUrl !== '' && !isset($extra['callback_url'])) {
            $extra['callback_url'] = $this->callbackUrl;
        }

        // This is the media publication boundary. Keep payloads explicit:
        // media identity + source context now, selected claims later. Mediary
        // may run its own AI, but those results should also become claims.
        $options = [
            'json' => array_merge([
                'client'   => $client,
                'urls'     => $urls,
                'dispatch' => true,
            ], $extra),
        ];

        if (str_contains($this->mediaServerBaseUrl, '.wip')) {
            $options['proxy'] = 'http://127.0.0.1:7080';
        }

        // Registration is linear in batch size, so a single constant is wrong by construction: 10s
        // was fine against a mediary on localhost and fails against a real one. A cold dokku
        // container plus 100 URLs timed out at 10s having received 0 bytes, while 16 URLs to the
        // same host took 1.0s — the server was never the problem. Scale with the work instead, so
        // changing --batch-size can't silently reintroduce this.
        //
        // Sync downloads are different in kind (mediary fetches each image inline, 30-60s for large
        // ones), so they keep their own generous floor.
        $isSyncRequest = !empty($extra['sync']);
        $timeout = $isSyncRequest
            ? self::SYNC_TIMEOUT
            : min(self::BATCH_TIMEOUT_CAP, self::BATCH_TIMEOUT_BASE + count($urls) * self::BATCH_TIMEOUT_PER_URL);

        $options['timeout']      = $timeout;
        $options['max_duration'] = $timeout;

        $response = $this->httpClient->request(
            'POST',
            $url = sprintf('%s/%s/batch', rtrim($this->mediaServerBaseUrl, '/'), $client),
            $options
        );

        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new RuntimeException($url . "\n" . 'Media server batch call failed. ' . $status);
        }

        return BatchDispatchResult::fromArray($response->toArray());
    }

    /**
     * Probe one registered media item by mediary asset id.
     */
    public function probe(string $assetId): MediaProbeResult
    {
        $rows = $this->probeAssets([$assetId]);
        $row = $rows[0] ?? null;

        if ($row === null) {
            throw new RuntimeException(sprintf('Media server has no asset %s.', $assetId));
        }

        return MediaProbeResult::fromArray($row);
    }

    /**
     * Probe multiple registered media items by ids.
     *
     * @param list<string> $assetIds
     * @return list<MediaProbeResult>
     */
    public function probeMany(array $assetIds): array
    {
        return array_map(MediaProbeResult::fromArray(...), $this->probeAssets($assetIds));
    }

    /**
     * Both probes go through JSON-RPC `probeAssets`, not the old REST routes.
     *
     * GET /fetch/media/{id} and POST /fetch/media/by-ids are behind mediary's session
     * firewall: its security.yaml makes only ^/[^/]+/batch$, ^/api/v1$ and ^/api/claim-store/
     * PUBLIC_ACCESS, and everything else falls through `- { path: ^/, roles: ROLE_USER }`.
     * An unauthenticated client therefore got a 302 to /login -- and because Symfony's
     * HttpClient follows redirects, the failure did not even surface as a redirect: it came
     * back 200 with the login page's HTML and blew up inside toArray() as a JSON parse error,
     * which reads like mediary returned garbage rather than like we were never let in.
     *
     * /api/v1 is public at the firewall, and mediary's ProbeAssetsMethod serves it from the
     * same AssetProbeService the REST routes used, so the rows are identical by construction.
     *
     * @param list<string> $assetIds
     * @return list<array<string,mixed>>
     */
    private function probeAssets(array $assetIds): array
    {
        $ids = array_values(array_filter($assetIds, static fn (string $id): bool => $id !== ''));
        if ($ids === []) {
            return [];
        }

        $options = [
            'json' => [
                'jsonrpc' => '2.0',
                'method' => 'probeAssets',
                'params' => ['ids' => $ids, 'token' => (string) $this->apiToken],
                'id' => uniqid('probeAssets', true),
            ],
        ];

        // Same .wip rule the batch push uses -- a local mediary resolves only through the
        // Symfony CLI proxy, and one path working while the other silently cannot reach the
        // server is exactly the split that hides problems.
        if (str_contains($this->mediaServerBaseUrl, '.wip')) {
            $options['proxy'] = 'http://127.0.0.1:7080';
        }

        $response = $this->httpClient->request(
            'POST',
            rtrim($this->mediaServerBaseUrl, '/') . '/api/v1',
            $options,
        );

        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new RuntimeException(sprintf('Media server probe failed (%d).', $status));
        }

        /** @var array<string,mixed> $body */
        $body = $response->toArray(false);

        // A JSON-RPC error is a 200 with an `error` member, so checking the status alone would
        // read a failure as an empty result.
        if (isset($body['error'])) {
            throw new RuntimeException(sprintf(
                'Media server probe failed: %s (%s).',
                (string) ($body['error']['message'] ?? 'unknown error'),
                (string) ($body['error']['code'] ?? '-'),
            ));
        }

        /** @var list<array<string,mixed>> $assets */
        $assets = $body['result']['assets'] ?? [];

        return $assets;
    }
}
