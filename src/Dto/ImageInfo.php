<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Dto;

use Survos\ImageContracts\ImageMeta;

/**
 * The stored image-info blob (a media row's `info`), with the few fields consumers read promoted to properties.
 *
 * Field names follow imgproxy Pro's /info, which is what existing rows hold; fromArray() also accepts the short keys some
 * producers write. Everything else (palette, perceptual hash, classification, embedded EXIF/IPTC/XMP, ...) stays in `raw`,
 * which is what gets stored. Nothing here depends on an image-server bundle: a producer with less to report, such as
 * imagor's meta/, simply yields a sparser instance.
 */
final readonly class ImageInfo
{
    /**
     * @param array<string, mixed>      $raw
     * @param list<mixed>               $objects detected regions (faces and the like), in the producer's own shape
     * @param array<string, mixed>|null $average {R,G,B,A}
     */
    public function __construct(
        public array $raw,
        public ?int $width = null,
        public ?int $height = null,
        public ?int $orientation = null,
        public ?string $format = null,
        public ?string $mimeType = null,
        public ?int $size = null,
        public ?string $blurhash = null,
        /** ThumbHash, base64: the form every decoder takes. A hex value (imgproxy's) is converted once here. */
        public ?string $thumbHash = null,
        public ?array $average = null,
        public array $objects = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $dimensions = \is_array($data['dimensions'] ?? null) ? $data['dimensions'] : [];
        $objects = $data['objects'] ?? $data['detected_objects'] ?? $data['do'] ?? null;
        $average = $data['average'] ?? $data['avg'] ?? null;

        return new self(
            raw: $data,
            width: self::int($data['width'] ?? $dimensions['width'] ?? null),
            height: self::int($data['height'] ?? $dimensions['height'] ?? null),
            orientation: self::int($data['orientation'] ?? $dimensions['orientation'] ?? null),
            format: self::string($data['format'] ?? null),
            mimeType: self::string($data['mime_type'] ?? $data['mimeType'] ?? null),
            size: self::int($data['size'] ?? null),
            blurhash: self::string($data['blurhash'] ?? $data['bh'] ?? null),
            thumbHash: self::thumbHashBase64($data['thumb_hash'] ?? $data['thumbhash'] ?? $data['th'] ?? null),
            average: \is_array($average) ? $average : null,
            objects: \is_array($objects) ? array_values($objects) : [],
        );
    }

    /**
     * Hex ThumbHash (imgproxy's form) to base64; already-base64 input passes through. Public because consumers that read the
     * stored blob straight out of SQL need the same rule without hydrating the whole object.
     */
    public static function thumbHashBase64(mixed $value): ?string
    {
        $value = self::string($value);
        if (null === $value) {
            return null;
        }
        if (0 === \strlen($value) % 2 && ctype_xdigit($value) && false !== ($bytes = hex2bin($value))) {
            return base64_encode($bytes);
        }

        return $value;
    }

    /** "#rrggbb" from `average` ({R,G,B,A}), the cheapest placeholder. */
    public function averageHex(): ?string
    {
        $rgb = $this->average;
        if (!isset($rgb['R'], $rgb['G'], $rgb['B'])) {
            return null;
        }

        return \sprintf('#%02x%02x%02x', (int) $rgb['R'], (int) $rgb['G'], (int) $rgb['B']);
    }

    /** The backend-neutral subset ({@see \Survos\ImageContracts\ImageMetaProviderInterface}). */
    public function toImageMeta(): ImageMeta
    {
        return new ImageMeta(
            width: $this->width,
            height: $this->height,
            format: null === $this->format ? null : strtolower($this->format),
            thumbhash: $this->thumbHash,
            blurhash: $this->blurhash,
            averageColor: $this->averageHex(),
        );
    }

    private static function int(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }

    private static function string(mixed $v): ?string
    {
        return \is_string($v) && '' !== $v ? $v : null;
    }
}
