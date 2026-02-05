<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Survos\MediaBundle\Entity\BaseMedia;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('media:stats', 'Report local media status counts')]
final class MediaStatsCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $repo = $this->entityManager->getRepository(BaseMedia::class);

        $total = (int) $repo->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $statusRows = $repo->createQueryBuilder('m')
            ->select('m.status AS status', 'COUNT(m.id) AS count')
            ->groupBy('m.status')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getArrayResult();

        $conn = $this->entityManager->getConnection();
        $typeRows = $conn->fetchAllAssociative(
            'SELECT type, COUNT(id) AS count FROM media GROUP BY type ORDER BY count DESC'
        );

        $withS3 = (int) $repo->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.s3Url IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();

        $missingS3 = (int) $repo->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.s3Url IS NULL')
            ->getQuery()
            ->getSingleScalarResult();

        $missingExternal = (int) $repo->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.externalUrl IS NULL')
            ->getQuery()
            ->getSingleScalarResult();

        $io->title('Media stats (local DB)');
        $io->writeln(sprintf('Total: %d', $total));
        $io->writeln(sprintf('With s3Url: %d', $withS3));
        $io->writeln(sprintf('Missing s3Url: %d', $missingS3));
        if ($missingExternal > 0) {
            $io->writeln(sprintf('Missing externalUrl: %d', $missingExternal));
        }

        if ($statusRows !== []) {
            $io->section('By status');
            $io->table(['Status', 'Count'], array_map(
                static fn(array $row): array => [$row['status'], (int) $row['count']],
                $statusRows
            ));
        }

        if ($typeRows !== []) {
            $io->section('By type');
            $io->table(['Type', 'Count'], array_map(
                static fn(array $row): array => [$row['type'], (int) $row['count']],
                $typeRows
            ));
        }

        return Command::SUCCESS;
    }
}
