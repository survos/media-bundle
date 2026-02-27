# Survos Media Bundle

SurvosMediaBundle provides a **deterministic, URL‑centric media registry** for Symfony applications.

It is intentionally *not* a media processor. Instead, it:

- Registers media references (URLs or local files)
- Assigns **deterministic IDs** derived from URLs
- Stores application‑local media metadata
- Syncs with a centralized media server (future step)
- Generates thumbnail URLs via imgproxy‑style patterns (future step)

This mirrors the relationship between **babel‑bundle** and **lingua‑server**:

- Applications own their media tables
- A central service owns the binaries and heavy processing

---

## Core Concept: Deterministic Media IDs

Every media item has a **stable, deterministic ID** derived from its URL.

```php
use Survos\MediaBundle\Service\MediaRegistry;

$id = MediaRegistry::idFromUrl('https://example.com/image.jpg');
```

The algorithm:

- Base64‑encodes the URL
- Converts it to URL‑safe base64
- Removes padding

This is the same philosophy used by **imgproxy**.

### Why this matters

- No database lookups to resolve URLs
- IDs are reversible
- Same URL → same ID across apps
- Safe primary key for Meilisearch

---

## Registering Media

The primary entry point is `MediaRegistry`.

```php
foreach ($data->images as $imageUrl) {
    $media = $mediaRegistry->ensureMedia($imageUrl);
}
```

### Behavior

- Defaults to `Photo`
- No duplicate URLs
- Bulk‑safe (`flush: false`)
- No network calls

Local files are also supported:

```php
$media = $mediaRegistry->ensureMedia($uploadedFile);
```

Local files are assigned a temporary `local://` URL until synced.

---

## Probing Mediary (Polling Fallback)

When webhook callbacks are unavailable (for example, local dev tunnels are down), poll mediary directly via the bundle service.

```php
use Survos\MediaBundle\Service\MediaBatchDispatcher;

$result = $mediaBatchDispatcher->dispatch('museum', [$url], [
    'callback_url' => 'https://my-app.example/webhook/media',
]);

$assetId = $result->media[0]->mediaKey;
$probe = $mediaBatchDispatcher->probe($assetId);

if ($probe->isComplete()) {
    // use $probe->meta / $probe->context / $probe->ocr / $probe->ai
}
```

Available methods:

- `probe(string $assetId): MediaProbeResult` → calls `GET /fetch/media/{id}`
- `probeMany(array $assetIds): array<MediaProbeResult>` → calls `POST /fetch/media/by-ids`

Probe payload includes current workflow state (`marking`), variants/thumb URLs, metadata, and any OCR/AI context that has been written so far.

CLI helper:

```bash
bin/console media:probe 5c4e0c2d6f8a1b9e
bin/console media:probe "https://example.org/image.jpg"
bin/console media:probe --url "upload://sha256/abcd..."
```

---

## What This Bundle Does *Not* Do

- Download media
- Resize images
- Cache thumbnails
- Perform OCR, tagging, or EXIF extraction

Those responsibilities belong to the **media server** and **imgproxy**.

---

## Status

This bundle is intentionally minimal and evolving.

Next steps include:

- `media:sync` command
- Provider detection (YouTube, Flickr, etc.)
- Thumbnail URL generation helpers
