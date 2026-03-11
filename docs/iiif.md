# IIIF Integration for media-bundle

## Overview

Add IIIF (International Image Interoperability Framework) support to `media-bundle` so that all image serving — thumbnails in search results, detail views, page-turning viewers — follows a single consistent URL pattern. This replaces ad-hoc image URL construction and gives us interoperability with the cultural heritage ecosystem (DPLA, Europeana, Omeka-S, Universal Viewer, Mirador, etc.).

The bundle does NOT implement an image server. It generates IIIF-compliant URLs and JSON manifests that are served by **imgproxy** (dynamic resizing) or **Mediary** (our media server), with original images stored on **Hetzner S3**.

---

## Architecture

```
┌─────────────┐     ┌──────────────────┐     ┌────────────┐
│  Meilisearch │────▶│  Symfony App      │────▶│  Browser   │
│  (Items idx) │     │  (media-bundle)   │     │            │
│  (Pages idx) │     │                   │     │  <img> tags│
└─────────────┘     │  Generates:       │     │  OpenSeaDragon
                    │  - IIIF Image URLs│     │  or TIFY   │
                    │  - Manifests JSON │     └────────────┘
                    │  - info.json      │
                    └────────┬─────────┘
                             │ URLs point to
                    ┌────────▼─────────┐
                    │   imgproxy        │
                    │   (or Mediary)    │
                    │                   │
                    │   Reads from:     │
                    │   Hetzner S3      │
                    └──────────────────┘
```

### Key Principle

Every image in the system is identified by a single **IIIF image identifier** (e.g., `page_001`). All URLs are derived from that identifier. There is no separate "thumbnail URL" or "full image URL" field — just the identifier and a URL pattern.

---

## IIIF Image API (URL Construction)

### URL Pattern

```
{base_url}/iiif/{identifier}/{region}/{size}/{rotation}/{quality}.{format}
```

Parameters are applied in order: Region → Size → Rotation → Quality → Format.

### Twig Helper / Service

Create a `IiifUrlGenerator` service and Twig extension:

```php
// src/Service/IiifUrlGenerator.php

namespace Survos\MediaBundle\Service;

class IiifUrlGenerator
{
    public function __construct(
        private string $iiifBaseUrl,  // e.g., https://mediary.scanseum.com/iiif
    ) {}

    /**
     * Generate a IIIF Image API URL.
     *
     * @param string $identifier  The image identifier (e.g., "page_001")
     * @param string $region      "full" | "square" | "x,y,w,h" | "pct:x,y,w,h"
     * @param string $size        "max" | "w," | ",h" | "w,h" | "^w," | "pct:n"
     * @param int    $rotation    0-360, prefix with ! for mirroring
     * @param string $quality     "default" | "color" | "gray" | "bitonal"
     * @param string $format      "jpg" | "png" | "webp"
     */
    public function imageUrl(
        string $identifier,
        string $region = 'full',
        string $size = 'max',
        int|string $rotation = 0,
        string $quality = 'default',
        string $format = 'jpg',
    ): string {
        return sprintf(
            '%s/%s/%s/%s/%s/%s.%s',
            $this->iiifBaseUrl,
            rawurlencode($identifier),
            $region,
            $size,
            $rotation,
            $quality,
            $format,
        );
    }

    /**
     * Convenience: thumbnail URL at a given width.
     */
    public function thumbnail(string $identifier, int $width = 300): string
    {
        return $this->imageUrl($identifier, 'full', "$width,");
    }

    /**
     * Convenience: square thumbnail.
     */
    public function squareThumbnail(string $identifier, int $size = 200): string
    {
        return $this->imageUrl($identifier, 'square', "$size,$size");
    }

    /**
     * URL to the info.json endpoint for this image.
     */
    public function infoUrl(string $identifier): string
    {
        return sprintf('%s/%s/info.json', $this->iiifBaseUrl, rawurlencode($identifier));
    }
}
```

### Twig Extension

```php
// src/Twig/IiifExtension.php

namespace Survos\MediaBundle\Twig;

use Survos\MediaBundle\Service\IiifUrlGenerator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class IiifExtension extends AbstractExtension
{
    public function __construct(private IiifUrlGenerator $iiif) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('iiif_url', [$this->iiif, 'imageUrl']),
            new TwigFunction('iiif_thumbnail', [$this->iiif, 'thumbnail']),
            new TwigFunction('iiif_square', [$this->iiif, 'squareThumbnail']),
            new TwigFunction('iiif_info', [$this->iiif, 'infoUrl']),
        ];
    }
}
```

