<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Renders AI task results for an Asset, grouped by type.
 *
 * Usage:
 *   {# AI tab — image analysis tasks #}
 *   <twig:AiMetadata :results="asset.aiResults()" group="image" />
 *
 *   {# OCR tab — text extraction tasks #}
 *   <twig:AiMetadata :results="asset.aiResults()" group="ocr" />
 *
 * Handles both legacy per-task results AND the new enrich_from_thumbnail DTO.
 */
#[AsTwigComponent('AiMetadata', template: '@SurvosMedia/components/AiMetadata.html.twig')]
final class AiMetadata
{
    /** All aiResults() from the Asset — keyed by task name */
    public array $results = [];

    /** Which group to display: 'image' or 'ocr' */
    public string $group = 'image';

    /** Task → group mapping */
    private const OCR_TASKS = [
        'ocr', 'ocr_mistral', 'transcribe_handwriting',
        'annotate_handwriting', 'layout',
    ];

    /**
     * Tasks for the current group, with their results.
     * Returns [ taskName => result[] ]
     */
    public function tasks(): array
    {
        $out = [];
        foreach ($this->results as $task => $result) {
            $isOcr = in_array($task, self::OCR_TASKS, true);
            if ($this->group === 'ocr' && $isOcr) {
                $out[$task] = $result;
            } elseif ($this->group === 'image' && !$isOcr) {
                $out[$task] = $result;
            }
        }
        return $out;
    }

    /**
     * For enrich_from_thumbnail — the rich structured result.
     * Returns null for all other tasks.
     */
    public function enrichResult(): ?array
    {
        return $this->results['enrich_from_thumbnail'] ?? null;
    }

    public function hasResults(): bool
    {
        return !empty($this->tasks());
    }

    /** Human-readable task label */
    public function taskLabel(string $task): string
    {
        return match($task) {
            'enrich_from_thumbnail' => 'Image Enrichment (single-pass)',
            'basic_description'     => 'Description',
            'generate_title'        => 'Title',
            'keywords'              => 'Keywords',
            'people_and_places'     => 'People & Places',
            'extract_metadata'      => 'Metadata Extraction',
            'classify'              => 'Classification',
            'context_description'   => 'Context Description',
            'summarize'             => 'Summary',
            'ocr'                   => 'OCR',
            'ocr_mistral'           => 'OCR (Mistral)',
            'transcribe_handwriting'=> 'Handwriting Transcription',
            'annotate_handwriting'  => 'Handwriting Annotation',
            'layout'                => 'Layout Analysis',
            default                 => ucwords(str_replace('_', ' ', $task)),
        };
    }
}
