<?php

declare(strict_types=1);

namespace Survos\MediaBundle;

use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\Traits\HasDoctrineEntities;
use Survos\MediaBundle\Service\ImageTaggingService;
use Survos\MediaBundle\Service\MediaRegistry;
use Survos\MediaBundle\Service\OcrService;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
class SurvosMediaBundle extends AbstractSurvosBundle
{
    use HasDoctrineEntities;

    protected function doctrineAlias(): string
    {
        return 'Media';
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('default_locale')->defaultValue('en')->end()
                ->scalarNode('cache_ttl')->defaultValue(3600)->end()
                ->booleanNode('sais_integration')->defaultTrue()->end()
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
                        'small'  => ['resize' => 'fill', 'width' => 192, 'height' => 192],
                        'medium' => ['resize' => 'fit',  'width' => 400, 'height' => 400],
                        'large'  => ['resize' => 'fit',  'width' => 800, 'height' => 800],
                        'ai'     => ['resize' => 'fit',  'width' => 512, 'height' => 512],
                        'thumb'  => ['resize' => 'fit',  'width' => 300, 'height' => 300],
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
        parent::prependExtension($container, $builder);

        if ($builder->hasExtension('ux_twig_component')) {
            $builder->prependExtensionConfig('ux_twig_component', [
                'defaults' => [
                    'Survos\\MediaBundle\\Twig\\Components\\' => 'components/',
                ],
            ]);
        }

        // Icons used by the Media admin menu + entity EntityMeta attributes. Declaring
        // them as aliases lets `ux:icons:lock` discover and cache them (they're referenced
        // from PHP attributes, which the template scanner can't see). Apps may override.
        if ($builder->hasExtension('ux_icons')) {
            $builder->prependExtensionConfig('ux_icons', [
                'aliases' => [
                    'media' => 'mdi:video-image',
                    'photo' => 'mdi:camera',
                    'video' => 'mdi:video',
                    'audio' => 'mdi:music',
                ],
            ]);
        }

        // Read-only connection to mediary's central claims + media (asset) DB. mediary
        // owns and writes these (like lingua owns translation memory); apps that use
        // media-bundle READ them directly via this connection (see claims-bundle's
        // ClaimReader). Writes are blocked at the Postgres role level (mediary_ro).
        //
        // Registered ONLY when the app sets MEDIARY_RO_DATABASE_URL — so apps that don't
        // read mediary directly get no extra connection (and avoid the multi-connection
        // default_connection trap). Opt in by setting that env to the mediary_ro DSN.
        $roDsn = $_ENV['MEDIARY_RO_DATABASE_URL'] ?? $_SERVER['MEDIARY_RO_DATABASE_URL'] ?? getenv('MEDIARY_RO_DATABASE_URL');
        if ($roDsn && $builder->hasExtension('doctrine')) {
            $builder->prependExtensionConfig('doctrine', [
                'dbal' => [
                    'connections' => [
                        'mediary_ro' => [
                            'url' => '%env(resolve:MEDIARY_RO_DATABASE_URL)%',
                        ],
                    ],
                ],
            ]);
        }
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);

        $container->import('../config/services.php');

        foreach ([ImageTaggingService::class, MediaRegistry::class, OcrService::class] as $class) {
            $builder->register($class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setPublic(true);
        }

        // Twig/Live components in src/Twig/Components/ are auto-registered by
        // AbstractSurvosBundle::loadExtension().

        $container->parameters()
            ->set('survos_media.config', $config)
            ->set('survos_media.cache_ttl', $config['cache_ttl'])
            ->set('survos_media.sais_integration', $config['sais_integration'])
            ->set('survos_media.presets', $config['presets'])
            ->set('survos_media.media_server.host', $config['media_server']['host'])
            ->set('survos_media.media_server.apiKey', $config['media_server']['apiKey'])
            ->set('survos_media.media_server.resize_path', $config['media_server']['resize_path']);

        foreach ($config['providers'] as $name => $providerConfig) {
            if (!$providerConfig['enabled']) {
                continue;
            }
            $container->parameters()
                ->set("survos_media.provider.{$name}.config", $providerConfig);
        }
    }
}
