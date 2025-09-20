<?php

namespace Survos\MediaBundle;

use Survos\BabelBundle\EventSubscriber\BabelLocaleRequestSubscriber;
use Survos\BabelBundle\Service\StringResolver;
use Survos\MediaBundle\Provider\ProviderInterface;
use Survos\MediaBundle\Service\ImageTaggingService;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class SurvosMediaBundle extends AbstractBundle # implements ConfigurationInterface
{
//    public function getConfigTreeBuilder(): TreeBuilder
//    {
//        $treeBuilder = new TreeBuilder('survos_media');
//
//        $treeBuilder->getRootNode()
//            ->children()
//                ->scalarNode('default_locale')->defaultValue('en')->end()
//                ->scalarNode('cache_ttl')->defaultValue(3600)->end()
//                ->booleanNode('sais_integration')->defaultTrue()->end()
//                ->arrayNode('providers')
//                    ->useAttributeAsKey('name')
//                    ->arrayPrototype()
//                        ->children()
//                            ->booleanNode('enabled')->defaultTrue()->end()
//                            ->scalarNode('api_key')->end()
//                            ->scalarNode('api_secret')->end()
//                            ->scalarNode('access_token')->end()
//                            ->arrayNode('options')
//                                ->variablePrototype()->end()
//                            ->end()
//                        ->end()
//                    ->end()
//                ->end()
//            ->end();
//
//        return $treeBuilder;
//    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('default_locale')->defaultValue('en')->end()
                ->scalarNode('cache_ttl')->defaultValue(3600)->end()
                ->booleanNode('sais_integration')->defaultTrue()->end()
                ->arrayNode('providers')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->booleanNode('enabled')->defaultTrue()->end()
                            ->scalarNode('api_key')->end()
                            ->scalarNode('api_secret')->end()
                            ->scalarNode('access_token')->end()
                            ->arrayNode('options')
                                ->variablePrototype()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }


    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Import services
        $container->import('../config/services.php');

        foreach ([ImageTaggingService::class] as $class) {
            $builder->register($class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setPublic(true);
        }


        // Set configuration parameters
        $container->parameters()
            ->set('survos_media.config', $config)
            ->set('survos_media.cache_ttl', $config['cache_ttl'])
            ->set('survos_media.sais_integration', $config['sais_integration']);

        // Configure providers
        foreach ($config['providers'] as $name => $providerConfig) {
            if (!$providerConfig['enabled']) {
                continue;
            }

            $container->parameters()
                ->set("survos_media.provider.{$name}.config", $providerConfig);
        }
    }
}
