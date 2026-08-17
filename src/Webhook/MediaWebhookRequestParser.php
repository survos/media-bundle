<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Webhook;

use Survos\Kit\Webhook\AbstractJsonWebhookParser;

/**
 * Authenticates mediary's `asset.analyzed` deliveries at `/webhook/mediary`.
 *
 * Everything interesting is in {@see AbstractJsonWebhookParser} — signature verification, the
 * fail-closed empty-secret rule, and the "authenticated but not for me is a 200" broadcast rule.
 * What is left here is the two facts that are specific to mediary.
 *
 * This replaced a hand-rolled `MediaCallbackController` at `/media/callback` that had NO
 * authentication of any kind: anyone who could reach the URL could POST an `originalUrl` plus an
 * `s3Url`/`storageKey`/`width` and rewrite the matching media row, because
 * {@see \Survos\MediaBundle\Service\MediaUpdateApplier} derives the row key from the URL and
 * writes. See survos-sites/mediary#8.
 *
 * Configure in the consuming app:
 *
 *     framework:
 *         webhook:
 *             routing:
 *                 mediary:
 *                     service: Survos\MediaBundle\Webhook\MediaWebhookRequestParser
 *                     secret: '%env(MEDIARY_WEBHOOK_SECRET)%'
 */
final class MediaWebhookRequestParser extends AbstractJsonWebhookParser
{
    /** Also the `#[AsRemoteEventConsumer]` key — see {@see \Survos\MediaBundle\RemoteEvent\MediaRemoteEventConsumer}. */
    public const string WEBHOOK_NAME = 'mediary';

    /** mediary's only event today. Listed so a future one cannot reach a consumer that predates it. */
    public const string EVENT_ASSET_ANALYZED = 'asset.analyzed';

    protected function webhookName(): string
    {
        return self::WEBHOOK_NAME;
    }

    protected function acceptedEvents(): array
    {
        return [self::EVENT_ASSET_ANALYZED];
    }
}
