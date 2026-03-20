<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Interface;

use Survos\MediaBundle\Dto\MediaSyncItem;

interface MediaSyncInterface
{
    public function getMediaSync(): ?MediaSyncItem;

    public function setMediaSync(?MediaSyncItem $mediaSync): void;
}
