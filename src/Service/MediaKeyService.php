<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Service;

use InvalidArgumentException;

use function base64_encode;
use function rtrim;
use function strtr;
use function trim;

final class MediaKeyService
{
    static public function keyFromString(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('Value must not be empty.');
        }

        return rtrim(
            strtr(base64_encode($value), '+/', '-_'),
            '='
        );
    }

    static public function stringFromEncoded(string $encoded): string
    {
        $encoded = strtr($encoded, '-_', '+/');
        $encoded = str_pad($encoded, (int) ceil(strlen($encoded) / 4) * 4, '=');

        $decoded = base64_decode($encoded, true);

        if ($decoded === false) {
            throw new InvalidArgumentException('Invalid base64 encoding.');
        }

        return $decoded;
    }

    public static function archivePathFromKey(
        string $key,
        string $extension,
        string $prefix = 'o',
    ): string {
        if ($key === '') {
            throw new InvalidArgumentException('Media key must not be empty.');
        }

        $hash = hash('xxh3', $key);
        $aa = $hash[0];
        $bb = substr($hash, 1, 2);

        return sprintf(
            '%s/%s/%s/%s.%s',
            trim($prefix, '/'),
            $aa,
            $bb,
            $key,
            $extension
        );
    }
    public static function archivePathFromUrl(
        string $url,
        string $extension,
        string $prefix = 'o',
    ): string {
        return self::archivePathFromKey(self::keyFromString($url), $extension, $prefix);
    }

    public static function extensionFromMime(string $mime): string
    {
        $extensions = \Symfony\Component\Mime\MimeTypes::getDefault()->getExtensions($mime);
        $extension = $extensions[0] ?? null;

        if ($extension === null) {
            throw new InvalidArgumentException(sprintf(
                'Cannot determine file extension for MIME type "%s".',
                $mime
            ));
        }

        return $extension;
    }

}
