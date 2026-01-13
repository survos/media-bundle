<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Dto;

final class MediaRegistration
{
    public function __construct(
        public readonly string $originalUrl,
        public readonly string $mediaKey,
        public readonly string $status,
    ) {
    }

    public static function fromArray(array $row): self
    {
        return new self(
            $row['originalUrl'],
            $row['mediaKey'],
            $row['status'],
        );
    }
}
