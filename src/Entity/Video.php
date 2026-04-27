<?php

namespace Survos\MediaBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\FieldBundle\Attribute\EntityMeta;
use Survos\FieldBundle\Attribute\Field;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
#[EntityMeta(icon: 'mdi:video', group: 'Media')]
class Video extends BaseMedia
{
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['media:read'])]
    #[Field(sortable: true)]
    public ?int $viewCount;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['media:read'])]
    #[Field(sortable: true)]
    public ?int $likeCount;

    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $chapters;

    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $subtitles;

    public function getType(): string
    {
        return 'video';
    }
}
