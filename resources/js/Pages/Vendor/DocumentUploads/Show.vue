<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import VendorLayout from '@/Layouts/VendorLayout.vue';
import StatusBadge from '@/Components/Portal/StatusBadge.vue';
import DocumentTimeline from '@/Components/Portal/DocumentTimeline.vue';
import PdfPageCanvas from '@/Components/Portal/Annotator/PdfPageCanvas.vue';
import { usePdfDocument } from '@/Composables/usePdfDocument';
import { usePdfViewport } from '@/Composables/usePdfViewport';
import { headerFieldsFor } from '@/Components/Portal/documentFields';
import {
    ArrowLeftIcon, MagnifyingGlassMinusIcon, MagnifyingGlassPlusIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    document: { type: Object, required: true },
    lineItems: { type: Array, default: () => [] },
    lineItemColumns: { type: Array, default: () => [] },
    templateFields: { type: Array, default: () => [] },
    validatedFields: { type: Object, default: () => ({}) },
    extractedFields: { type: Object, default: () => ({}) },
});

// Follows the document's template — its own fields under its own labels — so a
// custom field appears here instead of a fixed default set. Standard fields the
// template omits still show, since they back the columns shown on the record.
const headerFields = computed(() => headerFieldsFor(props.templateFields));

const PROMOTED_KEYS = new Set([
    'invoice_no', 'po_number', 'document_date', 'due_date', 'subtotal', 'tax_amount', 'total_amount',
]);

const fieldValue = (field) => {
    const raw = PROMOTED_KEYS.has(field.key)
        ? props.document[field.key]
        : (props.validatedFields?.[field.key] ?? props.extractedFields?.[field.key] ?? null);

    if (raw === null || raw === undefined || raw === '') return '—';
    if (field.type === 'date') return formatDate(raw);
    if (field.key === 'total_amount') return `${props.document.currency || ''} ${money(raw)}`.trim();

    return ['subtotal', 'tax_amount'].includes(field.key) ? money(raw) : raw;
};

// ---- document viewer (same zoom + right-drag pan as the staff screens) ----
const { doc, numPages, loading: pdfLoading, error: pdfError, load } = usePdfDocument();
const page = ref(1);
const {
    ZOOM_MIN, ZOOM_MAX, zoom, zoomPercent, zoomIn, zoomOut, zoomReset, onCanvasWheel,
    canvasScroll, isPanning, onCanvasPointerDown, onCanvasPointerMove, endPan,
} = usePdfViewport();

onMounted(() => load(route('vendor.document-uploads.file', props.document.id)));

const typeLabel = (type) => ({ invoice: 'Invoice', purchase_order: 'Purchase Order', quotation: 'Quotation' }[type] || 'Document');
const money = (value) => (value == null ? '—' : Number(value).toLocaleString(undefined, { minimumFractionDigits: 2 }));
const formatDate = (value) => (value ? new Date(value).toLocaleDateString() : '—');

// Amount-style columns are right-aligned and money-formatted; a quantity is
// numeric but not currency, and any custom column the template defines is text.
const AMOUNT_KEYS = new Set(['unit_price', 'line_total', 'amount', 'subtotal', 'tax']);
const isNumericColumn = (key) => AMOUNT_KEYS.has(key) || key === 'quantity';
const cellText = (row, key) => {
    const value = row[key];
    if (value === null || value === undefined || value === '') return '—';
    return AMOUNT_KEYS.has(key) ? money(value) : value;
};

const cancellable = ['received', 'conversion_failed', 'extraction_failed', 'needs_validation', 'returned'];

const cancel = () => {
    if (confirm('Cancel this document? It will no longer be processed.')) {
        router.put(route('vendor.document-uploads.cancel', props.document.id));
    }
};
</script>

