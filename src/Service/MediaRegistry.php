<?php
declare(strict_types=1);

namespace Survos\MediaBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use RuntimeException;
use Survos\MediaBundle\Entity\BaseMedia;
use Survos\MediaBundle\Entity\Photo;
use Survos\MediaBundle\Util\MediaIdentity;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use SplFileInfo;
use function is_string;
use function trim;

final class MediaRegistry
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function ensureMedia(
        string|UploadedFile|SplFileInfo $source,
        ?string $class = null,
        bool $flush = true
    ): BaseMedia {
        $class ??= Photo::class;

        if (!is_subclass_of($class, BaseMedia::class)) {
            throw new InvalidArgumentException(sprintf('Class "%s" must extend %s.', $class, BaseMedia::class));
        }

        if (is_string($source)) {
            $url = trim($source);
            if ($url === '') {
                throw new InvalidArgumentException('Media URL must not be empty.');
            }

            $repository = $this->entityManager->getRepository(BaseMedia::class);
            $id = MediaIdentity::idFromOriginalUrl($url);
            $existing = $repository->find($id);
            if ($existing instanceof BaseMedia) {
                return $existing;
            }

            /** @var BaseMedia $media */
            $media = new $class($id);
            $media->externalUrl = $url;

            $this->entityManager->persist($media);

            if ($flush) {
                $this->entityManager->flush();
            }

            return $media;
        }

        if ($source instanceof UploadedFile || $source instanceof SplFileInfo) {
            if (!$source->isReadable()) {
                throw new RuntimeException('Local media file is not readable.');
            }

            $pseudoUrl = 'local://' . $source->getBasename();
            $id = MediaIdentity::idFromOriginalUrl($pseudoUrl);

            /** @var BaseMedia $media */
            $media = new $class($id);
            $media->provider = null;
            $media->externalId = null;

            $this->entityManager->persist($media);

            if ($flush) {
                $this->entityManager->flush();
            }

            return $media;
        }

        throw new InvalidArgumentException('Unsupported media source type.');
    }


}
