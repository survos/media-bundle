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

    /**
     * URLs mediary refused because they are not fetchable over HTTP.
     *
     * Almost always source data holding an identifier where an image URL was
     * expected — Smithsonian EDAN ids being the case that surfaced this. Worth
     * showing rather than swallowing: a short media[] with no explanation reads
     * as "some rows already existed".
     *
     * @var list<string>
     */
    public array $rejected = [];

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
        $self->rejected = array_values(array_filter(
            (array) ($data['rejected'] ?? []),
            static fn ($u): bool => is_string($u),
        ));

        return $self;
    }
}
