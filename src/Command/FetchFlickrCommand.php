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
    name: 'fetch:flickr',
    description: 'Fetch photos from Flickr'
)]
class FetchFlickrCommand extends Command
{
    public function __construct(
        private readonly MediaManager $mediaManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user-id', 'u', InputOption::VALUE_REQUIRED, 'Flickr User ID')
            ->addOption('force-refresh', 'f', InputOption::VALUE_NONE, 'Force cache refresh');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $userId = $input->getOption('user-id');
        if (!$userId) {
            $io->error('User ID is required. Use --user-id=USER_ID');
            return Command::FAILURE;
        }

        $options = ['userId' => $userId];

        $io->info("Fetching photos from Flickr user: {$userId}");

        try {
            $synced = $this->mediaManager->syncProvider('flickr', $options);
            $io->success("Successfully fetched " . count($synced) . " photos from Flickr");
            
            $io->table(
                ['Code', 'Title', 'Published'],
                array_map(fn($photo) => [
                    $photo->code,
                    substr($photo->getTitle() ?? 'Untitled', 0, 50) . '...',
                    $photo->publishedAt?->format('Y-m-d H:i')
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
