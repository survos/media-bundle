<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Dto;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\With;

/**
* Pipeline accumulator for a single media item through the import stages (normalized → mediary → AI → OCR → zm).
*
* Previously named MediaEnrichment. Renamed to avoid confusion with the
* display-oriented MediaEnrichment DTO (which aggregates aiCompleted results). for a single media item.
*
* Each stage populates more fields. The DTO is the single source of truth
* that feeds zm Item/Value creation via field_map.yaml.
*
* Stage 1 — from normalized JSONL (source metadata, free):
*   title, description, date, creator, subject, contentType, sourceUrl
*
* Stage 2 — from mediary sync (after upload):
*   s3Url, thumbUrl, width, height, mediaKey, mediaStatus
*
* Stage 3 — from AI vision (one call per thumbnail, ~$0.0004):
*   aiTitle, aiDescription, aiKeywords, aiPeople, aiPlaces, hasText
*   Fills only fields that Stage 1 left null.
*
* Stage 4 — OCR (only when hasText = true):
*   transcription
*
* Stage 5 → zm import:
*   Each non-null field maps to a dcterms: Value via field_map.yaml
*
* The `source` field tracks which stage populated each value
* ('import', 'ai', 'ocr', 'human') for zm Value.confidence.
*/
final class ImportEnrichmentContext
{
// ── Identity ──────────────────────────────────────────────────────────────

public ?string $id          = null;  // normalized record id / ark_id
public ?string $sourceUrl   = null;  // original URL submitted to mediary
public ?string $contentType = null;  // ContentType constant (photograph, map, etc.)
public ?string $aggregator  = null;  // dc, pp, fortepan, etc.

// ── Stage 1: Source metadata (DC / PP / Fortepan etc.) ───────────────────

/** dcterms:title — from source or AI */
public ?string $title        = null;
public string  $titleSource  = 'import';

/** dcterms:description */
public ?string $description       = null;
public string  $descriptionSource = 'import';

/** dcterms:date */
public ?string $date       = null;
public string  $dateSource = 'import';

/** dcterms:creator — array of names */
public ?array $creators      = null;
public string $creatorsSource = 'import';

/** dcterms:subject — array of terms */
public ?array $subjects      = null;
public string $subjectsSource = 'import';

/** dcterms:language */
public ?string $language = null;

/** schema:latitude / schema:longitude */
public ?float $latitude  = null;
public ?float $longitude = null;

/** dcterms:rights */
public ?string $rights    = null;
public ?string $rightsUri = null;

// ── Stage 2: Mediary sync ─────────────────────────────────────────────────

/** IIIF Image API base URL — use this for AI instead of downloading to S3 */
public ?string $iiifBase    = null;

/** Source image URL (original image or archive URL) */
public ?string $imageUrl    = null;

public ?string $mediaKey    = null;  // mediary asset key
public ?string $mediaStatus = null;  // new, queued, downloaded, archived
public ?string $s3Url       = null;  // canonical S3 URL
public ?string $thumbUrl    = null;  // pre-sized thumbnail URL
public ?int    $width       = null;
public ?int    $height      = null;

// ── Stage 3: AI vision enrichment ────────────────────────────────────────

/** AI-generated title (fills $title if null after Stage 1) */
public ?string $aiTitle       = null;

/** AI-generated description */
public ?string $aiDescription = null;

/** AI-extracted keywords */
public ?array $aiKeywords = null;

/** AI-identified people */
public ?array $aiPeople = null;

/** AI-identified places */
public ?array $aiPlaces = null;

/** AI-estimated date hint when source has none */
public ?string $aiDateHint = null;

/** AI confidence 0-1 */
public float $aiConfidence = 0.0;

/**
* Whether the image contains readable text → run OCR pipeline.
* Set by AI vision task; drives Stage 4.
*/
public bool $hasText = false;

// ── Stage 4: OCR ─────────────────────────────────────────────────────────

public ?string $transcription = null;

// ── Derived: effective values for zm import ───────────────────────────────

/**
* The best available title: source title > AI title > null.
* zm will use this as the dcterms:title Value.
*/
public function effectiveTitle(): ?string
{
return $this->title ?? $this->aiTitle;
}

/**
* The best available description: source > AI > null.
*/
public function effectiveDescription(): ?string
{
return $this->description ?? $this->aiDescription;
}

/**
* All subject terms: source subjects + AI keywords + AI places merged.
* @return string[]
*/
public function effectiveSubjects(): array
{
return array_unique(array_filter(array_merge(
$this->subjects      ?? [],
$this->aiKeywords    ?? [],
$this->aiPlaces      ?? [],
)));
}

/**
* Best URL to send to the AI vision model.
*
 * Priority:
 *   1. iiifBase + size parameter — direct from provider's IIIF server,
 *      cached by imgProxy so we don't hammer their server
 *   2. imageUrl  — original image URL, cached by imgProxy
 *   3. thumbUrl  — pre-built by provider (fallback only)
 *   4. s3Url     — our archived copy (use only if nothing else available)
*
* @param int $px  Target pixel width for AI analysis (512 = low-res, cheap)
*/
public function imageUrlForAi(int $px = 512): ?string
{
// IIIF: construct a properly-sized image URL directly
// imgProxy will cache this, so provider servers aren't hammered
if ($this->iiifBase) {
return $this->iiifBase . "/full/{$px},/0/default.jpg";
}

if ($this->imageUrl) {
return $this->imageUrl;
}

// Pre-built CDN thumbnail — good enough at 300-480px for AI
if ($this->thumbUrl) {
return $this->thumbUrl;
}

// S3 archived copy — use imgProxy for resizing
if ($this->s3Url) {
return $this->s3Url;
}

// Source URL as last resort — AI will fetch it at full size (expensive)
return $this->sourceUrl;
}

/**
* Build a map suitable for zm Value creation via field_map.yaml.
* Keys are dcterms field names; values are what to store.
*
* @return array<string, mixed>
*/
public function toValueMap(): array
{
return array_filter([
'dcterms:title'       => $this->effectiveTitle(),
'dcterms:description' => $this->effectiveDescription(),
'dcterms:date'        => $this->date ?? $this->aiDateHint,
'dcterms:creator'     => $this->creators,
'dcterms:subject'     => $this->effectiveSubjects() ?: null,
'dcterms:language'    => $this->language,
'dcterms:rights'      => $this->rights,
'dcterms:license'     => $this->rightsUri,
'schema:latitude'     => $this->latitude,
'schema:longitude'    => $this->longitude,
// Media-specific (not DC but useful for zm Media entity)
'_mediaKey'           => $this->mediaKey,
'_s3Url'              => $this->s3Url,
'_thumbUrl'           => $this->thumbUrl,
'_imageUrl'           => $this->imageUrl,
'_transcription'      => $this->transcription,
'_contentType'        => $this->contentType,
'_aiPeople'           => $this->aiPeople,
], static fn($v) => $v !== null && $v !== [] && $v !== '');
}

// ── Factories ─────────────────────────────────────────────────────────────

/**
* Stage 1: populate from a normalized JSONL record.
*/
public static function fromNormalized(array $row): self
{
$e = new self();
$e->id          = $row['id']           ?? $row['ark_id'] ?? null;
$e->sourceUrl   = $row['url']           ?? $row['page_url'] ?? null;
$e->contentType = $row['content_type']  ?? null;
$e->aggregator  = $row['aggregator']    ?? null;
$e->title       = $row['title']         ?? $row['label'] ?? null;
$e->description = $row['description']   ?? $row['location_caption'] ?? null;
$e->date        = $row['date']          ?? (string)($row['year'] ?? '') ?: null;
$e->creators    = $row['creators']      ?? ($row['name_facet'] ?? null);
$e->subjects    = $row['subject_facet'] ?? ($row['subjects'] ?? ($row['tags'] ?? null));
$e->language    = $row['language']      ?? null;
$e->latitude    = isset($row['latitude'])  ? (float)$row['latitude']  : null;
$e->longitude   = isset($row['longitude']) ? (float)$row['longitude'] : null;
$e->rights      = $row['rights']        ?? $row['license'] ?? null;
$e->rightsUri   = $row['rights_uri']    ?? null;
$e->iiifBase    = $row['iiif_base']      ?? null;
$e->imageUrl    = $row['image_url']      ?? ($row['imageUrl'] ?? null);
$e->thumbUrl    = $row['thumbnail_url'] ?? null;
return $e;
}

/**
* Stage 2: merge mediary sync result.
*/
public function applyMediaRegistration(MediaRegistration $reg): static
{
$this->mediaKey    = $reg->mediaKey;
$this->mediaStatus = $reg->status;
$this->s3Url       = $reg->s3Url;
$this->thumbUrl    = $this->thumbUrl ?? $reg->smallUrl; // keep source thumb if available
return $this;
}

/**
* Stage 3: merge AI vision enrichment.
* Only fills fields that are still null after Stage 1.
*/
public function applyAiEnrichment(array $aiResult): static
{
$this->aiTitle       = $aiResult['title']       ?? null;
$this->aiDescription = $aiResult['description'] ?? null;
$this->aiKeywords    = $aiResult['keywords']    ?? null;
$this->aiPeople      = $aiResult['people']      ?? null;
$this->aiPlaces      = $aiResult['places']      ?? null;
$this->aiDateHint    = $aiResult['date_hint']   ?? null;
$this->aiConfidence  = (float)($aiResult['confidence'] ?? 1.0);
$this->hasText       = (bool)($aiResult['has_text'] ?? false);

// Promote AI values to primary when source had nothing
if ($this->title === null && $this->aiTitle !== null) {
$this->title       = $this->aiTitle;
$this->titleSource = 'ai';
}
if ($this->description === null && $this->aiDescription !== null) {
$this->description       = $this->aiDescription;
$this->descriptionSource = 'ai';
}
if ($this->date === null && $this->aiDateHint !== null) {
$this->date       = $this->aiDateHint;
$this->dateSource = 'ai';
}

return $this;
}

/**
* Stage 4: merge OCR transcription.
*/
public function applyTranscription(string $text): static
{
$this->transcription = $text;
// OCR text also serves as description if nothing else exists
if ($this->description === null && strlen($text) < 500) {
$this->description       = $text;
$this->descriptionSource = 'ocr';
}
return $this;
}
}
