<?php

namespace Survos\MediaBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Video extends BaseMedia
{
    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $viewCount;

    #[ORM\Column(type: 'integer', nullable: true)]
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
