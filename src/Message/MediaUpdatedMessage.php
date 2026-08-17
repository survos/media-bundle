<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Message;

/**
 * "Something about this image changed" — carried from the HTTP callback onto
 * the queue so the request can return immediately.
 *
 * Holds the raw webhook payload rather than a parsed DTO: the message may sit
 * in the queue across a deploy, and an array survives a DTO signature change
 * where a serialized object would not.
 */
final class MediaUpdatedMessage
{
    public function __construct(
        /** @var array<string,mixed> mediary's asset.analyzed payload, verbatim */
        public readonly array $payload,
    ) {
    }
}
