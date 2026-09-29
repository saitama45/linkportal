<?php

namespace App\Http\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reads and writes NPC Monitoring files. The rows are in the shared database,
 * the files are not: the hub (ghelpdesk) keeps seals on its own `public` disk.
 * The mirror of the hub's PortalDocumentStorage, so it handles both deployments:
 *
 *  1. Shared filesystem (local dev, or a shared mount): the `npc` disk points at
 *     the hub's public storage and files are read and written there directly.
 *  2. Separate App Services: the `npc` root does not exist on this server.
 *     Seals stream from the hub's public `/storage/...` URL (GHELPDESK_URL), and
 *     proofs uploaded here stay on this app's `public` disk, which the hub
 *     fetches from LINKPORTAL_URL in turn. Both paths share one relative layout.
 */
class HubNpcStorage
{
    public function sharedDiskAvailable(): bool
    {
        return $this->diskExists('npc');
    }

    /**
     * A download response for the file, or a 404. Availability is settled before
     * this returns, so callers may record the download once it has.
     */
    public function download(?string $path, ?string $name): StreamedResponse
    {
        $relative = $this->relative($path);
        abort_if($relative === null, 404, 'The file is not available.');
        $name = $name ?: basename($relative);

        foreach ($this->localDisks() as $disk) {
            if (Storage::disk($disk)->exists($relative)) {
                return Storage::disk($disk)->download($relative, $name);
            }
        }

        $response = $this->fetchFromHub($relative);
        abort_if($response === null, 404, 'The file is not available.');
        $body = $response->toPsrResponse()->getBody();

        return response()->streamDownload(function () use ($body) {
            while (! $body->eof()) {
                echo $body->read(64 * 1024);
            }
        }, $name, array_filter([
            'Content-Type' => $response->header('Content-Type') ?: 'application/octet-stream',
            'Content-Length' => $response->header('Content-Length') ?: null,
        ]));
    }

    public function storeProof(UploadedFile $file, string $directory, string $name): string
    {
        return str_replace('\\', '/', $file->storeAs($directory, $name, $this->writableDisk()));
    }

    /** Removes a file this server wrote. A proof the hub stored stays on the hub. */
    public function delete(?string $path): void
    {
        $relative = $this->relative($path);

        if ($relative !== null) {
            Storage::disk($this->writableDisk())->delete($relative);
        }
    }

    private function writableDisk(): string
    {
        return $this->sharedDiskAvailable() ? 'npc' : 'public';
    }

    private function localDisks(): array
    {
        return array_values(array_filter(['npc', 'public'], fn (string $disk) => $this->diskExists($disk)));
    }

    /**
     * Checked from config rather than by resolving the disk: a local disk
     * creates its root on construction and throws when it cannot. That covers
     * the hub's directory on another server, or `public/storage` linked to a
     * directory that does not exist, and either one is a 500 on every NPC file action.
     */
    private function diskExists(string $disk): bool
    {
        $root = config("filesystems.disks.{$disk}.root");

        return is_string($root) && $root !== '' && is_dir($root);
    }

    /** A streamed GET of the hub's public storage URL, or null when unavailable. */
    private function fetchFromHub(string $relative): ?Response
    {
        $baseUrl = rtrim((string) config('services.ghelpdesk.base_url'), '/');

        if ($baseUrl === '') {
            return null;
        }

        $url = $baseUrl.'/storage/'.implode('/', array_map('rawurlencode', explode('/', $relative)));

        try {
            $response = Http::withOptions(['stream' => true])->timeout(60)->get($url);
        } catch (\Throwable $e) {
            Log::warning('NPC hub file fetch failed', ['path' => $relative, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('NPC hub file fetch returned an error', ['path' => $relative, 'status' => $response->status()]);

            return null;
        }

        return $response;
    }

    /** The stored path, normalised; null when empty or trying to leave the storage root. */
    private function relative(?string $path): ?string
    {
        $relative = ltrim(str_replace('\\', '/', (string) $path), '/');

        return $relative === '' || str_contains($relative, '..') ? null : $relative;
    }
}
