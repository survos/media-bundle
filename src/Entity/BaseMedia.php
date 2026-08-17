<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types;
use Survos\FieldBundle\Attribute\EntityMeta;
use Survos\FieldBundle\Attribute\Field;
use Survos\FieldBundle\Attribute\RouteIdentity;
use Survos\FieldBundle\Entity\RouteIdentityTrait;
use Survos\FieldBundle\Entity\RouteParametersInterface;
use Survos\DataContracts\Workflow\ContextSubjectInterface;
use Survos\DataContracts\Workflow\ImageSubjectInterface;
use Survos\DataContracts\Workflow\WorkflowSubjectInterface;
use Survos\MediaBundle\Repository\MediaRepository;
use Survos\MediaBundle\Trait\HasAiVisionTrait;
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
    operations: [new Get(uriTemplate: '/media/{id}'), new GetCollection(uriTemplate: '/media')],
    normalizationContext: ['groups' => ['media:read'], 'skip_null_values' => true],
)]
#[EntityMeta(icon: 'mdi:video-image', group: 'Media')]
#[RouteIdentity(field: 'id')]
abstract class BaseMedia implements RouteParametersInterface, WorkflowSubjectInterface, ImageSubjectInterface, ContextSubjectInterface
{
    use RouteIdentityTrait;
    use HasAiVisionTrait;

    #[ORM\Id]
    #[ORM\Column(length: 32)]
    #[Groups(['media:read'])]
    #[ApiProperty(identifier: true)]
    #[Field(sortable: true, order: 10)]
    public string $id;

    #[ORM\Column(length: 255)]
    #[Groups(['media:read'])]
    #[Field(sortable: true, filterable: true)]
    public string $status;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['media:read'])]
    #[Field(filterable: true)]
    public ?string $provider = null;

    /**
     * Dataset key (provider/code, e.g. "mus/fortepan") this media belongs to. First-class column
     * (was only in rawData) so dataset-aware apps can query/group by it and per-collection AI-task
     * callbacks can key on it. Null in apps that don't use dataset-bundle.
     */
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['media:read'])]
    #[Field(filterable: true)]
    public ?string $dataset = null;

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

    /**
     * imgproxy Pro's /info response, stored verbatim.
     *
     * Kept whole rather than shredded into columns because the enabled info
     * options vary by imgproxy version and build — promoting each field would
     * mean a migration every time imgproxy learns something new. The fields we
     * actually query (width/height/mime) ARE promoted to real columns above; the
     * rest is read through the hooks below.
     *
     * Yes, this weighs the row down. Acceptable here: media rows are written by
     * the sync/callback path and read mostly through folios and search indexes,
     * not by hydrating the entity.
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $info = null;

    /**
     * Face boxes detected by imgproxy Pro, normalised 0..1 with confidence.
     *
     * A property hook, not a column: this is specifically imgproxy's object
     * detection shape (yolox `class_name: "face"`), not a general-purpose face
     * record. Detections from another engine — OpenCV, say — would have a
     * different shape and do not belong in the same field. Treat it as bonus
     * data that happens to ride along with /info.
     *
     * @return list<array{top:float,left:float,width:float,height:float,confidence:float,class_name:string}>
     */
    #[Groups(['media:read'])]
    public array $faces {
        get => array_values(array_filter(
            $this->info['objects'] ?? [],
            static fn (mixed $o): bool => is_array($o) && ($o['class_name'] ?? null) === 'face',
        ));
    }

    /** Average RGB of the image, from /info. Useful as a placeholder colour. */
    public ?array $averageColor {
        get => is_array($this->info['average'] ?? null) ? $this->info['average'] : null;
    }

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

    // ── Workflow subject: the media row IS the AI-task subject ────────────────

    /** The one canonical fetchable image/document URL — prefer the archived copy. */
    public function getWorkflowImageUrl(): ?string
    {
        return $this->s3Url ?? $this->externalUrl;
    }

    /** Transient per-run hints (not persisted) merged into the task context, e.g. ['max_pages' => 2]. */
    public array $runtimeContext = [];

    /** @return array<string, mixed> */
    public function getWorkflowContext(): array
    {
        return [...$this->rawData, ...$this->runtimeContext];
    }

    public function getWorkflowSubjectId(): string
    {
        return $this->id;
    }

    public function getWorkflowSubjectType(): string
    {
        return $this->getType();
    }

    public function getWorkflowScope(): ?string
    {
        return null;
    }

    public function isWorkflowLocked(): bool
    {
        return $this->aiLocked;
    }

    public function setWorkflowLocked(bool $locked): void
    {
        $this->aiLocked = $locked;
    }
}
