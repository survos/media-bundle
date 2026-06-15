<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Service;

use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * An AI-task result stored as an S3 sidecar next to the media it describes.
 *
 * Given a media id (e.g. BaseMedia::$id) and a task name, the sidecar is a JSON
 * file `<id>.<task>.json` on the shared bucket — e.g. `<id>.mistral_ocr.json`.
 * The path is sharded by media-bundle's own {@see MediaKeyService::archivePathFromKey()};
 * this class never invents an id or hash. The described media (a jpg or pdf) need
 * not itself be on S3.
 *
 * It is the cache for an AI task: {@see remember()} returns the existing sidecar
 * if present, otherwise runs the producer (the actual, paid AI call), writes the
 * result as the sidecar, and returns it. Because the sidecar lives on the shared
 * bucket, "run locally" == "available everywhere" with no sync step.
 *
 * The $storage operator is app-wired (md/mediary point it at the same bucket).
 */
final class SidecarService
{
    /**
     * @param ?FilesystemOperator $storage the shared archive bucket; null when no
     *        such storage is installed/configured — methods that touch it then throw.
     */
    public function __construct(
        private readonly ?FilesystemOperator $storage = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly string $prefix = 'o',
    ) {}

    /** True when an archive storage is wired (so callers can guard before running tasks). */
    public function isAvailable(): bool
    {
        return $this->storage !== null;
    }

    /** Storage key for the sidecar: <prefix>/a/bb/<id>.<task>.json (no storage needed). */
    public function path(string $id, string $task): string
    {
        return MediaKeyService::archivePathFromKey($id, $task . '.json', $this->prefix);
    }

    public function exists(string $id, string $task): bool
    {
        return $this->fs()->fileExists($this->path($id, $task));
    }

    /**
     * Read the sidecar if it exists, else null.
     *
     * @return array<string, mixed>|null
     */
    public function read(string $id, string $task): ?array
    {
        $path = $this->path($id, $task);
        $fs = $this->fs();
        if (!$fs->fileExists($path)) {
            return null;
        }

        return json_decode($fs->read($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Cache-aside for an AI task. Returns the sidecar if present; otherwise runs
     * $producer (the paid AI call), persists its result as the sidecar, returns it.
     *
     * @param callable():array<string, mixed> $producer
     *
     * @return array<string, mixed>
     */
    public function remember(string $id, string $task, callable $producer, bool $force = false): array
    {
        $fs = $this->fs();
        $path = $this->path($id, $task);

        if (!$force && $fs->fileExists($path)) {
            $this->logger->info('sidecar hit [{task}] {path}', ['task' => $task, 'path' => $path]);

            return json_decode($fs->read($path), true, 512, JSON_THROW_ON_ERROR);
        }

        $this->logger->info('sidecar miss [{task}] {path} — running producer', ['task' => $task, 'path' => $path]);
        $data = $producer();

        $fs->write(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        return $data;
    }

    private function fs(): FilesystemOperator
    {
        return $this->storage
            ?? throw new \RuntimeException('No archive storage configured (flysystem "archive.storage"); cannot read/write the AI-task sidecar.');
    }
}
