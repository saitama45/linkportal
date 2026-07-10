<?php

namespace App\Http\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GhelpdeskClient
{
    public function submitDocumentReview(array $payload): Response
    {
        return $this->request()->post('/api/accounting/document-reviews', $payload);
    }

    protected function request(): PendingRequest
    {
        $baseUrl = rtrim((string) config('services.ghelpdesk.base_url'), '/');

        if ($baseUrl === '') {
            throw new \RuntimeException('GHELPDESK_URL is not configured.');
        }
        if (app()->isProduction() && ! str_starts_with($baseUrl, 'https://')) {
            throw new \RuntimeException('GHELPDESK_URL must be https in production.');
        }

        return Http::baseUrl($baseUrl)
            ->withToken((string) config('services.ghelpdesk.token'))
            ->acceptJson()
            ->timeout(60);
    }
}