<template>
    <Head :title="`${document.reference_no} - Link Portal`" />

    <VendorLayout>
        <template #header>
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div class="flex items-center gap-4">
                    <Link :href="route('vendor.document-uploads.index')"
                        class="rounded-lg p-2 text-slate-400 transition-all hover:bg-slate-100 hover:text-slate-600">
                        <ArrowLeftIcon class="h-5 w-5" />
                    </Link>
                    <div>
                        <h2 class="text-2xl font-black tracking-tight text-slate-900">{{ document.reference_no }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ typeLabel(document.document_type) }} · {{ document.original_filename }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <StatusBadge :status="document.status" />
                    <button v-if="cancellable.includes(document.status)" type="button"
                        class="rounded-xl border border-red-200 px-4 py-2 text-sm font-semibold text-red-600 transition-all hover:bg-red-50"
                        @click="cancel">
                        Cancel Document
                    </button>
                </div>
            </div>
        </template>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="min-w-0 space-y-6 lg:col-span-2">
                <div class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <h3 class="text-sm font-bold uppercase tracking-widest text-slate-500">Your Document</h3>
                        <div class="flex items-center gap-3">
                            <div v-if="numPages > 1" class="flex items-center gap-2 text-sm text-slate-600">
                                <button type="button" class="rounded-lg bg-slate-100 px-2.5 py-1 font-bold disabled:opacity-40" :disabled="page <= 1" @click="page--">‹</button>
                                Page {{ page }} / {{ numPages || '?' }}
                                <button type="button" class="rounded-lg bg-slate-100 px-2.5 py-1 font-bold disabled:opacity-40" :disabled="page >= numPages" @click="page++">›</button>
                            </div>
                            <div class="flex items-center gap-1 rounded-lg bg-slate-100 p-1 text-slate-600">
                                <button type="button" class="rounded-md p-1.5 hover:bg-white disabled:opacity-40" title="Zoom out"
                                    :disabled="zoom <= ZOOM_MIN" @click="zoomOut">
                                    <MagnifyingGlassMinusIcon class="h-4 w-4" />
                                </button>
                                <button type="button"
                                    class="min-w-[3.5rem] rounded-md px-1.5 py-1 text-center text-xs font-bold hover:bg-white"
                                    title="Reset to fit width" @click="zoomReset">
                                    {{ zoomPercent }}%
                                </button>
                                <button type="button" class="rounded-md p-1.5 hover:bg-white disabled:opacity-40" title="Zoom in"
                                    :disabled="zoom >= ZOOM_MAX" @click="zoomIn">
                                    <MagnifyingGlassPlusIcon class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    </div>

                    <p v-if="pdfError" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ pdfError }}</p>
                    <p v-else-if="pdfLoading" class="p-8 text-center text-sm text-slate-500">Loading document...</p>

                    <div ref="canvasScroll"
                        :class="['max-h-[70vh] overflow-auto rounded-lg bg-slate-50', isPanning ? 'cursor-grabbing select-none' : '']"
                        @wheel="onCanvasWheel"
                        @pointerdown="onCanvasPointerDown"
                        @pointermove="onCanvasPointerMove"
                        @pointerup="endPan"
                        @pointercancel="endPan"
                        @contextmenu.prevent>
                        <PdfPageCanvas v-if="doc" :doc="doc" :page-number="page" :zoom="zoom" />
                    </div>
                    <p class="mt-2 text-center text-[11px] text-slate-400">
                        Ctrl + scroll to zoom · hold right-click and drag to pan
                    </p>
                </div>

                <div class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
                    <h3 class="text-sm font-bold uppercase tracking-widest text-slate-500">Extracted Details</h3>
                    <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-3">
                        <div v-for="field in headerFields" :key="field.key">
                            <dt class="text-xs font-semibold uppercase text-slate-400">{{ field.label }}</dt>
                            <dd :class="['mt-1 text-sm', field.key === 'total_amount'
                                ? 'font-black text-slate-900' : 'font-semibold text-slate-800']">
                                {{ fieldValue(field) }}
                            </dd>
                        </div>
                    </dl>
                    <p class="mt-4 text-xs text-slate-400">
                        Details are read automatically and verified by our team before review. You'll be notified if anything needs your attention.
                    </p>
                </div>

                <div class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
                    <h3 class="text-sm font-bold uppercase tracking-widest text-slate-500">Line Items</h3>

                    <div v-if="lineItems.length" class="mt-4 overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr class="border-b border-slate-100">
                                    <th v-for="col in lineItemColumns" :key="col.key"
                                        :class="['pb-2 text-xs font-bold uppercase tracking-wide text-slate-400',
                                                 isNumericColumn(col.key) ? 'text-right' : '']">
                                        {{ col.label }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, index) in lineItems" :key="index" class="border-b border-slate-50 last:border-0">
                                    <td v-for="col in lineItemColumns" :key="col.key"
                                        :class="['py-2.5 text-slate-700',
                                                 isNumericColumn(col.key) ? 'text-right tabular-nums whitespace-nowrap' : '']">
                                        {{ cellText(row, col.key) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p v-else class="mt-4 text-sm text-slate-400">
                        No line items were read from this document.
                    </p>
                </div>

                <div v-if="document.external_decision" class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
                    <h3 class="text-sm font-bold uppercase tracking-widest text-slate-500">Review Outcome</h3>
                    <div class="mt-4 flex items-start gap-3">
                        <StatusBadge :status="document.external_decision" />
                        <div>
                            <p v-if="document.external_decision_remarks" class="text-sm text-slate-700">{{ document.external_decision_remarks }}</p>
                            <p class="mt-1 text-xs text-slate-400">{{ formatDate(document.external_decided_at) }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
                <h3 class="mb-6 text-sm font-bold uppercase tracking-widest text-slate-500">Progress</h3>
                <DocumentTimeline :events="document.events" audience="vendor" />
            </div>
        </div>
    </VendorLayout>
</template>
