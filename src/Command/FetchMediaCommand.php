<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Command;

use Survos\MediaBundle\Service\MediaManager;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('media:fetch', 'Fetch media metadata from external providers')]
final class FetchMediaCommand
{
    public function __construct(
        private readonly MediaManager $mediaManager,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Provider name (youtube, flickr, etc.)')] string $provider,
        #[Option('Channel ID for YouTube')] ?string $channelId = null,
        #[Option('User ID for Flickr')] ?string $userId = null,
        #[Option('Force cache refresh')] bool $forceRefresh = false,
        #[Option('Show what would be synced without saving')] bool $dryRun = false,
    ): int {
        if (!$providerObj = $this->mediaManager->getProvider($provider)) {
            $io->error("Provider '{$provider}' not found");
            $io->note('Available providers: ' . implode(', ', array_keys($this->mediaManager->getProviders())));
            return Command::FAILURE;
        }

        $options = array_filter([
            'channelId' => $channelId,
            'userId'    => $userId,
        ]);

        $io->info("Fetching media from {$provider}...");

        if ($dryRun) {
            $io->note('DRY RUN - No changes will be made');
        }

        try {
            if ($dryRun) {
                $count = 0;
                foreach ($providerObj->fetchAll($options) as $media) {
                    $io->writeln("Would fetch: {$media->code} - {$media->getTitle()}");
                    if (++$count >= 10) {
                        $io->note('Showing first 10 results...');
                        break;
                    }
                }
            } else {
                $synced = $this->mediaManager->syncProvider($provider, $options);
                $io->success("Fetched " . count($synced) . " media items from {$provider}");
            }
        } catch (\Exception $e) {
            $io->error("Fetch failed: " . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
