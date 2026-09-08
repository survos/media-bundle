<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Tests\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\MediaBundle\Service\MediaUpdateApplier;

/**
 * A terminal status must always be accepted, and so must an unrecognised one.
 *
 * `failed` and `deleted` were missing from the rank table, so they ranked 0 and lost to every
 * known status: `failed` arriving for an `archived` row evaluated 0 >= 3 and was dropped. A client
 * could never learn that an asset had failed, and since callers treat only
 * ['complete','failed','deleted'] as settled, one wedged row stalled its entire dataset forever.
 *
 * The rank table's own comment claimed unknown statuses were "always accepted, so a new mediary
 * place cannot deadlock us". It was describing behaviour the code did not have.
 */
#[CoversClass(MediaUpdateApplier::class)]
final class StatusProgressionTest extends TestCase
{
    /** @return list<array{string, string, bool, string}> */
    public static function transitions(): array
    {
        return [
            ['archived', 'failed',   true,  'terminal failure must reach a client stuck at archived'],
            ['archived', 'deleted',  true,  'terminal deletion must reach a client stuck at archived'],
            ['complete', 'failed',   true,  'terminal statuses rank together, so either may follow the other'],
            ['archived', 'complete', true,  'ordinary forward progress'],
            ['complete', 'archived', false, 'a late-arriving earlier status must not clobber a later one'],
            ['informed', 'new',      false, 'ditto, further apart'],
            ['archived', 'archived', false, 'a repeat is not progress -- applying twice is a no-op'],
            ['archived', 'quantum',  true,  'an unknown status is accepted rather than freezing the row'],
        ];
    }

    #[Test]
    #[DataProvider('transitions')]
    public function statusProgression(string $current, string $incoming, bool $expected, string $why): void
    {
        $method = new \ReflectionMethod(MediaUpdateApplier::class, 'isForwardProgress');

        self::assertSame(
            $expected,
            $method->invoke(
                (new \ReflectionClass(MediaUpdateApplier::class))->newInstanceWithoutConstructor(),
                $current,
                $incoming,
            ),
            $why,
        );
    }

    #[Test]
    public function aFirstStatusIsAlwaysAccepted(): void
    {
        $method = new \ReflectionMethod(MediaUpdateApplier::class, 'isForwardProgress');

        self::assertTrue($method->invoke(
            (new \ReflectionClass(MediaUpdateApplier::class))->newInstanceWithoutConstructor(),
            null,
            'new',
        ));
    }
}
