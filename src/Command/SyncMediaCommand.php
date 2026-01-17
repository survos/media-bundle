<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Command;

use App\Service\AssetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Survos\MediaBundle\Entity\BaseMedia;
use Survos\MediaBundle\Service\MediaBatchDispatcher;
use Survos\MediaBundle\Repository\MediaRepository;
use Survos\MediaBundle\Service\MediaRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

use function basename;
use function getcwd;

#[AsCommand('media:sync', 'Sync local media with memoria server')]
final class SyncMediaCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MediaBatchDispatcher   $dispatcher,
        private MediaRegistry $mediaRegistry,
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
    ): int {
        /** @var MediaRepository $repo */
        $repo = $this->entityManager->getRepository(BaseMedia::class);

        $client = basename((string) getcwd());
        $io->note(sprintf('Client: %s', $client));

        if ($url !== null) {
            $io->info('Syncing single URL');
            // persist it so we can save the response.
            $this->mediaRegistry->ensureMedia($url, flush: true);
            $result = $this->dispatcher->dispatch($client, [$url]);
            $repo->upsertFromBatchResult($result);
            $this->entityManager->flush();

            $io->success('URL synced');
            return Command::SUCCESS;
        }

        $io->info('Syncing all local media');

        $statusFilter = $all ? null : 'new';
        $urls = $repo->iterateOriginalUrlsByStatus($statusFilter, $limit);
        $batch = [];
        $total = 0;

        foreach ($urls as $originalUrl) {
            $batch[] = $originalUrl;
            if (\count($batch) >= $batchSize) {
                $total = $this->dispatch($client, $batch, $repo, $total);
                $batch = [];
            }
        }

        // if there's any left
        if ($batch !== []) {
            $total = $this->dispatch($client, $batch, $repo, $total);
        }

        $io->success(sprintf('Synced %d media URLs', $total));
        return Command::SUCCESS;
    }

    private function dispatch(string $client, array $batch, MediaRepository $repo, int $total): int
    {
        $result = $this->dispatcher->dispatch($client, $batch);
        $repo->upsertFromBatchResult($result);
        $this->entityManager->flush();
        $total += \count($batch);
        return $total;

    }
}
