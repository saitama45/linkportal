<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import StatusBadge from '@/Components/Portal/StatusBadge.vue';
import DocumentTimeline from '@/Components/Portal/DocumentTimeline.vue';
import IntakeLineItemsEditor from '@/Components/Portal/IntakeLineItemsEditor.vue';
import PdfPageCanvas from '@/Components/Portal/Annotator/PdfPageCanvas.vue';
import { usePdfDocument } from '@/Composables/usePdfDocument';
import { useConfirm } from '@/Composables/useConfirm';
import {
    ArrowLeftIcon, ArrowPathIcon, CheckBadgeIcon, ExclamationTriangleIcon,
    PaperAirplaneIcon, ShieldCheckIcon, TrashIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    document: { type: Object, required: true },
    vendors: { type: Array, default: () => [] },
    documentTypes: { type: Array, default: () => [] },
    canValidate: { type: Boolean, default: false },
    canSubmit: { type: Boolean, default: false },
    canResolveExceptions: { type: Boolean, default: false },
    canDelete: { type: Boolean, default: false },
});

const { confirm } = useConfirm();

const extraction = computed(() => props.document.latest_extraction);
const extractionField = (key) => (extraction.value?.header_fields || []).find((f) => f.key === key);

// ---- editable state ----
const HEADER_FIELDS = [
    { key: 'invoice_no', label: 'Document No.', type: 'text' },
    { key: 'po_number', label: 'PO Number', type: 'text' },
    { key: 'document_date', label: 'Document Date', type: 'date' },
    { key: 'due_date', label: 'Due Date', type: 'date' },
    { key: 'currency', label: 'Currency', type: 'text' },
    { key: 'subtotal', label: 'Subtotal', type: 'number' },
    { key: 'tax_amount', label: 'Tax', type: 'number' },
    { key: 'total_amount', label: 'Total', type: 'number' },
    { key: 'vendor_address', label: 'Vendor Address', type: 'text' },
];

const dateOnly = (value) => (value ? String(value).slice(0, 10) : null);

const form = ref({
    invoice_no: props.document.invoice_no,
    po_number: props.document.po_number,
    document_date: dateOnly(props.document.document_date),
    due_date: dateOnly(props.document.due_date),
    currency: props.document.currency || 'PHP',
    subtotal: props.document.subtotal,
    tax_amount: props.document.tax_amount,
    total_amount: props.document.total_amount,
    vendor_address: props.document.validated_fields?.vendor_address
        ?? extractionField('vendor_address')?.value ?? null,
});

// Line-item columns come from the template (custom names + unlimited count);
// fall back to the five standard columns for manual / template-less documents.
const DEFAULT_LINE_COLUMNS = [
    { key: 'description', label: 'Description' },
    { key: 'quantity', label: 'Quantity' },
    { key: 'uom', label: 'UOM' },
    { key: 'unit_price', label: 'Unit Price' },
    { key: 'line_total', label: 'Line Total' },
];
const NUMERIC_LINE_KEYS = new Set(['quantity', 'unit_price', 'line_total']);
const STANDARD_LINE_LABELS = { description: 'Description', quantity: 'Quantity', uom: 'UOM', unit_price: 'Unit Price', line_total: 'Line Total' };
const lineColumnLabel = (col) => col.label
    || STANDARD_LINE_LABELS[col.key]
    || (col.key || '').replace(/_/g, ' ').replace(/\b\w/g, (m) => m.toUpperCase());

const lineItemColumns = computed(() => {
    const cols = props.document.template_version?.annotations?.table?.columns;
    if (cols?.length) return cols.map((c) => ({ key: c.key, label: lineColumnLabel(c) }));
    return DEFAULT_LINE_COLUMNS;
});

