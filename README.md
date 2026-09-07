# Survos Media Bundle

A **client for [mediary](https://mediary.survos.com)**, the central media server — not a
general-purpose media manager. An application installs this bundle to register the images it
knows about, hand them to mediary, and receive back what mediary learned. mediary owns the
binaries, the S3 archive, the AI, and the canonical URLs; the application owns only its own rows.

> **Renaming.** The package is scheduled to be renamed `survos/mediary-bundle`, which is what it
> has actually been for a while. The name `media-bundle` predates the split and reads like generic
> media management, which is the one thing this is not.

This mirrors **babel-bundle** ↔ **lingua-server**: applications own their tables, a central
service owns the heavy lifting.

---

## Two encodings, and they are not the same thing

This trips people up, so it is spelled out. There are **two** derivations from a URL, both live,
both deliberate.

### 1. The imgproxy-style key — reversible

```php
use Survos\DataContracts\Util\MediaKeyService;

$key = MediaKeyService::keyFromString('https://example.com/image.jpg');
$url = MediaKeyService::stringFromEncoded($key);   // round-trips
```

URL-safe base64: `base64_encode`, then `+/` → `-_`, then padding stripped. This is the same
philosophy imgproxy uses, and it is **reversible on purpose** — a resize URL carries its own
source, so no lookup is needed to render one. `MediaUrlGenerator::resize()` uses it whenever it
is handed a bare URL string rather than a `Media` row.

### 2. The asset id — not reversible

```php
use Survos\DataContracts\Util\MediaIdentity;

$id = MediaIdentity::idFromOriginalUrl('https://example.com/image.jpg');
// => 16 lowercase hex chars, e.g. "0c05e2ff8ac3dd8f"
```

`xxh3` of the trimmed URL. This is `Media::$id` and mediary's `Asset::$id` — the primary key both
sides agree on. Fixed width, so it is safe in a column, a Meilisearch id, and a directory name;
the URL is not recoverable from it, and nothing should try.

`MediaKeyService::archivePathFromKey()` uses **both**: it hashes the key to pick a bucket, then
stores the key itself as the filename — `o/<1 hex>/<2 hex>/<key>.<ext>`, so the stored path is
still reversible to the source URL. Note the bucket split is one char then two (16 × 256), not the
symmetric `aa/bb` the name suggests.

This is **not** the layout mediary uses for archived originals, which are
`orig/<2>/<2>/<long hex>.<ext>`. Two different schemes; don't assume one function produces the
other.

> The `MediaRegistry::idFromUrl()` example that used to head this README does not exist — the
> method was never added or was removed. `tests/Service/MediaRegistryTest.php:75` still calls it.

### Why both live in data-contracts

mediary computes the same values from the same URL without depending on this bundle, and this
bundle computes them without depending on mediary. Two copies of either algorithm would be free
to drift, and a drift looks like "mediary answered about an image we never asked about."

Same rule for the preset names (`MediaPreset`), the batch payload shape (`BatchPayloadDto` /
`BatchItemDto`), and the sync protocol keys (`MediaSyncKeys`).

### Why it matters

- No database lookup to resolve a URL to an id
- Same URL → same id in every app and in mediary
- Safe primary key for Meilisearch

---

## The Media workflow — how rows actually reach mediary

**This is the supported path.** Registering a `Media` row is the only manual step.

`BaseMedia` carries a marking, and the app declares a workflow for it:

```php
use Survos\MediaBundle\Entity\BaseMedia;
use Survos\MediaBundle\Workflow\MediaWorkflowDefinition;
use Survos\StateBundle\Attribute\Workflow;

#[Workflow(supports: [BaseMedia::class], name: self::WORKFLOW_NAME)]
final class MediaWorkflow extends MediaWorkflowDefinition
{
    public const WORKFLOW_NAME = 'media';
}
```

An empty body is the normal case — you inherit `new → dispatch → dispatched → sync → synced`
(plus `failed`). `MediaWorkflowDefinition` deliberately carries **no** `#[Workflow]` attribute, so
the base never self-registers; the app decides. An app that needs its own steps redeclares just
the constant it wants to re-point (ssai, for instance, gives `PLACE_NEW` a `next` that runs triage
before dispatch), and gets application-specific behaviour without forking the definition.

`PLACE_NEW` declares `next: [TRANSITION_DISPATCH]`, so state-bundle's `InitialPlaceKickoffListener`
queues the dispatch on `postFlush`. Persist a row and the rest runs unattended:

```php
$media = $mediaRegistry->ensureMedia($imageUrl);
$em->flush();   // → media.dispatch queued → mediary → callback → status applied
```

`supports` is `BaseMedia` rather than `Photo` so `Video` and `Audio` get the same lifecycle;
Symfony matches by instanceof and the marking lives on the base.

> Requires **state-bundle**, which is why it moved from `suggest` to `require`. It used to be
> optional back when the plan was to keep image URLs in `Media` and generate thumbnails locally.
> That plan is gone: all `Media` rows interact with mediary, and that interaction has state.
>
> Needs state-bundle **≥ 2.27.4**. Before that, `InitialPlaceKickoffListener` resolved the
> workflow by exact class name, so a workflow declared `supports: [BaseMedia::class]` never
> kicked off for the `Photo` rows anyone actually persists — silently, with no log line. Rows sat
> at `marking=new` forever.

### What this replaces

`media:ensure` + `media:sync`, where "which media still needs pushing" was a `WHERE status='new'`
scan and a foreground batch loop. Those commands remain for one-off debugging (`media:sync --url=…`
to push a single image through), but a pipeline that calls them is doing it the old way.

---

## Registering media

```php
foreach ($data->images as $imageUrl) {
    $media = $mediaRegistry->ensureMedia($imageUrl);   // bulk-safe, no flush, no network
}
```

- Defaults to `Photo`
- No duplicate URLs
- Local files supported (`ensureMedia($uploadedFile)`), assigned a temporary `local://` URL

---

## Receiving mediary's callback

mediary POSTs a signed `asset.analyzed` webhook to `/webhook/mediary` when an image finishes
analysis. This bundle supplies the two pieces that belong to it — a request parser
(`Survos\MediaBundle\Webhook\MediaWebhookRequestParser`) and a consumer that calls
`MediaUpdateApplier`. It contributes no route and no controller; the endpoint is
FrameworkBundle's own.

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

**Run a consumer for that transport.** A queued callback that nobody consumes looks exactly like
a mediary that never answered: rows stay at their pre-callback status indefinitely, with the
evidence sitting in a queue table rather than in a log.

Full contract, including how to run several webhooks on separate queues:
[kit-bundle/docs/webhooks.md](../kit-bundle/docs/webhooks.md).

> Replaced the unauthenticated `MediaCallbackController` at `/media/callback`, where anyone who
> could reach the URL could rewrite a media row. See survos-sites/mediary#8.

---

## AI-task sidecars (`SidecarClient`)

Cached AI-task results are read and written **through mediary**, over JSON-RPC:

```php
$data = $sidecarClient->read($mediaId, 'observe');          // null on a miss
$data = $sidecarClient->remember($mediaId, 'observe', fn () => $this->runAi(...));
```

`SidecarService` used to live here and be injected directly, which made every client a second
writer into mediary's bucket namespace — its own S3 credentials, its own copy of the path
convention, its own idea of when a sidecar was stale. That is the same shape as an app keeping a
`Media` table beside mediary's `Asset`: two owners of one thing, each free to drift. The service
now lives in mediary behind `sidecarGet` / `sidecarPut`, so caching, batching or a Redis layer can
appear there without any client changing.

Two deliberate exceptions:

- `path()` is computed locally from `MediaKeyService` — asking mediary for it would be a network
  round trip to learn something both sides can already derive, plus a chance to disagree.
- `remember()`'s compute-if-missing branch stays client-side: a producer callable cannot cross the
  wire, and the work it represents (a paid AI call) belongs to whoever wanted the answer.

---

## Probing mediary (polling fallback)

When webhooks are unavailable — a local dev tunnel is down, say — poll mediary directly:

```php
$result = $mediaBatchDispatcher->dispatch('museum', [$url], [
    'callback_url' => 'https://my-app.example/webhook/media',
]);

$probe = $mediaBatchDispatcher->probe($result->media[0]->mediaKey);
if ($probe->isComplete()) {
    // $probe->meta / ->context / ->ocr / ->ai
}
```

- `probe(string $assetId): MediaProbeResult` → `GET /fetch/media/{id}`
- `probeMany(array $assetIds): array<MediaProbeResult>` → `POST /fetch/media/by-ids`

The payload includes mediary's workflow state (`marking`), variants/thumb URLs, metadata, and any
OCR/AI context written so far.

```bash
bin/console media:probe 5c4e0c2d6f8a1b9e
bin/console media:probe "https://example.org/image.jpg"
bin/console media:probe --url "upload://sha256/abcd..."
```

---

## Publishing claims to mediary

Apps run AI with `survos/ai-workflow-bundle` and store tracked metadata as claims. Publishing
sends the image plus selected source/AI/human claims to mediary, while mediary stays responsible
for global media access and canonical image URLs. See [docs/publishing.md](docs/publishing.md).

---

## Optional dependencies

`survos/tabler-bundle` is a **suggest**, not a require. `MediaMenuSubscriber` and the search/UI
templates are registered only when TablerBundle is present, so a headless or CLI-only client — a
consumer that does nothing but register rows and drain queues — needs no theme.

---

## What this bundle does *not* do

- Download media
- Resize images
- Cache thumbnails
- Perform OCR, tagging, or EXIF extraction
- Hold an `Asset` table (that is mediary's; the app has `Media`, and only one of the two owns the file)

Those belong to mediary and imgproxy.

---

## Status

Known rough edges, recorded rather than hidden:

- **`BaseMedia` is brittle.** `imageUrl` / `thumbnailUrl` / `s3Url` are three flat columns whose
  relationship is conventional rather than enforced, and the constructor seeds both `status` and
  `marking` because the two state fields have not been reconciled — `status` is mediary's answer,
  `marking` is the workflow's, and nothing guarantees they agree. It should be an interface, and
  the URL fields should be one addressable thing.
- **No transition listeners.** The workflow moves markings; `status` is still written by the
  callback. Nothing fires `TRANSITION_SYNC`, so a row that mediary has fully processed sits at
  `dispatched` rather than reaching `synced`.
- Provider detection (YouTube, Flickr, …) is partial.
