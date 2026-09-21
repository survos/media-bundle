<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Survos\MediaBundle\Dto\MediaProbeResult;
use Survos\MediaBundle\Dto\MediaUpdate;
use Survos\MediaBundle\Entity\BaseMedia;
use Survos\MediaBundle\Service\MediaBatchDispatcher;
use Survos\MediaBundle\Service\MediaUpdateApplier;
use Survos\MediaBundle\Workflow\MediaWorkflowDefinition;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

use function array_chunk;
use function array_column;
use function array_diff;
use function array_keys;
use function array_map;
use function array_merge;
use function array_slice;
use function array_values;
use function ceil;
use function count;
use function implode;
use function ksort;
use function max;
use function sprintf;

/**
 * RECOVERY ONLY. Nothing should need this on a healthy day.
 *
 * Mediary pushes each asset's state back over a signed webhook, and
 * {@see \Survos\MediaBundle\RemoteEvent\MediaRemoteEventConsumer} applies it. When the
 * callback cannot be delivered — the laptop was shut down, the tunnel was down, podman was
 * not started after a reboot, the hostname moved — mediary retries, gives up, and parks the
 * message in its failed transport. The work itself is finished and stored on mediary; only
 * the notification is lost, so the local row keeps whatever status it last heard.
 *
 * This command closes that gap by asking instead of waiting: it probes mediary for the rows
 * we sent and applies the answer through the same {@see MediaUpdateApplier} the callback
 * uses, so a reconcile writes exactly what the missed webhook would have written.
 *
 * Why probe and not re-dispatch. Re-dispatching the URL also returns current state inline
 * (see MediaWorkflow::onDispatch in harvest), and that is the right move for a row we never
 * successfully sent. But it is a write: it re-registers the asset, re-sends source context,
 * and can re-queue AI tasks. These rows were accepted already — we are only missing the
 * news — so the read path is the honest one.
 *
 * Scope: rows in marking `dispatched` (we sent them) whose reflected `status` has not
 * reached a terminal place. `marking` is ours and `status` is mediary's; see BaseMedia.
 */
