<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Repository;

use Doctrine\ORM\EntityRepository;
use Survos\MediaBundle\Dto\BatchDispatchResult;
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

    public function upsertFromBatchResult(BatchDispatchResult $result): void
    {
        foreach ($result->media as $registration) {
            $media = $this->find($registration->mediaKey);
            assert($media, "Missing $registration->mediaKey");
            if (!$media) {
                continue;
            }
            // ugh, this is what Mapper is for!!
            $media->status = $registration->status;
            $media->smallUrl = $registration->smallUrl;
            $media->s3Url = $registration->s3Url;
            $media->storageKey = $registration->storageKey;
        }
        $this->getEntityManager()->flush();
    }
}
