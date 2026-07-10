<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DocumentException;
use App\Models\DocumentExceptionRule;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DocumentExceptionController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('document-exceptions.view'), 403);

        $query = DocumentException::query()
            ->with(['intakeDocument:id,reference_no,vendor_id,document_type,status,source', 'intakeDocument.vendor:id,code,name'])
            ->latest();

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('message', 'like', "%{$request->search}%")
                    ->orWhereHas('intakeDocument', fn ($d) => $d->where('reference_no', 'like', "%{$request->search}%"))
                    ->orWhereHas('intakeDocument.vendor', fn ($v) => $v->where('name', 'like', "%{$request->search}%"));
            });
        }
        $query->where('status', $request->filled('status') ? $request->status : 'open');
        if ($request->filled('rule_key')) {
            $query->where('rule_key', $request->rule_key);
        }
        if ($request->filled('severity')) {
            $query->where('severity', $request->severity);
        }

        return Inertia::render('Admin/DocumentExceptions/Index', [
            'exceptions' => Inertia::scroll($query->paginate(20)->withQueryString()),
            'filters' => $request->only(['search', 'status', 'rule_key', 'severity']),
            'rules' => DocumentExceptionRule::orderBy('label')->get(),
            'canResolve' => $request->user()->can('document-exceptions.resolve'),
        ]);
    }

    public function resolve(Request $request, DocumentException $documentException)
    {
        abort_unless($request->user()->can('document-exceptions.resolve'), 403);

        $validated = $request->validate([
            'status' => 'required|in:resolved,waived',
            'resolution_note' => 'required_if:status,waived|nullable|string|max:500',
        ]);

        if ($documentException->status !== 'open') {
            return redirect()->back()->with('error', 'Exception is already closed.');
        }

        $documentException->update([
            'status' => $validated['status'],
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
            'resolution_note' => $validated['resolution_note'] ?? null,
        ]);

        $documentException->intakeDocument?->recordEvent(
            'exception_resolved',
            "{$documentException->rule_key} {$validated['status']}",
            ['exception_id' => $documentException->id],
            'user',
            $request->user()->id,
        );

        return redirect()->back()->with('success', 'Exception '.$validated['status'].'.');
    }

    /** Enable/disable a rule or tune its config (severity, thresholds). */
    public function updateRule(Request $request, DocumentExceptionRule $rule)
    {
        abort_unless($request->user()->can('document-exceptions.resolve'), 403);

        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'severity' => 'required|in:blocker,warning',
            'config' => 'nullable|array',
        ]);

        $rule->update($validated);

        return redirect()->back()->with('success', 'Rule updated.');
    }
}
