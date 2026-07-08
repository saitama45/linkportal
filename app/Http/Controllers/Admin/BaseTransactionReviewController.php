<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Services\ApprovalService;
use App\Models\Company;
use App\Models\Product;
use App\Models\Uom;
use App\Models\Vendor;
use App\Traits\HandlesLineItems;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Shared internal review surface for vendor-submitted transactions
 * (invoices, purchase orders, quotations): list, inspect, and act
 * through the multi-level ApprovalService.
 */
abstract class BaseTransactionReviewController extends Controller
{
    use HandlesLineItems;

    /** @var class-string<\Illuminate\Database\Eloquent\Model> */
    protected string $modelClass;

    protected string $pageFolder;      // e.g. 'Invoices'

    protected string $propName;        // e.g. 'invoices'

    protected string $permissionModule;

    protected array $searchColumns = ['reference_no'];

    public function index(Request $request)
    {
        $query = $this->modelClass::query()
            ->with(['vendor:id,code,name', 'company:id,name'])
            ->whereNotIn('status', ['draft'])
            ->latest();

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                foreach ($this->searchColumns as $column) {
                    $q->orWhere($column, 'like', "%{$request->search}%");
                }
                $q->orWhereHas('vendor', fn ($v) => $v->where('name', 'like', "%{$request->search}%"));
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return Inertia::render("{$this->pageFolder}/Index", [
            $this->propName => Inertia::scroll($query->paginate(20)->withQueryString()),
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->can("{$this->permissionModule}.create"), 403);

        return Inertia::render('Transactions/Create', [
            'documentType' => $this->permissionModule,
            'vendors' => Vendor::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'companies' => Company::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'products' => Product::active()
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'default_price', 'tax_rate', 'uom_id']),
            'uoms' => Uom::active()
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
        ]);
    }

    public function show(int $id)
    {
        $document = $this->modelClass::with([
            'vendor:id,code,name,email',
            'company:id,name',
            'items.uom:id,code',
            'attachments',
            'approvals.user:id,name',
        ])->findOrFail($id);

        return Inertia::render("{$this->pageFolder}/Show", [
            'document' => $document,
            'documentType' => ApprovalService::documentTypeFor($document),
        ]);
    }

    public function act(Request $request, int $id)
    {
        $validated = $request->validate([
            'action' => 'required|in:approved,rejected,returned',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $document = $this->modelClass::findOrFail($id);

        ApprovalService::act($document, $request->user(), $validated['action'], $validated['remarks'] ?? null);

        return redirect()->back()->with('success', 'Document '.$validated['action'].'.');
    }
}
