<?php

namespace Survos\MediaBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Audio extends BaseMedia
{
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $artist;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $album;

    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $bitrate;

    public function getType(): string
    {
        return 'audio';
    }
}
