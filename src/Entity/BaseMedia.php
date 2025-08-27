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
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public readonly ?int $id;

    #[ORM\Column(length: 255, unique: true)]
    public string $code;

    #[ORM\Column(length: 100)]
    public ?string $provider;

    #[ORM\Column(length: 255)]
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

    public function __construct(string $code = null, string $provider = null, string $externalId = null)
    {
        $this->code = $code ?? uniqid();
        $this->provider = $provider;
        $this->externalId = $externalId;
        $this->createdAt = new \DateTimeImmutable();
    }

    // SurvosSaisBundle integration
    public function getThumbnailUrl(?int $width = null, ?int $height = null): ?string
    {
        if (!$this->thumbnailUrl) {
            return null;
        }

        if ($width || $height) {
            return $this->generateSaisUrl($this->thumbnailUrl, $width, $height);
        }

        return $this->thumbnailUrl;
    }

    private function generateSaisUrl(string $originalUrl, ?int $width, ?int $height): string
    {
        $params = array_filter(['w' => $width, 'h' => $height]);
        return sprintf('/media/resize/%s?%s', 
            base64_encode($originalUrl), 
            http_build_query($params)
        );
    }

    abstract public function getType(): string;
}
