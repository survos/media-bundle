<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Tests\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\DataContracts\Util\MediaKeyService;
use Survos\DataContracts\Vocabulary\MediaPreset;
use Survos\MediaBundle\Service\MediaUrlGenerator;

/**
 * resize() had NO test, and its bare-URL branch was a fatal error: it called
 * MediaKeyService::keyFromString() unqualified from namespace Survos\MediaBundle\Service, which
 * resolved to a class that stopped existing when MediaKeyService moved to
 * Survos\DataContracts\Util. Nothing caught it: bundle sources sat outside the PHPStan paths
 * and bundle tests outside the phpunit testsuite, so both nets had the same hole.
 *
 * The string branch is exercised first here because that is the one that was broken.
 */
#[CoversClass(MediaUrlGenerator::class)]
final class MediaUrlGeneratorTest extends TestCase
{
    private const URL = 'https://example.com/image.jpg';

    private function generator(): MediaUrlGenerator
    {
        return new MediaUrlGenerator('https://media.example.org/', '/resize/{preset}/{id}');
    }

    #[Test]
    public function resizeFromBareUrlUsesTheReversibleImgproxyKey(): void
    {
        $url = $this->generator()->resize(self::URL, MediaPreset::SMALL);

        // Not the xxh3 asset id: a bare URL has no row, so the key must carry its own source.
        $key = MediaKeyService::keyFromString(self::URL);
        self::assertSame('https://media.example.org/resize/small/' . $key, $url);

        // ...and it must still round-trip out of the generated URL.
        self::assertSame(self::URL, MediaKeyService::stringFromEncoded($key));
    }

    #[Test]
    public function unknownPresetIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->generator()->resize(self::URL, 'gigantic');
    }

    #[Test]
    public function emptySourceUrlIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->generator()->resize('', MediaPreset::SMALL);
    }

    #[Test]
    public function unconfiguredMediaServerIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new MediaUrlGenerator(null, null))->resize(self::URL, MediaPreset::SMALL);
    }

    #[Test]
    public function clientIsAppendedAsQuery(): void
    {
        self::assertStringEndsWith(
            '?client=museado',
            $this->generator()->resize(self::URL, MediaPreset::SMALL, client: 'museado'),
        );
    }

    #[Test]
    public function hostTrailingSlashDoesNotDoubleUp(): void
    {
        self::assertStringNotContainsString(
            'org//resize',
            $this->generator()->resize(self::URL, MediaPreset::SMALL),
        );
    }
}
