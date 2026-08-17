<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Dto;

final class BatchDispatchResult
{
    /** @var MediaRegistration[] */
    public array $media = [];

    /**
     * The response rows exactly as mediary sent them.
     *
     * MediaRegistration is a fixed six-field projection, so anything mediary
     * adds to the batch response (width/height/mime, and whatever comes next)
     * would be silently dropped before MediaUpdate ever saw it. Keeping the raw
     * rows means the batch path and the webhook path can converge on the same
     * payload without this DTO needing a change for every new field.
     *
     * @var list<array<string,mixed>>
     */
    public array $rows = [];

    public static function fromArray(array $data): self
    {
        $self = new self();
        foreach ($data['media'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $self->rows[]   = $row;
            $self->media[]  = MediaRegistration::fromArray($row);
        }
        return $self;
    }
}
