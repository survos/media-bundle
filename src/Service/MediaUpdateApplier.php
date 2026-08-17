<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\MediaBundle\Dto\MediaUpdate;
use Survos\MediaBundle\Entity\BaseMedia;
use Survos\MediaBundle\Event\MediaUpdatedEvent;
use Survos\MediaBundle\Repository\MediaRepository;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * THE single write path for mediary-derived state on the local media row.
 *
 * Two callers, one implementation, deliberately:
 *
 *   media:sync            calls this DIRECTLY with mediary's batch response —
 *                         synchronous, no queue, so a failure surfaces in the
 *                         command output instead of a worker log.
 *   MediaUpdatedMessage   the async callback path, which does nothing but hand
 *                         the same blob to the same method.
 *
 * Because both consume the identical blob shape (MediaUpdate), the debuggable
 * path and the production path cannot diverge. The previous arrangement — an
 * inline upsert in MediaRepository for sync, and nothing at all for callbacks —
 * is how md ended up with 26,244 rows holding an origin URL and no dimensions.
 *
 * Idempotent: redelivery and re-sync are both normal. Every field is either
 * immutable once set (storageKey/s3Url/dimensions derive from content) or
 * rank-guarded (status), so applying twice is a no-op.
 */
final class MediaUpdateApplier
{
    /**
     * Status progression. A late-arriving earlier status must not clobber a
     * later one — neither queues nor a re-sync promise ordering. Unknown
     * statuses rank 0 and are always accepted, so a new mediary place cannot
     * deadlock us.
     */
    private const STATUS_RANK = [
        'new' => 1,
        'iiif' => 2,
        'archived' => 3,
        'informed' => 4,
        'ai_ready' => 5,
        'analyzed' => 6,
        'complete' => 7,
    ];

    public function __construct(
        private readonly EntityManagerInterface   $em,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LoggerInterface          $logger,
    ) {
    }

    /**
     * MediaRepository extends EntityRepository, not ServiceEntityRepository, so
     * it is not autowirable — it only exists via the entity manager. Resolved
     * per call rather than in the constructor because a repository handle does
     * not survive em->clear(), and the batch path clears.
     */
    private function repository(): MediaRepository
    {
        /** @var MediaRepository */
        return $this->em->getRepository(BaseMedia::class);
    }

    /**
     * @return string[] names of fields that actually changed (empty = no-op)
     */
    public function apply(MediaUpdate $update, bool $flush = true): array
    {
        if ($update->originalUrl === '') {
            $this->logger->warning('media update: no originalUrl, dropping');
            return [];
        }

        $key = $update->mediaKey();
        $media = $this->repository()->find($key);

        if (!$media) {
            // Broadcast model: mediary serves many clients and cannot know which
            // care about a given asset. "Not ours" is a normal outcome, NOT an
            // error — throwing here would make mediary retry and replay forever
            // on behalf of an app that never asked for it.
            $this->logger->info('media update: no local row for {key} ({url}), ignoring', [
                'key' => $key,
                'url' => $update->originalUrl,
            ]);
            return [];
        }

        if ($problem = $update->storageKeyProblem()) {
            // Warn, never reject: legacy `orig/` sha256 keys are expected until
            // the reversible base64 scheme is rolled out.
            $this->logger->warning('media update: storage key inconsistent for {key}: {problem}', [
                'key' => $key,
                'problem' => $problem,
                'legacy' => $update->isLegacyStorageKey(),
            ]);
        }

        $changed = [];

        // Set-if-provided. A null means "no news", not "clear it" — a partial
        // notification must never erase state we already have.
        foreach ([
            'storageKey' => $update->storageKey
                ?? MediaUpdate::storageKeyFromArchiveUrl($update->s3Url),
            's3Url'    => $update->s3Url,
            'smallUrl' => $update->smallUrl,
            // effective* fall back to /info, which covers far more assets than
            // mediary's promoted columns do.
            'mimeType' => $update->effectiveMime(),
            'width'    => $update->effectiveWidth(),
            'height'   => $update->effectiveHeight(),
            // Stored whole. Faces and average colour are read off it via
            // property hooks rather than shredded into columns — they're
            // imgproxy-shaped bonus data, not a general contract.
            'info'     => $update->info?->raw,
        ] as $field => $value) {
            if ($value === null || !property_exists($media, $field)) {
                continue;
            }
            if ($media->$field !== $value) {
                $media->$field = $value;
                $changed[] = $field;
            }
        }

        if ($update->status !== null && $this->isForwardProgress($media->status ?? null, $update->status)) {
            $media->status = $update->status;
            $changed[] = 'status';
        }

        if ($changed === []) {
            return [];
        }

        if ($flush) {
            $this->em->flush();
        }

        $this->logger->info('media update: {key} [{fields}]', [
            'key' => $key,
            'fields' => implode(',', $changed),
        ]);

        // App-specific propagation (ssai Item, harvest Img, folio reindex) hangs
        // off this, so mediary never learns any app's entity shape.
        $this->dispatcher->dispatch(new MediaUpdatedEvent($media, $update, $changed));

        return $changed;
    }

    /**
     * Apply a whole mediary batch response (`{"media":[...]}`) in one go.
     * Flushes once at the end rather than per row.
     *
     * @return array{applied:int, changed:int, skipped:int}
     */
    public function applyBatch(array $rows, bool $flush = true): array
    {
        $applied = $changed = $skipped = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                $skipped++;
                continue;
            }
            $fields = $this->apply(MediaUpdate::fromBatchRow($row), flush: false);
            $applied++;
            if ($fields !== []) {
                $changed++;
            }
        }
        if ($flush) {
            $this->em->flush();
        }
        return ['applied' => $applied, 'changed' => $changed, 'skipped' => $skipped];
    }

    private function isForwardProgress(?string $current, string $incoming): bool
    {
        if ($current === null) {
            return true;
        }
        if ($current === $incoming) {
            return false;
        }
        return (self::STATUS_RANK[$incoming] ?? 0) >= (self::STATUS_RANK[$current] ?? 0);
    }
}
