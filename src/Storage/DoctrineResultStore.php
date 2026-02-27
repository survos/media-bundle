<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Storage;

use Survos\AiVisionBundle\AiVisionTask;
use Survos\AiVisionBundle\Storage\ResultStoreInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Wraps any Doctrine entity that uses HasAiVisionTrait as a ResultStoreInterface.
 *
 * Usage:
 *
 *   use Survos\MediaBundle\Storage\DoctrineResultStore;
 *   use Survos\AiVisionBundle\Task\AiVisionTaskRunner;
 *   use Survos\AiVisionBundle\AiVisionTask;
 *
 *   $store = new DoctrineResultStore($scanRecord, $em, fn() => $scanRecord->imageUrl);
 *   $queue = AiVisionTaskRunner::buildQueue(AiVisionTask::quickScanPipeline());
 *   $runner->runAll($store, $queue);
 *   $em->flush();  // caller flushes — the store does not
 */
final class DoctrineResultStore implements ResultStoreInterface
{
    /** @var \Closure(): ?string */
    private readonly \Closure $imageUrlResolver;

    /**
     * @param object   $entity           Any entity using HasAiVisionTrait.
     * @param \Closure $imageUrlResolver Returns the image URL from the entity.
     */
    public function __construct(
        private readonly object $entity,
        private readonly EntityManagerInterface $em,
        \Closure $imageUrlResolver,
    ) {
        $this->imageUrlResolver = $imageUrlResolver;
    }

    public function getImageUrl(): ?string
    {
        return ($this->imageUrlResolver)();
    }

    public function getPrior(string $taskName): ?array
    {
        foreach ($this->entity->aiCompleted as $entry) {
            if (($entry['task'] ?? null) === $taskName) {
                return $entry['result'] ?? null;
            }
        }
        return null;
    }

    public function getAllPrior(): array
    {
        $out = [];
        foreach ($this->entity->aiCompleted as $entry) {
            if (isset($entry['task'], $entry['result'])) {
                $out[$entry['task']] = $entry['result'];
            }
        }
        return $out;
    }

    public function saveResult(string $taskName, array $result): void
    {
        $this->entity->aiCompleted[] = [
            'task'   => $taskName,
            'at'     => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'result' => $result,
        ];

        // Denormalize classify result into typed column for SQL filtering.
        if ($taskName === AiVisionTask::CLASSIFY->value
            && empty($result['failed']) && empty($result['skipped'])
        ) {
            $this->entity->aiDocumentType = $result['type'] ?? null;
        }

        // Note: caller is responsible for flushing the EntityManager.
    }

    public function isLocked(): bool
    {
        return $this->entity->aiLocked;
    }
}
