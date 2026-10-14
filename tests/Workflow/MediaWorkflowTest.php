<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Tests\Workflow;

use PHPUnit\Framework\TestCase;
use Survos\MediaBundle\Entity\Photo;
use Symfony\Component\Workflow\WorkflowBundle;

final class MediaWorkflowTest extends TestCase
{
    public function testNativeDiscoveryAndExistingMarkingLifecycle(): void
    {
        if (!class_exists(WorkflowBundle::class)) {
            self::markTestSkipped('Native workflow discovery requires Symfony 8.2.');
        }
        require_once __DIR__.'/Fixtures/MediaWorkflowKernel.php';
        $kernel = new MediaWorkflowKernel();
        try {
            $kernel->boot();
            $workflow = $kernel->getContainer()->get('test.workflow');
            $media = new Photo('https://example.com/test.jpg');
            self::assertSame(['new'], array_keys($workflow->getMarking($media)->getPlaces()));
            self::assertSame(['dispatch'], $workflow->getMetadataStore()->getPlaceMetadata('new')['next']);
            $dispatch = $workflow->getDefinition()->getTransitions()[0];
            self::assertTrue($workflow->getMetadataStore()->getMetadata('async', $dispatch));
            self::assertSame('media.dispatch', $workflow->getMetadataStore()->getMetadata('transport', $dispatch));
            $workflow->apply($media, 'dispatch');
            self::assertSame('dispatched', $media->getMarking());
            $workflow->apply($media, 'sync');
            self::assertSame('synced', $media->getMarking());
            self::assertTrue($workflow->can($media, 'sync'), 'Repeated callbacks remain applicable.');
            self::assertFalse($workflow->can($media, 'dispatch'));
        } finally {
            $kernel->shutdown();
        }
    }
}
