<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Services\AuditLogger;
use App\Http\Services\OcrClient;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class DocumentTemplateController extends Controller
{
    private const DISK = 'local';

    public function index(Request $request)
    {
        abort_unless($request->user()->can('document-templates.view'), 403);

        $query = DocumentTemplate::query()
            ->with(['vendor:id,code,name', 'activeVersion:id,version_no,activated_at'])
            ->latest();

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhereHas('vendor', fn ($v) => $v->where('name', 'like', "%{$request->search}%"));
            });
        }
        if ($request->filled('document_type')) {
            $query->where('document_type', $request->document_type);
        }

        return Inertia::render('Admin/DocumentTemplates/Index', [
            'templates' => Inertia::scroll($query->paginate(20)->withQueryString()),
            'filters' => $request->only(['search', 'document_type']),
            'vendors' => Vendor::where('status', 'active')->orderBy('name')->get(['id', 'code', 'name']),
            // (vendor_id, document_type) pairs already covered — the create modal
            // hides these so a duplicate template can't be made (use New Version).
            'existingScopes' => DocumentTemplate::get(['vendor_id', 'document_type'])
                ->map(fn ($t) => ['vendor_id' => $t->vendor_id, 'document_type' => $t->document_type])
                ->values(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('document-templates.create'), 403);

        $validated = $request->validate([
            'vendor_id' => 'nullable|exists:portal_vendors,id',
            'document_type' => 'required|in:invoice,purchase_order,quotation',
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
        ]);

        // One template per vendor + document type — extra layouts go on separate
        // versions of that template, not a second template (keeps resolveFor unambiguous).
        $scopeExists = DocumentTemplate::where('document_type', $validated['document_type'])
            ->when(empty($validated['vendor_id']), fn ($q) => $q->whereNull('vendor_id'))
            ->when(! empty($validated['vendor_id']), fn ($q) => $q->where('vendor_id', $validated['vendor_id']))
            ->exists();
        if ($scopeExists) {
            throw ValidationException::withMessages([
                'vendor_id' => 'A template already exists for this vendor and document type. Open it and add a New Version instead.',
            ]);
        }

        $template = DocumentTemplate::create($validated + [
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);
        AuditLogger::log('document_template_created', $template);

        return redirect()->route('document-templates.edit', $template->id)
            ->with('success', 'Template created. Upload a sample document to start annotating.');
    }

    public function edit(Request $request, DocumentTemplate $documentTemplate)
    {
        abort_unless($request->user()->can('document-templates.view'), 403);

        return Inertia::render('Admin/DocumentTemplates/Edit', [
            'template' => $documentTemplate->load([
                'vendor:id,code,name',
                'versions' => fn ($q) => $q->select('id', 'template_id', 'version_no', 'status', 'annotations', 'page_meta', 'sample_file_path', 'activated_at', 'created_at'),
            ]),
            'canEdit' => $request->user()->can('document-templates.edit'),
            'canDelete' => $request->user()->can('document-templates.delete'),
            'vendors' => Vendor::where('status', 'active')->orderBy('name')->get(['id', 'code', 'name']),
            // (id, vendor_id, document_type) for every template — lets the edit
            // modal hide combinations already taken by *other* templates while
            // still allowing this one to keep (or reselect) its own scope.
            'existingScopes' => DocumentTemplate::get(['id', 'vendor_id', 'document_type'])
                ->map(fn ($t) => ['id' => $t->id, 'vendor_id' => $t->vendor_id, 'document_type' => $t->document_type])
                ->values(),
        ]);
    }

    public function update(Request $request, DocumentTemplate $documentTemplate)
    {
        abort_unless($request->user()->can('document-templates.edit'), 403);

        $validated = $request->validate([
            'vendor_id' => 'nullable|exists:portal_vendors,id',
            'document_type' => 'sometimes|required|in:invoice,purchase_order,quotation',
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'status' => 'nullable|in:draft,active,archived',
        ]);

        if (($validated['status'] ?? null) === 'active' && ! $documentTemplate->active_version_id) {
            return redirect()->back()->with('error', 'Activate a version before activating the template.');
        }

        // Same one-template-per-vendor+type rule as creation, applied only when
        // scope actually changed (a no-op edit must never trip over itself).
        if (array_key_exists('vendor_id', $validated) || array_key_exists('document_type', $validated)) {
            $vendorId = array_key_exists('vendor_id', $validated) ? $validated['vendor_id'] : $documentTemplate->vendor_id;
            $documentType = $validated['document_type'] ?? $documentTemplate->document_type;

            $scopeTaken = DocumentTemplate::where('document_type', $documentType)
                ->where('id', '!=', $documentTemplate->id)
                ->when($vendorId === null, fn ($q) => $q->whereNull('vendor_id'))
                ->when($vendorId !== null, fn ($q) => $q->where('vendor_id', $vendorId))
                ->exists();
            if ($scopeTaken) {
                throw ValidationException::withMessages([
                    'vendor_id' => 'A template already exists for this vendor and document type.',
                ]);
            }
        }

        $documentTemplate->update($validated + ['updated_by' => $request->user()->id]);

        return redirect()->back()->with('success', 'Template updated.');
    }

    public function destroy(Request $request, DocumentTemplate $documentTemplate)
    {
        abort_unless($request->user()->can('document-templates.delete'), 403);

        AuditLogger::log('document_template_deleted', $documentTemplate);
        $documentTemplate->versions()->delete();
        $documentTemplate->delete();

        return redirect()->route('document-templates.index')->with('success', 'Template deleted.');
    }

    // ---- Versions ----

    /** Upload a sample PDF and open a new draft version. */
    public function storeVersion(Request $request, DocumentTemplate $documentTemplate, OcrClient $ocr)
    {
        abort_unless($request->user()->can('document-templates.edit'), 403);

        $request->validate(['sample' => 'required|file|max:20480|mimes:pdf']);

        $path = $request->file('sample')->store("portal/templates/{$documentTemplate->id}", self::DISK);

        // page_meta is a convenience snapshot; the annotator derives page dimensions
        // from pdf.js and the sidecar re-analyzes at extraction time, so a missing
        // OCR service must not block creating the draft.
        $pageMeta = null;
        $ocrOffline = false;
        try {
            $pageMeta = $ocr->analyze(Storage::disk(self::DISK)->path($path))['pages'] ?? null;
        } catch (\App\Exceptions\OcrServiceException) {
            $ocrOffline = true;
        }

        // Carry the latest annotations forward so a new sample doesn't restart the work
        $latest = $documentTemplate->versions()->first();

        $version = $documentTemplate->versions()->create([
            'version_no' => ($documentTemplate->versions()->max('version_no') ?? 0) + 1,
            'annotations' => $latest?->annotations,
            'sample_file_path' => $path,
            'page_meta' => $pageMeta,
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        if ($ocrOffline) {
            return redirect()->back()->with('warning', "Draft version v{$version->version_no} created. The OCR service is offline, so you can annotate now but Test Extract won't work until it's running.");
        }

        return redirect()->back()->with('success', "Draft version v{$version->version_no} created.");
    }

    /** Save annotations on a draft or active version. */
    public function updateVersion(Request $request, DocumentTemplate $documentTemplate, DocumentTemplateVersion $version)
    {
        abort_unless($request->user()->can('document-templates.edit'), 403);
        abort_unless($version->template_id === $documentTemplate->id, 404);

        // Draft + active are editable in place; superseded/archived history is locked.
        if (! in_array($version->status, ['draft', 'active'], true)) {
            return redirect()->back()->with('error', 'This version is archived. Create a new version to make changes.');
        }

        $request->validate([
            'annotations' => 'required|array',
            'annotations.fields' => 'present|array',
            'annotations.fields.*.key' => 'required|string|max:50',
            'annotations.fields.*.label' => 'nullable|string|max:100',
            'annotations.fields.*.type' => 'required|in:text,date,amount,qty',
            'annotations.fields.*.required' => 'boolean',
            'annotations.fields.*.page' => 'nullable|integer|min:1',
            // Un-annotated fields keep bbox null; only drawn boxes carry coordinates.
            'annotations.fields.*.bbox' => 'nullable|array|size:4',
            'annotations.fields.*.bbox.*' => 'numeric|between:0,1',
            'annotations.table' => 'nullable|array',
            'annotations.table.page' => 'required_with:annotations.table|integer|min:1',
            'annotations.table.repeat_on_following_pages' => 'boolean',
            'annotations.table.bbox' => 'required_with:annotations.table|array|size:4',
            'annotations.table.bbox.*' => 'numeric|between:0,1',
            'annotations.table.columns' => 'required_with:annotations.table|array|min:1',
            'annotations.table.columns.*.key' => 'required|string|max:50',
            'annotations.table.columns.*.x0' => 'required|numeric|between:0,1',
            'annotations.table.columns.*.x1' => 'required|numeric|between:0,1',
        ]);

        // Store the full submitted payload (keeps labels/schema that validated() would drop).
        $version->update(['annotations' => $request->input('annotations')]);

        return redirect()->back()->with('success', 'Annotations saved.');
    }

    /** Promote a draft version to active (supersedes the previous active). */
    public function activateVersion(Request $request, DocumentTemplate $documentTemplate, DocumentTemplateVersion $version)
    {
        abort_unless($request->user()->can('document-templates.edit'), 403);
        abort_unless($version->template_id === $documentTemplate->id, 404);

        $hasBoxedField = collect($version->annotations['fields'] ?? [])
            ->contains(fn ($f) => ! empty($f['bbox']));
        if (! $hasBoxedField && empty($version->annotations['table'])) {
            return redirect()->back()->with('error', 'Annotate at least one field or table region before activating.');
        }

        DB::transaction(function () use ($documentTemplate, $version, $request) {
            $documentTemplate->versions()
                ->where('status', 'active')
                ->where('id', '!=', $version->id)
                ->update(['status' => 'superseded']);

            $version->update(['status' => 'active', 'activated_at' => now()]);

            $documentTemplate->update([
                'active_version_id' => $version->id,
                'status' => 'active',
                'updated_by' => $request->user()->id,
            ]);
        });

        AuditLogger::log('document_template_activated', $documentTemplate);

        return redirect()->back()->with('success', "Version v{$version->version_no} is now active.");
    }

    /** Stream the sample PDF for the annotator viewer. */
    public function sampleFile(Request $request, DocumentTemplate $documentTemplate, DocumentTemplateVersion $version)
    {
        abort_unless($request->user()->can('document-templates.view'), 403);
        abort_unless($version->template_id === $documentTemplate->id, 404);
        abort_unless($version->sample_file_path && Storage::disk(self::DISK)->exists($version->sample_file_path), 404);

        return Storage::disk(self::DISK)->response($version->sample_file_path);
    }

    /** Dry-run extraction of (possibly unsaved) annotations against the sample PDF. */
    public function testExtract(Request $request, DocumentTemplate $documentTemplate, DocumentTemplateVersion $version, OcrClient $ocr)
    {
        abort_unless($request->user()->can('document-templates.view'), 403);
        abort_unless($version->template_id === $documentTemplate->id, 404);
        abort_unless($version->sample_file_path, 422);

        $annotations = $request->input('annotations') ?: $version->annotations ?: [];

        $result = $ocr->extract(
            Storage::disk(self::DISK)->path($version->sample_file_path),
            $annotations,
        );

        return response()->json($result);
    }
}
