<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Trait;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\FieldBundle\Attribute\Field;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Doctrine storage columns for AI workflow directives.
 *
 * `aiQueue` holds task names seeded on the media — a processing DIRECTIVE
 * (e.g. ["ocr_mistral"]), not results. Task *results* are persisted as an S3
 * sidecar keyed by media id (see {@see \Survos\MediaBundle\Service\SidecarService}),
 * and distilled facts flow through claims-bundle. `aiCompleted` records a light
 * completion pointer per task (task, at, sidecar key) — the bulk blob stays on S3.
 *
 * The columns are mapped on the trait itself — an entity just `use`s it. They are
 * exposed to the admin browse grid (#[Field]) and API serialization (#[Groups]) so
 * pending tasks and completed results show in the media-search browse page.
 */
trait HasAiVisionTrait
{
    /** @var list<string> task names still to run on this media (pending directive) */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    #[Groups(['media:read'])]
    #[Field(filterable: true, facet: true)]
    public array $aiQueue = [];

    /** @var list<array{task: string, at: string, sidecar: string}> completed-task pointers */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    #[Groups(['media:read'])]
    #[Field]
    public array $aiCompleted = [];

    #[ORM\Column(options: ['default' => false])]
    public bool $aiLocked = false;

    #[ORM\Column(nullable: true)]
    #[Groups(['media:read'])]
    #[Field(filterable: true, facet: true)]
    public ?string $aiDocumentType = null;

    // ── Queue helpers ─────────────────────────────────────────────────────────

    public function enqueueAiTask(string $taskName): void
    {
        if (!in_array($taskName, $this->aiQueue, true)) {
            $this->aiQueue[] = $taskName;
        }
    }

    public function hasAiTaskPending(): bool
    {
        return $this->aiQueue !== [];
    }

    public function isAiComplete(): bool
    {
        return $this->aiQueue === [] && $this->aiCompleted !== [];
    }

    // ── Result accessors ──────────────────────────────────────────────────────

    public function getAiResult(string $taskName): ?array
    {
        foreach ($this->aiCompleted as $entry) {
            if (($entry['task'] ?? null) === $taskName) {
                return $entry['result'] ?? null;
            }
        }
        return null;
    }

    public function getOcrText(): ?string
    {
        return $this->getAiResult('ocr')['text']
            ?? $this->getAiResult('ocr_mistral')['text']
            ?? $this->getAiResult('transcribe_handwriting')['text']
            ?? $this->getAiResult('transcribe_handwriting')['transcription']
            ?? null;
    }

    public function getAiDescription(): ?string
    {
        return $this->getAiResult('enrich_from_thumbnail')['description']
            ?? $this->getAiResult('basic_description')['description']
            ?? $this->getAiResult('context_description')['description']
            ?? null;
    }

    public function getAiTitle(): ?string
    {
        return $this->getAiResult('enrich_from_thumbnail')['title']
            ?? $this->getAiResult('generate_title')['title']
            ?? null;
    }

    public function getAiKeywords(): array
    {
        return $this->getAiResult('enrich_from_thumbnail')['keywords_high']
            ?? $this->getAiResult('keywords')['keywords']
            ?? [];
    }
}
