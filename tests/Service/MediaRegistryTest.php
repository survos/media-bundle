<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;
use Survos\MediaBundle\Entity\BaseMedia;
use Survos\MediaBundle\Service\MediaRegistry;

final class MediaRegistryTest extends TestCase
{
    public function testEnsureMediaWithUrlDoesNotFail(): void
    {
        $repository = new class implements ObjectRepository {
            public function find($id) { return null; }
            public function findAll() { return []; }
            public function findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null) { return []; }
            public function findOneBy(array $criteria) { return null; }
            public function getClassName() { return BaseMedia::class; }
        };

        $em = new class($repository) implements EntityManagerInterface {
            public function __construct(private ObjectRepository $repository) {}
            public function getRepository($className) { return $this->repository; }
            public function persist($object) {}
            public function flush() {}

            // --- Unused methods ---
            public function find($className, $id) {}
            public function remove($object) {}
            public function merge($object) {}
            public function clear($objectName = null) {}
            public function detach($object) {}
            public function refresh($object) {}
            public function getClassMetadata($className) {}
            public function getMetadataFactory() {}
            public function initializeObject($obj) {}
            public function contains($object) {}
            public function getConnection() {}
            public function getExpressionBuilder() {}
            public function beginTransaction() {}
            public function transactional($func) {}
            public function commit() {}
            public function rollback() {}
            public function createQuery($dql = '') {}
            public function createNamedQuery($name) {}
            public function createNativeQuery($sql, $rsm) {}
            public function createNamedNativeQuery($name) {}
            public function createQueryBuilder() {}
            public function getReference($entityName, $id) {}
            public function getPartialReference($entityName, $identifier) {}
            public function close() {}
            public function copy($entity, $deep = false) {}
            public function lock($entity, $lockMode, $lockVersion = null) {}
            public function getEventManager() {}
            public function getConfiguration() {}
            public function isOpen() {}
            public function getUnitOfWork() {}
            public function newHydrator($hydrationMode) {}
            public function getHydrator($hydrationMode) {}
            public function getProxyFactory() {}
            public function getFilters() {}
            public function isFiltersStateClean() {}
            public function hasFilters() {}
        };

        $registry = new MediaRegistry($em);

        $media = $registry->ensureMedia('https://example.com/image.jpg');

        self::assertInstanceOf(BaseMedia::class, $media);
        self::assertSame(
            MediaRegistry::idFromUrl('https://example.com/image.jpg'),
            $media->id
        );
    }
}
