<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Repository;

use Doctrine\ORM\EntityRepository;
use Survos\MediaBundle\Contract\MediaSyncKeys;
use Survos\MediaBundle\Entity\BaseMedia;

final class MediaRepository extends EntityRepository
{

    public function findByCode(string $code): ?BaseMedia
    {
        return $this->findOneBy(['code' => $code]);
    }

    /**
     * @return BaseMedia[]
     */
    public function findByCodes(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        return $this->createQueryBuilder('m')
            ->andWhere('m.code IN (:codes)')
            ->setParameter('codes', $codes)
            ->getQuery()
            ->getResult();
    }

    public function iterateOriginalUrlsByStatus(?string $status = null, ?int $limit = null): iterable
    {
        $qb = $this->createQueryBuilder('m')
            ->select('m.externalUrl');

        if ($status !== null) {
            $qb->andWhere('m.status = :status')
               ->setParameter('status', $status);
        }

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        foreach ($qb->getQuery()->toIterable() as $row) {
            yield $row['externalUrl'];
        }
    }

    /**
     * Count URLs for progress bar display before iterating.
     */
    public function countUrlsWithContext(?string $status = null, ?int $limit = null, ?string $dataset = null): int
    {
        $qb = $this->createQueryBuilder('m')
            ->select('COUNT(m.externalUrl)');

        if ($status !== null) {
            $qb->andWhere('m.status = :status')
               ->setParameter('status', $status);
        }

        if ($dataset !== null) {
            $qb->andWhere('m.dataset = :dataset')
               ->setParameter('dataset', $dataset);
        }

        $count = (int) $qb->getQuery()->getSingleScalarResult();

        return $limit !== null ? min($count, $limit) : $count;
    }

    /**
     * Iterate [url => rawData] pairs for building context maps on dispatch.
     * @return iterable<string, array>
     */
    public function iterateUrlsWithContext(?string $status = null, ?int $limit = null, ?string $dataset = null): iterable
    {
        $qb = $this->createQueryBuilder('m')
            ->select('m.externalUrl', 'm.rawData', 'm.aiQueue');

        if ($status !== null) {
            $qb->andWhere('m.status = :status')
               ->setParameter('status', $status);
        }

        if ($dataset !== null) {
            $qb->andWhere('m.dataset = :dataset')
               ->setParameter('dataset', $dataset);
        }

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        foreach ($qb->getQuery()->toIterable() as $row) {
            if ($row['externalUrl']) {
                $ctx = $row['rawData'] ?? [];
                // The seeded AI directive (aiQueue) is a separate column, NOT part of rawData — fold it
                // into the dispatch context so mediary's AssetRegistry::ensureAsset sets asset.aiQueue
                // (the observe/ocr task). Without this the asset lands with an empty aiQueue and the AI
                // pipeline never fires.
                if (!empty($row['aiQueue'])) {
                    $ctx[MediaSyncKeys::AI_QUEUE] = array_values((array) $row['aiQueue']);
                }
                yield $row['externalUrl'] => $ctx;
            }
        }
    }

    // upsertFromBatchResult() lived here and was the second write path for
    // mediary state — a blind overwrite that clobbered status regardless of
    // ordering and asserted a local row exists (mediary broadcasts; often it
    // does not). MediaUpdateApplier replaces it for both callers, sync and
    // async, so push and pull cannot drift.
}
