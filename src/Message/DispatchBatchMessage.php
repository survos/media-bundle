<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Message;

/**
 * Async message to dispatch a batch of URLs to the mediary server.
 * Decouples the slow HTTP call from the sync command loop.
 */
final class DispatchBatchMessage
{
    public function __construct(
        public readonly string $client,
        /** @var string[] */
        public readonly array $urls,
        /** @var array<string,array> url => rawData context map */
        public readonly array $contextMap = [],
        public readonly bool  $uploadOnly = true,
    ) {}
}
