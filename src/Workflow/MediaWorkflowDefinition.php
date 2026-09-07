<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Workflow;

use Survos\StateBundle\Attribute\Place;
use Survos\StateBundle\Attribute\Transition;

/**
 * The canonical lifecycle of a local media row's relationship WITH MEDIARY.
 *
 * Deliberately NOT a copy of mediary's AssetFlow. The remote asset has its own graph
 * (new → iiif → archived → informed → ai_ready → analyzed → complete) and that is mediary's
 * business; this models only what WE do about it: have we sent this row, and did the send land.
 * The remote place continues to arrive as data in BaseMedia::$status, rank-guarded on the way in
 * by MediaUpdateApplier because webhooks arrive out of order.
 *
 * That separation is the whole point. Re-modelling mediary's places locally is what produced three
 * hand-rolled reimplementations of the same list — BaseMedia::$status ("until the AssetWorkflow
 * Constants are shared"), MediaUpdateApplier::STATUS_RANK, and harvest's
 * DatasetEnrichGuard::TERMINAL — each in a different repository, each free to drift.
 *
 * NO #[Workflow] ATTRIBUTE HERE, ON PURPOSE. state-bundle's AttributesWorkflowConfigBuilder skips
 * any class without one, so this base never registers itself and never claims an entity. An app
 * subclasses it, adds #[Workflow(supports: [...], name: ...)], and gets the standard mediary
 * interaction for free:
 *
 *     #[Workflow(supports: [Photo::class], name: self::WORKFLOW_NAME)]
 *     final class MediaWorkflow extends MediaWorkflowDefinition {
 *         public const WORKFLOW_NAME = 'media';
 *     }
 *
 * Inheritance works because the builder reads getReflectionConstants() without filtering on the
 * declaring class, so inherited places and transitions are discovered. An app that needs extra
 * steps redeclares the constant it wants to re-point — e.g. giving PLACE_NEW a different `next`
 * to slip a local step in front of the dispatch. Note the tradeoff: a redeclared constant no
 * longer tracks changes made here.
 */
class MediaWorkflowDefinition
{
    /**
     * Registered locally, not yet offered to mediary.
     *
     * `next` is what makes "create a Media row" the only manual step: state-bundle's
     * InitialPlaceKickoffListener collects on postPersist and dispatches on postFlush, so
     * persisting a row in this place queues its own dispatch. postFlush and not postPersist
     * because a TransitionMessage carries only an id — the row has to be committed before a
     * worker can find it.
     */
    #[Place(
        initial: true,
        info: 'registered locally',
        description: 'media row exists here; mediary has not been told about it yet',
        next: [self::TRANSITION_DISPATCH],
    )]
    public const PLACE_NEW = 'new';

    /**
     * Handed to mediary. Whatever it already knew came back on the same response — for an asset
     * it has seen before that is the terminal state, in one round trip, with no webhook involved.
     */
    #[Place(
        info: 'sent to mediary',
        description: 'registered with mediary; awaiting terminal status if it was not already known',
    )]
    public const PLACE_DISPATCHED = 'dispatched';

    /** Mediary has reported a terminal place for this row and it has been applied locally. */
    #[Place(info: 'in sync with mediary', description: 'terminal remote status applied locally')]
    public const PLACE_SYNCED = 'synced';

    /**
     * The dispatch could not be completed.
     *
     * A real place rather than a log line, deliberately. Upstream, AssetWorkflow pops an AI task
     * off the queue, catches the exception, logs it and completes the asset anyway — leaving a
     * failure indistinguishable from a success and, on production, tens of thousands of assets
     * that look finished but were never processed. A listener here should let the exception
     * escape so messenger retries and the row lands somewhere visible.
     */
    #[Place(info: 'dispatch failed', description: 'exhausted retries; needs looking at')]
    public const PLACE_FAILED = 'failed';

    /**
     * Publish this row to mediary.
     *
     * async so state-bundle creates a dedicated `<workflow>.dispatch` transport. That per-message
     * boundary also removes the ceiling the batch path has: dispatching inline holds a Messenger
     * message unacked for the whole HTTP call, and RabbitMQ closes the channel at consumer_timeout
     * (30 minutes), which turns a large run into a redelivery loop rather than an error. Batching
     * belongs in the consumer (Symfony's BatchHandlerInterface keeps per-message ack/nack), not in
     * a command loop.
     */
    #[Transition(
        from: self::PLACE_NEW,
        to: self::PLACE_DISPATCHED,
        async: true,
        info: 'publish to mediary',
        description: 'POST this row to mediary and apply whatever it reports back',
    )]
    public const TRANSITION_DISPATCH = 'dispatch';

    /** Apply a terminal remote status — from the dispatch response, or later from the webhook. */
    #[Transition(
        from: [self::PLACE_DISPATCHED, self::PLACE_SYNCED],
        to: self::PLACE_SYNCED,
        info: 'apply remote status',
        description: 'mediary reported a terminal place; the local row now reflects it',
    )]
    public const TRANSITION_SYNC = 'sync';

    /** Give a dead dispatch somewhere to rest, so it is countable instead of only loggable. */
    #[Transition(
        from: [self::PLACE_NEW, self::PLACE_DISPATCHED],
        to: self::PLACE_FAILED,
        info: 'dispatch failed',
        description: 'retries exhausted',
    )]
    public const TRANSITION_FAIL = 'fail';
}
