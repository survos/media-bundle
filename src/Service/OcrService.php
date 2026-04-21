<?php

declare(strict_types=1);

namespace Survos\MediaBundle\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Calls the ai-tools analysis service (ex-edge) to OCR an image and return
 * richer analysis at the same time (document/photo/text-type flags).
 *
 * Three input modes, in order of preference:
 *   1. shared-volume FILENAME — zero upload; ai-tools reads from its
 *      SCANSTATION_SHARED_IMAGE_DIR. Use this when ssai + ai-tools mount the
 *      same storage (local dev, deployed with shared NFS/volume).
 *   2. REMOTE URL — ai-tools fetches the image itself. One hop. Best for
 *      bulk imports (IIIF, S3, institution URLs) — never downloads to the
 *      client.
 *   3. LOCAL FILE multipart UPLOAD — fallback when neither a shared volume
 *      nor a public URL is available.
 *
 * Configuration:
 *   AI_TOOLS_HOST — base URL, e.g. http://127.0.0.1:8884 or
 *                   https://ai-tools.survos.com (no trailing slash)
 */
final class OcrService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default::AI_TOOLS_HOST)%')] private readonly ?string $aiToolsHost = null,
        private readonly int $httpTimeoutSeconds = 120,
    ) {}

    /**
     * Run ai-tools analysis with OCR. Exactly one of $filename, $url, $localFile must be set.
     *
     * @return array{
     *     text: ?string,             # Tesseract-extracted text (trimmed), or null
     *     analysis: array<string,mixed>,  # full /analyze/type response for richer flags
     *     durationMs: int,           # wall-clock of the HTTP call
     *     error: ?string             # human-readable reason when text === null
     * }
     */
    public function extract(
        ?string $filename = null,
        ?string $url = null,
        ?string $localFile = null,
        string $lang = 'eng',
    ): array {
        $start = microtime(true);

        $host = rtrim((string) $this->aiToolsHost, '/');
        if ($host === '') {
            return $this->failure('AI_TOOLS_HOST not configured', $start);
        }

        $provided = array_filter([$filename, $url, $localFile], static fn($v) => $v !== null && $v !== '');
        if (count($provided) !== 1) {
            return $this->failure('pass exactly one of filename, url, localFile', $start);
        }

        try {
            if ($localFile !== null) {
                $response = $this->postUpload($host, $localFile, $lang);
            } else {
                $response = $this->postJson($host, [
                    'filename'         => $filename,
                    'url'              => $url,
                    'include_ocr_text' => true,
                    'run_ocr'          => true,
                    'lang'             => $lang,
                ]);
            }

            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                return $this->failure(sprintf('HTTP %d from %s', $status, $host), $start);
            }

            $body = $response->toArray(false);
            $text = $body['ocr']['text'] ?? null;
            if (!is_string($text)) {
                $text = null;
            } else {
                $text = trim($text);
                if ($text === '') {
                    $text = null;
                }
            }

            return [
                'text'       => $text,
                'analysis'   => $body,
                'durationMs' => $this->elapsed($start),
                'error'      => null,
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('OcrService: {err}', ['err' => $e->getMessage()]);
            return $this->failure($e->getMessage(), $start);
        }
    }

    /** @param array<string,mixed> $payload */
    private function postJson(string $host, array $payload): object
    {
        return $this->httpClient->request('POST', $host . '/analyze/type', [
            'json'    => array_filter($payload, static fn($v) => $v !== null && $v !== ''),
            'timeout' => $this->httpTimeoutSeconds,
        ]);
    }

    private function postUpload(string $host, string $localFile, string $lang): object
    {
        if (!is_file($localFile) || !is_readable($localFile)) {
            throw new \RuntimeException('file not readable: ' . basename($localFile));
        }

        // DataPart::fromPath lazily opens the file and keeps it readable for
        // the whole request lifecycle — we don't manage the handle ourselves
        // so Symfony HttpClient's rewind-on-retry works.
        $form = new FormDataPart([
            'file'             => DataPart::fromPath($localFile),
            'include_ocr_text' => '1',
            'run_ocr'          => '1',
            'lang'             => $lang,
        ]);

        return $this->httpClient->request('POST', $host . '/analyze/upload', [
            'headers' => $form->getPreparedHeaders()->toArray(),
            'body'    => $form->bodyToIterable(),
            'timeout' => $this->httpTimeoutSeconds,
        ]);
    }

    /** @return array{text: null, analysis: array<string,mixed>, durationMs: int, error: string} */
    private function failure(string $reason, float $start): array
    {
        return [
            'text'       => null,
            'analysis'   => [],
            'durationMs' => $this->elapsed($start),
            'error'      => $reason,
        ];
    }

    private function elapsed(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
