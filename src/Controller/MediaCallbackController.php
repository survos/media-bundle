<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Controller;

use Survos\MediaBundle\Message\MediaUpdatedMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives mediary's "something about this image changed" notifications.
 *
 * Deliberately thin: validate enough to reject junk, put it on the queue,
 * return. No database work happens in the request. mediary POSTs these
 * synchronously inside its own workflow transition, so a slow subscriber would
 * slow down the publisher — and a subscriber that throws would make mediary
 * log a failed webhook for something that was merely busy.
 *
 * Ships in the bundle rather than each app because the shape of `media` is the
 * one thing mediary and every consumer already agree on. App-specific work
 * (ssai's Item, harvest's Img) listens for MediaUpdatedEvent instead.
 */
final class MediaCallbackController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/media/callback', name: 'survos_media_callback', methods: ['POST'])]
    public function callback(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return $this->json(['status' => 'error', 'reason' => 'body is not a JSON object'], 400);
        }

        if (($payload['originalUrl'] ?? '') === '') {
            // Without this we cannot derive the media key, so there is nothing
            // to reconcile against. 400 rather than a silent 200 — this one IS
            // a publisher bug, unlike an asset we simply don't own.
            return $this->json(['status' => 'error', 'reason' => 'originalUrl is required'], 400);
        }

        $this->bus->dispatch(new MediaUpdatedMessage($payload));

        // 202: accepted, not yet applied. Anything else would imply the row is
        // already updated, which it is not until the queue drains.
        return $this->json([
            'status' => 'accepted',
            'event' => $payload['event'] ?? null,
        ], 202);
    }
}
