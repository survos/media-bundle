<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Util;

/**
 * Normalizes heterogeneous detected-object boxes into a uniform shape for
 * display: percentage rectangles, a confidence-derived colour, and the CSS
 * background math needed to render a cropped thumbnail of each box from the
 * full image.
 *
 * Shared by BoundingBoxImage (overlay + hover popup) and DetectedObjectList
 * (grouped thumbnails) so both agree on coordinates, colours and crops.
 *
 * Input boxes are tolerant — each may use:
 *   - position: left|x and top|y
 *   - size:     width and height
 *   - label:    label|class_name|class
 *   - confidence: 0..1
 *
 * Coordinates are assumed normalized (0..1, imgproxy's format). Pass a
 * coordinateSpace {width,height} to rescale another grid (e.g. ledger's 0..10000).
 */
final class BoundingBoxNormalizer
{
    /**
     * @param array<int, array<string, mixed>>     $boxes
     * @param array{width?: int|float, height?: int|float}|null $coordinateSpace
     *
     * @return list<array{
     *   left: float, top: float, width: float, height: float,
     *   label: ?string, confidence: ?float, confPct: ?int, level: string,
     *   color: string,
     *   crop: array{size: string, pos: string, aspect: float}
     * }>
     */
    public static function rects(
        array $boxes,
        ?array $coordinateSpace = null,
        ?int $imageWidth = null,
        ?int $imageHeight = null,
        string $colorBy = 'confidence',
    ): array {
        $spaceW = $coordinateSpace['width'] ?? null;
        $spaceH = $coordinateSpace['height'] ?? null;

        $rects = [];
        foreach (array_values($boxes) as $i => $box) {
            if (!is_array($box)) {
                continue;
            }

            $left = $box['left'] ?? $box['x'] ?? null;
            $top = $box['top'] ?? $box['y'] ?? null;
            $width = $box['width'] ?? null;
            $height = $box['height'] ?? null;

            if (!is_numeric($left) || !is_numeric($top) || !is_numeric($width) || !is_numeric($height)) {
                continue;
            }

            $left = (float) $left;
            $top = (float) $top;
            $width = (float) $width;
            $height = (float) $height;

            if (is_numeric($spaceW) && (float) $spaceW > 0) {
                $left /= (float) $spaceW;
                $width /= (float) $spaceW;
            }
            if (is_numeric($spaceH) && (float) $spaceH > 0) {
                $top /= (float) $spaceH;
                $height /= (float) $spaceH;
            }

            $label = $box['label'] ?? $box['class_name'] ?? $box['class'] ?? null;
            $label = is_string($label) && trim($label) !== '' ? trim($label) : null;

            $confidence = $box['confidence'] ?? null;
            $confidence = is_numeric($confidence) ? (float) $confidence : null;

            $level = self::level($confidence);

            $rects[] = [
                'left' => round($left * 100, 3),
                'top' => round($top * 100, 3),
                'width' => round($width * 100, 3),
                'height' => round($height * 100, 3),
                'label' => $label,
                'confidence' => $confidence,
                'confPct' => $confidence !== null ? (int) round($confidence * 100) : null,
                'level' => $level,
                'color' => $colorBy === 'class'
                    ? self::classColor($label, $i)
                    : self::levelColor($level),
                'crop' => self::crop($left, $top, $width, $height, $imageWidth, $imageHeight),
            ];
        }

        return $rects;
    }

    /**
     * Group normalized rects by label, most-frequent group first, and within a
     * group highest-confidence first.
     *
     * @param list<array<string, mixed>> $rects
     * @return list<array{label: string, count: int, items: list<array<string, mixed>>}>
     */
    public static function group(array $rects): array
    {
        $groups = [];
        foreach ($rects as $rect) {
            $label = $rect['label'] ?? 'object';
            $groups[$label][] = $rect;
        }

        $out = [];
        foreach ($groups as $label => $items) {
            usort($items, static fn (array $a, array $b): int => ($b['confidence'] ?? 0) <=> ($a['confidence'] ?? 0));
            $out[] = ['label' => (string) $label, 'count' => count($items), 'items' => $items];
        }

        usort($out, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $out;
    }

    private static function level(?float $confidence): string
    {
        if ($confidence === null) {
            return 'unknown';
        }

        return match (true) {
            $confidence >= 0.75 => 'high',
            $confidence >= 0.50 => 'medium',
            default => 'low',
        };
    }

    /** Tabler-ish palette so colour reads as a confidence signal. */
    private static function levelColor(string $level): string
    {
        return match ($level) {
            'high' => '#2fb344',   // green
            'medium' => '#f59f00', // amber
            'low' => '#d63939',    // red
            default => '#868e96',  // gray (unknown)
        };
    }

    private static function classColor(?string $label, int $index): string
    {
        $hue = $label !== null ? (int) (crc32($label) % 360) : ($index * 47 % 360);

        return sprintf('hsl(%d, 70%%, 45%%)', $hue);
    }

    /**
     * CSS background-crop for a normalized box: makes any element show just the
     * box region of the full image. size/pos are percentages relative to the
     * element; aspect is the crop's pixel width:height (for sizing the element).
     *
     * @return array{size: string, pos: string, aspect: float}
     */
    private static function crop(float $l, float $t, float $w, float $h, ?int $imgW, ?int $imgH): array
    {
        $sizeX = $w > 0 ? 100 / $w : 100;
        $sizeY = $h > 0 ? 100 / $h : 100;
        $posX = $w < 1 ? ($l / (1 - $w)) * 100 : 0;
        $posY = $h < 1 ? ($t / (1 - $h)) * 100 : 0;

        // Pixel aspect of the crop; falls back to the normalized ratio (square
        // image) when natural dimensions are unknown.
        $aspect = ($imgW && $imgH && $h > 0)
            ? ($w * $imgW) / ($h * $imgH)
            : ($h > 0 ? $w / $h : 1.0);

        return [
            'size' => round($sizeX, 3) . '% ' . round($sizeY, 3) . '%',
            'pos' => round($posX, 3) . '% ' . round($posY, 3) . '%',
            'aspect' => round($aspect, 4) ?: 1.0,
        ];
    }
}
