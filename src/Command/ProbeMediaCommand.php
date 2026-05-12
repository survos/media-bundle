<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Command;

use Survos\MediaBundle\Service\MediaBatchDispatcher;
use Survos\MediaBundle\Util\MediaIdentity;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('media:probe', 'Probe a media asset by id or URL and print JSON')]
final class ProbeMediaCommand
{
    public function __construct(
        private readonly MediaBatchDispatcher $dispatcher,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Asset id (16 hex chars) or original URL')] string $source,
        #[Option('Force treat source as URL')] bool $url = false,
    ): int {
        $isId    = (bool) preg_match('/^[a-f0-9]{16}$/i', $source);
        $assetId = ($url || !$isId)
            ? MediaIdentity::idFromOriginalUrl($source)
            : strtolower($source);

        if ($url || !$isId) {
            $io->comment(sprintf('Resolved URL to asset id: %s', $assetId));
        }

        try {
            $probe = $this->dispatcher->probe($assetId);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $json = json_encode($probe->raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $io->error('Failed to encode probe payload as JSON.');
            return Command::FAILURE;
        }

        $io->writeln($json);
        return Command::SUCCESS;
    }
}
