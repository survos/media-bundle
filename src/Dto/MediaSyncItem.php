<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Dto;

use Survos\DataBundle\Vocabulary\DcTerms;
use Survos\ImportBundle\Dto\Attributes\Map;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\With;

/**
 * Typed DTO carrying all metadata needed to register a media asset with mediary.
 *
 * Populated from a normalized JSONL row via DtoMapper::mapRecord().
 * The `source:` on each #[Map] names the key in the normalized row
 * (output of DcSetRecordListener / import:convert).
 *
 * `url` is not in the normalized row — it is derived in afterMap() from
 * iiif_base (appending /full/max/0/default.jpg) or thumbnail_url fallback.
 *
 * PHPDoc provides the JSON Schema description via PropertyInfoDescriber.
 * The dc_term is noted in each docblock; when Symfony AI adds `description:`
 * to #[With], it should move there.
 *
 * Usage:
 *   $item = $dtoMapper->mapRecord($normalizedRow, MediaSyncItem::class);
 *   if ($item->isPermitted()) { ... }
 *
 * @see DcTerms for the vocabulary of dc_term values used below
 */
final class MediaSyncItem
{
    // ── Image URL (derived in afterMap) ───────────────────────────────────

    /**
     * The image URL used as the primary identity key in mediary.
     * Derived from iiif_base + '/full/max/0/default.jpg' when available,
     * falling back to thumbnail_url.
     * Not mapped directly — set by afterMap().
     * @var string|null
     */
    public ?string $url = null;

    // ── Rights — never AI-extracted, must come from authoritative source ──

    /**
     * dc_term: dcterms:accessRights
     * Reuse permission granted by the holding institution.
     * Controls whether this item is eligible for mediary storage.
     * @var string|null
     */
    #[Map(source: 'reuse_allowed', facet: true)]
    #[With(enum: ['no restrictions', 'creative commons', 'contact host', 'all rights reserved'])]
    public ?string $reuseAllowed = null;

    /**
     * dc_term: dcterms:rights
     * Human-readable rights statement from the source record.
     * @var string|null
     */
    #[Map(source: 'rights')]
    public ?string $rights = null;

    /**
     * dc_term: dcterms:license
     * Machine-readable license URI.
     * @var string|null
     */
    #[Map(source: 'license')]
    public ?string $licenseUri = null;

    // ── Identity / provenance ─────────────────────────────────────────────

    /**
     * Short ARK identifier from the source system.
     * For DC: "tb09jw368" (from "commonwealth:tb09jw368").
     * dc_term: dcterms:identifier
     * @var string|null
     */
    #[Map(source: 'ark_id')]
    public ?string $sourceArk = null;

    /**
     * Full source system ID including institution prefix.
     * Example: "commonwealth:tb09jw368"
     * @var string|null
     */
    #[Map(source: 'id')]
    public ?string $sourceId = null;

    /**
     * Aggregator / provider code: dc | euro | pp | mds | smith
     * Not in the normalized row — set externally before dispatch.
     * @var string|null
     */
    #[With(enum: ['dc', 'euro', 'pp', 'mds', 'smith'])]
    public ?string $aggregator = null;

    // ── Core Dublin Core fields ───────────────────────────────────────────

    /**
     * dc_term: dcterms:title
     * Primary title of the object.
     * @var string|null
     */
    #[Map(source: 'title', searchable: true)]
    public ?string $title = null;

    /**
     * dc_term: dcterms:description
     * Abstract or descriptive note about the object.
     * @var string|null
     */
    #[Map(source: 'description', searchable: true)]
    public ?string $description = null;

    /**
     * dc_term: dcterms:date
     * Date of creation or publication (ISO 8601 preferred).
     * Source may be an array — first element is taken in afterMap().
     * Declared as mixed so DtoMapper doesn't coerce the array to a string
     * before afterMap() can extract the first element.
     * @var string|null
     */
    #[Map(source: 'date', sortable: true)]
    public mixed $date = null;

    /**
     * dc_term: dcterms:type
     * Nature or genre using DCMI Type vocabulary.
     * Source field: type_of_resource (array — first element taken).
     * Declared as mixed so DtoMapper doesn't coerce the array to a string
     * before afterMap() can normalize it.
     * @var string|null
     */
    #[Map(source: 'type_of_resource')]
    #[With(enum: ['Text', 'StillImage', 'PhysicalObject', 'Sound', 'MovingImage', 'Dataset', 'Collection'])]
    public mixed $type = null;

