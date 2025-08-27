<?php

namespace Survos\MediaBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Survos\MediaBundle\Repository\MediaRepository;
use Survos\MediaBundle\Traits\HasMediaTrait;

#[AsDoctrineListener(event: Events::postLoad)]
final class MediaPostLoadListener
{
    public function __construct(
        private readonly MediaRepository $mediaRepository
    ) {}

    public function postLoad(PostLoadEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$this->usesHasMediaTrait($entity)) {
            return;
        }

        // Load images
        $imageCodes = $entity->imageCodes;
        if ($imageCodes) {
            $images = new ArrayCollection(
                $this->mediaRepository->findByCodes($imageCodes)
            );
        } else {
            $images = new ArrayCollection();
        }
        $entity->images = $images;

        // Load audio
        if ($audioCode = $entity->audioCode) {
            $entity->audio = $this->mediaRepository->findByCode($audioCode);
        }

        // Load videos
        $videoCodes = $entity->videoCodes;
        if ($videoCodes) {
            $videos = new ArrayCollection(
                $this->mediaRepository->findByCodes($videoCodes)
            );
        } else {
            $videos = new ArrayCollection();
        }
        $entity->videos = $videos;
    }

    private function usesHasMediaTrait($entity): bool
    {
        $traits = class_uses_recursive($entity::class);
        return in_array(HasMediaTrait::class, $traits);
    }
}
