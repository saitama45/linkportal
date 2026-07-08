<?php

namespace App\Http\Services;

use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\User;
use App\Models\VendorBankAccount;
use App\Models\VendorDocument;
use App\Models\VendorProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovalService
{
    /**
     * document_type => [model class, permission module, human label].
     */
    public const DOCUMENT_TYPES = [
        'vendor_profile' => [VendorProfile::class, 'vendors', 'Vendor Profile'],
        'vendor_document' => [VendorDocument::class, 'vendor-documents', 'Vendor Document'],
        'vendor_bank_account' => [VendorBankAccount::class, 'vendors', 'Vendor Bank Account'],
        'invoice' => [Invoice::class, 'invoices', 'Invoice'],
        'purchase_order' => [PurchaseOrder::class, 'purchase-orders', 'Purchase Order'],
        'quotation' => [Quotation::class, 'quotations', 'Quotation'],
    ];

    public static function documentTypeFor(Model $document): ?string
    {
        foreach (self::DOCUMENT_TYPES as $type => [$class]) {
            if ($document instanceof $class) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Best-matching active flow: company-specific beats the default (null company);
     * amount thresholds are honored when configured.
     */
    public static function resolveFlow(string $documentType, ?int $companyId = null, ?float $amount = null): ?ApprovalFlow
    {
        return ApprovalFlow::with('levels')
            ->where('document_type', $documentType)
            ->where('is_active', true)
            ->where(function ($q) use ($companyId) {
                $q->whereNull('company_id');
                if ($companyId) {
                    $q->orWhere('company_id', $companyId);
                }
            })
            ->where(function ($q) use ($amount) {
                $q->whereNull('min_amount');
                if ($amount !== null) {
                    $q->orWhere('min_amount', '<=', $amount);
                }
            })
            ->where(function ($q) use ($amount) {
                $q->whereNull('max_amount');
                if ($amount !== null) {
                    $q->orWhere('max_amount', '>=', $amount);
                }
            })
            ->orderByRaw('CASE WHEN company_id IS NULL THEN 1 ELSE 0 END')
            ->first();
    }

    public static function totalLevels(string $documentType, ?int $companyId = null, ?float $amount = null): int
    {
        $flow = self::resolveFlow($documentType, $companyId, $amount);

        return max(1, (int) ($flow->total_levels ?? 1));
    }

    /**
     * Permission required to act at a level; falls back to "<module>.approve".
     */
    public static function requiredPermission(string $documentType, int $level, ?int $companyId = null, ?float $amount = null): string
    {
        $module = self::DOCUMENT_TYPES[$documentType][1] ?? $documentType;
        $flow = self::resolveFlow($documentType, $companyId, $amount);
        $flowLevel = $flow?->levels->firstWhere('level', $level);

        return $flowLevel?->required_permission ?: "{$module}.approve";
    }

    /**
     * Vendor submits a document into the workflow.
     */
    public static function submit(Model $document, ?float $amount = null): void
    {
        $documentType = self::documentTypeFor($document);

        $document->forceFill([
            'status' => 'submitted',
            'current_approval_level' => 1,
            'submitted_at' => now(),
        ])->save();

        AuditLogger::log('submitted', $document);

        $permission = self::requiredPermission($documentType, 1, $document->company_id ?? null, $amount);
        $label = self::DOCUMENT_TYPES[$documentType][2] ?? 'Document';
        $ref = $document->reference_no ?? ('#'.$document->getKey());

        PortalNotifier::notifyUsersWithPermission(
            $permission,
            $document->company_id ?? null,
            'approval_pending',
            "{$label} pending approval",
            "A {$label} ({$ref}) was submitted and awaits your review.",
            null
        );
    }

    /**
     * Internal approver acts on a pending document: approved | rejected | returned.
     */
    public static function act(Model $document, User $user, string $action, ?string $remarks = null): Model
    {
        if (! in_array($action, ['approved', 'rejected', 'returned'], true)) {
            throw ValidationException::withMessages(['action' => 'Invalid approval action.']);
        }

        if (! in_array($document->status, ['submitted', 'under_review'], true)) {
            throw ValidationException::withMessages(['status' => 'This document is not pending approval.']);
        }

        $documentType = self::documentTypeFor($document);
        $level = max(1, (int) $document->current_approval_level);
        $amount = (float) ($document->total_amount ?? 0) ?: null;
        $permission = self::requiredPermission($documentType, $level, $document->company_id ?? null, $amount);

        if (! $user->can($permission)) {
            throw ValidationException::withMessages(['permission' => 'You are not authorized to act on this approval level.']);
        }

        return DB::transaction(function () use ($document, $user, $action, $remarks, $documentType, $level, $amount) {
            Approval::create([
                'approvable_type' => get_class($document),
                'approvable_id' => $document->getKey(),
                'level' => $level,
                'user_id' => $user->id,
                'action' => $action,
                'remarks' => $remarks,
                'acted_at' => now(),
            ]);

            $before = ['status' => $document->status, 'level' => $level];
            $totalLevels = self::totalLevels($documentType, $document->company_id ?? null, $amount);
            $label = self::DOCUMENT_TYPES[$documentType][2] ?? 'Document';
            $ref = $document->reference_no ?? ('#'.$document->getKey());

            if ($action === 'rejected') {
                $document->forceFill(['status' => 'rejected'])->save();
            } elseif ($action === 'returned') {
                $document->forceFill(['status' => 'returned', 'current_approval_level' => 0])->save();
            } elseif ($level >= $totalLevels) {
                $document->forceFill(['status' => 'approved'])->save();
            } else {
                $document->forceFill(['status' => 'under_review', 'current_approval_level' => $level + 1])->save();

                PortalNotifier::notifyUsersWithPermission(
                    self::requiredPermission($documentType, $level + 1, $document->company_id ?? null, $amount),
                    $document->company_id ?? null,
                    'approval_pending',
                    "{$label} pending approval (level ".($level + 1).')',
                    "{$label} {$ref} advanced to approval level ".($level + 1).'.',
                    null
                );
            }

            AuditLogger::log($action, $document, $before, ['status' => $document->status, 'level' => $document->current_approval_level]);

            if ($document->vendor) {
                PortalNotifier::notifyVendor(
                    $document->vendor,
                    "document_{$action}",
                    "{$label} {$action}",
                    "Your {$label} {$ref} was {$action}".($remarks ? ": {$remarks}" : '.'),
                    null
                );
            }

            return $document->fresh();
        });
    }
}
