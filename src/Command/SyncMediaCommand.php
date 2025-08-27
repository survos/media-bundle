<?php

namespace Survos\MediaBundle\Command;

use Survos\MediaBundle\Service\MediaManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'media:sync',
    description: 'Sync media from external providers'
)]
class SyncMediaCommand extends Command
{
    public function __construct(
        private readonly MediaManager $mediaManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('provider', InputArgument::REQUIRED, 'Provider name (youtube, flickr, etc.)')
            ->addOption('channel-id', 'c', InputOption::VALUE_REQUIRED, 'Channel ID for YouTube')
            ->addOption('user-id', 'u', InputOption::VALUE_REQUIRED, 'User ID for Flickr')
            ->addOption('force-refresh', 'f', InputOption::VALUE_NONE, 'Force cache refresh')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be synced without saving');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $providerName = $input->getArgument('provider');

        if (!$provider = $this->mediaManager->getProvider($providerName)) {
            $io->error("Provider '{$providerName}' not found");
            $io->note('Available providers: ' . implode(', ', array_keys($this->mediaManager->getProviders())));
            return Command::FAILURE;
        }

        $options = array_filter([
            'channelId' => $input->getOption('channel-id'),
            'userId' => $input->getOption('user-id'),
        ]);

        $io->info("Syncing media from {$providerName}...");
        
        if ($input->getOption('dry-run')) {
            $io->note('DRY RUN - No changes will be made');
        }

        try {
            if ($input->getOption('dry-run')) {
                $count = 0;
                foreach ($provider->fetchAll($options) as $media) {
                    $io->writeln("Would sync: {$media->code} - {$media->getTitle()}");
                    $count++;
                    if ($count >= 10) {
                        $io->note('Showing first 10 results...');
                        break;
                    }
                }
            } else {
                $synced = $this->mediaManager->syncProvider($providerName, $options);
                $io->success("Synced " . count($synced) . " media items from {$providerName}");
                
                if ($synced) {
                    $io->table(
                        ['Type', 'Code', 'Title'],
                        array_map(fn($media) => [
                            $media->getType(),
                            $media->code,
                            substr($media->getTitle() ?? 'Untitled', 0, 60) . '...'
                        ], array_slice($synced, 0, 10))
                    );
                    
                    if (count($synced) > 10) {
                        $io->note('Showing first 10 results. Total: ' . count($synced));
                    }
                }
            }
        } catch (\Exception $e) {
            $io->error("Sync failed: " . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