### Usage in Twig Templates

```twig
{# Search results grid — plain <img> tags, no JS needed #}
{% for item in searchResults %}
  <div class="search-result">
    <img src="{{ iiif_thumbnail(item.iiifImageId, 300) }}"
         loading="lazy"
         alt="{{ item.title }}">
    <h3>{{ item.title }}</h3>
  </div>
{% endfor %}

{# Square thumbnails for a grid layout #}
<img src="{{ iiif_square(item.iiifImageId, 200) }}">

{# Full-size image #}
<img src="{{ iiif_url(item.iiifImageId) }}">

{# Custom: 50% width, grayscale #}
<img src="{{ iiif_url(item.iiifImageId, 'full', 'pct:50', 0, 'gray', 'webp') }}">
```

### Bundle Configuration

```yaml
# config/packages/survos_media.yaml
survos_media:
    iiif:
        base_url: '%env(IIIF_BASE_URL)%'  # https://mediary.scanseum.com/iiif
```

---

## IIIF Presentation API (Manifests)

### Data Model Mapping

| IIIF Concept   | Scanseum Entity | Description                              |
|----------------|-----------------|------------------------------------------|
| **Collection** | Institution / ItemSet | A group of manifests               |
| **Manifest**   | Item            | The intellectual object (document, photo) |
| **Canvas**     | Page (Image)    | A single page/view with dimensions       |
| **Annotation** | OCR text, AI description | Content painted onto a canvas  |

### Manifest Generator Service

```php
// src/Service/IiifManifestGenerator.php

namespace Survos\MediaBundle\Service;

class IiifManifestGenerator
{
    public function __construct(
        private IiifUrlGenerator $iiifUrl,
        private string $appBaseUrl,  // https://scanseum.com
    ) {}

    /**
     * Generate a IIIF Presentation 3.0 Manifest for an Item.
     *
     * @param object $item   The Item entity (must have getId(), getTitle(), getPages())
     * @return array          JSON-serializable manifest array
     */
    public function generateManifest(object $item): array
    {
        $manifestId = $this->appBaseUrl . '/iiif/' . $item->getId() . '/manifest.json';

        $canvases = [];
        foreach ($item->getPages() as $index => $page) {
            $canvases[] = $this->buildCanvas($page, $index);
        }

        return [
            '@context' => 'http://iiif.io/api/presentation/3/context.json',
            'id' => $manifestId,
            'type' => 'Manifest',
            'label' => ['en' => [$item->getTitle()]],
            'summary' => ['en' => [$item->getDescription() ?? '']],
            'metadata' => $this->buildMetadata($item),
            'rights' => $item->getRightsUrl() ?? 'http://rightsstatements.org/vocab/CNE/1.0/',
            'requiredStatement' => [
                'label' => ['en' => ['Attribution']],
                'value' => ['en' => [$item->getAttribution() ?? '']],
            ],
            'thumbnail' => [[
                'id' => $this->iiifUrl->thumbnail($item->getRepresentativeImageId(), 300),
                'type' => 'Image',
                'format' => 'image/jpeg',
                'service' => [$this->buildImageService($item->getRepresentativeImageId())],
            ]],
            'items' => $canvases,
        ];
    }

    private function buildCanvas(object $page, int $index): array
    {
        $canvasId = $this->appBaseUrl . '/iiif/canvas/' . $page->getId();
        $imageId = $page->getIiifImageId();

        $canvas = [
            'id' => $canvasId,
            'type' => 'Canvas',
            'label' => ['en' => [$page->getLabel() ?? 'Page ' . ($index + 1)]],
            'width' => $page->getWidth(),
            'height' => $page->getHeight(),
            'items' => [[
                'id' => $canvasId . '/annotation-page',
                'type' => 'AnnotationPage',
                'items' => [[
                    'id' => $canvasId . '/annotation/image',
                    'type' => 'Annotation',
                    'motivation' => 'painting',
                    'target' => $canvasId,
                    'body' => [
                        'id' => $this->iiifUrl->imageUrl($imageId),
                        'type' => 'Image',
                        'format' => 'image/jpeg',
                        'width' => $page->getWidth(),
                        'height' => $page->getHeight(),
                        'service' => [$this->buildImageService($imageId)],
                    ],
                ]],
            ]],
        ];

        // Add OCR text as annotation if available
        if ($ocrText = $page->getOcrText()) {
            $canvas['annotations'] = [[
                'id' => $canvasId . '/annotation-page/ocr',
                'type' => 'AnnotationPage',
                'items' => [[
                    'id' => $canvasId . '/annotation/ocr',
                    'type' => 'Annotation',
                    'motivation' => 'supplementing',
                    'target' => $canvasId,
                    'body' => [
                        'type' => 'TextualBody',
                        'value' => $ocrText,
                        'format' => 'text/plain',
                        'language' => 'en',
                    ],
                ]],
            ]];
        }

        return $canvas;
    }

    private function buildImageService(string $imageId): array
    {
        return [
            'id' => $this->iiifUrl->iiifBaseUrl() . '/' . $imageId,
            'type' => 'ImageService3',
            'profile' => 'level1',
        ];
    }

    private function buildMetadata(object $item): array
    {
        $metadata = [];

        $fields = [
            'Date' => 'getDate',
            'Creator' => 'getCreator',
            'Subject' => 'getSubjects',
            'Type' => 'getType',
            'Source' => 'getSource',
            'Identifier' => 'getIdentifier',
        ];

        foreach ($fields as $label => $method) {
            if (method_exists($item, $method) && ($value = $item->$method())) {
                $values = is_array($value) ? $value : [$value];
                $metadata[] = [
                    'label' => ['en' => [$label]],
                    'value' => ['en' => $values],
                ];
            }
        }

        return $metadata;
    }
}
```

