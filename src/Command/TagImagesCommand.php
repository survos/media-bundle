<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Command;

use Survos\MediaBundle\Service\ImageTaggingService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'media:tag-images',
    description: 'Tag a single image URL with OpenAI Vision and return JSON (tags, safety, description, time period).'
)]
final class TagImagesCommand
{
    public function __construct(
        private readonly ImageTaggingService $tagger,
    ) {}

    public function __invoke(
        SymfonyStyle $io,

        #[Argument('Image URL (http, https, or data URI)')]
        string $url,

        #[Option('Detail level: low or high', shortcut: 'd')]
        string $detail = 'low',

        #[Option('Language for description output')]
        string $lang = 'en',

        #[Option('Year context to improve tagging')]
        ?string $year = null,

        #[Option('Location context to improve tagging')]
        ?string $location = null,

        #[Option('Existing human-added tags, comma separated')]
        ?string $knownTagsCsv = null,

        #[Option('Free-form contextual hint for better tagging')]
        ?string $hint = null,

        #[Option('Output path to save JSON results')]
        ?string $jsonOut = null,

        #[Option('Dry run without calling OpenAI', 'dry')]
        bool $dryRun = false,
    ): int {
        $io->title('OpenAI Image Tagger (single URL)');

        if (!str_starts_with($url, 'http://')
            && !str_starts_with($url, 'https://')
            && !str_starts_with($url, 'data:')
        ) {
            $io->error('URL must start with http://, https://, or data:.');
            return Command::INVALID;
        }

        if (!\in_array($detail, ['low', 'high'], true)) {
            $io->error('--detail must be "low" or "high".');
            return Command::INVALID;
        }

        $result = [];

        if ($dryRun) {
            $io->note('DRY RUN — returning a synthetic example payload.');
            $result = [
                'tags'        => ['test', 'dry-run', 'no-api-call'],
                'safety'      => 'safe',
                'description' => 'This is a dry-run placeholder.',
                'time_period' => null,
                'confidence'  => 'low',
            ];
        } else {
            try {
                $result = $this->tagger->tagImageUrl($url, [
                    'detail'       => $detail,
                    'lang'         => $lang,
                    'year'         => $year,
                    'location'     => $location,
                    'knownTagsCsv' => $knownTagsCsv,
                    'hint'         => $hint,
                ]);
            } catch (\Throwable $e) {
                $io->error('OpenAI call failed: ' . $e->getMessage());
                return Command::FAILURE;
            }
        }

        $io->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($jsonOut) {
            $dir = \dirname($jsonOut);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($jsonOut, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $io->success("Wrote JSON → {$jsonOut}");
        }

        return Command::SUCCESS;
    }
}