    /**
     * dc_term: dcterms:creator
     * Entity primarily responsible for making the object.
     * @var string[]|null
     */
    #[Map(source: 'name_facet', searchable: true, facet: true)]
    public ?array $creator = null;

    /**
     * dc_term: dcterms:subject
     * Subject keywords and controlled vocabulary terms.
     * @var string[]|null
     */
    #[Map(source: 'subject_facet', searchable: true, facet: true)]
    public ?array $subject = null;

    /**
     * dc_term: dcterms:publisher
     * Holding institution name.
     * @var string|null
     */
    #[Map(source: 'institution', facet: true)]
    public ?string $institution = null;

    /**
     * dc_term: dcterms:isPartOf
     * Collection name(s) the object belongs to.
     * @var string[]|null
     */
    #[Map(source: 'collections', facet: true)]
    public ?array $collection = null;

    // ── IIIF ──────────────────────────────────────────────────────────────

    /**
     * IIIF Image API base URL (everything before /full/…).
     * Example: https://iiif.digitalcommonwealth.org/iiif/2/commonwealth%3Atb09jw37j
     * The image URL is derived from this by appending /full/max/0/default.jpg
     * @var string|null
     */
    #[Map(source: 'iiif_base')]
    public ?string $iiifBase = null;

    /**
     * IIIF Presentation API manifest URL.
     * @var string|null
     */
    #[Map(source: 'iiif_manifest')]
    public ?string $iiifManifest = null;

    /**
     * Pre-built thumbnail URL (display only, not used as mediary identity).
     * @var string|null
     */
    #[Map(source: 'thumbnail_url')]
    public ?string $thumbnailUrl = null;

    /**
     * dc_term: dcterms:source
     * Object landing page at the holding institution.
     * @var string|null
     */
    #[Map(source: 'url')]
    public ?string $sourceUrl = null;

    // ── afterMap hook ─────────────────────────────────────────────────────

    /**
     * Called by DtoMapper after all #[Map] properties are resolved.
     * Handles derived fields that can't be mapped directly from a single source key.
     */
    public function afterMap(array &$mapped, array $original): void
    {
        // Derive the image URL from iiif_base (preferred) or thumbnail_url fallback.
        // Use /full/max/0/default.jpg — "max" is the IIIF standard for the largest
        // available size the server will serve. Never triggers upsizing errors unlike
        // fixed pixel sizes (e.g. 1600,) which fail on servers that only downsize.
        if ($this->iiifBase !== null) {
            $this->url = $this->iiifBase . '/full/max/0/default.jpg';
        } elseif ($this->thumbnailUrl !== null) {
            $this->url = $this->thumbnailUrl;
        }
        $mapped['url'] = $this->url;

        // date arrives as array from DC — take the first element
        if (is_array($this->date)) {
            $this->date = $this->date[0] ?? null;
            $mapped['date'] = $this->date;
        }

        // type_of_resource is an array — take first, normalize to DCMI Type
        if (is_array($this->type)) {
            $raw = $this->type[0] ?? null;
            $this->type = $raw ? $this->normalizeDcmiType($raw) : null;
            $mapped['type'] = $this->type;
        }
    }

    // ── Rights gate ───────────────────────────────────────────────────────

    /**
     * Whether this item may be stored in mediary.
     * null = field absent → allow through (err on side of inclusion).
     */
    public function isPermitted(): bool
    {
        return $this->reuseAllowed === null
            || in_array($this->reuseAllowed, ['no restrictions', 'creative commons'], true);
    }

    // ── Serialisation ─────────────────────────────────────────────────────

