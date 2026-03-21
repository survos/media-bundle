<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Interface;

/**
 * Marks an entity as carrying enrich_from_thumbnail results.
 * Use HasEnrichmentTrait to get the column + computed getters automatically.
 */
interface EnrichmentInterface
{
    public function getEnrichment(): array;

    /** All AI task results keyed by task name. Consumed by AiMetadata and MediaShow components. */
    public function getAiResults(): array;

    /** Source/import metadata as a dcterms:-keyed array. Consumed by SourceMetadata and MediaShow. */
    public function getSourceMeta(): array;

    /** Typed aggregate of all AI task results. Consumed by MediaShow "Media Enrichment" tab. */
    public function getMediaEnrichmentDto(): ?\Survos\MediaBundle\Dto\MediaEnrichment;
}
