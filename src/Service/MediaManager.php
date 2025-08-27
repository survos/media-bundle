<?php

namespace Survos\MediaBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Survos\MediaBundle\Entity\BaseMedia;
use Survos\MediaBundle\Provider\ProviderInterface;
use Survos\MediaBundle\Repository\MediaRepository;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class MediaManager
{
    /** @var ProviderInterface[] */
    private array $providers = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaRepository $mediaRepository,
        private readonly CacheInterface $cache,
        private readonly int $cacheTtl = 3600
    ) {}

    public function addProvider(ProviderInterface $provider): void
    {
        $this->providers[$provider->getName()] = $provider;
    }

    public function getProvider(string $name): ?ProviderInterface
    {
        return $this->providers[$name] ?? null;
    }

    public function getProviders(): array
    {
        return $this->providers;
    }

    public function syncProvider(string $providerName, array $options = []): array
    {
        $provider = $this->getProvider($providerName);
        if (!$provider) {
            throw new \InvalidArgumentException("Provider '{$providerName}' not found");
        }

        $cacheKey = sprintf('media_sync_%s_%s', $providerName, md5(serialize($options)));
        
        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($provider, $options) {
            $item->expiresAfter($this->cacheTtl);
            
            $synced = [];
            $batchSize = 20;
            $i = 0;

            foreach ($provider->fetchAll($options) as $media) {
                $existingMedia = $this->mediaRepository->findOneBy([
                    'provider' => $provider->getName(),
                    'externalId' => $media->externalId
                ]);

                if ($existingMedia) {
                    $this->updateMediaFromProvider($existingMedia, $media);
                    $synced[] = $existingMedia;
                } else {
                    $this->em->persist($media);
                    $synced[] = $media;
                }

                if (++$i % $batchSize === 0) {
                    $this->em->flush();
                    $this->em->clear();
                }
            }

            $this->em->flush();
            return $synced;
        });
    }

    private function updateMediaFromProvider(BaseMedia $existing, BaseMedia $new): void
    {
        $existing
            ->setTitle($new->getTitle())
            ->setDescription($new->getDescription());
            
        $existing->thumbnailUrl = $new->thumbnailUrl;
        $existing->rawData = $new->rawData;
        $existing->updatedAt = new \DateTimeImmutable();

        if ($existing instanceof \Survos\MediaBundle\Entity\Video && $new instanceof \Survos\MediaBundle\Entity\Video) {
            $existing->viewCount = $new->viewCount;
            $existing->likeCount = $new->likeCount;
        }
    }

    public function findMediaByCode(string $code): ?BaseMedia
    {
        return $this->mediaRepository->findByCode($code);
    }

    public function findMediaByCodes(array $codes): array
    {
        return $this->mediaRepository->findByCodes($codes);
    }
}
