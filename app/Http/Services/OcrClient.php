<?php

namespace App\Http\Services;

use App\Exceptions\OcrServiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Client for the self-hosted OCR sidecar (ocr-worker/). File paths in the
 * payloads are absolute shared-filesystem paths, not uploads.
 */
class OcrClient
{
    public function health(): array
    {
        return $this->get('/health');
    }

    /**
     * Convert DOC/DOCX to PDF via LibreOffice headless.
     *
     * @return array{pdf_path: string, page_count: int, duration_ms: int}
     */
    public function convert(string $inputPath, string $outputDir): array
    {
        return $this->post('/convert', [
            'input_path' => $inputPath,
            'output_dir' => $outputDir,
        ]);
    }

    /**
     * @return array{page_count: int, pages: array<int, array{page: int, width_pt: float, height_pt: float, has_text_layer: bool}>}
     */
    public function analyze(string $pdfPath): array
    {
        return $this->post('/analyze', ['pdf_path' => $pdfPath]);
    }

    /**
     * Run template-driven extraction. $template is the annotations JSON stored
     * on a portal_document_template_versions row (fields + table).
     */
    public function extract(string $pdfPath, array $template, array $options = []): array
    {
        return $this->post('/extract', array_filter([
            'pdf_path' => $pdfPath,
            'template' => $template ?: null,
            'options' => $options ?: null,
        ]));
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl(config('services.ocr.base_url'))
            ->timeout((int) config('services.ocr.timeout', 180))
            ->retry(2, 1000, throw: false)
            ->acceptJson();
    }

    protected function get(string $path): array
    {
        return $this->send('get', $path);
    }

    protected function post(string $path, array $payload = []): array
    {
        return $this->send('post', $path, $payload);
    }

    protected function send(string $method, string $path, array $payload = []): array
    {
        try {
            $response = $method === 'get'
                ? $this->request()->get($path)
                : $this->request()->post($path, $payload);
        } catch (ConnectionException $e) {
            throw new OcrServiceException("OCR service unreachable: {$e->getMessage()}");
        }

        if ($response->failed()) {
            $detail = $response->json('detail') ?? $response->body();
            throw new OcrServiceException(
                "OCR service error on {$path}: " . (is_string($detail) ? $detail : json_encode($detail)),
                $response->status(),
                $response->json() ?? [],
            );
        }

        return $response->json() ?? [];
    }
}
