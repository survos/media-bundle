<?php

namespace Survos\MediaBundle\Provider;

use Survos\MediaBundle\Entity\BaseMedia;
use Survos\MediaBundle\Entity\Photo;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class FlickrProvider extends AbstractProvider
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        array $config = []
    ) {
        parent::__construct($config);
    }

    public function getName(): string
    {
        return 'flickr';
    }

    public function supports(string $type): bool
    {
        return $type === 'photo';
    }

    public function fetchAll(array $options = []): iterable
    {
        $userId = $options['userId'] ?? $this->config['default_user'] ?? null;
        if (!$userId) {
            throw new \InvalidArgumentException('userId is required for Flickr sync');
        }

        $page = 1;
        do {
            $url = sprintf(
                'https://api.flickr.com/services/rest/?method=flickr.people.getPhotos&api_key=%s&user_id=%s&format=json&nojsoncallback=1&page=%d',
                $this->config['api_key'],
                $userId,
                $page
            );

            $response = $this->httpClient->request('GET', $url);
            $data = $response->toArray();

            if ($data['stat'] !== 'ok') {
                break;
            }

            foreach ($data['photos']['photo'] as $photo) {
                yield $this->normalize($photo);
            }

            $page++;
        } while ($page <= $data['photos']['pages']);
    }

    public function fetchById(string $id): ?BaseMedia
    {
        $url = sprintf(
            'https://api.flickr.com/services/rest/?method=flickr.photos.getInfo&api_key=%s&photo_id=%s&format=json&nojsoncallback=1',
            $this->config['api_key'],
            $id
        );

        $response = $this->httpClient->request('GET', $url);
        $data = $response->toArray();

        if ($data['stat'] !== 'ok') {
            return null;
        }

        return $this->normalize($data['photo']);
    }

    public function normalize(array $rawData): BaseMedia
    {
        $id = $rawData['id'];
        
        /** @var Photo $photo */
        $photo = $this->createMedia('photo', $id);
        
        $photo
            ->setTitle($rawData['title'] ?? 'Untitled')
            ->setDescription($rawData['description']['_content'] ?? '');
            
        $photo->thumbnailUrl = sprintf(
            'https://live.staticflickr.com/%s/%s_%s_m.jpg',
            $rawData['server'],
            $id,
            $rawData['secret']
        );
        
        $photo->externalUrl = sprintf('https://www.flickr.com/photos/%s/%s', $rawData['owner'], $id);
        $photo->rawData = $rawData;

        if (isset($rawData['dateupload'])) {
            $photo->publishedAt = new \DateTimeImmutable('@' . $rawData['dateupload']);
        }

        return $photo;
    }
}
