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
