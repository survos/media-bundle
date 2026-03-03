<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Storage;

use Doctrine\ORM\EntityManagerInterface;
use Survos\AiPipelineBundle\Storage\ResultStoreInterface;

/**
 * Wraps any Doctrine entity that uses HasAiVisionTrait as a ResultStoreInterface.
 *
 * Strips bulk fields (raw_response, image_base64 on image_blocks) before
 * persisting to the database — those blobs are only useful for file-based stores
 * and would blow Postgres JSON column sizes and PHP memory limits.
 *
 * Usage:
 *   $store = new DoctrineResultStore($image, $em, fn() => $image->s3Url);
 *   while ($queue !== []) { $runner->runNext($store, $queue); }
 *   $em->flush();  // caller flushes
 */
final class DoctrineResultStore implements ResultStoreInterface
{
    /** @var \Closure(): ?string */
    private readonly \Closure $imageUrlResolver;

    /**
     * @param object   $entity           Any entity using HasAiVisionTrait.
     * @param \Closure $imageUrlResolver Returns the image URL / subject string.
     */
    public function __construct(
        private readonly object $entity,
        private readonly EntityManagerInterface $em,
        \Closure $imageUrlResolver,
    ) {
        $this->imageUrlResolver = $imageUrlResolver;
    }

    public function getSubject(): ?string
    {
        return ($this->imageUrlResolver)();
    }

    public function getInputs(): array
    {
        $url = $this->getSubject();
        return $url !== null ? ['image_url' => $url] : [];
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
            'result' => self::stripBulkFields($result),
        ];

        // Denormalize classify result into typed column for fast SQL filtering.
        if ($taskName === 'classify'
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

    /**
     * Strip fields that are only useful for file-based stores (raw Mistral response,
     * base64 image crops) to keep the DB column small.
     *
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private static function stripBulkFields(array $result): array
    {
        // Drop the full raw API response — far too large for a DB column.
        unset($result['raw_response']);

        // Drop base64 image crops from OCR image_blocks — keep coords and id.
        if (isset($result['image_blocks']) && is_array($result['image_blocks'])) {
            $result['image_blocks'] = array_map(static function (array $block): array {
                unset($block['image_base64']);
                return $block;
            }, $result['image_blocks']);
        }

        // Drop per-page markdown from OCR pages — the full text is in result['text'].
        if (isset($result['pages']) && is_array($result['pages'])) {
            $result['pages'] = array_map(static function (array $page): array {
                unset($page['markdown']);
                return $page;
            }, $result['pages']);
        }

        return $result;
    }
}
