<?php

namespace Survos\MediaBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\MediaBundle\Repository\MediaRepository;

#[ORM\Entity(repositoryClass: MediaRepository::class)]
#[ORM\Table(name: 'media')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'type', type: 'string')]
#[ORM\DiscriminatorMap([
    'photo' => Photo::class,
    'video' => Video::class,
    'audio' => Audio::class,
])]
abstract class BaseMedia
{

    #[ORM\Id]
    #[ORM\Column(length: 255)]
    public string $id;

    #[ORM\Column(length: 255, unique: true)]
    public string $code;

    #[ORM\Column(length: 100, nullable: true)]
    public ?string $provider;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $externalId;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $externalUrl;

    #[ORM\Column(type: 'json')]
    public array $rawData = [];

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $publishedAt;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $thumbnailUrl;

    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $location;

    #[ORM\Column(type: 'json')]
    public array $tags = [];

    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $width;

    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $height;

    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $duration;

    #[ORM\Column(type: 'bigint', nullable: true)]
    public ?int $fileSize;

    #[ORM\Column(length: 100, nullable: true)]
    public ?string $mimeType;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $description;

    public function __construct(string $id, ?string $code = null, ?string $provider = null, ?string $externalId = null)
    {
        $this->id = $id;
        $this->code = $code ?? uniqid();
        $this->provider = $provider;
        $this->externalId = $externalId;
        $this->createdAt = new \DateTimeImmutable();
    }

    abstract public function getType(): string;
}
