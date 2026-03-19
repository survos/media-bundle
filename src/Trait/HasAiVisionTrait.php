<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Trait;

/**
 * Doctrine-flavoured storage columns for AI pipeline enrichment.
 *
 * Add to any entity that wants to store ai-pipeline-bundle task results
 * in its own database row.  Wrap in DoctrineResultStore to pass to
 * AiPipelineRunner:
 *
 *   $store = new DoctrineResultStore($entity, $em, fn() => $thumbnailUrl);
 *   $runner->runAll($store, ['enrich_from_thumbnail']);
 *   $em->flush();
 *
 * Required ORM columns (add to your entity):
 *
 *   #[ORM\Column(type: Types::JSON)]
 *   public array $aiQueue = [];
 *
 *   #[ORM\Column(type: Types::JSON)]
 *   public array $aiCompleted = [];
 *
 *   #[ORM\Column]
 *   public bool $aiLocked = false;
 *
 *   #[ORM\Column(nullable: true)]
 *   public ?string $aiDocumentType = null;
 */
trait HasAiVisionTrait
{
    public array $aiQueue = [];
    public array $aiCompleted = [];
    public bool $aiLocked = false;
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
