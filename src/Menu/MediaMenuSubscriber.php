<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Menu;

use Survos\MediaBundle\Entity\Audio;
use Survos\MediaBundle\Entity\Photo;
use Survos\MediaBundle\Entity\Video;
use Survos\MenuBundle\Attribute\AsMenuListener;
use Survos\MenuBundle\Event\MenuEvent;
use Survos\MenuBundle\MenuSlot;
use Survos\TablerBundle\Menu\AbstractAdminMenuSubscriber;

final class MediaMenuSubscriber extends AbstractAdminMenuSubscriber
{
    protected function getLabel(): string
    {
        return 'Media';
    }

    protected function getGroupIcon(): ?string
    {
        return 'mdi:video-image';
    }

    protected function getResourceClasses(): array
    {
        return [
            'Photos' => Photo::class,
            'Videos' => Video::class,
            'Audio' => Audio::class,
        ];
    }

    #[AsMenuListener(MenuSlot::ADMIN_NAVBAR_MENU)]
    public function onAdminNavbarMenu(MenuEvent $event): void
    {
        $this->buildAdminMenu($event);
    }
}
