# media:reconcile — recovering from callbacks that never arrived

Recovery only. On a healthy day nothing needs this command.

## What goes wrong

A local media row is a projection of mediary's `Asset`. We push the row (the `media` workflow's
`dispatch` transition), mediary archives and analyses it, and mediary pushes each state change
back over a signed webhook, which `MediaRemoteEventConsumer` applies through
`MediaUpdateApplier`.

That last hop is the fragile one, and it fails in an entirely ordinary way: the machine mediary
is calling back is a laptop. Shut the lid, reboot without starting podman, let the tunnel
hostname move, and mediary's webhook retries, exhausts them, and parks the message in its failed
transport. Nothing is lost on mediary's side — the asset is archived, analysed, scored, stored.
Only the notification is gone, so the local row keeps whatever status it last heard and stays
there forever. There is no `sync` transition wired to anything, so `marking` stays at
`dispatched` too.

A real example, 2026-09-20: mediary's failed transport held 368 undeliverable
`asset.analyzed` webhooks, 92 of them to `m4-harvest.scanstationai.work` from a single morning's
reboot. Locally, 7 `oni/georgia-sn01884514` rows still said `new` while mediary had them
`complete`.

## What the command does

```bash
bin/console media:reconcile --dataset=oni/georgia-sn01884514
```

It selects rows in marking `dispatched` whose reflected `status` has not reached a terminal
place (`complete`, `failed`, `deleted` — `MediaUpdateApplier::TERMINAL_STATUSES`), probes
mediary for them in chunks, and applies each answer through the same `MediaUpdateApplier` the
callback uses. A reconcile therefore writes exactly what the missed webhook would have written,
including the rank guard that stops a stale answer from walking a row backwards.

| option | default | |
|---|---|---|
| `--dataset` | all | limit to one dataset key, e.g. `omeka/wej` |
| `--media` | — | one 16-hex media id, for debugging a single row |
| `--limit` | 0 (all) | stop after this many rows |
| `--chunk` | 25 | ids per probe call |
| `--all` | off | include rows whose status is already terminal (re-read everything) |
| `--dry-run` | off | probe and report, write nothing |

Output is a table of what mediary currently says, plus counts of rows applied and changed.

## Why probe, not re-dispatch

Re-dispatching the URL would also return current state inline — `MediaWorkflow::onDispatch` in
harvest hands mediary's response straight to `applyBatch` — and for a row we never successfully
sent, re-dispatching is the right move. But it is a write: it re-registers the asset, re-sends
source context, and can re-queue AI tasks. These rows were accepted already; we are only missing
the news. `probeAssets` is the read-only equivalent, so recovery cannot cost an AI run.

## The other direction: mediary can replay

Mediary has `media:replay-webhooks` ("Re-deliver asset.analyzed to clients that never got it"),
which pushes the same notifications again. Prefer it when you have access to mediary and the
client is reachable now — it exercises the real path, and every client gets the news, not just
the one you happen to be sitting in front of.

`media:reconcile` is the pull side, for when you do not have mediary access, do not know which
rows were missed, or want the app's own view repaired without depending on delivery working
this time.

## Reading the output

- **mediary says: complete** — the lost-callback case. These rows get their storage key, archive
  URL, dimensions and status, and `changed` counts them.
- **mediary says: ai_ready / archived** — not a lost callback. Mediary genuinely has not finished
  these, and the reconcile correctly applies nothing. Re-queue the work on mediary instead
  (`media:task --dataset=<key> --enqueue=<task>`).
- **unknown to mediary** — the id is in our table but mediary has never seen it, or registered it
  under a different identity. Worth investigating; a reconcile cannot fix it.
- **could not be probed** — an id whose probe row is so large it times out on its own.

## Chunk size and the timeout split

A probe row carries the asset's whole context: OCR text, AI output, children, the promoted
`/info` fields. A chunk of 100 newspaper pages will blow past the HTTP client's idle timeout
while 100 photographs return instantly. Rather than make the operator guess a chunk size per
dataset, a chunk that times out is split in half and retried, down to single ids; an id that
fails alone is reported and skipped so the rest of the run still completes.

## Scope, and what it deliberately does not do

- It does not touch rows in marking `new`. Those were never dispatched and belong to the dataset
  workflow's dispatch step, not to recovery. (Rows with marking `new` but a non-`new` status do
  exist — they heard a broadcast without a local dispatch — and are also left alone.)
- It does not advance `marking`. The `media` workflow defines `sync` (`dispatched` → `synced`)
  but nothing has ever applied it, so `marking` is dispatch bookkeeping and `status` is the
  signal every consumer actually reads (`DatasetEnrichGuard`, `DatasetMediaGateListener`). Making
  `sync` real is a separate change.
- It is not a scheduled job. If rows routinely need reconciling, the callback path is broken and
  that is the thing to fix.
