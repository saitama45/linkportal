<?php

namespace App\Traits;

use App\Models\Approval;

/**
 * Shared behavior for documents that pass through the multi-level approval workflow.
 * Requires columns: status, current_approval_level.
 */
trait Approvable
{
    public function approvals()
    {
        return $this->morphMany(Approval::class, 'approvable')->orderBy('level');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'returned'], true);
    }

    public function isPendingApproval(): bool
    {
        return in_array($this->status, ['submitted', 'under_review'], true);
    }

    public function scopePendingApproval($query)
    {
        return $query->whereIn('status', ['submitted', 'under_review']);
    }

    public function scopeForVendor($query, $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }
}
