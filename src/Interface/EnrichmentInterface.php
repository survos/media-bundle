<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Interface;

/**
 * Legacy marker for entities carrying enrich_from_thumbnail results.
 *
 * @deprecated since survos/media-bundle 2.1. Prefer explicit claims/DTOs for
 * AI results and Survos\ImgproxyBundle\Contract\AiThumbnailProviderInterface
 * for low-resolution AI image URL integration.
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

    /**
     * Best available OCR text for this entity — highest-confidence ai:ocrText claim,
     * falling back to any stored transcript. Returns null when no OCR has run.
     * Override in entities that carry OCR (e.g. Image). Default: null.
     */
    public function bestOcr(): ?string;
}
