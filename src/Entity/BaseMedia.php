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
    public ?string $provider = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $externalId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $externalUrl = null;

    #[ORM\Column(type: Types::JSON)]
    public array $rawData = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public readonly \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $s3Url = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $smallUrl = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $storageKey = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $location = null;

    #[ORM\Column(type: Types::JSON)]
    public array $tags = [];

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    public ?int $width = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    public ?int $height = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    public ?int $duration = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    public ?int $fileSize = null;

    #[ORM\Column(length: 100, nullable: true)]
    public ?string $mimeType = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $description = null;

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
