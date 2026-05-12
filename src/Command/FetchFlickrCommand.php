<?php

namespace Survos\MediaBundle\Command;

use Survos\MediaBundle\Service\MediaManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('fetch:flickr', 'Fetch photos from Flickr')]
final class FetchFlickrCommand
{
    public function __construct(
        private readonly MediaManager $mediaManager,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Flickr User ID')] ?string $userId = null,
        #[Option('Force cache refresh')] bool $forceRefresh = false,
    ): int {
        if (!$userId) {
            $io->error('User ID is required. Use --user-id=USER_ID');
            return Command::FAILURE;
        }

        $io->info("Fetching photos from Flickr user: {$userId}");

        try {
            $synced = $this->mediaManager->syncProvider('flickr', ['userId' => $userId]);
            $io->success("Successfully fetched " . count($synced) . " photos from Flickr");

            $io->table(
                ['Code', 'Title', 'Published'],
                array_map(fn($photo) => [
                    $photo->code,
                    substr($photo->getTitle() ?? 'Untitled', 0, 50) . '...',
                    $photo->publishedAt?->format('Y-m-d H:i'),
                ], array_slice($synced, 0, 10))
            );

            if (count($synced) > 10) {
                $io->note('Showing first 10 results. Total: ' . count($synced));
            }
        } catch (\Exception $e) {
            $io->error("Failed to fetch Flickr photos: " . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
