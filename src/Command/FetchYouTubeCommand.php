<?php

namespace Survos\MediaBundle\Command;

use Survos\MediaBundle\Service\MediaManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('fetch:youtube', 'Fetch videos from YouTube')]
final class FetchYouTubeCommand
{
    public function __construct(
        private readonly MediaManager $mediaManager,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('YouTube Channel ID')] ?string $channelId = null,
        #[Option('Force cache refresh')] bool $forceRefresh = false,
    ): int {
        if (!$channelId) {
            $io->error('Channel ID is required. Use --channel-id=CHANNEL_ID');
            return Command::FAILURE;
        }

        $io->info("Fetching videos from YouTube channel: {$channelId}");

        try {
            $synced = $this->mediaManager->syncProvider('youtube', ['channelId' => $channelId]);
            $io->success("Successfully fetched " . count($synced) . " videos from YouTube");

            $io->table(
                ['Code', 'Title', 'Published'],
                array_map(fn($video) => [
                    $video->code,
                    substr($video->getTitle() ?? 'Untitled', 0, 50) . '...',
                    $video->publishedAt?->format('Y-m-d H:i'),
                ], array_slice($synced, 0, 10))
            );

            if (count($synced) > 10) {
                $io->note('Showing first 10 results. Total: ' . count($synced));
            }
        } catch (\Exception $e) {
            $io->error("Failed to fetch YouTube videos: " . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