### Manifest Controller

```php
// src/Controller/IiifController.php

namespace Survos\MediaBundle\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class IiifController
{
    #[Route('/iiif/{id}/manifest.json', name: 'iiif_manifest')]
    public function manifest(Item $item, IiifManifestGenerator $generator): JsonResponse
    {
        $manifest = $generator->generateManifest($item);

        return new JsonResponse($manifest, headers: [
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    #[Route('/iiif/{id}/info.json', name: 'iiif_image_info')]
    public function imageInfo(Page $page, IiifUrlGenerator $iiifUrl): JsonResponse
    {
        // Level 1: server supports size by width (imgproxy handles this)
        $info = [
            '@context' => 'http://iiif.io/api/image/3/context.json',
            'id' => $iiifUrl->iiifBaseUrl() . '/' . $page->getIiifImageId(),
            'type' => 'ImageService3',
            'protocol' => 'http://iiif.io/api/image',
            'profile' => 'level1',
            'width' => $page->getWidth(),
            'height' => $page->getHeight(),
            'sizes' => [
                ['width' => 150, 'height' => $this->scaleHeight($page, 150)],
                ['width' => 400, 'height' => $this->scaleHeight($page, 400)],
                ['width' => 1200, 'height' => $this->scaleHeight($page, 1200)],
            ],
        ];

        return new JsonResponse($info, headers: [
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }

    private function scaleHeight(object $page, int $width): int
    {
        return (int) round($page->getHeight() * ($width / $page->getWidth()));
    }
}
```

---

## imgproxy as IIIF Backend

imgproxy is fast enough for dynamic resizing. We translate IIIF Image API URLs to imgproxy URLs. This can be done either:

1. **Nginx/Caddy rewrite rules** (recommended for production), or
2. **A Symfony controller** that 302-redirects to imgproxy (simpler to start)

### Option A: Caddy Rewrite (Production)

```caddyfile
# Translate IIIF Image API URLs to imgproxy URLs
# Pattern: /iiif/{identifier}/{region}/{size}/{rotation}/{quality}.{format}

@iiif_image path_regexp iiif ^/iiif/([^/]+)/full/(\d+),/0/default\.(jpg|webp|png)$
handle @iiif_image {
    rewrite * /insecure/rs:fit:{re.iiif.2}/plain/s3://scanseum/{re.iiif.1}.jpg@{re.iiif.3}
    reverse_proxy imgproxy:8080
}

# Square crop
@iiif_square path_regexp iiif_sq ^/iiif/([^/]+)/square/(\d+),(\d+)/0/default\.(jpg|webp|png)$
handle @iiif_square {
    rewrite * /insecure/rs:fill:{re.iiif_sq.2}:{re.iiif_sq.3}/g:ce/plain/s3://scanseum/{re.iiif_sq.1}.jpg@{re.iiif_sq.4}
    reverse_proxy imgproxy:8080
}

# Full size
@iiif_full path_regexp iiif_full ^/iiif/([^/]+)/full/max/0/default\.(jpg|webp|png)$
handle @iiif_full {
    rewrite * /insecure/plain/s3://scanseum/{re.iiif_full.1}.jpg@{re.iiif_full.2}
    reverse_proxy imgproxy:8080
}

# info.json — proxy to Symfony
@iiif_info path_regexp iiif_info ^/iiif/([^/]+)/info\.json$
handle @iiif_info {
    reverse_proxy symfony:8000
}
```