const initialItems = () => {
    if (props.document.validated_line_items?.length) {
        return props.document.validated_line_items.map((item) => ({ ...item }));
    }
    return (extraction.value?.line_items || []).map((row) => Object.fromEntries(
        lineItemColumns.value.map((c) => [c.key, row.cells?.[c.key]?.value ?? (NUMERIC_LINE_KEYS.has(c.key) ? null : '')]),
    ));
};
const lineItems = ref(initialItems());
const dirty = ref(false);
onMounted(() => setTimeout(() => { dirty.value = false; }, 0));

// ---- confidence helpers ----
const LOW = 0.75;
const chip = (confidence) => {
    if (confidence == null) return null;
    if (confidence >= 0.9) return 'bg-emerald-100 text-emerald-700';
    if (confidence >= LOW) return 'bg-amber-100 text-amber-700';
    return 'bg-red-100 text-red-700';
};
const pct = (value) => `${Math.round((value ?? 0) * 100)}%`;

// ---- pdf viewer + extraction boxes ----
const { doc, numPages, loading: pdfLoading, error: pdfError, load } = usePdfDocument();
const page = ref(1);
const focusedFieldKey = ref(null);

onMounted(() => load(route('document-intake.file', props.document.id)));

const boxesOnPage = computed(() => {
    const fields = (extraction.value?.header_fields || [])
        .filter((f) => f.bbox && f.page === page.value)
        .map((f) => ({ id: `field-${f.key}`, key: f.key, bbox: f.bbox, confidence: f.confidence, kind: 'field' }));
    const rows = (extraction.value?.line_items || [])
        .filter((r) => r.bbox && r.page === page.value)
        .map((r) => ({ id: `row-${r.row_index}`, key: `row ${r.row_index + 1}`, bbox: r.bbox, confidence: r.row_confidence, kind: 'row' }));
    return [...fields, ...rows];
});

const boxClass = (box) => {
    const focused = box.kind === 'field' && box.key === focusedFieldKey.value;
    if (box.confidence >= 0.9) return ['border-emerald-500 bg-emerald-400/10', focused ? 'ring-2 ring-emerald-400' : ''];
    if (box.confidence >= LOW) return ['border-amber-500 bg-amber-400/10', focused ? 'ring-2 ring-amber-400' : ''];
    return ['border-red-500 bg-red-400/10', focused ? 'ring-2 ring-red-400' : ''];
};

const focusField = (key) => {
    focusedFieldKey.value = key;
    const field = extractionField(key);
    if (field?.page && field.page !== page.value) page.value = field.page;
};

// ---- totals reconciliation ----
const lineSum = computed(() => lineItems.value.reduce((sum, item) => sum + (Number(item.line_total) || 0), 0));
const totalsDelta = computed(() => {
    const total = Number(form.value.total_amount);
    if (!total) return null;
    const withTax = lineSum.value + (Number(form.value.tax_amount) || 0);
    return Math.min(Math.abs(total - withTax), Math.abs(total - lineSum.value));
});
const totalsOk = computed(() => totalsDelta.value !== null && totalsDelta.value <= 0.05);
const money = (value) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

// ---- exceptions ----
const openExceptions = computed(() => props.document.exceptions.filter((e) => e.status === 'open'));
const closedExceptions = computed(() => props.document.exceptions.filter((e) => e.status !== 'open'));
const hasBlockers = computed(() => openExceptions.value.some((e) => e.severity === 'blocker'));

const resolveException = (exception, status) => {
    let note = null;
    if (status === 'waived') {
        note = prompt('Waive note (required):');
        if (!note) return;
    }
    router.put(route('document-exceptions.resolve', exception.id), { status, resolution_note: note }, { preserveScroll: true });
};

// ---- classification (unmatched email / unknown type) ----
const needsClassification = computed(() => !props.document.vendor_id || !props.document.document_type);
const classifyForm = ref({ vendor_id: null, document_type: props.document.document_type, remember_sender: true });
const classify = () => {
    router.put(route('document-intake.classify', props.document.id), classifyForm.value, { preserveScroll: true });
};

// ---- actions ----
const editableStatuses = ['needs_validation', 'validated', 'returned'];
const editable = computed(() => props.canValidate && editableStatuses.includes(props.document.status));

