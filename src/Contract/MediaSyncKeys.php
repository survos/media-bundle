<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Contract;

/**
 * Keys of the media:sync batch protocol — the contract between the producer
 * (MediaBatchDispatcher, fed by media:ensure) and the consumer (mediary's
 * batch endpoint). Defined once here so writer and reader can never drift.
 *
 * The batch payload is: {client, urls, dispatch, context, sourceClaims}, where
 * `context` and `sourceClaims` are keyed by image URL. DATASET and RECORD_KEY
 * live inside a URL's context entry; SOURCE_CLAIMS is its own top-level map.
 */
final class MediaSyncKeys
{
    /** Top-level payload map: URL => list<ClaimDTO> (modeled @import source claims). */
    public const string SOURCE_CLAIMS = 'sourceClaims';

    /** Context key: dataset id (provider/code, e.g. "mus/fortepan") — the claim scope. */
    public const string DATASET = 'dataset';

    /** Context key: item grouping id — becomes the mediary MediaRecord the claims hang on. */
    public const string RECORD_KEY = 'media_record_key';

    private function __construct()
    {
    }
}
