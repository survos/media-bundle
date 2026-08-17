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

## Receiving mediary's callback

mediary POSTs a signed `asset.analyzed` webhook to `/webhook/mediary` when an image finishes
analysis. This bundle supplies the two pieces that belong to it — a request parser
(`Survos\MediaBundle\Webhook\MediaWebhookRequestParser`) and a consumer that calls
`MediaUpdateApplier`, the same write path `media:sync` uses. It contributes no route and no
controller; the endpoint is FrameworkBundle's own.

In the app:

```yaml
# config/routes/webhook.yaml
webhook:
    resource: '@FrameworkBundle/Resources/config/routing/webhook.php'
    prefix: /webhook

# config/packages/webhook.yaml
framework:
    webhook:
        routing:
            mediary:
                service: Survos\MediaBundle\Webhook\MediaWebhookRequestParser
                secret: '%env(default::MEDIARY_WEBHOOK_SECRET)%'

# config/packages/messenger.yaml — REQUIRED, or the endpoint's 202 is a lie
framework:
    messenger:
        routing:
            'Symfony\Component\RemoteEvent\Messenger\ConsumeRemoteEventMessage': media_callback
```

Set `MEDIARY_WEBHOOK_SECRET` to the same value mediary signs with, and point
`MEDIA_CALLBACK_URL` at `https://your-app/webhook/mediary`.

To react to updates, listen for `MediaUpdatedEvent` — mediary never learns your entity shape.

Full contract, including how to run several webhooks on separate queues:
[kit-bundle/docs/webhooks.md](../kit-bundle/docs/webhooks.md).

> Replaced the unauthenticated `MediaCallbackController` at `/media/callback`, where anyone who
> could reach the URL could rewrite a media row. See survos-sites/mediary#8.

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

## Publishing Claims to Mediary

Apps run AI with `survos/ai-workflow-bundle` and store tracked metadata as
claims. Media publishing should send the image plus selected source/AI/human
claims to mediary, while mediary remains responsible for global media access and
canonical image URLs. See [docs/publishing.md](docs/publishing.md).

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
