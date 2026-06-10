<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Dto;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * The `media:sync` wire contract — the JSON body of mediary's `/{client}/batch`.
 *
 * Typed so Symfony can deserialize it straight into a controller argument via
 * `#[MapRequestPayload]` (no hand-rolled `$request->toArray()` parsing) and so the
 * producer (md's MediaBatchDispatcher) and consumer (mediary's BatchController)
 * can never drift.
 *
 * The wire stays FLAT and non-breaking: `context` is the per-URL hint map exactly
 * as today (directives like `dataset`/`ai_queue` mixed with `dcterms:*` hints).
 * Use {@see itemFor()} for a typed view of any URL's entry. The reserved key
 * names still live in {@see BatchItemDto} for now; they move onto these
 * properties (and `MediaSyncKeys` retires) in a second pass once both ends parse
 * through this DTO.
 *
 * Shape:
 *   { client, urls[], dispatch, sync?, callback_url?,
 *     context: { <url>: { …hints, dataset, media_record_key, ai_queue } },
 *     sourceClaims: { <url>: list<claim> } }
 */
final class BatchPayloadDto
{
    /**
     * @param list<string>                              $urls         image URLs to register
     * @param array<string, array<string, mixed>>       $context      per-URL flat hint map, keyed by url
     * @param array<string, list<array<string,mixed>>>  $sourceClaims per-URL modeled @import claims, keyed by url
     */
    public function __construct(
        public string $client = '',
        public array $urls = [],
        public array $context = [],
        public array $sourceClaims = [],
        public bool $dispatch = true,
        public bool $sync = false,
        #[SerializedName('callback_url')]
        public ?string $callbackUrl = null,
    ) {
    }

    /** Typed view of one URL's context entry (empty DTO when absent). */
    public function itemFor(string $url): BatchItemDto
    {
        $ctx = $this->context[$url] ?? null;

        return BatchItemDto::fromArray(is_array($ctx) ? $ctx : []);
    }

    /** @return list<array<string,mixed>> modeled @import claims for a URL */
    public function claimsFor(string $url): array
    {
        $claims = $this->sourceClaims[$url] ?? null;

        return is_array($claims) ? $claims : [];
    }

    /** @return list<string> de-duplicated, non-empty URLs */
    public function cleanUrls(): array
    {
        return array_values(array_unique(array_filter(
            $this->urls,
            static fn ($u): bool => is_string($u) && $u !== '',
        )));
    }
}
