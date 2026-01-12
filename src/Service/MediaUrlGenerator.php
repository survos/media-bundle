<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Service;

use InvalidArgumentException;
use Survos\MediaBundle\Entity\BaseMedia;
use Mperonnet\ImgProxy\UrlBuilder;
use Mperonnet\ImgProxy\Option\Resize;
use Mperonnet\ImgProxy\Option\Width;
use Mperonnet\ImgProxy\Option\Height;
use function rtrim;
use function sprintf;

final class MediaUrlGenerator
{
    public function __construct(
        private readonly array $presets,
        private readonly string $imgproxyBaseUrl,
        private readonly string $imgproxyKey,
        private readonly string $imgproxySalt,
        private readonly ?string $mediaServerHost,
        private readonly ?string $mediaServerResizePath,
    ) {
    }

    public function resize(string|BaseMedia $media, string $preset, bool $mediaServer = false): string
    {
        if (!isset($this->presets[$preset])) {
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

        $id = MediaRegistry::idFromUrl($url);

        if ($mediaServer && $this->mediaServerHost && $this->mediaServerResizePath) {
            $path = strtr($this->mediaServerResizePath, [
                '{preset}' => $preset,
                '{id}' => $id,
            ]);

            $url = rtrim($this->mediaServerHost, '/') . $path;
        } else {
            $presetDef = $this->presets[$preset];

            // Build imgproxy URL using imgproxy-php (signed)
            $builder = UrlBuilder::signed($this->imgproxyKey, $this->imgproxySalt);

            // Minimal, correct mapping: resize mode + width/height
            if (isset($presetDef['resize'])) {
                $builder = $builder->with(new Resize($presetDef['resize']));
            }
            if (isset($presetDef['width'])) {
                $builder = $builder->with(new Width((int) $presetDef['width']));
            }
            if (isset($presetDef['height'])) {
                $builder = $builder->with(new Height((int) $presetDef['height']));
            }

            return rtrim($this->imgproxyBaseUrl, '/') . $builder->encoded(false)->url($url);
        }

        return $url;

    }
}
