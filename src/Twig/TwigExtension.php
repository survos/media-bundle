<?php

namespace Survos\MediaBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

use Survos\MediaBundle\Service\MediaUrlGenerator;

class TwigExtension extends AbstractExtension
{
    public function __construct() {
    }

    public function getFilters(): array
    {
        return [
            // If your filter generates SAFE HTML, add ['is_safe' => ['html']]
            // Reference: https://twig.symfony.com/doc/3.x/advanced.html#automatic-escaping
            new TwigFilter('filter_name', fn(string $s) => '@todo: filter ' . $s),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('media_presets', fn() => MediaUrlGenerator::PRESETS),
            new TwigFunction('media_preset', fn(string $name) => MediaUrlGenerator::PRESETS[$name] ?? null),
            new TwigFunction('media_preset_small', fn() => MediaUrlGenerator::PRESET_SMALL),
            new TwigFunction('media_preset_medium', fn() => MediaUrlGenerator::PRESET_MEDIUM),
            new TwigFunction('media_preset_large', fn() => MediaUrlGenerator::PRESET_LARGE),
        ];
    }
}
