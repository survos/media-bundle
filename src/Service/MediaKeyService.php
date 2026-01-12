<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Service;

use InvalidArgumentException;

use function base64_encode;
use function rtrim;
use function strtr;
use function trim;

final class MediaKeyService
{
    public function keyFromString(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('Value must not be empty.');
        }

        return rtrim(
            strtr(base64_encode($value), '+/', '-_'),
            '='
        );
    }
}
