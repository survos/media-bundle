<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\MediaBundle\Entity\BaseMedia;

final class MediaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BaseMedia::class);
    }

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
}
