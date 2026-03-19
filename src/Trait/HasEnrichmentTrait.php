<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Trait;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\MeiliBundle\Metadata\Facet;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Stores enrich_from_thumbnail results in a `defaults` JSONB column and
 * exposes them as computed top-level properties for Meilisearch indexing,
 * API serialization, and faceting.
 *
 * Usage:
 *
 *   class MyEntity implements EnrichmentInterface
 *   {
 *       use HasEnrichmentTrait;
 *   }
 *
 * The `defaults` column intentionally carries other sub-keys too (storage,
 * capture_metadata, import, etc.) — enrich_from_thumbnail is just one pocket.
 * All computed getters read from defaults['enrich_from_thumbnail'] only.
 */
trait HasEnrichmentTrait
{
    #[ORM\Column(type: Types::JSON, nullable: false, options: ['jsonb' => true])]
    #[Groups(['image:read', 'enrichment:read'])]
    public array $defaults = [];

    // ── Interface implementation ───────────────────────────────────────────────

    public function getEnrichment(): array
    {
        return $this->defaults['enrich_from_thumbnail'] ?? [];
    }

    // ── Computed top-level fields — serialized into Meili as first-class keys ──
    // Each getter reads from getEnrichment() so there is one source of truth.
    // These are NOT stored columns — they are virtual, computed on read.

    /**
     * Flat list of keyword term strings across all confidence tiers.
     * Stored as a JSON array in Meili — full-text searchable and filterable.
     *
     * @return string[]
     */
    #[Groups(['image:read', 'enrichment:read'])]
    public function getEnrichKeywords(): array
    {
        $enrich = $this->getEnrichment();
        $all = array_merge(
            $enrich['keywords_high']   ?? $enrich['keywords'] ?? [],
            $enrich['keywords_medium'] ?? [],
            $enrich['keywords_low']    ?? [],
        );
        return array_values(array_unique(array_filter(array_map(
            static fn(mixed $kw): string => is_array($kw) ? (string) ($kw['term'] ?? '') : (string) $kw,
            $all
        ))));
    }

    /**
     * Dense narrative summary — the primary full-text search field.
     */
    #[Groups(['image:read', 'enrichment:read'])]
    public function getEnrichDenseSummary(): ?string
    {
        return $this->getEnrichment()['dense_summary'] ?? null;
    }

    /**
     * Content type classifier (e.g. "object", "document", "photograph").
     * Facetable.
     */
    #[Facet]
    #[Groups(['image:read', 'enrichment:read'])]
    public function getEnrichContentType(): ?string
    {
        $v = $this->getEnrichment()['content_type'] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * Whether the image contains significant text (drives OCR routing).
     * Facetable boolean.
     */
    #[Facet]
    #[Groups(['image:read', 'enrichment:read'])]
    public function getEnrichHasText(): ?bool
    {
        $enrich = $this->getEnrichment();
        return isset($enrich['has_text']) ? (bool) $enrich['has_text'] : null;
    }

    /**
     * Flat list of place name strings.
     *
     * @return string[]
     */
    #[Groups(['image:read', 'enrichment:read'])]
    public function getEnrichPlaces(): array
    {
        return array_values(array_filter(array_map(
            static fn(mixed $p): string => is_array($p) ? (string) ($p['name'] ?? '') : (string) $p,
            (array) ($this->getEnrichment()['places'] ?? [])
        )));
    }

    /**
     * Estimated date hint from the AI (free text, e.g. "1940s", "ca. 1923").
     * Facetable as a string.
     */
    #[Facet]
    #[Groups(['image:read', 'enrichment:read'])]
    public function getEnrichDateHint(): ?string
    {
        $v = $this->getEnrichment()['date_hint'] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * AI confidence score (0.0–1.0) for the whole enrichment run.
     */
    #[Groups(['image:read', 'enrichment:read'])]
    public function getEnrichConfidence(): ?float
    {
        $v = $this->getEnrichment()['confidence'] ?? null;
        return is_numeric($v) ? (float) $v : null;
    }

    /**
     * True if enrich_from_thumbnail has been run on this entity.
     */
    public function isEnriched(): bool
    {
        return $this->getEnrichment() !== [];
    }
}
