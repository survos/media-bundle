<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Service;

use Survos\MediaBundle\Dto\BatchDispatchResult;
use Survos\MediaBundle\Dto\MediaEnrichment;
use Survos\MediaBundle\Dto\MediaProbeResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use RuntimeException;

final class MediaBatchDispatcher
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(MEDIARY_ENDPOINT)%')] private readonly string $mediaServerBaseUrl,
    ) {
    }

    /**
     * Dispatch a batch of MediaEnrichment DTOs to mediary.
     *
     * Sends the full toValueMap() output as context for each URL so mediary
     * stores dcterms:title, dcterms:subject, content_type, etc. in sourceMeta.
     * This replaces ad-hoc aiTasks lists — the DTO's null fields drive what AI fills.
     *
     * @param MediaEnrichment[] $enrichments  keyed by image URL (or imageUrlForAi())
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

        // Sync downloads can take 30-60s for large images — use a generous timeout
        $isSyncRequest = !empty($extra['sync']);
        $options['timeout']      = $isSyncRequest ? 120 : 10;
        $options['max_duration'] = $isSyncRequest ? 120 : 10;

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
     * Probe one registered media item by SAIS/Mediary asset id.
     */
    public function probe(string $assetId): MediaProbeResult
    {
        $options = [];
        if (str_contains($this->mediaServerBaseUrl, '.wip')) {
            $options['proxy'] = 'http://127.0.0.1:7080';
        }

        $url = sprintf('%s/fetch/media/%s', rtrim($this->mediaServerBaseUrl, '/'), rawurlencode($assetId));
        $response = $this->httpClient->request('GET', $url, $options);
        $status = $response->getStatusCode();

        if ($status !== 200) {
            throw new RuntimeException(sprintf('Media server probe failed (%d) for %s.', $status, $assetId));
        }

        return MediaProbeResult::fromArray($response->toArray());
    }

    /**
     * Probe multiple registered media items by ids.
     *
     * @param list<string> $assetIds
     * @return list<MediaProbeResult>
     */
    public function probeMany(array $assetIds): array
    {
        $ids = array_values(array_filter($assetIds, static fn (string $id): bool => $id !== ''));
        if ($ids === []) {
            return [];
        }

        $options = [
            'json' => ['ids' => $ids],
        ];
        if (str_contains($this->mediaServerBaseUrl, '.wip')) {
            $options['proxy'] = 'http://127.0.0.1:7080';
        }

        $url = sprintf('%s/fetch/media/by-ids', rtrim($this->mediaServerBaseUrl, '/'));
        $response = $this->httpClient->request('POST', $url, $options);
        $status = $response->getStatusCode();

        if ($status !== 200) {
            throw new RuntimeException(sprintf('Media server batch probe failed (%d).', $status));
        }

        /** @var list<array<string,mixed>> $rows */
        $rows = $response->toArray();
        return array_map(MediaProbeResult::fromArray(...), $rows);
    }
}