    /**
     * Flatten to a context array for the BatchController per-URL context map.
     * Keys use dcterms: prefix for DC fields so the context blob is RDF-aligned.
     * Null / empty values are omitted to keep the payload lean.
     *
     * @return array<string, mixed>
     */
    public function toSourceMetaArray(): array
    {
        return array_filter([
            // Rights (promoted to real columns on Asset, also in context for completeness)
            'reuse_allowed'             => $this->reuseAllowed,
            'rights'                    => $this->rights,
            'license_uri'               => $this->licenseUri,
            // Identity
            'source_ark'                => $this->sourceArk,
            'source_id'                 => $this->sourceId,
            'aggregator'                => $this->aggregator,
            // DC fields — dcterms: keyed so context blob is queryable by RDF term
            DcTerms::TITLE->value       => $this->title,
            DcTerms::DESCRIPTION->value => $this->description,
            DcTerms::DATE->value        => $this->date,
            DcTerms::TYPE->value        => $this->type,
            DcTerms::CREATOR->value     => $this->creator,
            DcTerms::SUBJECT->value     => $this->subject,
            DcTerms::PUBLISHER->value   => $this->institution,
            DcTerms::IS_PART_OF->value  => $this->collection,
            DcTerms::SOURCE->value      => $this->sourceUrl,
            // IIIF
            'iiif_base'                 => $this->iiifBase,
            'iiif_manifest'             => $this->iiifManifest,
            'thumbnail_url'             => $this->thumbnailUrl,
        ], static fn($v) => $v !== null && $v !== [] && $v !== '');
    }

    /**
     * Reconstruct a MediaSyncItem from a stored array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $item = new self();
        $item->url = $data['url'] ?? null;
        $item->reuseAllowed = $data['reuseAllowed'] ?? $data['reuse_allowed'] ?? null;
        $item->rights = $data['rights'] ?? null;
        $item->licenseUri = $data['licenseUri'] ?? $data['license_uri'] ?? null;
        $item->sourceArk = $data['sourceArk'] ?? $data['source_ark'] ?? null;
        $item->sourceId = $data['sourceId'] ?? $data['source_id'] ?? null;
        $item->aggregator = $data['aggregator'] ?? null;
        $item->title = $data['title'] ?? null;
        $item->description = $data['description'] ?? null;
        $item->date = $data['date'] ?? null;
        $item->type = $data['type'] ?? null;
        $item->creator = $data['creator'] ?? null;
        $item->subject = $data['subject'] ?? null;
        $item->institution = $data['institution'] ?? null;
        $item->collection = $data['collection'] ?? null;
        $item->iiifBase = $data['iiifBase'] ?? $data['iiif_base'] ?? null;
        $item->iiifManifest = $data['iiifManifest'] ?? $data['iiif_manifest'] ?? null;
        $item->thumbnailUrl = $data['thumbnailUrl'] ?? $data['thumbnail_url'] ?? null;
        $item->sourceUrl = $data['sourceUrl'] ?? $data['source_url'] ?? null;
        return $item;
    }

    /**
     * Serialize to array for JSON storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'url'            => $this->url,
            'reuseAllowed'   => $this->reuseAllowed,
            'rights'        => $this->rights,
            'licenseUri'     => $this->licenseUri,
            'sourceArk'      => $this->sourceArk,
            'sourceId'       => $this->sourceId,
            'aggregator'     => $this->aggregator,
            'title'         => $this->title,
            'description'   => $this->description,
            'date'          => $this->date,
            'type'          => $this->type,
            'creator'       => $this->creator,
            'subject'       => $this->subject,
            'institution'   => $this->institution,
            'collection'    => $this->collection,
            'iiifBase'      => $this->iiifBase,
            'iiifManifest'  => $this->iiifManifest,
            'thumbnailUrl'  => $this->thumbnailUrl,
            'sourceUrl'     => $this->sourceUrl,
        ], static fn($v) => $v !== null && $v !== [] && $v !== '');
    }

    // ── Private helpers ───────────────────────────────────────────────────

    /**
     * Normalise a source type string to a DCMI Type vocabulary term.
     * DC uses "Still image", "Text" etc.; DCMI Type uses "StillImage", "Text".
     */
    private function normalizeDcmiType(string $raw): string
    {
        return match (strtolower(trim($raw))) {
            'still image', 'stillimage', 'image' => 'StillImage',
            'text'                               => 'Text',
            'physical object', 'physicalobject'  => 'PhysicalObject',
            'sound'                              => 'Sound',
            'moving image', 'movingimage'        => 'MovingImage',
            'dataset'                            => 'Dataset',
            'collection'                         => 'Collection',
            'software'                           => 'Software',
            'event'                              => 'Event',
            'service'                            => 'Service',
            default                              => $raw,
        };
    }
}
