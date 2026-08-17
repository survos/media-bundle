<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Survos\MediaBundle\Entity\BaseMedia;
use Survos\MediaBundle\Message\DispatchBatchMessage;
use Survos\MediaBundle\Repository\MediaRepository;
use Survos\MediaBundle\Service\MediaBatchDispatcher;
use Survos\MediaBundle\Service\MediaRegistry;
use Survos\MediaBundle\Service\MediaUpdateApplier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function basename;
use function getcwd;

#[AsCommand('media:sync', 'Sync local BaseMedia rows (status=new) to mediary server')]
final class SyncMediaCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MediaBatchDispatcher   $dispatcher,
        private readonly MediaRegistry          $mediaRegistry,
        private readonly HttpClientInterface    $httpClient,
        /**
         * The same write path the async callback uses. Running it here — in a
         * command, synchronously — is the whole point: whatever media:sync does
         * to a row is exactly what MediaRemoteEventConsumer will do to it off
         * the queue, so a bug is reproducible in the foreground.
         */
        private readonly MediaUpdateApplier     $applier,
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

        #[Option('HEAD-check every source URL before dispatch and dump+stop on the first non-200 (debug aid, tracing a bad-image report back to its record). Off by default — each check is a live round trip (~1-2s), so a full run would take hours.')]
        bool $checkUrls = false,

        #[Option('Restrict to media from this dataset key (e.g. mus/saveoursigns). Omit to sync every dataset\'s status=new media.')]
        ?string $dataset = null,
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
                $stats = $this->applier->applyBatch($result->rows);
                if ($io->isVerbose()) {
                    $io->writeln(sprintf('  applied %d, changed %d, skipped %d',
                        $stats['applied'], $stats['changed'], $stats['skipped']));
                }
            }
            $io->success('URL dispatched' . ($uploadOnly ? ' (upload-only)' : ' and synced'));
            return Command::SUCCESS;
        }

        $statusFilter = $all ? null : 'new';
        $totalCount   = $repo->countUrlsWithContext($statusFilter, $limit, $dataset);

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

        foreach ($repo->iterateUrlsWithContext($statusFilter, $limit, $dataset) as $batchUrl => $rawData) {
            $batch[$batchUrl] = $rawData;
            if (count($batch) >= $batchSize) {
                $total = $this->flushBatch($client, $batch, $repo, $total, $io, $sync, $uploadOnly, $async, $progress, $checkUrls);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $total = $this->flushBatch($client, $batch, $repo, $total, $io, $sync, $uploadOnly, $async, $progress, $checkUrls);
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
        bool $checkUrls = false,
    ): int {
        $urls       = array_keys($batch);
        $contextMap = array_filter($batch, static fn($ctx) => $ctx !== []);

        if ($io->isVeryVerbose()) {
            foreach ($urls as $u) {
                $io->writeln(sprintf('  → %s', $u));
            }
        }

        // --check-urls debug aid: mediary reports non-200 source images with no clue which record
        // they came from. Check locally, right before dispatch, where we still have the record's
        // own metadata (rawData) — dump it and stop cold instead of feeding mediary a URL it can't
        // fetch and getting an opaque failure three hops away. Opt-in and off by default: each
        // check is a live round trip (~1-2s against a Lambda-backed image handler), so checking
        // every URL on every run would take hours at real dataset sizes.
        if ($checkUrls) {
            foreach ($batch as $checkUrl => $rawData) {
                try {
                    $head   = $this->httpClient->request('HEAD', $checkUrl, ['timeout' => 10]);
                    $status = $head->getStatusCode();
                } catch (\Throwable $e) {
                    $status = $e->getMessage();
                }
                if ($status !== 200) {
                    $io->error(sprintf('Source image did not return 200 (got %s): %s', $status, $checkUrl));
                    dump($rawData);
                    dump(['client' => $client, 'context' => $contextMap[$checkUrl] ?? null]);
                    exit(1);
                }
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
                // media:sync publishes media and source context to mediary.
                // Claims are projected separately so source, AI, OCR, and human
                // metadata keep provenance instead of becoming opaque blobs.
                $result = $this->dispatcher->dispatch($client, $urls, $extra);
                if (!$uploadOnly) {
                    // Raw rows, not the parsed MediaRegistration[] — the batch
                    // response is converging on the webhook payload, and the
                    // applier is the side that knows how to read both.
                    $this->applier->applyBatch($result->rows);
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
