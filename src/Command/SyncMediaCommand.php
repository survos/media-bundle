<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Survos\MediaBundle\Entity\BaseMedia;
use Survos\MediaBundle\Repository\MediaRepository;
use Survos\MediaBundle\Service\MediaBatchDispatcher;
use Survos\MediaBundle\Service\MediaRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

use function basename;
use function getcwd;

#[AsCommand('media:sync', 'Sync local BaseMedia rows (status=new) to mediary server')]
final class SyncMediaCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MediaBatchDispatcher   $dispatcher,
        private readonly MediaRegistry          $mediaRegistry,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,

        #[Option('Single URL to sync (debug)')]
        ?string $url = null,

        #[Option('Batch size')]
        int $batchSize = 100,

        #[Option('Sync all media regardless of status')]
        bool $all = false,

        #[Option('Limit total number of media to sync')]
        ?int $limit = null,

        #[Option('Process download synchronously (skip async queue) — useful for testing')]
        bool $sync = false,
    ): int {
        /** @var MediaRepository $repo */
        $repo   = $this->entityManager->getRepository(BaseMedia::class);
        $client = basename((string) getcwd());
        $io->note(sprintf('Client: %s', $client));

        if ($url !== null) {
            $io->info('Syncing single URL');
            $this->mediaRegistry->ensureMedia($url, flush: true);
            $extra = $sync ? ['sync' => true] : [];
            $result = $this->dispatcher->dispatch($client, [$url], $extra);
            $repo->upsertFromBatchResult($result);
            $this->entityManager->flush();
            $io->success('URL synced');
            return Command::SUCCESS;
        }

        $statusFilter = $all ? null : 'new';
        $batch        = [];   // [url => sourceMetaArray]
        $total        = 0;

        foreach ($repo->iterateUrlsWithContext($statusFilter, $limit) as $url => $rawData) {
            $batch[$url] = $rawData;
            if (count($batch) >= $batchSize) {
                $total = $this->dispatchBatch($client, $batch, $repo, $total, $io, $sync);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $total = $this->dispatchBatch($client, $batch, $repo, $total, $io, $sync);
        }

        $io->success(sprintf('Synced %d media URLs', $total));
        return Command::SUCCESS;
    }

    /** @param array<string, array> $batch  url => sourceMetaArray */
    private function dispatchBatch(string $client, array $batch, MediaRepository $repo, int $total, SymfonyStyle $io, bool $sync = false): int
    {
        $urls       = array_keys($batch);
        $contextMap = array_filter($batch, static fn($ctx) => $ctx !== []);

        if ($io->isVerbose()) {
            foreach ($urls as $url) {
                $io->writeln(sprintf('  → %s', $url));
            }
        }

        $extra = $contextMap !== [] ? ['context' => $contextMap] : [];
        if ($sync) {
            $extra['sync'] = true;
        }
        $result = $this->dispatcher->dispatch($client, $urls, $extra);
        $repo->upsertFromBatchResult($result);
        $this->entityManager->flush();
        return $total + count($urls);
    }
}
