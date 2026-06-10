<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Dto;

use Survos\MediaBundle\Contract\MediaSyncKeys;

/**
 * Per-URL transport/context within a {@see BatchPayloadDto}.
 *
 * The structured replacement for shipping a media row's `rawData` grab-bag.
 * It carries DIRECTIVES (dataset scope, record grouping key, AI task queue) plus
 * residual source-metadata hints — NOT modeled facts, which travel separately as
 * sourceClaims and are keyed by URL at the payload top level.
 */
final class BatchItemDto
{
    /**
     * @param list<string>         $aiQueue    AI task names to seed on the mediary Asset (directive, not a claim)
     * @param array<string, mixed> $sourceMeta residual hints mediary stores in sourceMeta (dcterms:*, content_type, iiif_*, thumbnail_url)
     */
    public function __construct(
        public ?string $dataset = null,
        public ?string $recordKey = null,
        public array $aiQueue = [],
        public array $sourceMeta = [],
    ) {
    }

    /**
     * Emit the wire shape the mediary BatchController already reads: a flat
     * context map of source-meta hints with the reserved directive keys mixed in.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = $this->sourceMeta;
        if ($this->dataset !== null && $this->dataset !== '') {
            $out[MediaSyncKeys::DATASET] = $this->dataset;
        }
        if ($this->recordKey !== null && $this->recordKey !== '') {
            $out[MediaSyncKeys::RECORD_KEY] = $this->recordKey;
        }
        if ($this->aiQueue !== []) {
            $out[MediaSyncKeys::AI_QUEUE] = array_values($this->aiQueue);
        }

        return $out;
    }

    /**
     * Parse a per-URL context entry, lifting the reserved directive keys out of
     * the residual source-meta hints.
     *
     * @param array<string, mixed> $ctx
     */
    public static function fromArray(array $ctx): self
    {
        $dataset   = $ctx[MediaSyncKeys::DATASET] ?? null;
        $recordKey = $ctx[MediaSyncKeys::RECORD_KEY] ?? null;
        $aiQueue   = is_array($ctx[MediaSyncKeys::AI_QUEUE] ?? null) ? $ctx[MediaSyncKeys::AI_QUEUE] : [];

        unset(
            $ctx[MediaSyncKeys::DATASET],
            $ctx[MediaSyncKeys::RECORD_KEY],
            $ctx[MediaSyncKeys::AI_QUEUE],
        );

        return new self(
            dataset: is_string($dataset) ? $dataset : null,
            recordKey: is_string($recordKey) ? $recordKey : null,
            aiQueue: array_values(array_filter($aiQueue, 'is_string')),
            sourceMeta: $ctx,
        );
    }
}
