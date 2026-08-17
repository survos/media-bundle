<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Dto;

use Survos\ImgproxyBundle\Dto\ImgproxyInfo;
use Survos\MediaBundle\Service\MediaKeyService;
use Survos\MediaBundle\Util\MediaIdentity;

/**
 * One "something about this image changed" notification from mediary.
 *
 * Normalises mediary's `asset.analyzed` webhook into the shape of our local
 * media row. mediary deliberately knows nothing about an app's own entities
 * (ssai's Item, harvest's Img) — it only speaks this, and apps layer their own
 * handling on the MediaUpdatedEvent that follows the upsert.
 */
final class MediaUpdate
{
    public function __construct(
        public readonly string  $originalUrl,
        public readonly ?string $status = null,
        public readonly ?string $storageKey = null,
        public readonly ?string $s3Url = null,
        public readonly ?string $smallUrl = null,
        public readonly ?string $mime = null,
        public readonly ?int    $width = null,
        public readonly ?int    $height = null,
        /** @var array<string,mixed> ocr/sha256 — the non-/info remainder */
        public readonly array   $context = [],
        /**
         * imgproxy Pro's /info response, typed.
         *
         * Reusing ImgproxyBundle's DTO rather than re-modelling the shape here:
         * this blob IS an imgproxy response, and a second copy of its structure
         * would drift the first time imgproxy adds a field. That's why
         * media-bundle now requires imgproxy-bundle outright instead of merely
         * suggesting it.
         *
         * Carries dimensions, faces (objects), average colour, blurhash and
         * embedded exif/iptc/xmp — most of which nothing has ever synced.
         */
        public readonly ?ImgproxyInfo $info = null,
    ) {
    }

    /**
     * Prefer explicit width/height, fall back to /info.
     *
     * mediary promotes these to columns but only for ~197k of 608k assets,
     * while /info carries them for every asset it has probed — so the fallback
     * is the common case, not the exception.
     */
    public function effectiveWidth(): ?int
    {
        return $this->width ?? $this->info?->width;
    }

    public function effectiveHeight(): ?int
    {
        return $this->height ?? $this->info?->height;
    }

    public function effectiveMime(): ?string
    {
        return $this->mime ?? $this->info?->mimeType;
    }

    /**
     * mediary's `asset.analyzed` payload. Unknown keys are ignored on purpose:
     * this is a pub/sub contract, and a subscriber must not break when the
     * publisher starts sending more than it used to.
     */
    public static function fromWebhook(array $p): self
    {
        return new self(
            originalUrl: (string) ($p['originalUrl'] ?? ''),
            status:      isset($p['marking']) ? (string) $p['marking'] : null,
            // mediary doesn't currently send storageKey (only archiveUrl) — see
            // mediaKeyFromArchiveUrl(). Prefer the explicit field when it appears.
            storageKey:  isset($p['storageKey']) ? (string) $p['storageKey'] : null,
            s3Url:       isset($p['archiveUrl']) ? (string) $p['archiveUrl'] : null,
            smallUrl:    isset($p['smallUrl']) ? (string) $p['smallUrl'] : null,
            mime:        isset($p['mime']) ? (string) $p['mime'] : null,
            width:       isset($p['width']) ? (int) $p['width'] : null,
            height:      isset($p['height']) ? (int) $p['height'] : null,
            context:     is_array($p['context'] ?? null) ? $p['context'] : [],
            info:        self::infoFrom($p['context']['info'] ?? $p['info'] ?? null),
        );
    }

    /**
     * One row of mediary's batch response (`{"media":[{...}]}`).
     *
     * Intentionally the SAME target shape as fromWebhook(): media:sync and the
     * async callback must produce identical MediaUpdate objects, or the
     * debuggable path stops predicting the production path.
     *
     * Note the batch response uses `s3Url`/`status` where the webhook uses
     * `archiveUrl`/`marking` — same values, different spelling, which is why
     * both need normalising here rather than at the call sites.
     */
    public static function fromBatchRow(array $r): self
    {
        return new self(
            originalUrl: (string) ($r['originalUrl'] ?? ''),
            status:      isset($r['status']) ? (string) $r['status'] : null,
            storageKey:  isset($r['storageKey']) ? (string) $r['storageKey'] : null,
            s3Url:       isset($r['s3Url']) ? (string) $r['s3Url'] : null,
            smallUrl:    isset($r['smallUrl']) ? (string) $r['smallUrl'] : null,
            // mediary does not yet return these on the batch endpoint — only on
            // the asset.analyzed webhook. Until it does, media:sync leaves
            // dimensions null rather than inventing them.
            mime:        isset($r['mime']) ? (string) $r['mime'] : null,
            width:       isset($r['width']) ? (int) $r['width'] : null,
            height:      isset($r['height']) ? (int) $r['height'] : null,
            context:     is_array($r['context'] ?? null) ? $r['context'] : [],
            info:        self::infoFrom($r['context']['info'] ?? $r['info'] ?? null),
        );
    }

