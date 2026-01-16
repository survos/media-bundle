<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Service;

use Survos\MediaBundle\Dto\BatchDispatchResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use RuntimeException;

final class MediaBatchDispatcher
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(MEDIARY_ENDPOINT)%')] private readonly string $mediaServerBaseUrl,
    ) {
    }

    public function dispatch(string $client, array $urls): BatchDispatchResult
    {
        $options = [
            'json' => [
                'urls' => $urls,
                'dispatch' => true,
            ],
        ];

        if (str_contains($this->mediaServerBaseUrl, '.wip')) {
            $options['proxy'] = 'http://127.0.0.1:7080';
        }

        // we need a better way to define the endpoint!

        $response = $this->httpClient->request(
            'POST',
            $url = sprintf('%s/%s/batch', rtrim($this->mediaServerBaseUrl, '/'), $client),
            $options
        );

        $status = $response->getStatusCode();
        if ($status !== 200) {
            dump($url . '?url=' . $urls[0]);
            throw new RuntimeException('Media server batch call failed. ' . $status);
        }

        return BatchDispatchResult::fromArray($response->toArray());
    }
}
