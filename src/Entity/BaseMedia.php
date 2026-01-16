<?php

namespace Survos\MediaBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types;
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
    #[ORM\Column(length: 32)]
    // this is the xxh3 of the url
    public string $id;

    #[ORM\Column(length: 255)]
    public string $status;

    #[ORM\Column(length: 100, nullable: true)]
    public ?string $provider;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $externalId;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $externalUrl;

    #[ORM\Column(type: Types::JSON)]
    public array $rawData = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public readonly \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $publishedAt;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $s3Url;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $smallUrl;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $storageKey;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $location;

    #[ORM\Column(type: Types::JSON)]
    public array $tags = [];

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    public ?int $width;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    public ?int $height;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    public ?int $duration;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    public ?int $fileSize;

    #[ORM\Column(length: 100, nullable: true)]
    public ?string $mimeType;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $description;

    public function __construct(string $id, ?string $code = null, ?string $provider = null, ?string $externalId = null)
    {
        $this->id = $id;
        $this->status = 'new'; // until the AssetWorkflow Constants are shared.
//        $this->code = $code ?? uniqid();
        $this->provider = $provider;
        $this->externalId = $externalId;
        $this->createdAt = new \DateTimeImmutable();
    }

    abstract public function getType(): string;
}
