<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Event;

use Survos\MediaBundle\Dto\MediaUpdate;
use Survos\MediaBundle\Entity\BaseMedia;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Fired after the local media row has been updated from a mediary callback.
 *
 * This is the seam for app-specific work. mediary knows the shape of `media`
 * and nothing else, so anything that needs to propagate further — ssai's Item,
 * harvest's Img, re-indexing a folio — listens here rather than asking mediary
 * to learn about it.
 *
 *     #[AsEventListener]
 *     public function onMediaUpdated(MediaUpdatedEvent $event): void
 *     {
 *         if ($event->update->s3Url === null) { return; }
 *         // ... update your own entity from $event->media
 *     }
 */
final class MediaUpdatedEvent extends Event
{
    public function __construct(
        public readonly BaseMedia   $media,
        public readonly MediaUpdate $update,
        /** Fields that actually changed, so listeners can skip no-op deliveries. */
        public readonly array       $changed = [],
    ) {
    }

    public function didChange(string ...$fields): bool
    {
        foreach ($fields as $f) {
            if (in_array($f, $this->changed, true)) {
                return true;
            }
        }
        return false;
    }
}
