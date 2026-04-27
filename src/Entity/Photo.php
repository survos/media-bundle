<?php

namespace Survos\MediaBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\FieldBundle\Attribute\EntityMeta;
use Survos\FieldBundle\Attribute\Field;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
#[EntityMeta(icon: 'mdi:camera', group: 'Media')]
class Photo extends BaseMedia
{
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['media:read'])]
    #[Field(filterable: true)]
    public ?string $camera;

    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $exifData;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['media:read'])]
    #[Field(sortable: true)]
    public ?\DateTimeImmutable $takenAt;

    public function getType(): string
    {
        return 'photo';
    }
}
