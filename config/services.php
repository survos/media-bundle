<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Survos\MediaBundle\Command\FetchYouTubeCommand;
use Survos\MediaBundle\Command\FetchFlickrCommand;
use Survos\MediaBundle\Command\SyncMediaCommand;
use Survos\MediaBundle\EventListener\MediaPostLoadListener;
use Survos\MediaBundle\Provider\YouTubeProvider;
use Survos\MediaBundle\Provider\FlickrProvider;
use Survos\MediaBundle\Repository\MediaRepository;
use Survos\MediaBundle\Service\MediaManager;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // Repository
    $services->set(MediaRepository::class)
        ->tag('doctrine.repository_service');

    // Service
    $services->set(MediaManager::class)
        ->arg('$cacheTtl', param('survos_media.cache_ttl'));

    // Event Listener
    $services->set(MediaPostLoadListener::class)
        ->tag('doctrine.event_listener', ['event' => 'postLoad']);

    // Providers
    $services->set(YouTubeProvider::class)
        ->arg('$config', param('survos_media.provider.youtube.config'));

    $services->set(FlickrProvider::class)
        ->arg('$config', param('survos_media.provider.flickr.config'));

    // Commands
    $services->set(FetchYouTubeCommand::class);
    $services->set(FetchFlickrCommand::class);
    $services->set(SyncMediaCommand::class);
};
