<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Dto;

final class MediaRegistration
{
    public function __construct(
        public readonly string $originalUrl,
        public readonly string $mediaKey,
        public readonly string $status,
        public readonly ?string $storageKey,
        public readonly ?string $s3Url,
        public readonly ?string $smallUrl,
    ) {
    }

    public static function fromArray(array $row): self
    {
        return new self(
            $row['originalUrl'],
            $row['mediaKey'],
            $row['status'],
            $row['storageKey'],
            $row['s3Url']?:null,
            $row['smallUrl']?:null,
        );
    }
}
