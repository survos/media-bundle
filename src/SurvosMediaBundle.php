<?php

namespace Survos\MediaBundle;

use Survos\BabelBundle\EventSubscriber\BabelLocaleRequestSubscriber;
use Survos\BabelBundle\Service\StringResolver;
use Survos\MediaBundle\Provider\ProviderInterface;
use Survos\MediaBundle\Service\ImageTaggingService;
use Survos\MediaBundle\Service\MediaRegistry;
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
                  ->arrayNode('imgproxy')
                      ->addDefaultsIfNotSet()
                      ->children()
                          ->scalarNode('base_url')->defaultValue('https://images.survos.com')->end()
                          ->scalarNode('key')->defaultValue('%env(IMGPROXY_KEY)%')->end()
                          ->scalarNode('salt')->defaultValue('%env(IMGPROXY_SALT)%')->end()
                      ->end()
                  ->end()
                 ->arrayNode('media_server')
                     ->addDefaultsIfNotSet()
                     ->children()
                         ->scalarNode('host')->defaultValue('https://media.wip')->end()
                         ->scalarNode('apiKey')->defaultNull()->end()
                         ->scalarNode('resize_path')->defaultValue('/media/{preset}/{id}')->end()
                     ->end()
                 ->end()
                  ->arrayNode('presets')
                      ->useAttributeAsKey('name')
                      ->arrayPrototype()
                          ->children()
                              ->scalarNode('resize')->defaultValue('fit')->end()
                              ->integerNode('width')->isRequired()->end()
                              ->integerNode('height')->isRequired()->end()
                          ->end()
                      ->end()
                      ->defaultValue([
                          'small' => ['resize' => 'fill', 'width' => 192, 'height' => 192],
                          'medium' => ['resize' => 'fit', 'width' => 400, 'height' => 400],
                          'large' => ['resize' => 'fit', 'width' => 800, 'height' => 800],
                      ])
                  ->end()
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


     public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
     {
         $builder->prependExtensionConfig('doctrine', [
             'orm' => [
                 'mappings' => [
                     'SurvosMediaBundle' => [
                         'is_bundle' => false,
                         'type' => 'attribute',
                         'dir' => \dirname(__DIR__).'/src/Entity',
                         'prefix' => 'Survos\\MediaBundle\\Entity',
                         'alias' => 'Media',
                     ],
                 ],
             ],
         ]);
     }

     public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
     {
        // Import services
        $container->import('../config/services.php');

         foreach ([ImageTaggingService::class, MediaRegistry::class] as $class) {
            $builder->register($class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setPublic(true);
        }


         // Set configuration parameters
         $container->parameters()
             ->set('survos_media.config', $config)
             ->set('survos_media.cache_ttl', $config['cache_ttl'])
             ->set('survos_media.sais_integration', $config['sais_integration'])
             ->set('survos_media.presets', $config['presets'])
             ->set('survos_media.media_server.host', $config['media_server']['host'])
             ->set('survos_media.media_server.apiKey', $config['media_server']['apiKey'])
             ->set('survos_media.media_server.resize_path', $config['media_server']['resize_path'])
              ->set('survos_media.imgproxy_base_url', $config['imgproxy']['base_url'])
              ->set('survos_media.imgproxy.key', $config['imgproxy']['key'])
              ->set('survos_media.imgproxy.salt', $config['imgproxy']['salt']);

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
