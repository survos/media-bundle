<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Util;

final class MediaIdentity
{
    /**
     * Stable 16-byte identifier derived from canonical original URL.
     */
    /**
     * Stable 32-char lowercase hex identifier derived from canonical original URL.
     */
    public static function idFromOriginalUrl(string $url): string
    {
        $canonical = self::canonicalizeUrl($url);

        return hash('xxh3', $canonical, false);
    }

    private static function canonicalizeUrl(string $url): string
    {
        return trim($url);
    }
}
