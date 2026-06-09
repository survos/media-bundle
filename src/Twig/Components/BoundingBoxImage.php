<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Twig\Components;

use Survos\MediaBundle\Util\BoundingBoxNormalizer;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Generic, dumb bounding-box overlay for any image.
 *
 * Renders an <img> with a CSS overlay drawing one thin rectangle per box.
 * Boxes are positioned as percentages, so the overlay scales with the rendered
 * image and needs no JavaScript. Border colour encodes confidence (green/amber/
 * red) — the confidence number is kept off the image (too small to read) and
 * shown on hover instead: hovering a box reveals a zoomed crop of the region
 * plus its caption and confidence.
 *
 * Box shapes and coordinate handling are documented on BoundingBoxNormalizer.
 *
 * Usage:
 *   <twig:BoundingBoxImage
 *     src="{{ url }}"
 *     :boxes="asset.context.info.objects"
 *     :imageWidth="asset.width"
 *     :imageHeight="asset.height" />
 */
#[AsTwigComponent('BoundingBoxImage', template: '@SurvosMedia/components/BoundingBoxImage.html.twig')]
final class BoundingBoxImage
{
    /** Image URL. */
    public string $src = '';

    /** Image alt text. */
    public string $alt = '';

    /**
     * Raw boxes (heterogeneous shapes accepted — see BoundingBoxNormalizer).
     *
     * @var array<int, array<string, mixed>>
     */
    public array $boxes = [];

    /**
     * Optional source coordinate space {width, height}. When null, box
     * coordinates are assumed already normalized to 0..1.
     *
     * @var array{width?: int|float, height?: int|float}|null
     */
    public ?array $coordinateSpace = null;

    /** Natural image dimensions — used to size the hover-crop with the right aspect. */
    public ?int $imageWidth = null;
    public ?int $imageHeight = null;

    /** 'confidence' (default) colours boxes green/amber/red; 'class' colours by label. */
    public string $colorBy = 'confidence';

    /** Show a zoomed-crop popup with caption + confidence on hover. */
    public bool $popups = true;

    /** CSS max-height for the image (keeps tall scans in view). */
    public string $maxHeight = '520px';

    /**
     * @return list<array<string, mixed>>
     */
    public function rects(): array
    {
        return BoundingBoxNormalizer::rects(
            $this->boxes,
            $this->coordinateSpace,
            $this->imageWidth,
            $this->imageHeight,
            $this->colorBy,
        );
    }

    public function hasBoxes(): bool
    {
        return $this->rects() !== [];
    }
}