### Option B: Symfony Redirect Controller (Development)

```php
#[Route('/iiif/{identifier}/{region}/{size}/{rotation}/{quality}.{format}',
    name: 'iiif_image',
    requirements: ['identifier' => '[^/]+', 'region' => '.+', 'format' => 'jpg|png|webp']
)]
public function iiifImage(
    string $identifier,
    string $region,
    string $size,
    string $rotation,
    string $quality,
    string $format,
    string $imgproxyBaseUrl,  // injected from config
    string $s3Bucket,
): RedirectResponse {
    $imgproxyUrl = $this->buildImgproxyUrl(
        $imgproxyBaseUrl, $s3Bucket, $identifier, $region, $size, $rotation, $quality, $format
    );

    return new RedirectResponse($imgproxyUrl, 302, [
        'Cache-Control' => 'public, max-age=86400',
    ]);
}

private function buildImgproxyUrl(
    string $baseUrl, string $bucket,
    string $identifier, string $region, string $size,
    string $rotation, string $quality, string $format,
): string {
    $source = "s3://$bucket/$identifier.jpg";
    $params = [];

    // Parse size: "300," → width=300; ",400" → height=400; "300,400" → both
    if ($size === 'max') {
        // No resize
    } elseif (preg_match('/^(\d+),$/', $size, $m)) {
        $params[] = "rs:fit:{$m[1]}";
    } elseif (preg_match('/^,(\d+)$/', $size, $m)) {
        $params[] = "rs:fit:0:{$m[1]}";
    } elseif (preg_match('/^(\d+),(\d+)$/', $size, $m)) {
        $params[] = "rs:fill:{$m[1]}:{$m[2]}";
    }

    // Parse region: "square" → gravity center + crop
    if ($region === 'square') {
        $params[] = 'g:ce';
        // Ensure size uses fill for square crop
    }

    // Quality: gray
    if ($quality === 'gray') {
        $params[] = 'sat:-100';
    }

    // Rotation
    if ((int) $rotation !== 0) {
        $params[] = "rot:$rotation";
    }

    $processing = implode('/', $params);
    $ext = $format !== 'jpg' ? "@$format" : '';

    return "$baseUrl/insecure/$processing/plain/$source$ext";
}
```

---

## Meilisearch Integration

### Items Index Document Shape

```json
{
  "id": "item_4821",
  "title": "Pension Application, Pvt. James Wilson",
  "description": "Twelve-page pension file including affidavits...",
  "date": "1892",
  "subjects": ["USCT", "Civil War", "pensions"],
  "institution_id": "carver_museum",
  "collection": "Civil War Records",
  "page_count": 12,
  "manifest_url": "/iiif/item_4821/manifest.json",
  "iiif_image_id": "carver/item_4821/page_001",
  "_geo": { "lat": 38.48, "lng": -78.17 }
}
```

The `iiif_image_id` is the representative image (usually page 1). The frontend constructs all thumbnail URLs from it — no pre-computed thumbnail URLs stored.

### Pages Index Document Shape

```json
{
  "id": "page_4821_007",
  "item_id": "item_4821",
  "page_number": 7,
  "iiif_image_id": "carver/item_4821/page_007",
  "ocr_text": "...the soldier contracted typhoid fever at...",
  "ai_description": "Affidavit from attending physician describing illness",
  "width": 4800,
  "height": 6400
}
```

### Frontend: Search Results (Stimulus Controller)

No IIIF library needed for search results — just `<img>` tags with IIIF URLs:

