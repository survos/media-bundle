<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Twig\Components;

use Survos\MediaBundle\Dto\MediaSyncItem;
use Survos\MediaBundle\Interface\MediaSyncInterface;
use Survos\IiifBundle\Service\IiifUrl;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Generic show-page component for a media-bearing entity.
 *
 * The entity only has to expose getSourceMeta(): array. getMediaSync() is used
 * when it also implements MediaSyncInterface. It used to be typed as
 * EnrichmentInterface, but that interface is deprecated as of 2.1 -- requiring
 * it here meant an app could not act on its own deprecation warning without
 * this component rejecting the entity with a TypeError.
 *
 * Renders: image preview, claims-driven tabs, source metadata, and task controls.
 *
 * App-specific content (workflow strip, tags, etc.) goes in the
 * {% block app_header %} and {% block app_sections %} slots via
 * anonymous component embedding with blocks.
 *
 * Usage (ssai):
 *   {% component 'MediaShow' with {
 *       entity: image,
 *       imageUrl: display_url,
 *       iiifInfoUrl: iiif_info_url,
 *       iiifBase: media_sync.iiifBase ?? null,
 *       taskRoute: 'image_run_task',
 *       pipelineRoute: 'image_enqueue_pipeline',
 *       routeParams: image.rp,
 *   } %}
 *     {% block app_header %}...workflow strip...{% endblock %}
 *     {% block app_sections %}...tags, OCR columns...{% endblock %}
 *   {% endcomponent %}
 *
 * Usage (media):
 *   {% component 'MediaShow' with {
 *       entity: asset,
 *       imageUrl: zoomImageUrl,
 *       taskRoute: 'asset_run_task',
 *       pipelineRoute: 'asset_enqueue_pipeline',
 *       routeParams: {id: asset.id},
 *   } %}{% endcomponent %}
 */
#[AsTwigComponent('MediaShow', template: '@SurvosMedia/components/MediaShow.html.twig')]
final class MediaShow
{
    /** The entity — must expose getSourceMeta(). MediaSyncInterface is optional. */
    public object $entity;

    /** Pre-resolved proxied/resized image URL (controller should build this). */
    public ?string $imageUrl = null;

    /** Local IIIF info.json URL (our own tile endpoint). Takes priority over iiifBase. */
    public ?string $iiifInfoUrl = null;

    /** External IIIF base URL (e.g. from MediaSyncItem.iiifBase). */
    public ?string $iiifBase = null;

    /** Route name for running a single AI task (kept for compat, unused in tabs). */
    public string $taskRoute = '';

    /** @deprecated Pipeline tab removed; kept to avoid breaking callers. */
    public string $pipelineRoute = '';

    /** Base route params for taskRoute / pipelineRoute. */
    public array $routeParams = [];

    /** Symfony workflow name — when set, a Workflow tab is rendered. */
    public string $workflowCode = '';

    /** APP_ENTITY_* global key for the state-debug transitions link. */
    public string $globalKey = '';

    /** Image alt text. */
    public string $imageAlt = '';

    /** Optional tab to activate on load (e.g. 'ocr', 'source_metadata'). */
    public string $activeTab = 'ocr';

    // ── Derived helpers ──────────────────────────────────────────────────────

    public function mediaSync(): ?MediaSyncItem
    {
        if ($this->entity instanceof MediaSyncInterface) {
            return $this->entity->getMediaSync();
        }
        return null;
    }

    public function sourceMeta(): array
    {
        return method_exists($this->entity, 'getSourceMeta') ? $this->entity->getSourceMeta() : [];
    }

    /** True when there's anything to show in the image card. */
    public function hasImage(): bool
    {
        return $this->iiifInfoUrl !== null
            || $this->iiifBase !== null
            || $this->imageUrl !== null;
    }

    /** Best "open full size" URL — IIIF max (real endpoints) or the source image. */
    public function fullSizeUrl(): ?string
    {
        if ($this->iiifBase !== null) {
            return IiifUrl::imageUrl($this->iiifBase);
        }
        return $this->imageUrl;
    }
}
