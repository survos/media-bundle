<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Twig\Components;

use Survos\MediaBundle\Util\BoundingBoxNormalizer;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Grouped, thumbnailed listing of detected objects — companion to
 * BoundingBoxImage. Objects are grouped by label ("Face (4)") and each is shown
 * as a cropped thumbnail of its region plus its confidence. Same coordinate /
 * colour / crop math as the overlay (via BoundingBoxNormalizer).
 *
 * Usage:
 *   <twig:DetectedObjectList
 *     src="{{ url }}"
 *     :boxes="asset.context.info.objects"
 *     :imageWidth="asset.width"
 *     :imageHeight="asset.height" />
 */
#[AsTwigComponent('DetectedObjectList', template: '@SurvosMedia/components/DetectedObjectList.html.twig')]
final class DetectedObjectList
{
    /** Image URL the crops are taken from. */
    public string $src = '';

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $boxes = [];

    /**
     * @var array{width?: int|float, height?: int|float}|null
     */
    public ?array $coordinateSpace = null;

    public ?int $imageWidth = null;
    public ?int $imageHeight = null;

    public string $colorBy = 'confidence';

    /** Thumbnail height in px; width derives from each crop's aspect. */
    public int $thumbHeight = 72;

    /**
     * @return list<array{label: string, count: int, items: list<array<string, mixed>>}>
     */
    public function groups(): array
    {
        return BoundingBoxNormalizer::group(
            BoundingBoxNormalizer::rects(
                $this->boxes,
                $this->coordinateSpace,
                $this->imageWidth,
                $this->imageHeight,
                $this->colorBy,
            ),
        );
    }

    public function hasBoxes(): bool
    {
        return $this->groups() !== [];
    }

    /** Display width for a crop thumbnail, clamped to a sane range. */
    public function thumbWidth(float $aspect): int
    {
        return max(28, min(220, (int) round($this->thumbHeight * $aspect)));
    }
}
