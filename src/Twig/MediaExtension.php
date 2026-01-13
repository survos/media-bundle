<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Twig;

use Survos\MediaBundle\Service\MediaUrlGenerator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class MediaExtension extends AbstractExtension
{
    public function __construct(
        private readonly MediaUrlGenerator $urlGenerator,
        private readonly array $presets,
    ) {
    }

    public function getGlobals(): array
    {
        $globals = [];
        foreach ($this->presets as $name => $_) {
            $globals['PRESET_' . strtoupper($name)] = $name;
        }

        return $globals;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('media_resize', [$this, 'mediaResize']),
        ];
    }

    public function mediaResize(
        string|object $media,
        string $preset,
        ?string $client=null
    ): string {
        return $this->urlGenerator->resize($media, $preset, client: $client);
    }
}