    /** mediary nests the blob under context.info; tolerate a top-level one too. */
    private static function infoFrom(mixed $raw): ?ImgproxyInfo
    {
        return is_array($raw) && $raw !== [] ? ImgproxyInfo::fromArray($raw) : null;
    }

    /**
     * The local media row this update belongs to.
     *
     * Derived from originalUrl rather than trusting an id in the payload —
     * mediary serves many clients and has no idea what we call this row. Both
     * sides use MediaIdentity, so this is the one identifier that is
     * reproducible without coordination: MediaRegistry::ensureMedia() assigns
     * it locally and mediary's AssetRepository::findOneByUrl() derives the same
     * value for Asset::$id.
     *
     * NOT MediaKeyService::keyFromString() — that is the base64url of the URL,
     * which is the ARCHIVE PATH component (see storageKeyProblem()), not a row
     * id. Using it here meant every callback and every synced batch row looked
     * up a key no media row has ever had, logged "no local row", and returned
     * without writing anything.
     */
    public function mediaKey(): string
    {
        return MediaIdentity::idFromOriginalUrl($this->originalUrl);
    }

    /**
     * Verify the archive key mediary returned is the one we would have derived.
     *
     * Two schemes are in play as of 2026-08-17:
     *
     *   o/{1}/{2}/{base64url}.{ext}   TARGET. The filename is the base64url of
     *                                 the source URL, so it is REVERSIBLE —
     *                                 the same property imgproxy relies on.
     *                                 Fully reproducible, so we recompute and
     *                                 compare exactly.
     *   orig/{2}/{2}/{sha256}.{ext}   LEGACY, being deprecated. All 608,287
     *                                 currently-archived assets. A content
     *                                 hash is one-way, so the strongest
     *                                 available check is that the shard equals
     *                                 the first four characters of the hash.
     *
     * Returns null when consistent, else a human-readable reason. Callers
     * should WARN rather than reject: a legacy key is expected, not a fault.
     */
    public function storageKeyProblem(): ?string
    {
        $key = $this->storageKey ?? self::storageKeyFromArchiveUrl($this->s3Url);
        if ($key === null || $key === '') {
            return null; // nothing archived yet — not a problem, just not ready
        }

        $parts = explode('/', $key);
        if (count($parts) < 4) {
            return sprintf('expected {prefix}/{aa}/{bb}/{name}.{ext}, got "%s"', $key);
        }

        $filename = $parts[count($parts) - 1];
        $name = strtok($filename, '.') ?: '';
        $ext = pathinfo($filename, PATHINFO_EXTENSION);

        // TARGET scheme — reversible, so assert the whole path, not just a shard.
        if ($parts[0] !== 'orig' && $ext !== '') {
            $expected = MediaKeyService::archivePathFromUrl($this->originalUrl, $ext, $parts[0]);
            return $key === $expected
                ? null
                : sprintf('key "%s" != derived "%s"', $key, $expected);
        }

        // LEGACY scheme — content hash is one-way; check the shard invariant only.
        [, $aa, $bb] = $parts;
        if (!str_starts_with($name, $aa . $bb)) {
            return sprintf('legacy shard "%s/%s" does not match hash "%s"', $aa, $bb, substr($name, 0, 8));
        }

        return null;
    }

    /** True when this key still uses the deprecated content-hash layout. */
    public function isLegacyStorageKey(): bool
    {
        $key = $this->storageKey ?? self::storageKeyFromArchiveUrl($this->s3Url);
        return $key !== null && str_starts_with($key, 'orig/');
    }

    /** Recover the object key from a public archive URL, when only that is sent. */
    public static function storageKeyFromArchiveUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        if ($path === '') {
            return null;
        }
        // Hetzner virtual-hosted style puts the bucket in the host, so the path
        // is already the key. Path-style would prefix it with the bucket name.
        return $path;
    }
}