// OCR can be (re)run for anything not already past the extraction stage — this
// mirrors the controller and lets a document stuck at `received` or a failed
// conversion/extraction be processed once a template is active / services are up.
const rerunBlockedStatuses = ['sending', 'pending_external_review', 'approved', 'rejected', 'cancelled'];
const canRerun = computed(() =>
    props.canValidate
    && !!props.document.vendor_id
    && !!props.document.document_type
    && !rerunBlockedStatuses.includes(props.document.status));

// A document that has never produced an extraction (or whose OCR failed) has no
// fields to show — surface why and offer to run OCR.
const notYetProcessed = computed(() =>
    !extraction.value || ['received', 'converting', 'extracting', 'conversion_failed', 'extraction_failed'].includes(props.document.status));

const saveCorrections = () => {
    router.put(route('document-intake.corrections', props.document.id),
        { fields: form.value, line_items: lineItems.value },
        { preserveScroll: true, onSuccess: () => { dirty.value = false; } });
};

const markValidated = async () => {
    const ok = await confirm({
        title: 'Mark as validated?',
        message: 'This confirms the extracted fields and line items are correct, and makes the document ready to submit to Accounting.',
        confirmButtonText: 'Mark Validated',
        type: 'info',
    });
    if (! ok) return;
    saveThen(() => {
        router.put(route('document-intake.validate', props.document.id), {}, { preserveScroll: true });
    });
};

const saveThen = (next) => {
    if (dirty.value) {
        router.put(route('document-intake.corrections', props.document.id),
            { fields: form.value, line_items: lineItems.value },
            { preserveScroll: true, onSuccess: () => { dirty.value = false; next(); } });
    } else {
        next();
    }
};

const rerunOcr = async () => {
    const ok = await confirm({
        title: extraction.value ? 'Re-run OCR' : 'Run OCR',
        message: extraction.value
            ? 'A new extraction attempt will be queued for this document.'
            : 'Conversion and extraction will be queued for this document.',
        confirmButtonText: extraction.value ? 'Re-run OCR' : 'Run OCR',
        type: 'info',
    });
    if (ok) {
        router.put(route('document-intake.rerun-ocr', props.document.id), {}, { preserveScroll: true });
    }
};

const deleteDocument = async () => {
    const ok = await confirm({
        title: 'Delete Document',
        message: `Delete ${props.document.reference_no}? This removes it from the intake inbox.`,
        confirmButtonText: 'Delete',
        type: 'danger',
    });
    if (ok) {
        router.delete(route('document-intake.destroy', props.document.id));
    }
};

const submitToReview = async () => {
    const ok = await confirm({
        title: 'Submit to Accounting',
        message: 'Submit this document to Accounting for review?',
        confirmButtonText: 'Submit',
        type: 'info',
    });
    if (ok) {
        router.put(route('document-intake.submit', props.document.id), {}, { preserveScroll: true });
    }
};

const typeLabel = computed(() => ({ invoice: 'Invoice', purchase_order: 'Purchase Order', quotation: 'Quotation' }[props.document.document_type] || 'Unclassified'));
</script>

