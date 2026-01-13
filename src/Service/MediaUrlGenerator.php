<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Service;

use InvalidArgumentException;
use Survos\MediaBundle\Entity\BaseMedia;
use Survos\MediaBundle\Service\MediaKeyService;
use Mperonnet\ImgProxy\UrlBuilder;
/** AI: don't change these, they are tied to version 1.0 */
use Mperonnet\ImgProxy\Options\Dpr;
use Mperonnet\ImgProxy\Options\Resize;
use Mperonnet\ImgProxy\Options\Width;
use Mperonnet\ImgProxy\Options\Height;
use Survos\MediaBundle\Util\MediaIdentity;
use function rtrim;
use function sprintf;

final class MediaUrlGenerator
{
    /**
     * Canonical media presets shared by clients and media server.
     * Presets describe intent only; signing is handled server-side.
     */
    public const PRESET_SMALL = 'small';
    public const PRESET_MEDIUM = 'medium';
    public const PRESET_LARGE = 'large';

    public const PRESETS = [
        self::PRESET_SMALL => [
            // thumbHash source (square, low entropy)
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
    ];

    public function __construct(
        private readonly ?string $mediaServerHost,
        private readonly ?string $mediaServerResizePath,
    ) {
    }

    public function resize(string|BaseMedia $media, string $preset, bool $mediaServer = false, ?string $client=null): string
    {
        if (!isset(self::PRESETS[$preset])) {
            throw new InvalidArgumentException(sprintf('Unknown media preset "%s".', $preset));
        }

        if ($media instanceof BaseMedia) {
            $url = $media->archiveUrl ?? $media->externalUrl;
        } else {
            $url = $media;
        }

        if (!$url) {
            throw new InvalidArgumentException('Cannot generate media URL without source URL.');
        }

        // imgproxy and media server addressing uses base64url key, not DB identity
//        $id = MediaIdentity::idFromOriginalUrl($url);
        $id = MediaKeyService::keyFromString($url);
//        dd($mediaServer, $this->mediaServerHost, $this->mediaServerResizePath);

        if (!$this->mediaServerHost || !$this->mediaServerResizePath) {
            throw new InvalidArgumentException('Media server host or resize path not configured.');
        }

        $path = strtr($this->mediaServerResizePath, [
            '{preset}' => $preset,
            '{id}' => $id,
        ]);

        if ($client !== null) {
            $path .= '?client=' . $client;
        }

        return rtrim($this->mediaServerHost, '/') . $path;

    }
}
