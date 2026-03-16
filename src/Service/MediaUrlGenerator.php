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
        // imgProxy direct signing — bypasses mediary, proxies remote URLs
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(default::IMGPROXY_HOST)%')]
        private readonly ?string $imgProxyHost = null,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(default::IMGPROXY_KEY)%')]
        private readonly ?string $imgProxyKey = null,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(default::IMGPROXY_SALT)%')]
        private readonly ?string $imgProxySalt = null,
    ) {
    }

    /**
     * Generate a signed imgProxy URL for ANY remote image URL —
     * IIIF endpoints, CDN thumbnails, provider image servers, etc.
     *
     * imgProxy fetches and caches the remote image; we never store it locally.
     * Use this instead of mediary for:
     *   - DC/Europeana IIIF endpoints (hit their IIIF at the right size)
     *   - Fortepan/PP CDN thumbnails (for AI analysis)
     *   - Any collection that provides its own image URLs
     *
     * @param string $remoteUrl  The source image URL (IIIF, CDN, etc.)
     * @param int    $width      Target width in pixels (0 = no constraint)
     * @param int    $height     Target height in pixels (0 = no constraint)
     * @param string $preset     'small'|'medium'|'large' or 'ai' (512px)
     */
    public function resizeRemote(
        string $remoteUrl,
        int    $width   = 0,
        int    $height  = 0,
        string $preset  = self::PRESET_SMALL,
    ): string {
        if (!$this->imgProxyHost) {
            // No imgProxy configured — return the source URL as-is
            return $remoteUrl;
        }

        // Preset sizes
        $sizes = [
            self::PRESET_SMALL  => [192, 192],
            self::PRESET_MEDIUM => [600, 400],
            self::PRESET_LARGE  => [1200, 800],
            'ai'                => [512, 512],   // AI vision: one size for all models
            'thumb'             => [300, 300],
        ];

        if ($width === 0 && $height === 0) {
            [$width, $height] = $sizes[$preset] ?? [300, 300];
        }

        $builder = $this->imgProxyKey && $this->imgProxySalt
            ? UrlBuilder::signed($this->imgProxyKey, $this->imgProxySalt)
            : new UrlBuilder();

        return rtrim($this->imgProxyHost, '/') . $builder
            ->usePlain()
            ->with(
                new Resize('fit'),
                new Width($width),
                new Height($height),
            )
            ->url($remoteUrl);
    }

    public function resize(string|BaseMedia $media, string $preset, bool $mediaServer = false, ?string $client=null): string
    {
        if (!isset(self::PRESETS[$preset])) {
            throw new InvalidArgumentException(sprintf('Unknown media preset "%s".', $preset));
        }

        if ($media instanceof BaseMedia) {
            // Return pre-computed smallUrl directly when available
            if ($preset === self::PRESET_SMALL && $media->smallUrl) {
                return $media->smallUrl;
            }
            // Use the hex id for the mediary resize endpoint
            $id = $media->id;
        } else {
            // Raw URL string — derive base64url key for mediary addressing
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
            '{id}' => $id,
        ]);

        if ($client !== null) {
            $path .= '?client=' . $client;
        }

        return rtrim($this->mediaServerHost, '/') . $path;

    }
}
