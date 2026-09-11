<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Services\AuditLogger;
use App\Models\NpcSealReceipt;
use App\Models\NpcStatus;
use App\Models\NpcStatusAttachment;
use App\Models\NpcStoreProof;
use App\Models\Store;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Support\CashierContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class NpcStatusController extends Controller
{
    public function index()
    {
        $store = CashierContext::store();
        $statuses = NpcStatus::query()
            ->whereHas('stores', fn ($query) => $query->whereKey($store->id))
            ->with(['company:id,name,code', 'attachments',
                'sealReceipts' => fn ($query) => $query->where('store_id', $store->id),
                'storeProofs' => fn ($query) => $query->where('store_id', $store->id),
            ])->orderByDesc('year')->get();

        $years = $statuses->map(function (NpcStatus $status) use ($store) {
            $seals = collect(NpcStatusAttachment::SEAL_TYPES)->map(function (string $type) use ($status, $store) {
                $attachment = $this->attachment($status, $store, $type);
                $receipt = $status->sealReceipts->firstWhere('seal_type', $type);
                $proof = $status->storeProofs->firstWhere('seal_type', $type);
                $parameters = [$status->id, $store->id, $type];

                return [
                    'type' => $type,
                    'label' => NpcStatusAttachment::TYPE_LABELS[$type],
                    'available' => (bool) $attachment,
                    'name' => $attachment?->file_name,
                    'download_url' => $attachment ? route('vendor.npc-statuses.stores.seal.download', $parameters) : null,
                    'downloaded_at' => $receipt?->downloaded_at?->toIso8601String(),
                    'confirmed_at' => $receipt?->confirmed_at?->toIso8601String(),
                    'proof' => $proof ? [
                        'name' => $proof->file_name,
                        'uploaded_at' => $proof->uploaded_at?->toIso8601String(),
                        'url' => route('vendor.npc-statuses.stores.proof.download', $parameters),
                    ] : null,
                    'proof_upload_url' => route('vendor.npc-statuses.stores.proof.upload', $parameters),
                ];
            })->all();

            return [
                'npc_status_id' => $status->id,
                'year' => $status->year,
                'entity_name' => $status->company?->name,
                'entity_code' => $status->company?->code,
                'validity_from' => $status->validity_from?->format('Y-m-d'),
                'validity_to' => $status->validity_to?->format('Y-m-d'),
                'seals' => $seals,
            ];
        })->all();

        return Inertia::render('Vendor/NpcStatus/Index', ['storeSeals' => [[
            'store_id' => $store->id,
            'store_name' => $store->name,
            'store_code' => $store->code,
            'years' => $years,
        ]]]);
    }

    private function authorizeStore(NpcStatus $status, Store $store, string $type): void
    {
        abort_unless(in_array($type, NpcStatusAttachment::SEAL_TYPES, true), 404);
        abort_unless(CashierContext::storeId() === (int) $store->id, 403);
        abort_unless($status->stores()->whereKey($store->id)->exists(), 404);
    }

    private function attachment(NpcStatus $status, Store $store, string $type): ?NpcStatusAttachment
    {
        // Match the hub's year cards; CCTV must never fall back to another store.
        return $status->attachments
            ->filter(fn ($file) => $file->type === $type
                && (int) $file->validity_from?->year === $status->year
                && ($type === NpcStatusAttachment::TYPE_CCTV_SEAL
                    ? (int) $file->store_id === (int) $store->id : $file->store_id === null))
            ->sortByDesc(fn ($file) => $file->validity_from->format('Y-m-d').$file->created_at?->timestamp)
            ->first();
    }

    public function downloadStoreSeal(Request $request, NpcStatus $npcStatus, Store $store, string $type)
    {
        $this->authorizeStore($npcStatus, $store, $type);
        $attachment = $this->attachment($npcStatus, $store, $type);
        abort_unless($attachment, 404, 'This seal is not available yet.');
        $this->ensureFileExists($attachment->file_path);

        $receipt = DB::transaction(function () use ($npcStatus, $store, $type) {
            NpcStatus::whereKey($npcStatus->id)->lockForUpdate()->firstOrFail();
            $receipt = NpcSealReceipt::firstOrNew([
                'npc_status_id' => $npcStatus->id, 'store_id' => $store->id, 'seal_type' => $type,
            ]);
            if (! $receipt->downloaded_at) {
                // These shared columns identify users, never vendors. The portal
                // audit and notification carry the actual vendor identity.
                $receipt->fill(['downloaded_at' => now(), 'downloaded_by' => null])->save();
                AuditLogger::log('npc_seal_downloaded', $receipt, null, ['seal_type' => $type]);
                $this->notifyDownload($npcStatus, $store, $type);
            }

            return $receipt;
        });

        if ($request->expectsJson()) {
            return response()->json([
                'download_url' => route('vendor.npc-statuses.stores.seal.download', [$npcStatus, $store, $type]),
                'downloaded_at' => $receipt->downloaded_at->toIso8601String(),
            ]);
        }

        return Storage::disk('npc')->download($attachment->file_path, $attachment->file_name);
    }

    public function uploadStoreProof(Request $request, NpcStatus $npcStatus, Store $store, string $type)
    {
        $this->authorizeStore($npcStatus, $store, $type);
        abort_unless($this->attachment($npcStatus, $store, $type), 404, 'This seal is not available yet.');
        $request->validate(['file' => 'required|file|mimes:pdf,jpg,jpeg,png,webp,gif,bmp,heic,heif|max:1024000']);

        $file = $request->file('file');
        $path = $file->storeAs("npc-store-proofs/{$npcStatus->id}/{$store->id}",
            'proof-'.$type.'-'.Str::uuid().'.'.$file->extension(), 'npc');

        try {
            $oldPath = DB::transaction(function () use ($npcStatus, $store, $type, $file, $path) {
                NpcStatus::whereKey($npcStatus->id)->lockForUpdate()->firstOrFail();
                $proof = NpcStoreProof::firstOrNew([
                    'npc_status_id' => $npcStatus->id, 'store_id' => $store->id, 'seal_type' => $type,
                ]);
                $oldPath = $proof->file_path;
                $proof->fill([
                    'file_path' => str_replace('\\', '/', $path),
                    'file_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'uploaded_by' => null,
                    'uploaded_at' => now(),
                ])->save();
                AuditLogger::log('npc_store_proof_uploaded', $proof, null, [
                    'npc_status_id' => $npcStatus->id, 'store_id' => $store->id,
                    'seal_type' => $type, 'file_name' => $proof->file_name,
                ]);

                return $oldPath;
            });
        } catch (\Throwable $exception) {
            Storage::disk('npc')->delete($path);
            throw $exception;
        }

        // Keep the old proof until both the replacement and its record succeed.
        if ($oldPath) {
            Storage::disk('npc')->delete($oldPath);
        }

        return $request->expectsJson()
            ? response()->json(['message' => 'Proof uploaded successfully'])
            : back()->with('success', 'Proof uploaded successfully');
    }

    public function downloadStoreProof(NpcStatus $npcStatus, Store $store, string $type)
    {
        $this->authorizeStore($npcStatus, $store, $type);
        $proof = $npcStatus->storeProofs()->where('store_id', $store->id)->where('seal_type', $type)->firstOrFail();
        $this->ensureFileExists($proof->file_path);

        return Storage::disk('npc')->download($proof->file_path, $proof->file_name);
    }

    private function ensureFileExists(?string $path): void
    {
        abort_unless($path && Storage::disk('npc')->exists($path), 404, 'The file is not available.');
    }

    private function notifyDownload(NpcStatus $status, Store $store, string $type): void
    {
        $permissions = ['npc_status.view', 'npc_status.edit'];
        $recipients = User::where(function ($query) use ($permissions) {
            $query->whereHas('permissions', fn ($q) => $q->whereIn('name', $permissions)->where('guard_name', 'web'))
                ->orWhereHas('roles.permissions', fn ($q) => $q->whereIn('name', $permissions)->where('guard_name', 'web'));
        })->get();
        $label = NpcStatusAttachment::TYPE_LABELS[$type];
        Notification::send($recipients, new ActivityNotification([
            'actor_id' => null,
            'actor_type' => 'vendor',
            'actor_vendor_id' => CashierContext::vendor()->id,
            'actor_name' => CashierContext::vendor()->name,
            'domain' => 'npc_status', 'event' => 'seal_downloaded', 'severity' => 'info',
            'title' => 'Store downloaded an NPC seal',
            'message' => "{$store->name} downloaded the {$label} for {$status->company?->name} ({$status->year}). Confirm receipt to mark the store as checked.",
            'subject' => 'npc_status:'.$status->id,
            // This database notification is rendered in the hub, not the portal.
            'url' => '/npc-statuses',
        ]));
    }
}
