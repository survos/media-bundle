<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Trait;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\MediaBundle\Dto\MediaEnrichment;
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
        $stored = $this->defaults['enrich_from_thumbnail'] ?? null;
        if (is_array($stored) && $stored !== []) {
            return $this->normalizeEnrichmentPayload($stored);
        }

        foreach ($this->aiCompleted ?? [] as $entry) {
            if (($entry['task'] ?? null) !== 'enrich_from_thumbnail') {
                continue;
            }

            $result = $entry['result'] ?? null;
            if (is_array($result) && $result !== []) {
                return $this->normalizeEnrichmentPayload($result);
            }
        }

        $dto = $this->getMediaEnrichmentDto();
        if ($dto === null) {
            return [];
        }

        return array_filter([
            'title' => $dto->title,
            'description' => $dto->description,
            'dense_summary' => $dto->denseSummary ?? $dto->summary,
            'content_type' => $dto->contentType ?? $dto->documentType,
            'date_hint' => $dto->dateHint,
            'keywords' => $dto->keywords,
            'keywords_high' => $dto->keywords,
            'people' => $dto->people,
            'places' => $dto->places,
            'has_text' => $dto->hasText ? true : null,
            'confidence' => $dto->confidence,
            'speculations' => $dto->speculations,
        ], static fn(mixed $value): bool => $value !== null && $value !== [] && $value !== false);
    }

    /**
     * Normalize enrich_from_thumbnail data into the canonical shape consumed by
     * computed getters, regardless of whether it came from defaults or aiCompleted.
     *
     * @param array<string,mixed> $enrich
     * @return array<string,mixed>
     */
    private function normalizeEnrichmentPayload(array $enrich): array
    {
        $keywordEntries = array_merge(
            $this->normalizeKeywordEntries($enrich['keywords'] ?? null, 'medium'),
            $this->normalizeKeywordEntries($enrich['keywords_high'] ?? null, 'high'),
            $this->normalizeKeywordEntries($enrich['keywords_medium'] ?? null, 'medium'),
            $this->normalizeKeywordEntries($enrich['keywords_low'] ?? null, 'low'),
        );

        $keywordEntries = $this->dedupeKeywordEntries($keywordEntries);

        $enrich['keywords'] = $keywordEntries;
        $enrich['keywords_high'] = $this->keywordTermsForConfidence($keywordEntries, 'high');
        $enrich['keywords_medium'] = $this->keywordTermsForConfidence($keywordEntries, 'medium');
        $enrich['keywords_low'] = $this->keywordTermsForConfidence($keywordEntries, 'low');

        return $enrich;
    }

    /**
     * @return list<array{term:string,confidence:string,basis:?string}>
     */
    private function normalizeKeywordEntries(mixed $keywords, string $defaultConfidence): array
    {
        if (!is_array($keywords) || $keywords === []) {
            return [];
        }

        $entries = [];

        if ($this->looksLikeTripletKeywords($keywords)) {
            for ($i = 0; $i < count($keywords); $i += 3) {
                $term = trim((string) $keywords[$i]);
                $confidence = $this->normalizeKeywordConfidence($keywords[$i + 1] ?? $defaultConfidence, $defaultConfidence);
                $basis = trim((string) ($keywords[$i + 2] ?? ''));
                if ($term !== '') {
                    $entries[] = [
                        'term' => $term,
                        'confidence' => $confidence,
                        'basis' => $basis !== '' ? $basis : null,
                    ];
                }
            }

            return $entries;
        }

        foreach ($keywords as $keyword) {
            if (is_string($keyword) || is_numeric($keyword)) {
                $term = trim((string) $keyword);
                if ($term !== '') {
                    $entries[] = [
                        'term' => $term,
                        'confidence' => $defaultConfidence,
                        'basis' => null,
                    ];
                }
                continue;
            }

            if (!is_array($keyword)) {
                continue;
            }

            $term = trim((string) ($keyword['term'] ?? $keyword['keyword'] ?? $keyword['name'] ?? ''));
            if ($term === '') {
                continue;
            }

            $basis = $keyword['basis'] ?? null;
            $entries[] = [
                'term' => $term,
                'confidence' => $this->normalizeKeywordConfidence($keyword['confidence'] ?? $defaultConfidence, $defaultConfidence),
                'basis' => is_string($basis) && trim($basis) !== '' ? trim($basis) : null,
            ];
        }

        return $entries;
    }

    /**
     * @param list<mixed> $keywords
     */
    private function looksLikeTripletKeywords(array $keywords): bool
    {
        if (!array_is_list($keywords) || count($keywords) < 3 || count($keywords) % 3 !== 0) {
            return false;
        }

        for ($i = 1; $i < count($keywords); $i += 3) {
            if (!is_string($keywords[$i])) {
                return false;
            }

            if (!in_array(strtolower(trim($keywords[$i])), ['high', 'medium', 'low'], true)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeKeywordConfidence(mixed $confidence, string $fallback): string
    {
        $normalized = is_string($confidence) ? strtolower(trim($confidence)) : '';

        return in_array($normalized, ['high', 'medium', 'low'], true)
            ? $normalized
            : $fallback;
    }

    /**
     * @param list<array{term:string,confidence:string,basis:?string}> $entries
     * @return list<array{term:string,confidence:string,basis:?string}>
     */
    private function dedupeKeywordEntries(array $entries): array
    {
        $deduped = [];

        foreach ($entries as $entry) {
            $key = strtolower($entry['term']);
            if (!isset($deduped[$key])) {
                $deduped[$key] = $entry;
                continue;
            }

            if ($deduped[$key]['basis'] === null && $entry['basis'] !== null) {
                $deduped[$key]['basis'] = $entry['basis'];
            }

            if ($this->keywordConfidenceRank($entry['confidence']) > $this->keywordConfidenceRank($deduped[$key]['confidence'])) {
                $deduped[$key]['confidence'] = $entry['confidence'];
            }
        }

        return array_values($deduped);
    }

    private function keywordConfidenceRank(string $confidence): int
    {
        return match ($confidence) {
            'high' => 3,
            'medium' => 2,
            'low' => 1,
            default => 0,
        };
    }

    /**
     * @param list<array{term:string,confidence:string,basis:?string}> $entries
     * @return string[]
     */
    private function keywordTermsForConfidence(array $entries, string $confidence): array
    {
        return array_values(array_map(
            static fn(array $entry): string => $entry['term'],
            array_values(array_filter(
                $entries,
                static fn(array $entry): bool => $entry['confidence'] === $confidence
            ))
        ));
    }

    /**
     * Source (human-provided) metadata mapped to dcterms: keys
     * for use with <twig:SourceMetadata :ctx="entity.sourceMeta" />.
     *
     * Reads from $defaults['import'] (snake_case normalized JSONL keys)
     * and maps to the dcterms: namespace the SourceMetadata component expects.
     *
     * @return array<string,mixed>
     */
    public function getSourceMeta(): array
    {
        $import = $this->defaults['import'] ?? [];
        if ($import === []) {
            return [];
        }

        // Priority-ordered: first matching key wins for each dcterms: field
        $map = [
            'title'         => 'dcterms:title',
            'description'   => 'dcterms:description',
            'date_display'  => 'dcterms:date',
            'date'          => 'dcterms:date',
            'creators'      => 'dcterms:creator',
            'subjects'      => 'dcterms:subject',
            'subject_facet' => 'dcterms:subject',
            'collections'   => 'dcterms:isPartOf',
            'institution'   => 'dcterms:publisher',
            'language'      => 'dcterms:language',
            'rights'        => 'rights',         // legacy alias handled by SourceMetadata
            'license'       => 'license_uri',
            'reuse_allowed' => 'reuse_allowed',
            'source_url'    => 'dcterms:source',
            'detail_url'    => 'dcterms:source',
        ];

        $out = [];
        foreach ($map as $src => $dst) {
            if (!isset($out[$dst]) && isset($import[$src]) && $import[$src] !== null && $import[$src] !== '') {
                $out[$dst] = $import[$src];
            }
        }

        // content_type drives type-specific field display
        if (isset($import['content_type'])) {
            $out['content_type'] = $import['content_type'];
        } elseif (isset($import['genre_basic'][0])) {
            $out['content_type'] = strtolower((string) $import['genre_basic'][0]);
        }

        // Geo extras — shown in catch-all section
        foreach (['latitude', 'longitude', 'city', 'state', 'county', 'country'] as $geo) {
            if (isset($import[$geo]) && $import[$geo] !== null) {
                $out[$geo] = $import[$geo];
            }
        }

        return $out;
    }

    /**
     * All AI task results keyed by task name, ready for
     * <twig:AiMetadata :results="entity.aiResults" />.
     *
     * Merges legacy per-task aiCompleted[] with enrich_from_thumbnail
     * from defaults (which the pipeline stores separately).
     *
     * @return array<string,mixed>
     */
    public function getAiResults(): array
    {
        $results = [];

        // Legacy per-task entries from aiCompleted column
        foreach ($this->aiCompleted ?? [] as $entry) {
            if (is_array($entry) && isset($entry['task'])) {
                $results[$entry['task']] = $entry['result'] ?? $entry;
            }
        }

        // enrich_from_thumbnail lives in defaults, not aiCompleted
        $enrich = $this->defaults['enrich_from_thumbnail'] ?? null;
        if ($enrich !== null) {
            $results['enrich_from_thumbnail'] = $enrich;
        }

        return $results;
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

    /**
     * Typed aggregate of all AI task results — suitable for the MediaShow
     * "Media Enrichment" display tab and for zm import value mapping.
     *
     * Built from aiCompleted[] (the per-task log column). Falls back to null
     * when no tasks have completed yet.
     */
    public function getMediaEnrichmentDto(): ?MediaEnrichment
    {
        $completed = $this->aiCompleted ?? [];
        if ($completed === []) {
            return null;
        }
        return MediaEnrichment::fromCompleted($completed);
    }
}
