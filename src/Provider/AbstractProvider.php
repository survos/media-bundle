<?php

namespace Survos\MediaBundle\Provider;

use Survos\MediaBundle\Entity\BaseMedia;
use Survos\MediaBundle\Entity\Photo;
use Survos\MediaBundle\Entity\Video;
use Survos\MediaBundle\Entity\Audio;

abstract class AbstractProvider implements ProviderInterface
{
    public function __construct(
        protected array $config = []
    ) {}

    protected function generateCode(string $id, ?string $suffix = null): string
    {
        $code = sprintf('%s_%s', $this->getName(), $id);
        return $suffix ? $code . '_' . $suffix : $code;
    }

    protected function createMedia(string $type, string $externalId): BaseMedia
    {
        $code = $this->generateCode($externalId);
        
        return match($type) {
            'photo' => new Photo($code, $this->getName(), $externalId),
            'video' => new Video($code, $this->getName(), $externalId),
            'audio' => new Audio($code, $this->getName(), $externalId),
            default => throw new \InvalidArgumentException("Unsupported media type: $type")
        };
    }
}
