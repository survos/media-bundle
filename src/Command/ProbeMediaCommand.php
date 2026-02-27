<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Command;

use Survos\MediaBundle\Service\MediaBatchDispatcher;
use Survos\MediaBundle\Util\MediaIdentity;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('media:probe', 'Probe a mediary asset by id or URL and print JSON')]
final class ProbeMediaCommand extends Command
{
    public function __construct(
        private readonly MediaBatchDispatcher $dispatcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('source', InputArgument::REQUIRED, 'Asset id (16 hex chars) or original URL')
            ->addOption('url', null, InputOption::VALUE_NONE, 'Force treat source as URL');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $source = (string) $input->getArgument('source');
        $forceUrl = (bool) $input->getOption('url');

        $isId = (bool) preg_match('/^[a-f0-9]{16}$/i', $source);
        $assetId = ($forceUrl || !$isId)
            ? MediaIdentity::idFromOriginalUrl($source)
            : strtolower($source);

        if ($forceUrl || !$isId) {
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

        $output->writeln($json);
        return Command::SUCCESS;
    }
}