```javascript
// assets/controllers/search_results_controller.js
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static values = {
        iiifBase: String,  // from data attribute
    }

    // Called for each result item
    thumbnailUrl(imageId, width = 300) {
        return `${this.iiifBaseValue}/${imageId}/full/${width},/0/default.jpg`
    }

    squareThumbnailUrl(imageId, size = 200) {
        return `${this.iiifBaseValue}/${imageId}/square/${size},${size}/0/default.jpg`
    }
}
```

### Twig for Search Results

```twig
<div data-controller="search-results"
     data-search-results-iiif-base-value="{{ iiif_base_url }}">

  {% for item in results %}
    <article class="search-result">
      <img src="{{ iiif_thumbnail(item.iiif_image_id, 300) }}"
           loading="lazy"
           width="300"
           alt="{{ item.title }}">
      <h3><a href="{{ path('item_show', {id: item.id}) }}">{{ item.title }}</a></h3>
      <p>{{ item.description|u.truncate(150) }}</p>
      {% if item.page_count > 1 %}
        <span class="badge">{{ item.page_count }} pages</span>
      {% endif %}
    </article>
  {% endfor %}

</div>
```

---

## Frontend: Detail View (Page Viewer)

Use **OpenSeadragon** (vanilla JS, no framework) for deep zoom, or **TIFY** for full manifest viewer. Both work with importmap/AssetMapper.

### Option A: OpenSeadragon + Manifest Parser (Minimal, Recommended)

```json
// importmap.php
return [
    'openseadragon' => 'https://cdn.jsdelivr.net/npm/openseadragon@4.1.1/+esm',
];
```

```javascript
// assets/controllers/iiif_viewer_controller.js
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static values = {
        manifestUrl: String,
    }

    async connect() {
        // Dynamic import — only loads when viewer is on page
        const OpenSeadragon = (await import('openseadragon')).default

        // Fetch manifest and extract tile sources
        const response = await fetch(this.manifestUrlValue)
        const manifest = await response.json()
        const tileSources = this.extractTileSources(manifest)

        this.viewer = OpenSeadragon({
            element: this.element,
            prefixUrl: 'https://cdn.jsdelivr.net/npm/openseadragon@4.1.1/build/openseadragon/images/',
            sequenceMode: tileSources.length > 1,
            showNavigator: true,
            tileSources,
        })
    }

    /**
     * Extract IIIF Image API info.json URLs from a Presentation 3.0 Manifest.
     */
    extractTileSources(manifest) {
        return (manifest.items || []).map(canvas => {
            const annotation = canvas.items?.[0]?.items?.[0]
            const body = annotation?.body
            const service = body?.service?.[0]

            if (service?.id) {
                // Return info.json URL for IIIF Image API tile source
                return service.id + '/info.json'
            }

            // Fallback: use image URL directly as a simple tile source
            return {
                type: 'image',
                url: body?.id,
                buildPyramid: false,
            }
        })
    }

    disconnect() {
        this.viewer?.destroy()
    }
}
```

```twig
{# Item detail page #}
<div data-controller="iiif-viewer"
     data-iiif-viewer-manifest-url-value="{{ path('iiif_manifest', {id: item.id}) }}"
     style="width: 100%; height: 600px;">
</div>
```

### Option B: TIFY (Full Manifest Viewer, ES Module)

```json
// importmap.php
return [
    'tify' => 'https://cdn.jsdelivr.net/npm/tify@0.35.0/dist/tify.js',
];
```

```javascript
// assets/controllers/tify_viewer_controller.js
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static values = {
        manifestUrl: String,
    }

    async connect() {
        // Load CSS dynamically
        if (!document.querySelector('link[href*="tify"]')) {
            const link = document.createElement('link')
            link.rel = 'stylesheet'
            link.href = 'https://cdn.jsdelivr.net/npm/tify@0.35.0/dist/tify.css'
            document.head.appendChild(link)
        }

        const { default: Tify } = await import('tify')
        this.tify = new Tify({
            container: this.element,
            manifestUrl: this.manifestUrlValue,
        })
    }

    disconnect() {
        this.tify?.destroy()
    }
}
```

```twig
<div data-controller="tify-viewer"
     data-tify-viewer-manifest-url-value="{{ path('iiif_manifest', {id: item.id}) }}"
     style="width: 100%; height: 700px;">
</div>
```

---

## Entity Requirements

### Page/Image Entity

The Image entity needs these fields for IIIF support:

