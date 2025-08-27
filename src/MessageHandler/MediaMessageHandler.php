<?php

namespace Survos\MediaBundle\MessageHandler;

use Survos\MediaBundle\Message\SyncProviderMessage;
use Survos\MediaBundle\Message\FetchMediaMessage;
use Survos\MediaBundle\Service\MediaManager;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Psr\Log\LoggerInterface;

#[AsMessageHandler]
class MediaMessageHandler
{
    public function __construct(
        private readonly MediaManager $mediaManager,
        private readonly LoggerInterface $logger
    ) {}

    public function handleSyncProvider(SyncProviderMessage $message): void
    {
        $this->logger->info('Starting media sync', [
            'provider' => $message->providerName,
            'options' => $message->options
        ]);

        try {
            $synced = $this->mediaManager->syncProvider($message->providerName, $message->options);
            
            $this->logger->info('Media sync completed', [
                'provider' => $message->providerName,
                'count' => count($synced)
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Media sync failed', [
                'provider' => $message->providerName,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function handleFetchMedia(FetchMediaMessage $message): void
    {
        $provider = $this->mediaManager->getProvider($message->providerName);
        if (!$provider) {
            throw new \InvalidArgumentException("Provider '{$message->providerName}' not found");
        }

        $this->logger->info('Fetching single media item', [
            'provider' => $message->providerName,
            'external_id' => $message->externalId
        ]);

        try {
            $media = $provider->fetchById($message->externalId);
            if ($media) {
                $this->logger->info('Media fetched successfully', [
                    'provider' => $message->providerName,
                    'external_id' => $message->externalId,
                    'title' => $media->getTitle()
                ]);
            } else {
                $this->logger->warning('Media not found', [
                    'provider' => $message->providerName,
                    'external_id' => $message->externalId
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch media', [
                'provider' => $message->providerName,
                'external_id' => $message->externalId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
