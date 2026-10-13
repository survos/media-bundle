<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Trait;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\MediaBundle\Dto\MediaSyncItem;

trait HasMediaSyncTrait
{
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['image:read', 'enrichment:read'])]
    public ?array $mediaSyncData = null;

    private ?MediaSyncItem $mediaSync = null;

    public function getMediaSync(): ?MediaSyncItem
    {
        if (null !== $this->mediaSync) {
            return $this->mediaSync;
        }

        if (null === $this->mediaSyncData) {
            return null;
        }

        $this->mediaSync = MediaSyncItem::fromArray($this->mediaSyncData);

        return $this->mediaSync;
    }

    public function setMediaSync(?MediaSyncItem $mediaSync): void
    {
        $this->mediaSync = $mediaSync;
        $this->mediaSyncData = $mediaSync?->toArray();
    }

    #[ORM\PostLoad]
    public function onMediaSyncPostLoad(): void
    {
        if (null !== $this->mediaSyncData && null === $this->mediaSync) {
            $this->mediaSync = MediaSyncItem::fromArray($this->mediaSyncData);
        }
    }
}