```php
// These fields must exist on the Page/Image entity

#[ORM\Column(length: 255)]
private string $iiifImageId;  // e.g., "carver/item_4821/page_001"
                               // This is the SOLE image identifier.
                               // All URLs derived from this.

#[ORM\Column]
private int $width;            // Pixel width of original scan

#[ORM\Column]
private int $height;           // Pixel height of original scan

#[ORM\Column(nullable: true)]
private ?string $ocrText = null;

#[ORM\Column(nullable: true)]
private ?string $aiDescription = null;

#[ORM\Column]
private int $pageNumber = 1;   // Order within the Item

#[ORM\Column(length: 100, nullable: true)]
private ?string $label = null;  // Display label, e.g., "Cover", "Page 7"
```

### Item Entity

```php
#[ORM\Column(length: 255)]
private string $title;

#[ORM\Column(type: 'text', nullable: true)]
private ?string $description = null;

// The representative image for thumbnails (usually page 1)
public function getRepresentativeImageId(): string
{
    return $this->getPages()->first()?->getIiifImageId() ?? '';
}
```

### S3 Key Convention

The `iiifImageId` maps directly to the S3 object key:

```
s3://scanseum/{iiifImageId}.jpg
s3://scanseum/carver/item_4821/page_001.jpg
s3://scanseum/carver/item_4821/page_002.jpg
```

This means scanning workflow writes to S3 at a predictable path, and the IIIF URL generator + imgproxy reads from that same path.

---

## CORS Headers

All IIIF endpoints MUST return CORS headers to allow cross-domain viewers:

```
Access-Control-Allow-Origin: *
```

This applies to:
- `info.json` responses
- `manifest.json` responses
- Image responses (imgproxy should also send CORS)

Configure in imgproxy:
```env
IMGPROXY_ALLOW_ORIGIN=*
```

---

## Caching Strategy

| Resource       | Cache-Control          | Rationale                           |
|----------------|------------------------|-------------------------------------|
| Images         | `public, max-age=31536000, immutable` | Images never change once scanned |
| `info.json`    | `public, max-age=604800` | Rarely changes, 1 week              |
| `manifest.json`| `public, max-age=86400`  | May update as metadata is enriched, 1 day |
| Search results | `private, no-cache`     | User-specific                        |

Put Cloudflare or your CDN in front of the IIIF image endpoint. Since the URLs are deterministic, cache hit rates will be excellent.

---

## Migration Path

### Phase 1: URL Generation Only
- Add `IiifUrlGenerator` and Twig extension to media-bundle
- Replace all existing thumbnail/image URL construction with `iiif_thumbnail()` / `iiif_url()`
- Configure imgproxy to handle IIIF URL pattern → S3
- No manifest generation yet; just consistent image URLs

### Phase 2: Manifests
- Add `IiifManifestGenerator` and manifest controller
- Add `width` and `height` fields to Image entity (populate during scan/import)
- Generate manifests for multi-page Items
- Add OpenSeadragon or TIFY viewer to Item detail page

### Phase 3: Search Integration
- Store `iiif_image_id` in Meilisearch Items index
- Store `manifest_url` (relative path) in Items index
- Pages index stores per-page `iiif_image_id` for deep linking
- "Found on page 7" badge in search results links to viewer at correct page

### Phase 4: Ecosystem Interop
- Publish IIIF Collection manifests for institutions
- Register with IIIF Content Search API for OCR text search within viewer
- Enable DPLA/Europeana harvesting via IIIF Collections
- Test manifests with Mirador, Universal Viewer, TIFY validators

---

## Testing

### Validate Manifests

Use the official IIIF Presentation API validator:
```
https://presentation-validator.iiif.io/
```

### Validate Image API

```bash
# info.json should return valid JSON with correct dimensions
curl -s https://mediary.scanseum.com/iiif/test_image/info.json | jq .

# Thumbnail should return a JPEG
curl -sI "https://mediary.scanseum.com/iiif/test_image/full/300,/0/default.jpg"
# Expect: Content-Type: image/jpeg, 200 OK

# Square crop
curl -sI "https://mediary.scanseum.com/iiif/test_image/square/200,200/0/default.jpg"
```

### Verify in Viewers

Once manifests are generated, test interoperability:

```
# Universal Viewer
https://universalviewer.io/uv.html?manifest=https://scanseum.com/iiif/item_4821/manifest.json

# Mirador
https://projectmirador.org/?manifest=https://scanseum.com/iiif/item_4821/manifest.json
```