final class ReconcileMediaCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MediaBatchDispatcher   $dispatcher,
        private readonly MediaUpdateApplier     $applier,
    ) {
    }

    #[AsCommand(
        'media:reconcile',
        '[recovery] Pull current state from mediary for dispatched rows whose callback never arrived'
    )]
    public function __invoke(
        SymfonyStyle $io,
        #[Option('Only this dataset, e.g. omeka/wej')] ?string $dataset = null,
        #[Option('Only this media id (16-hex), for debugging one row')] ?string $media = null,
        #[Option('Stop after this many rows (0 = all)')] int $limit = 0,
        #[Option('Ids per probe call; a probe row carries OCR and AI output, so keep it small')] int $chunk = 25,
        #[Option('Include rows whose status is already terminal')] bool $all = false,
        #[Option('Probe and report, write nothing')] bool $dryRun = false,
    ): int {
        $ids = $media !== null ? [$media] : $this->candidates($dataset, $limit, $all);

        if ($ids === []) {
            $io->success('Nothing to reconcile.');

            return Command::SUCCESS;
        }

        $io->writeln(sprintf(
            '%d row%s to probe%s%s',
            count($ids),
            count($ids) === 1 ? '' : 's',
            $dataset !== null ? ' in ' . $dataset : '',
            $dryRun ? ' (dry run)' : ''
        ));

        $totals   = ['applied' => 0, 'changed' => 0, 'skipped' => 0, 'unmatched' => 0];
        $missing  = [];
        $timedOut = [];
        $remote   = [];

        $io->progressStart(count($ids));
        foreach (array_chunk($ids, max(1, $chunk)) as $batch) {
            $results = $this->probe($batch, $timedOut);

            // probeMany drops ids mediary has never seen. Silence there would read as
            // "nothing to do" for a row that is in fact orphaned locally, so count them.
            $answered = array_column($results, 'id');
            $missing  = array_merge(
                $missing,
                array_values(array_diff($batch, $answered, $timedOut))
            );

            $updates = [];
            foreach ($results as $result) {
                $remote[$result->marking ?? 'unknown'] = ($remote[$result->marking ?? 'unknown'] ?? 0) + 1;
                $updates[] = MediaUpdate::fromProbeRow($result->raw);
            }

            if (!$dryRun) {
                foreach ($this->applier->applyUpdates($updates) as $key => $value) {
                    $totals[$key] += $value;
                }
            }

            $io->progressAdvance(count($batch));
        }
        $io->progressFinish();

        ksort($remote);
        $io->table(
            ['mediary says', 'rows'],
            array_map(static fn (string $k, int $v): array => [$k, $v], array_keys($remote), $remote)
        );

        if ($timedOut !== []) {
            $io->warning(sprintf(
                '%d id%s could not be probed before the client timed out, even alone: %s',
                count($timedOut),
                count($timedOut) === 1 ? '' : 's',
                implode(', ', array_slice($timedOut, 0, 10)) . (count($timedOut) > 10 ? ' …' : '')
            ));
        }

        if ($missing !== []) {
            $io->warning(sprintf(
                '%d id%s unknown to mediary (never registered, or registered under a different identity): %s',
                count($missing),
                count($missing) === 1 ? '' : 's',
                implode(', ', array_slice($missing, 0, 10)) . (count($missing) > 10 ? ' …' : '')
            ));
        }

        if ($dryRun) {
            $io->note('Dry run: no local row was written.');

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'applied %d, changed %d, unmatched %d',
            $totals['applied'],
            $totals['changed'],
            $totals['unmatched']
        ));

        if ($totals['unmatched'] > 0) {
            // Every id came out of our own table, so a row mediary answered for that no longer
            // resolves locally means the identity changed under us — worth knowing, not fatal.
            $io->warning('Some answers did not match a local row; their originalUrl no longer hashes to a row here.');
        }

        return Command::SUCCESS;
    }

    /**
     * Probe a chunk, halving it whenever the client gives up waiting.
     *
     * A probe row carries the asset's whole context — OCR text, AI output, children — so a
     * chunk of assets with long OCR can exceed the HTTP client's idle timeout while a chunk
     * of plain photographs of the same size returns instantly. Splitting on timeout adapts to
     * the data instead of making the operator guess a chunk size per dataset. An id that
     * times out on its own is recorded and skipped rather than failing the run: this is a
     * recovery command, and the other 2,000 rows should still get their state.
     *
     * @param list<string> $ids
     * @param list<string> $timedOut collects ids that failed alone
     * @return list<MediaProbeResult>
     */
    private function probe(array $ids, array &$timedOut): array
    {
        try {
            return $this->dispatcher->probeMany($ids);
        } catch (TransportExceptionInterface) {
            if (count($ids) === 1) {
                $timedOut[] = $ids[0];

                return [];
            }

            $half = (int) ceil(count($ids) / 2);

            return array_merge(
                $this->probe(array_slice($ids, 0, $half), $timedOut),
                $this->probe(array_slice($ids, $half), $timedOut),
            );
        }
    }

    /**
     * @return list<string>
     */
    private function candidates(?string $dataset, int $limit, bool $all): array
    {
        $qb = $this->entityManager->getRepository(BaseMedia::class)
            ->createQueryBuilder('m')
            ->select('m.id')
            ->where('m.marking = :dispatched')
            ->setParameter('dispatched', MediaWorkflowDefinition::PLACE_DISPATCHED)
            ->orderBy('m.id', 'ASC');

        if (!$all) {
            $qb->andWhere('m.status NOT IN (:terminal)')
                ->setParameter('terminal', MediaUpdateApplier::TERMINAL_STATUSES);
        }

        if ($dataset !== null) {
            $qb->andWhere('m.dataset = :dataset')->setParameter('dataset', $dataset);
        }

        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        return array_map(static fn (array $row): string => (string) $row['id'], $qb->getQuery()->getArrayResult());
    }
}
