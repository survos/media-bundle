# Publishing Media and Claims

Applications should run AI locally with `survos/ai-workflow-bundle` and store
the resulting assertions with the claims bundle. `survos/media-bundle` should
not become the owner of AI execution or metadata truth. Its role is to register
media references, compute stable media identity, and publish media payloads to
mediary.

## Responsibilities

- The application owns subject context and decides which workflows to run.
- `ai-workflow-bundle` runs extraction, OCR, thumbnail, and enrichment tasks.
- The claims bundle stores tracked metadata claims from source records, AI, OCR,
  human review, and import pipelines.
- `media-bundle` pushes media URLs/files and lightweight context to mediary.
- Mediary owns global media access: canonical asset identity, cached media,
  derivative URLs, IIIF-style access, and globally reachable image URLs.

Mediary may still run its own AI when that is useful, for example a cheap
thumbnail pass, global OCR, or tools exposed by an `ai-tools` service. Those
mediary-side claims should still be recorded as claims with mediary provenance,
not mixed into an opaque result blob.

## Publish Payload

When an app publishes media to mediary, it should eventually send the selected
claim projection alongside the image identity:

```json
{
  "client": "ssai",
  "urls": ["https://example.org/image.jpg"],
  "context": {
    "https://example.org/image.jpg": {
      "source_id": "commonwealth:abc123",
      "dcterms:title": "Main Street parade",
      "dcterms:subject": ["parades", "streets"],
      "dcterms:source": "https://institution.example/item/abc123"
    }
  },
  "claims": {
    "https://example.org/image.jpg": [
      {
        "predicate": "dcterms:title",
        "value": "Main Street parade",
        "source": "source",
        "confidence": 1.0
      },
      {
        "predicate": "dcterms:subject",
        "value": "parades",
        "source": "ai-workflow:extract_metadata@1.0",
        "confidence": 0.72,
        "basis": "Visible parade route and crowd"
      }
    ]
  }
}
```

Today the media dispatcher sends `context` only. That context is source metadata
for mediary registration and display. The next publishing step is a claims
projection policy that selects which app-local claims should be published with
the media.

## Projection Policy

Publish claims deliberately. Good defaults:

- publish authoritative source claims such as title, description, date, creator,
  subject, rights, license, and source URL;
- publish human-confirmed claims;
- publish AI/OCR claims above an application-defined confidence threshold;
- preserve source, confidence, basis, and run/version metadata;
- avoid speculative claims unless explicitly requested;
- avoid raw task result blobs.

This keeps mediary globally useful without making it the source of truth for
domain interpretation. Apps can republish when their local claims change.

## Naming

Prefer “claims” for tracked metadata assertions. A claim is metadata with
provenance and optional confidence; it may come from a source record, an AI
workflow, OCR, human review, or mediary-side processing.
