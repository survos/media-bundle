<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Service;

use InvalidArgumentException;
use Survos\MediaBundle\Entity\BaseMedia;
use function rtrim;
use function sprintf;

final class MediaUrlGenerator
{
    public const PRESET_SMALL = 'small';
    public const PRESET_MEDIUM = 'medium';
    public const PRESET_LARGE = 'large';
    public const PRESET_AI = 'ai';
    public const PRESET_THUMB = 'thumb';

    public const PRESETS = [
        self::PRESET_SMALL => [
            'size' => [192, 192],
            'resize' => 'fit',
            'quality' => 80,
            'format' => 'jpg',
            'dpr' => [1],
        ],
        self::PRESET_MEDIUM => [
            'size' => [600, 400],
            'resize' => 'fit',
            'quality' => 85,
            'format' => 'jpg',
            'dpr' => [1, 2],
        ],
        self::PRESET_LARGE => [
            'size' => [1200, 800],
            'resize' => 'fit',
            'quality' => 85,
            'format' => 'jpg',
            'dpr' => [1, 2],
        ],
        self::PRESET_AI => [
            'size' => [512, 512],
            'resize' => 'fit',
            'quality' => 85,
            'format' => 'jpg',
            'dpr' => [1],
        ],
        self::PRESET_THUMB => [
            'size' => [300, 300],
            'resize' => 'fit',
            'quality' => 80,
            'format' => 'jpg',
            'dpr' => [1],
        ],
    ];

    public function __construct(
        private readonly ?string $mediaServerHost,
        private readonly ?string $mediaServerResizePath,
    ) {
    }

    public function resize(string|BaseMedia $media, string $preset, bool $mediaServer = false, ?string $client = null): string
    {
        if (!isset(self::PRESETS[$preset])) {
            throw new InvalidArgumentException(sprintf('Unknown media preset "%s".', $preset));
        }

        if ($media instanceof BaseMedia) {
            if ($preset === self::PRESET_SMALL && $media->smallUrl) {
                return $media->smallUrl;
            }
            $id = $media->id;
        } else {
            if (!$media) {
                throw new InvalidArgumentException('Cannot generate media URL without source URL.');
            }
            $id = MediaKeyService::keyFromString($media);
        }

        if (!$this->mediaServerHost || !$this->mediaServerResizePath) {
            throw new InvalidArgumentException('Media server host or resize path not configured.');
        }

        $path = strtr($this->mediaServerResizePath, [
            '{preset}' => $preset,
            '{id}'     => $id,
        ]);

        if ($client !== null) {
            $path .= '?client=' . $client;
        }

        return rtrim($this->mediaServerHost, '/') . $path;
    }
}
