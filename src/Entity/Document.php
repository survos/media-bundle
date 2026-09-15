<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\FieldBundle\Attribute\EntityMeta;

/** A source document (usually a PDF); individual pages are projections of it. */
#[ORM\Entity]
#[EntityMeta(icon: 'mdi:file-document', group: 'Media')]
final class Document extends BaseMedia
{
    public function getType(): string
    {
        return 'document';
    }
}
