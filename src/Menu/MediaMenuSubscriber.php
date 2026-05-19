<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Menu;

use Survos\MediaBundle\Entity\Audio;
use Survos\MediaBundle\Entity\Photo;
use Survos\MediaBundle\Entity\Video;
use Survos\TablerBundle\Event\MenuEvent;
use Survos\TablerBundle\Menu\AbstractAdminMenuSubscriber;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

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
            'Audio'  => Audio::class,
        ];
    }

    #[AsEventListener(event: MenuEvent::ADMIN_NAVBAR_MENU)]
    public function onAdminNavbarMenu(MenuEvent $event): void
    {
        $this->buildAdminMenu($event);
    }
}
