<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Service;

use InvalidArgumentException;
use Survos\DataContracts\Vocabulary\MediaPreset;
use Survos\MediaBundle\Entity\BaseMedia;
use function rtrim;
use function sprintf;

final class MediaUrlGenerator
{

    public function __construct(
        private readonly ?string $mediaServerHost,
        private readonly ?string $mediaServerResizePath,
    ) {
    }

    public function resize(string|BaseMedia $media, string $preset, bool $mediaServer = false, ?string $client = null): string
    {
        if (!isset(MediaPreset::PRESETS[$preset])) {
            throw new InvalidArgumentException(sprintf('Unknown media preset "%s".', $preset));
        }

        if ($media instanceof BaseMedia) {
            if ($preset === MediaPreset::SMALL && $media->smallUrl) {
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
