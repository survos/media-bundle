<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\DataContracts\Util\MediaIdentity;
use Survos\MediaBundle\Entity\BaseMedia;
use Survos\MediaBundle\Service\MediaRegistry;
use Survos\MediaBundle\Service\MediaUrlGenerator;

/**
 * This file previously hand-rolled anonymous classes implementing EntityManagerInterface and
 * ObjectRepository. Both had drifted out of signature compatibility with Doctrine
 * (`find(mixed $id): ?object`), so the test was a fatal error, not a failure -- and it asserted
 * against MediaRegistry::idFromUrl(), a method that does not exist on that class. None of it was
 * noticed because bundle tests were outside the root phpunit testsuite and never ran.
 *
 * Mocks are generated from the current interface, so this class cannot silently rot the same way:
 * if Doctrine changes a signature, the mock changes with it.
 */
#[CoversClass(MediaRegistry::class)]
final class MediaRegistryTest extends TestCase
{
    private const URL = 'https://example.com/image.jpg';

    private function registry(?BaseMedia $existing = null): MediaRegistry
    {
        // Stubs, not mocks: nothing here verifies interactions, and PHPUnit 13 emits a notice for
        // a mock with no configured expectations. Note getRepository() declares Doctrine\ORM\
        // EntityRepository as its return type, not the broader ObjectRepository the old hand-rolled
        // double implemented -- doubling that interface raises IncompatibleReturnValueException.
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($existing);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        // Two constructor args since MediaUrlGenerator was added; the old test passed one and
        // had been an ArgumentCountError ever since.
        return new MediaRegistry($em, new MediaUrlGenerator('https://media.example.org', '/resize/{preset}/{id}'));
    }

    #[Test]
    public function ensureMediaDerivesItsIdFromMediaIdentity(): void
    {
        $media = $this->registry()->ensureMedia(self::URL);

        self::assertInstanceOf(BaseMedia::class, $media);

        // The id is xxh3 of the URL, computed in data-contracts so mediary derives the same value
        // in its own process without either side depending on the other. If this ever stops
        // matching, clients and mediary are silently talking about different images.
        self::assertSame(MediaIdentity::idFromOriginalUrl(self::URL), $media->id);
    }

    #[Test]
    public function ensureMediaIsIdempotentForTheSameUrl(): void
    {
        self::assertSame(
            $this->registry()->ensureMedia(self::URL)->id,
            $this->registry()->ensureMedia(self::URL)->id,
        );
    }

    #[Test]
    public function newMediaStartsAtTheWorkflowInitialPlace(): void
    {
        // Seeded in the constructor. Without it the row persists with a null marking, the kickoff
        // listener sees a marking that is not the initial place, and the workflow never starts --
        // silently, because a null workflow is also the ordinary "no workflow here" answer.
        self::assertSame('new', $this->registry()->ensureMedia(self::URL)->marking);
    }
}
