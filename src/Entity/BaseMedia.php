<?php

namespace Survos\MediaBundle\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types;
use Survos\FieldBundle\Attribute\EntityMeta;
use Survos\FieldBundle\Attribute\Field;
use Survos\MediaBundle\Repository\MediaRepository;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: MediaRepository::class)]
#[ORM\Table(name: 'media')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'type', type: 'string')]
#[ORM\DiscriminatorMap([
    'photo' => Photo::class,
    'video' => Video::class,
    'audio' => Audio::class,
])]
#[ApiResource(
    operations: [new Get(), new GetCollection()],
    normalizationContext: ['groups' => ['media:read'], 'skip_null_values' => true],
)]
#[EntityMeta(icon: 'mdi:video-image', group: 'Media')]
abstract class BaseMedia
{

    #[ORM\Id]
    #[ORM\Column(length: 32)]
    #[Groups(['media:read'])]
    #[Field(sortable: true)]
    public string $id;

    #[ORM\Column(length: 255)]
    #[Groups(['media:read'])]
    #[Field(sortable: true, filterable: true)]
    public string $status;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['media:read'])]
    #[Field(filterable: true)]
    public ?string $provider = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['media:read'])]
    public ?string $externalId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['media:read'])]
    #[Field(searchable: true)]
    public ?string $externalUrl = null;

    #[ORM\Column(type: Types::JSON)]
    public array $rawData = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['media:read'])]
    #[Field(sortable: true)]
    public readonly \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['media:read'])]
    public ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['media:read'])]
    public ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['media:read'])]
    public ?string $s3Url = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['media:read'])]
    public ?string $smallUrl = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $storageKey = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $location = null;

    #[ORM\Column(type: Types::JSON)]
    #[Groups(['media:read'])]
    public array $tags = [];

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Groups(['media:read'])]
    #[Field(sortable: true)]
    public ?int $width = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Groups(['media:read'])]
    #[Field(sortable: true)]
    public ?int $height = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Groups(['media:read'])]
    public ?int $duration = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    #[Groups(['media:read'])]
    public ?int $fileSize = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['media:read'])]
    #[Field(filterable: true)]
    public ?string $mimeType = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['media:read'])]
    #[Field(searchable: true, sortable: true)]
    public ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['media:read'])]
    #[Field(searchable: true)]
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
