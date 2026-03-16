<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Survos\MediaBundle\Entity\BaseMedia;
use Survos\MediaBundle\Message\DispatchBatchMessage;
use Survos\MediaBundle\Repository\MediaRepository;
use Survos\MediaBundle\Service\MediaBatchDispatcher;
use Survos\MediaBundle\Service\MediaRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

use function basename;
use function getcwd;

#[AsCommand('media:sync', 'Sync local BaseMedia rows (status=new) to mediary server')]
final class SyncMediaCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MediaBatchDispatcher   $dispatcher,
        private readonly MediaRegistry          $mediaRegistry,
        private readonly ?MessageBusInterface   $bus = null,
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

        #[Option('Upload only — fire-and-forget, skip reading status back from mediary. Much faster for large initial imports.')]
        bool $uploadOnly = false,

        #[Option('Dispatch each batch as an async Messenger message. Prevents timeouts on large datasets. Requires a worker.')]
        bool $async = false,
    ): int {
        /** @var MediaRepository $repo */
        $repo   = $this->entityManager->getRepository(BaseMedia::class);
        $client = basename((string) getcwd());
        $io->note(sprintf('Client: %s', $client));

        if ($async && $this->bus === null) {
            $io->error('--async requires a Messenger bus.');
            return Command::FAILURE;
        }

        // Single-URL debug mode
        if ($url !== null) {
            $this->mediaRegistry->ensureMedia($url, flush: true);
            $extra  = $sync ? ['sync' => true] : [];
            $result = $this->dispatcher->dispatch($client, [$url], $extra);
            if (!$uploadOnly) {
                $repo->upsertFromBatchResult($result);
                $this->entityManager->flush();
            }
            $io->success('URL dispatched' . ($uploadOnly ? ' (upload-only)' : ' and synced'));
            return Command::SUCCESS;
        }

        $statusFilter = $all ? null : 'new';
        $totalCount   = $repo->countUrlsWithContext($statusFilter, $limit);

        $io->note(sprintf('Media to sync: %d (upload-only: %s, async: %s)',
            $totalCount,
            $uploadOnly ? 'yes' : 'no',
            $async ? 'yes — run: bin/console messenger:consume media' : 'no'
        ));

        if ($totalCount === 0) {
            $io->success('Nothing to sync.');
            return Command::SUCCESS;
        }

        $progress = $io->createProgressBar($totalCount);
        $progress->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s% — %message%');
        $progress->setMessage('starting...');
        $progress->start();

        $batch = [];
        $total = 0;

        foreach ($repo->iterateUrlsWithContext($statusFilter, $limit) as $batchUrl => $rawData) {
            $batch[$batchUrl] = $rawData;
            if (count($batch) >= $batchSize) {
                $total = $this->flushBatch($client, $batch, $repo, $total, $io, $sync, $uploadOnly, $async, $progress);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $total = $this->flushBatch($client, $batch, $repo, $total, $io, $sync, $uploadOnly, $async, $progress);
        }

        $progress->finish();
        $io->newLine(2);
        $io->success(sprintf('Dispatched %d media URLs%s',
            $total,
            $async ? ' as async messages' : ($uploadOnly ? ' (upload-only)' : '')
        ));
        return Command::SUCCESS;
    }

    /** @param array<string, array> $batch url => rawData */
    private function flushBatch(
        string $client,
        array $batch,
        MediaRepository $repo,
        int $total,
        SymfonyStyle $io,
        bool $sync,
        bool $uploadOnly,
        bool $async,
        mixed $progress,
    ): int {
        $urls       = array_keys($batch);
        $contextMap = array_filter($batch, static fn($ctx) => $ctx !== []);

        if ($io->isVeryVerbose()) {
            foreach ($urls as $u) {
                $io->writeln(sprintf('  → %s', $u));
            }
        }

        if ($async && $this->bus !== null) {
            $this->bus->dispatch(new DispatchBatchMessage(
                client:     $client,
                urls:       $urls,
                contextMap: $contextMap,
                uploadOnly: $uploadOnly,
            ));
        } else {
            try {
                $extra = $contextMap !== [] ? ['context' => $contextMap] : [];
                if ($sync) {
                    $extra['sync'] = true;
                }
                $result = $this->dispatcher->dispatch($client, $urls, $extra);
                if (!$uploadOnly) {
                    $repo->upsertFromBatchResult($result);
                    $this->entityManager->flush();
                }
            } catch (\Symfony\Component\HttpClient\Exception\TransportException $e) {
                // Timeout — log and continue, URLs remain status=new for next run
                if ($io->isVerbose()) {
                    $io->writeln(sprintf(
                        '  <comment>timeout on %d URLs — skipping batch (retry on next sync)</comment>',
                        count($urls)
                    ));
                }
            }
        }

        $done = $total + count($urls);
        $progress->setMessage(sprintf('%d dispatched', $done));
        $progress->advance(count($urls));

        return $done;
    }
}
