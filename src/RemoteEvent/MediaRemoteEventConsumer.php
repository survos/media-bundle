<?php

declare(strict_types=1);

namespace Survos\MediaBundle\RemoteEvent;

use Survos\MediaBundle\Dto\MediaUpdate;
use Survos\MediaBundle\Service\MediaUpdateApplier;
use Survos\MediaBundle\Webhook\MediaWebhookRequestParser;
use Symfony\Component\RemoteEvent\Attribute\AsRemoteEventConsumer;
use Symfony\Component\RemoteEvent\Consumer\ConsumerInterface;
use Symfony\Component\RemoteEvent\RemoteEvent;

/**
 * Async arm of the media update path.
 *
 * Deliberately contains no logic: it normalises mediary's payload and hands it to
 * {@see MediaUpdateApplier}, which is the same call `media:sync` makes synchronously. If you
 * are debugging what a callback *does*, read the applier — this class only decides *when*.
 *
 * The framework gets it here: `WebhookController` dispatches a `ConsumeRemoteEventMessage`
 * carrying the webhook name, Messenger sends that to a transport, and `ConsumeRemoteEventHandler`
 * looks this class up by the same name the parser declares. Nothing in this bundle touches the
 * request, the queue, or the retry policy any more.
 *
 * Ships in the bundle rather than in each app because the shape of `media` is the one thing
 * mediary and every consumer already agree on. App-specific work (ssai's Item, harvest's Img)
 * listens for {@see \Survos\MediaBundle\Event\MediaUpdatedEvent} instead, which the applier
 * dispatches — so an app never has to know a webhook was involved at all.
 */
#[AsRemoteEventConsumer(MediaWebhookRequestParser::WEBHOOK_NAME)]
final class MediaRemoteEventConsumer implements ConsumerInterface
{
    public function __construct(
        private readonly MediaUpdateApplier $applier,
    ) {
    }

    public function consume(RemoteEvent $event): void
    {
        $this->applier->apply(MediaUpdate::fromWebhook($event->getPayload()));
    }
}
