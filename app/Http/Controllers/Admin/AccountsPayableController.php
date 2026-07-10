<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApInvoiceStatus;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AccountsPayableController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('accounts-payable.view'), 403);

        $query = ApInvoiceStatus::query()
            ->with('vendor:id,code,name')
            ->orderByDesc('updated_at');

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('invoice_no', 'like', "%{$request->search}%")
                    ->orWhere('payment_reference_no', 'like', "%{$request->search}%")
                    ->orWhereHas('vendor', fn ($v) => $v->where('name', 'like', "%{$request->search}%"));
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return Inertia::render('Admin/AccountsPayable/Index', [
            'statuses' => Inertia::scroll($query->paginate(20)->withQueryString()),
            'filters' => $request->only(['search', 'status']),
            'statusOptions' => ApInvoiceStatus::STATUSES,
        ]);
    }
}
