<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Tests\Workflow;

use Survos\MediaBundle\Entity\Photo;
use Survos\MediaBundle\Workflow\MediaWorkflowDefinition;
use Survos\WorkflowExtrasBundle\Attribute\AsWorkflow;
use Symfony\Component\DependencyInjection\Kernel\AbstractKernel;
use Symfony\Component\DependencyInjection\Kernel\KernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Workflow\WorkflowBundle;

#[AsWorkflow(name: 'test_media', supports: [Photo::class])]
final class TestMediaDefinition extends MediaWorkflowDefinition
{
}

final class MediaWorkflowKernel extends AbstractKernel
{
    use KernelTrait;

    private string $directory;

    public function __construct()
    {
        parent::__construct('test', true);
        $this->directory = sys_get_temp_dir().'/media-workflow-'.bin2hex(random_bytes(6));
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return $this->directory.'/cache';
    }

    public function getLogDir(): string
    {
        return $this->directory.'/log';
    }

    public function registerBundles(): iterable
    {
        yield new WorkflowBundle();
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('workflow', []);
        $container->services()->set(TestMediaDefinition::class)->autoconfigure();
        $container->services()->alias('test.workflow', 'state_machine.test_media')->public();
    }
}
