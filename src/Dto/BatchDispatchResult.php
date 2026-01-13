<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Dto;

final class BatchDispatchResult
{
    /** @var MediaRegistration[] */
    public array $media = [];

    public static function fromArray(array $data): self
    {
        $self = new self();
        foreach ($data['media'] as $row) {
            $self->media[] = MediaRegistration::fromArray($row);
        }
        return $self;
    }
}
