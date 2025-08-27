<?php

namespace Survos\MediaBundle\Provider;

use Survos\MediaBundle\Entity\BaseMedia;
use Survos\MediaBundle\Entity\Video;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class YouTubeProvider extends AbstractProvider
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        array $config = []
    ) {
        parent::__construct($config);
    }

    public function getName(): string
    {
        return 'youtube';
    }

    public function supports(string $type): bool
    {
        return $type === 'video';
    }

    public function fetchAll(array $options = []): iterable
    {
        $channelId = $options['channelId'] ?? $this->config['default_channel'] ?? null;
        if (!$channelId) {
            throw new \InvalidArgumentException('channelId is required for YouTube sync');
        }

        $nextToken = '';
        do {
            $url = sprintf(
                'https://www.googleapis.com/youtube/v3/search?part=id,snippet&type=video&maxResults=50&channelId=%s&key=%s&pageToken=%s',
                $channelId,
                $this->config['api_key'],
                $nextToken
            );

            $response = $this->httpClient->request('GET', $url);
            $data = $response->toArray();

            foreach ($data['items'] as $item) {
                yield $this->normalize($item);
            }

            $nextToken = $data['nextPageToken'] ?? '';
        } while ($nextToken);
    }

    public function fetchById(string $id): ?BaseMedia
    {
        $url = sprintf(
            'https://www.googleapis.com/youtube/v3/videos?part=snippet,statistics&id=%s&key=%s',
            $id,
            $this->config['api_key']
        );

        $response = $this->httpClient->request('GET', $url);
        $data = $response->toArray();

        if (empty($data['items'])) {
            return null;
        }

        return $this->normalize($data['items'][0]);
    }

    public function normalize(array $rawData): BaseMedia
    {
        $id = $rawData['id']['videoId'] ?? $rawData['id'];
        $snippet = $rawData['snippet'];
        
        /** @var Video $video */
        $video = $this->createMedia('video', $id);
        
        $video
            ->setTitle($snippet['title'])
            ->setDescription($snippet['description']);
        
        $video->thumbnailUrl = $snippet['thumbnails']['high']['url'] ?? $snippet['thumbnails']['default']['url'];
        $video->externalUrl = "https://www.youtube.com/watch?v={$id}";
        $video->rawData = $rawData;

        if (isset($snippet['publishedAt'])) {
            $video->publishedAt = new \DateTimeImmutable($snippet['publishedAt']);
        }

        if (isset($rawData['statistics'])) {
            $video->viewCount = (int) $rawData['statistics']['viewCount'];
            $video->likeCount = (int) ($rawData['statistics']['likeCount'] ?? 0);
        }

        if (isset($snippet['tags'])) {
            $video->tags = $snippet['tags'];
        }

        return $video;
    }
}
