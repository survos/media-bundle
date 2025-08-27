<?php

namespace Survos\MediaBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Photo extends BaseMedia
{
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $camera;

    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $exifData;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $takenAt;

    public function getType(): string
    {
        return 'photo';
    }
}
