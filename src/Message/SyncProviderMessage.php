<?php

namespace Survos\MediaBundle\Message;

class SyncProviderMessage
{
    public function __construct(
        public readonly string $providerName,
        public readonly array $options = []
    ) {}
}
