<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Dto;

final class MediaProbeResult
{
    /**
     * @param array<string,string> $thumbs
     * @param list<array<string,mixed>> $variants
     * @param array<string,mixed>|null $context
     * @param array<string,mixed> $meta
     * @param list<array<string,mixed>> $children
     * @param mixed $ocr
     * @param mixed $ai
     * @param array<string,mixed> $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly string $source,
        public readonly ?string $marking,
        public readonly array $thumbs,
        public readonly array $variants,
        public readonly ?array $context,
        public readonly array $meta,
        public readonly array $children,
        public readonly mixed $ocr,
        public readonly mixed $ai,
        public readonly array $raw,
    ) {
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: (string) ($row['id'] ?? ''),
            source: (string) ($row['source'] ?? ''),
            marking: isset($row['marking']) ? (string) $row['marking'] : null,
            thumbs: (array) ($row['thumbs'] ?? []),
            variants: array_values((array) ($row['variants'] ?? [])),
            context: isset($row['context']) && is_array($row['context']) ? $row['context'] : null,
            meta: (array) ($row['meta'] ?? []),
            children: array_values((array) ($row['children'] ?? [])),
            ocr: $row['ocr'] ?? null,
            ai: $row['ai'] ?? null,
            raw: $row,
        );
    }

    public function isComplete(): bool
    {
        return $this->marking === 'complete';
    }
}
