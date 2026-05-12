<?php

namespace Survos\MediaBundle\Command;

use Survos\MediaBundle\Service\MediaManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('media:providers', 'List available media providers')]
final class ListProvidersCommand
{
    public function __construct(
        private readonly MediaManager $mediaManager,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $providers = $this->mediaManager->getProviders();

        if (empty($providers)) {
            $io->warning('No providers configured');
            return Command::SUCCESS;
        }

        $io->title('Available Media Providers');

        $rows = [];
        foreach ($providers as $name => $provider) {
            $rows[] = [
                $name,
                $provider::class,
                implode(', ', ['photo', 'video', 'audio']),
            ];
        }

        $io->table(['Name', 'Class', 'Supported Types'], $rows);

        $io->note([
            'Use these provider names with:',
            '  bin/console media:sync <provider-name>',
            '  bin/console fetch:youtube --channel-id=...',
            '  bin/console fetch:flickr --user-id=...',
        ]);

        return Command::SUCCESS;
    }
}
