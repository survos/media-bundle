<?php

namespace Survos\MediaBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\FieldBundle\Attribute\EntityMeta;
use Survos\FieldBundle\Attribute\Field;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
#[EntityMeta(icon: 'mdi:music', group: 'Media')]
class Audio extends BaseMedia
{
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['media:read'])]
    #[Field(searchable: true, sortable: true)]
    public ?string $artist;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['media:read'])]
    #[Field(searchable: true, sortable: true)]
    public ?string $album;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['media:read'])]
    public ?int $bitrate;

    public function getType(): string
    {
        return 'audio';
    }
}
