<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Survos\MediaBundle\Command\FetchYouTubeCommand;
use Survos\MediaBundle\Command\FetchFlickrCommand;
use Survos\MediaBundle\Command\FetchMediaCommand;
use Survos\MediaBundle\Command\SyncMediaCommand;
use Survos\MediaBundle\Command\MediaStatsCommand;
use Survos\MediaBundle\EventListener\MediaPostLoadListener;
use Survos\MediaBundle\Provider\YouTubeProvider;
use Survos\MediaBundle\Provider\FlickrProvider;
use Survos\MediaBundle\Service\MediaBatchDispatcher;
use Survos\MediaBundle\Service\MediaKeyService;
use Survos\MediaBundle\Service\MediaManager;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // Services
     $services->set(MediaManager::class)
         ->public()
         ->arg('$cacheTtl', param('survos_media.cache_ttl'));

      $services->set(\Survos\MediaBundle\Service\MediaUrlGenerator::class)
          ->arg('$mediaServerHost', param('survos_media.media_server.host'))
          ->arg('$mediaServerResizePath', param('survos_media.media_server.resize_path'));

    // Event Listener
    $services->set(MediaPostLoadListener::class)
        ->tag('doctrine.event_listener', ['event' => 'postLoad']);

    // Providers
    $services->set(YouTubeProvider::class)
        ->arg('$config', param('survos_media.provider.youtube.config'));

    $services->set(FlickrProvider::class)
        ->arg('$config', param('survos_media.provider.flickr.config'));

    // Commands
    $services->set(MediaKeyService::class);
    $services->set(FetchYouTubeCommand::class);
    $services->set(FetchFlickrCommand::class);
    $services->set(FetchMediaCommand::class);
    $services->set(SyncMediaCommand::class);
    $services->set(MediaStatsCommand::class)
        ->tag('console.command');
    $services->set(MediaBatchDispatcher::class);
};
