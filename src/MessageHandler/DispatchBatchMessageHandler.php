<?php
declare(strict_types=1);

namespace Survos\MediaBundle\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Survos\MediaBundle\Entity\BaseMedia;
use Survos\MediaBundle\Message\DispatchBatchMessage;
use Survos\MediaBundle\Repository\MediaRepository;
use Survos\MediaBundle\Service\MediaBatchDispatcher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Psr\Log\LoggerInterface;

#[AsMessageHandler]
final class DispatchBatchMessageHandler
{
    public function __construct(
        private readonly MediaBatchDispatcher   $dispatcher,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface        $logger,
    ) {}

    public function __invoke(DispatchBatchMessage $message): void
    {
        try {
            $extra = $message->contextMap !== []
                ? ['context' => $message->contextMap]
                : [];

            $result = $this->dispatcher->dispatch($message->client, $message->urls, $extra);

            if (!$message->uploadOnly) {
                /** @var MediaRepository $repo */
                $repo = $this->em->getRepository(BaseMedia::class);
                $repo->upsertFromBatchResult($result);
                $this->em->flush();
            }

            $this->logger->info('Media batch dispatched', [
                'client' => $message->client,
                'count'  => count($message->urls),
            ]);

        } catch (\Throwable $e) {
            // Log and swallow — don't let one bad batch kill the worker.
            // The URLs remain in the DB with status=new for the next sync run.
            $this->logger->error('Media batch dispatch failed', [
                'client' => $message->client,
                'count'  => count($message->urls),
                'error'  => $e->getMessage(),
            ]);
            // Re-throw transport errors (network down) so Messenger retries.
            // Don't re-throw 4xx responses — they're permanent failures.
            if (!($e instanceof \Symfony\Component\HttpClient\Exception\TransportException)) {
                throw $e;
            }
        }
    }
}
