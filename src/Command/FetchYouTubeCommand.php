<?php

namespace Survos\MediaBundle\Command;

use Survos\MediaBundle\Service\MediaManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'fetch:youtube',
    description: 'Fetch videos from YouTube'
)]
class FetchYouTubeCommand extends Command
{
    public function __construct(
        private readonly MediaManager $mediaManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('channel-id', 'c', InputOption::VALUE_REQUIRED, 'YouTube Channel ID')
            ->addOption('force-refresh', 'f', InputOption::VALUE_NONE, 'Force cache refresh');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $channelId = $input->getOption('channel-id');
        if (!$channelId) {
            $io->error('Channel ID is required. Use --channel-id=CHANNEL_ID');
            return Command::FAILURE;
        }

        $options = ['channelId' => $channelId];

        $io->info("Fetching videos from YouTube channel: {$channelId}");

        try {
            $synced = $this->mediaManager->syncProvider('youtube', $options);
            $io->success("Successfully fetched " . count($synced) . " videos from YouTube");
            
            $io->table(
                ['Code', 'Title', 'Published'],
                array_map(fn($video) => [
                    $video->code,
                    substr($video->getTitle() ?? 'Untitled', 0, 50) . '...',
                    $video->publishedAt?->format('Y-m-d H:i')
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
