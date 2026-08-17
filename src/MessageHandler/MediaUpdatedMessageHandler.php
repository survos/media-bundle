<?php
declare(strict_types=1);

namespace Survos\MediaBundle\MessageHandler;

use Survos\MediaBundle\Dto\MediaUpdate;
use Survos\MediaBundle\Message\MediaUpdatedMessage;
use Survos\MediaBundle\Service\MediaUpdateApplier;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Async arm of the media update path.
 *
 * Deliberately contains no logic: it normalises the webhook payload and hands
 * it to MediaUpdateApplier, which is the same call `media:sync` makes
 * synchronously. If you are debugging what a callback *does*, read the
 * applier — this class only decides *when*.
 */
#[AsMessageHandler]
final class MediaUpdatedMessageHandler
{
    public function __construct(
        private readonly MediaUpdateApplier $applier,
    ) {
    }

    public function __invoke(MediaUpdatedMessage $message): void
    {
        $this->applier->apply(MediaUpdate::fromWebhook($message->payload));
    }
}
