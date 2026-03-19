<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Interface;

/**
 * Marks an entity as carrying enrich_from_thumbnail results.
 * Use HasEnrichmentTrait to get the column + computed getters automatically.
 */
interface EnrichmentInterface
{
    public function getEnrichment(): array;
}
