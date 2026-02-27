<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Trait;

use Survos\AiVisionBundle\AiVisionTask;

/**
 * Doctrine-flavoured storage columns for AI vision enrichment.
 *
 * Add to any entity that implements AiVisionInterface and wants to store
 * task results in its own database row.
 *
 * Required column mappings (add to your entity):
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
 *
 * Then wrap in DoctrineResultStore to hand to AiVisionTaskRunner:
 *
 *   $store = new DoctrineResultStore($entity, $entityManager, fn() => $entity->imageUrl);
 *   $runner->runAll($store, AiVisionTaskRunner::buildQueue(AiVisionTask::quickScanPipeline()));
 *   $entityManager->flush();
 */
trait HasAiVisionTrait
{
    public array $aiQueue = [];
    public array $aiCompleted = [];
    public bool $aiLocked = false;
    public ?string $aiDocumentType = null;

    // ── Queue helpers ─────────────────────────────────────────────────────────

    public function enqueueAiTasks(AiVisionTask ...$tasks): void
    {
        foreach ($tasks as $task) {
            if (!in_array($task->value, $this->aiQueue, true)) {
                $this->aiQueue[] = $task->value;
            }
        }
    }

    public function enqueueAiPipeline(string $pipeline = 'quick'): void
    {
        $tasks = match ($pipeline) {
            'full'  => AiVisionTask::fullEnrichmentPipeline(),
            default => AiVisionTask::quickScanPipeline(),
        };
        $this->enqueueAiTasks(...$tasks);
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

    public function getAiResult(AiVisionTask $task): ?array
    {
        foreach ($this->aiCompleted as $entry) {
            if (($entry['task'] ?? null) === $task->value) {
                return $entry['result'] ?? null;
            }
        }
        return null;
    }

    public function getOcrText(): ?string
    {
        return $this->getAiResult(AiVisionTask::OCR_MISTRAL)['text']
            ?? $this->getAiResult(AiVisionTask::OCR)['text']
            ?? null;
    }

    public function getAiDescription(): ?string
    {
        return $this->getAiResult(AiVisionTask::CONTEXT_DESCRIPTION)['description']
            ?? $this->getAiResult(AiVisionTask::BASIC_DESCRIPTION)['description']
            ?? null;
    }

    public function getAiTitle(): ?string
    {
        return $this->getAiResult(AiVisionTask::GENERATE_TITLE)['title'] ?? null;
    }

    public function getAiKeywords(): array
    {
        return $this->getAiResult(AiVisionTask::KEYWORDS)['keywords'] ?? [];
    }
}