<template>
    <Head :title="`${document.reference_no} - Document Intake`" />

    <AppLayout>
        <template #header>
            <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
                <div class="flex items-center gap-4">
                    <Link :href="route('document-intake.index')"
                        class="rounded-lg p-2 text-slate-400 transition-all hover:bg-slate-100 hover:text-slate-600">
                        <ArrowLeftIcon class="h-5 w-5" />
                    </Link>
                    <div>
                        <h2 class="text-2xl font-bold leading-tight text-slate-800">{{ document.reference_no }}</h2>
                        <p class="mt-1 text-sm text-slate-500">
                            {{ typeLabel }} · {{ document.vendor?.name || 'Unmatched vendor' }} · {{ document.original_filename }}
                        </p>
                    </div>
                    <StatusBadge :status="document.status" />
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button v-if="canRerun" type="button"
                        class="flex items-center gap-1.5 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-600 transition-all hover:bg-slate-50"
                        @click="rerunOcr">
                        <ArrowPathIcon class="h-4 w-4" />
                        {{ extraction ? 'Re-run OCR' : 'Run OCR' }}
                    </button>
                    <button v-if="editable" type="button" :disabled="!dirty"
                        class="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-semibold text-white shadow-lg shadow-emerald-600/20 transition-all hover:bg-emerald-700 disabled:opacity-50"
                        @click="saveCorrections">
                        Save Corrections
                    </button>
                    <button v-if="editable && document.status !== 'validated'" type="button" :disabled="hasBlockers"
                        class="flex items-center gap-1.5 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-lg shadow-indigo-600/20 transition-all hover:bg-indigo-700 disabled:opacity-50"
                        :title="hasBlockers ? 'Resolve blocking exceptions first' : ''"
                        @click="markValidated">
                        <ShieldCheckIcon class="h-4 w-4" />
                        Mark Validated
                    </button>
                    <button v-if="canSubmit && document.status === 'validated'" type="button" :disabled="hasBlockers"
                        class="flex items-center gap-1.5 rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-lg transition-all hover:bg-slate-700 disabled:opacity-50"
                        @click="submitToReview">
                        <PaperAirplaneIcon class="h-4 w-4" />
                        Submit to Accounting
                    </button>
                    <button v-if="canDelete" type="button"
                        class="flex items-center gap-1.5 rounded-xl border border-red-200 px-4 py-2 text-sm font-semibold text-red-600 transition-all hover:bg-red-50"
                        @click="deleteDocument">
                        <TrashIcon class="h-4 w-4" />
                        Delete
                    </button>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-[110rem] px-4 sm:px-6">
                <div class="grid gap-6 xl:grid-cols-2">
                    <!-- Left: document viewer -->
                    <div class="rounded-2xl border border-slate-100 bg-slate-50 p-4 shadow-sm xl:sticky xl:top-4 xl:self-start">
                        <div class="mb-3 flex items-center justify-between">
                            <div class="flex items-center gap-3 text-xs font-semibold text-slate-500">
                                <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm border-2 border-emerald-500 bg-emerald-400/20" /> high</span>
                                <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm border-2 border-amber-500 bg-amber-400/20" /> check</span>
                                <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm border-2 border-red-500 bg-red-400/20" /> low</span>
                            </div>
                            <div class="flex items-center gap-2 text-sm text-slate-600">
                                <button type="button" class="rounded-lg bg-white px-2.5 py-1 font-bold disabled:opacity-40" :disabled="page <= 1" @click="page--">‹</button>
                                Page {{ page }} / {{ numPages || '?' }}
                                <button type="button" class="rounded-lg bg-white px-2.5 py-1 font-bold disabled:opacity-40" :disabled="page >= numPages" @click="page++">›</button>
                            </div>
                        </div>

                        <p v-if="pdfError" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ pdfError }}</p>
                        <p v-else-if="pdfLoading" class="p-8 text-center text-sm text-slate-500">Loading document...</p>

                        <PdfPageCanvas v-if="doc" :doc="doc" :page-number="page">
                            <template #default="{ width, height }">
                                <div class="pointer-events-none absolute inset-0">
                                    <div v-for="box in boxesOnPage" :key="box.id"
                                        :class="['absolute rounded-sm border-2', ...boxClass(box)]"
                                        :style="{
                                            left: `${box.bbox[0] * width}px`,
                                            top: `${box.bbox[1] * height}px`,
                                            width: `${(box.bbox[2] - box.bbox[0]) * width}px`,
                                            height: `${(box.bbox[3] - box.bbox[1]) * height}px`,
                                        }">
                                        <span v-if="box.kind === 'field'"
                                            class="absolute -top-4 left-0 whitespace-nowrap rounded bg-slate-800/80 px-1 py-px text-[9px] font-bold text-white">
                                            {{ box.key }}
                                        </span>
                                    </div>
                                </div>
                            </template>
                        </PdfPageCanvas>
                    </div>

                    <!-- Right: validation panel -->
                    <div class="space-y-5">
                        <!-- Classification (unmatched/unclassified) -->
                        <div v-if="needsClassification" class="rounded-2xl border border-amber-200 bg-amber-50 p-5">
                            <h3 class="flex items-center gap-2 text-sm font-bold text-amber-800">
                                <ExclamationTriangleIcon class="h-5 w-5" />
                                Needs Classification
                            </h3>
                            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                <div v-if="!document.vendor_id">
                                    <label class="mb-1 block text-xs font-bold uppercase text-amber-700">Vendor</label>
                                    <select v-model="classifyForm.vendor_id" class="w-full rounded-lg border-amber-300 text-sm focus:border-emerald-500 focus:ring-emerald-500/30">
                                        <option :value="null">Select vendor...</option>
                                        <option v-for="vendor in vendors" :key="vendor.id" :value="vendor.id">{{ vendor.name }} ({{ vendor.code }})</option>
                                    </select>
                                </div>
                                <div v-if="!document.document_type">
                                    <label class="mb-1 block text-xs font-bold uppercase text-amber-700">Document Type</label>
                                    <select v-model="classifyForm.document_type" class="w-full rounded-lg border-amber-300 text-sm focus:border-emerald-500 focus:ring-emerald-500/30">
                                        <option :value="null">Select type...</option>
                                        <option v-for="type in documentTypes" :key="type" :value="type">{{ type.replaceAll('_', ' ') }}</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mt-3 flex items-center justify-between">
                                <label v-if="document.source === 'email' && !document.vendor_id" class="flex items-center gap-2 text-xs text-amber-700">
                                    <input v-model="classifyForm.remember_sender" type="checkbox" class="h-4 w-4 rounded border-amber-300 text-emerald-600" />
                                    Remember this sender for future emails
                                </label>
                                <button type="button" class="ml-auto rounded-xl bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700"
                                    @click="classify">
                                    Classify & Process
                                </button>
                            </div>
                        </div>

                        <!-- Not yet processed by OCR -->
                        <div v-if="!needsClassification && notYetProcessed"
                            class="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-5 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-start gap-3">
                                <ArrowPathIcon class="mt-0.5 h-5 w-5 flex-shrink-0 text-slate-400" />
                                <div>
                                    <h3 class="text-sm font-bold text-slate-700">
                                        {{ document.status === 'conversion_failed' || document.status === 'extraction_failed'
                                            ? 'OCR did not complete' : 'Not yet processed by OCR' }}
                                    </h3>
                                    <p class="mt-1 text-sm text-slate-500">
                                        No fields have been extracted yet. Run OCR to convert and extract this document —
                                        make sure the OCR service and queue worker are running.
                                    </p>
                                </div>
                            </div>
                            <button v-if="canRerun" type="button"
                                class="flex flex-shrink-0 items-center justify-center gap-1.5 rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-lg shadow-emerald-600/20 transition-all hover:bg-emerald-700"
                                @click="rerunOcr">
                                <ArrowPathIcon class="h-4 w-4" />
                                Run OCR
                            </button>
                        </div>

                        <!-- Totals reconciliation banner -->
                        <div v-if="totalsDelta !== null"
                            :class="['flex items-center justify-between rounded-2xl border p-4 text-sm font-semibold',
                                totalsOk ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-red-200 bg-red-50 text-red-800']">
                            <span>Line sum {{ money(lineSum) }} + tax {{ money(form.tax_amount) }} vs total {{ money(form.total_amount) }}</span>
                            <span>{{ totalsOk ? 'Reconciled ✓' : `Δ ${money(totalsDelta)}` }}</span>
                        </div>

                        <!-- Header fields -->
                        <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                            <div class="flex items-center justify-between">
                                <h3 class="text-xs font-bold uppercase tracking-widest text-slate-500">Header Fields</h3>
                                <span v-if="document.template_version_id" class="text-xs text-slate-400">
                                    Template: {{ document.template_version?.template?.name }}
                                </span>
                                <span v-else class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-700">No template — manual entry</span>
                            </div>
                            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                <div v-for="field in HEADER_FIELDS" :key="field.key" :class="field.key === 'vendor_address' ? 'sm:col-span-2' : ''">
                                    <label class="mb-1 flex items-center justify-between text-xs font-bold uppercase text-slate-400">
                                        {{ field.label }}
                                        <span v-if="chip(extractionField(field.key)?.confidence)"
                                            :class="['rounded-full px-1.5 py-0.5 text-[10px] font-bold normal-case', chip(extractionField(field.key)?.confidence)]">
                                            OCR {{ pct(extractionField(field.key)?.confidence) }}
                                        </span>
                                    </label>
                                    <input v-model="form[field.key]" :type="field.type" step="any" :disabled="!editable"
                                        class="w-full rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500/30 disabled:bg-slate-50"
                                        @focus="focusField(field.key)"
                                        @input="dirty = true" />
                                </div>
                            </div>
                        </div>

                        <!-- Exceptions -->
                        <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                            <h3 class="text-xs font-bold uppercase tracking-widest text-slate-500">
                                Exceptions
                                <span v-if="openExceptions.length" class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-700">{{ openExceptions.length }} open</span>
                            </h3>
                            <div class="mt-3 space-y-2">
                                <div v-for="exception in openExceptions" :key="exception.id"
                                    class="flex items-start justify-between gap-3 rounded-xl border border-slate-100 p-3">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <span :class="['rounded-full px-2 py-0.5 text-[10px] font-bold uppercase',
                                                exception.severity === 'blocker' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700']">
                                                {{ exception.severity }}
                                            </span>
                                            <span class="text-xs font-bold text-slate-500">{{ exception.rule_key }}</span>
                                        </div>
                                        <p class="mt-1 text-sm text-slate-700">{{ exception.message }}</p>
                                    </div>
                                    <div v-if="canResolveExceptions" class="flex flex-shrink-0 gap-1.5">
                                        <button type="button" title="Mark as fixed and close it — won't be raised again for this document."
                                            class="rounded-lg bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700 hover:bg-emerald-100"
                                            @click="resolveException(exception, 'resolved')">Resolve</button>
                                        <button type="button" title="Accept and proceed anyway (e.g. a known duplicate) — won't be raised again for this document."
                                            class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600 hover:bg-slate-200"
                                            @click="resolveException(exception, 'waived')">Waive</button>
                                    </div>
                                </div>
                                <p v-if="openExceptions.length === 0" class="text-sm text-slate-400">No open exceptions.</p>
                                <details v-if="closedExceptions.length" class="pt-1">
                                    <summary class="cursor-pointer text-xs font-semibold text-slate-400">{{ closedExceptions.length }} closed</summary>
                                    <div class="mt-2 space-y-1.5">
                                        <p v-for="exception in closedExceptions" :key="exception.id" class="text-xs text-slate-400">
                                            <span class="font-bold">{{ exception.rule_key }}</span> — {{ exception.status }}
                                            <template v-if="exception.resolution_note">({{ exception.resolution_note }})</template>
                                        </p>
                                    </div>
                                </details>
                            </div>
                        </div>

                        <!-- Activity -->
                        <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                            <h3 class="mb-4 text-xs font-bold uppercase tracking-widest text-slate-500">Activity</h3>
                            <DocumentTimeline :events="document.events" audience="admin" />
                        </div>
                    </div>
                </div>

                <!-- Line items (full width for readability) -->
                <div class="mt-6">
                    <IntakeLineItemsEditor
                        v-model="lineItems"
                        :columns="lineItemColumns"
                        :extraction-rows="extraction?.line_items || []"
                        :readonly="!editable"
                        @update:model-value="dirty = true"
                    />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
