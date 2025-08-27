<?php

namespace Survos\MediaBundle\Provider;

use Survos\MediaBundle\Entity\BaseMedia;

interface ProviderInterface
{
    public function getName(): string;
    public function supports(string $type): bool;
    public function fetchAll(array $options = []): iterable;
    public function fetchById(string $id): ?BaseMedia;
    public function normalize(array $rawData): BaseMedia;
}
