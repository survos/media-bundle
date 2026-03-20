<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Trait;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\MediaBundle\Dto\MediaSyncItem;
use Survos\MediaBundle\Interface\MediaSyncInterface;

trait HasMediaSyncTrait
{
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['image:read', 'enrichment:read'])]
    public ?array $mediaSyncData = null;

    private ?MediaSyncItem $mediaSync = null;

    public function getMediaSync(): ?MediaSyncItem
    {
        if ($this->mediaSync !== null) {
            return $this->mediaSync;
        }

        if ($this->mediaSyncData === null) {
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
        if ($this->mediaSyncData !== null && $this->mediaSync === null) {
            $this->mediaSync = MediaSyncItem::fromArray($this->mediaSyncData);
        }
    }
}
