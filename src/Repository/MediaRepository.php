<?php

namespace Survos\MediaBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\MediaBundle\Entity\BaseMedia;

/**
 * @extends ServiceEntityRepository<BaseMedia>
 */
class MediaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BaseMedia::class);
    }

    public function findByCode(string $code): ?BaseMedia
    {
        return $this->findOneBy(['code' => $code]);
    }

    public function findByCodes(array $codes): array
    {
        return $this->findBy(['code' => $codes]);
    }

    public function findByProvider(string $provider): array
    {
        return $this->findBy(['provider' => $provider]);
    }
}
