<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import StatusBadge from '@/Components/Portal/StatusBadge.vue';
import PdfPageCanvas from '@/Components/Portal/Annotator/PdfPageCanvas.vue';
import AnnotationOverlay from '@/Components/Portal/Annotator/AnnotationOverlay.vue';
import FieldPalette from '@/Components/Portal/Annotator/FieldPalette.vue';
import TemplateTester from '@/Components/Portal/Annotator/TemplateTester.vue';
import { usePdfDocument } from '@/Composables/usePdfDocument';
import { ArrowLeftIcon, ArrowUturnLeftIcon, Bars3Icon, BeakerIcon, BookOpenIcon, CheckBadgeIcon, DocumentArrowUpIcon, PlusIcon, TrashIcon, XMarkIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    template: { type: Object, required: true },
    canEdit: { type: Boolean, default: false },
});

const STANDARD_FIELDS = [
    { key: 'invoice_no', label: 'Document No.', type: 'text', required: true },
    { key: 'document_date', label: 'Document Date', type: 'date', required: true },
    { key: 'due_date', label: 'Due Date', type: 'date', required: false },
    { key: 'po_number', label: 'PO Number', type: 'text', required: false },
    { key: 'vendor_address', label: 'Vendor Address', type: 'text', required: false },
    { key: 'subtotal', label: 'Subtotal', type: 'amount', required: false },
    { key: 'tax_amount', label: 'Tax', type: 'amount', required: false },
    { key: 'total_amount', label: 'Total', type: 'amount', required: true },
];

// ---- version selection ----
const versions = computed(() => props.template.versions || []);
const selectedVersionId = ref(
    (versions.value.find((v) => v.status === 'draft') || versions.value.find((v) => v.status === 'active') || versions.value[0])?.id ?? null,
);
const version = computed(() => versions.value.find((v) => v.id === selectedVersionId.value) || null);
const editable = computed(() => props.canEdit && version.value?.status === 'draft');

// ---- annotation state (local working copy) ----
const fields = ref([]);
const table = ref(null);
const dirty = ref(false);

const loadAnnotations = () => {
    const stored = version.value?.annotations;
    fields.value = stored?.fields?.length
        ? stored.fields.map((f) => ({ ...f }))
        : STANDARD_FIELDS.map((f) => ({ ...f, page: 1, bbox: null }));
    table.value = stored?.table ? JSON.parse(JSON.stringify(stored.table)) : null;
    dirty.value = false;
    undoStack.value = [];
};

// ---- undo history ----
// A drag emits many updates; we snapshot once when an interaction begins
// (mutate-start from the overlay) and suppress per-move snapshots meanwhile.
const undoStack = ref([]);
const MAX_HISTORY = 50;
let interacting = false;

const snapshot = () => JSON.stringify({ fields: fields.value, table: table.value });

const pushHistory = () => {
    const snap = snapshot();
    if (undoStack.value[undoStack.value.length - 1] === snap) return;
    undoStack.value.push(snap);
    if (undoStack.value.length > MAX_HISTORY) undoStack.value.shift();
};

const beginInteraction = () => { interacting = true; pushHistory(); };
const endInteraction = () => { interacting = false; };

const undo = () => {
    if (!undoStack.value.length) return;
    const prev = JSON.parse(undoStack.value.pop());
    fields.value = prev.fields;
    table.value = prev.table;
    selectedFieldKey.value = null;
    dirty.value = true;
};

const deleteSelection = () => {
    const field = fields.value.find((f) => f.key === selectedFieldKey.value);
    if (field?.bbox) {
        pushHistory();
        fields.value = fields.value.map((f) => (f.key === field.key ? { ...f, bbox: null } : f));
        dirty.value = true;
        return;
    }
    if (table.value) {
        pushHistory();
        table.value = null;
        dirty.value = true;
    }
};

// ---- pdf viewer ----
const { doc, numPages, loading: pdfLoading, error: pdfError, load } = usePdfDocument();
const page = ref(1);
const mode = ref('field');
const selectedFieldKey = ref(null);

const loadPdf = () => {
    if (version.value?.sample_file_path) {
        load(route('document-templates.versions.sample', [props.template.id, version.value.id]));
    }
};

// Discrete edits (palette toggles, clear) snapshot immediately; drag edits are
// already snapshotted at beginInteraction, so they skip while `interacting`.
const onFieldsUpdate = (updated) => { if (!interacting) pushHistory(); fields.value = updated; dirty.value = true; };
const onTableUpdate = (updated) => { if (!interacting) pushHistory(); table.value = updated; dirty.value = true; };
const clearTable = () => { pushHistory(); table.value = null; dirty.value = true; };

// ---- line-item column mapping ----
// The extractor + validation expect these keys; users pick which one each
// positional column holds so any vendor's left-to-right order can be matched.
const COLUMN_KEYS = [
    { key: 'description', label: 'Description' },
    { key: 'quantity', label: 'Quantity' },
    { key: 'uom', label: 'UOM' },
    { key: 'unit_price', label: 'Unit Price' },
    { key: 'line_total', label: 'Line Total' },
];

const usedColumnKeys = computed(() => new Set((table.value?.columns || []).map((c) => c.key)));

const setColumnKey = (index, key) => {
    const columns = table.value.columns.map((c, i) => (i === index ? { ...c, key } : c));
    onTableUpdate({ ...table.value, columns });
};

const removeColumn = (index) => {
    if (table.value.columns.length <= 1) return;
    const columns = table.value.columns.filter((_, i) => i !== index);
    onTableUpdate({ ...table.value, columns });
};

const addColumn = () => {
    const columns = [...table.value.columns];
    const last = columns[columns.length - 1];
    const mid = (last.x0 + last.x1) / 2;
    const unused = COLUMN_KEYS.find((k) => !usedColumnKeys.value.has(k.key))?.key;
    if (!unused) return;
    columns[columns.length - 1] = { ...last, x1: mid };
    columns.push({ key: unused, x0: mid, x1: last.x1 });
    onTableUpdate({ ...table.value, columns });
};

// Drag a column row to reorder. The x-boundaries are fixed positional slots
// (they trace the document), so reordering reassigns which key sits in each
// left-to-right slot rather than moving the boxes.
const dragColumnIndex = ref(null);

const onColumnDragStart = (event, index) => {
    dragColumnIndex.value = index;
    event.dataTransfer.effectAllowed = 'move';
};

const onColumnDrop = (index) => {
    const from = dragColumnIndex.value;
    dragColumnIndex.value = null;
    if (from === null || from === index) return;
    const cols = table.value.columns;
    const keys = cols.map((c) => c.key);
    const [moved] = keys.splice(from, 1);
    keys.splice(index, 0, moved);
    const columns = cols.map((c, i) => ({ ...c, key: keys[i] }));
    onTableUpdate({ ...table.value, columns });
};

const annotationsPayload = computed(() => ({
    schema: 1,
    fields: fields.value,
    table: table.value,
}));

// ---- actions ----
const save = () => {
    router.put(route('document-templates.versions.update', [props.template.id, version.value.id]),
        { annotations: annotationsPayload.value },
        { preserveScroll: true, onSuccess: () => { dirty.value = false; } });
};

const activate = () => {
    if (dirty.value) { alert('Save your annotation changes first.'); return; }
    router.put(route('document-templates.versions.activate', [props.template.id, version.value.id]), {}, { preserveScroll: true });
};

const uploadSample = (event) => {
    const file = event.target.files[0];
    if (!file) return;
    router.post(route('document-templates.versions.store', props.template.id),
        { sample: file },
        { preserveScroll: true, forceFormData: true });
    event.target.value = '';
};

// ---- test extract ----
const testResult = ref(null);
const testLoading = ref(false);
const testError = ref(null);

// Declared after testResult so the immediate run isn't in its temporal dead zone.
watch(selectedVersionId, () => {
    loadAnnotations();
    loadPdf();
    page.value = 1;
    testResult.value = null;
}, { immediate: true });

const runTest = async () => {
    testLoading.value = true;
    testError.value = null;
    try {
        const { data } = await axios.post(
            route('document-templates.versions.test-extract', [props.template.id, version.value.id]),
            { annotations: annotationsPayload.value },
        );
        testResult.value = data;
    } catch (e) {
        testError.value = e.response?.data?.message || 'Extraction test failed — is the OCR service running?';
    } finally {
        testLoading.value = false;
    }
};

const typeLabel = { invoice: 'Invoice', purchase_order: 'Purchase Order', quotation: 'Quotation' }[props.template.document_type];

// ---- keyboard shortcuts (annotator) ----
const onKeydown = (event) => {
    if (!editable.value) return;
    const target = event.target;
    const typing = ['input', 'textarea', 'select'].includes((target.tagName || '').toLowerCase()) || target.isContentEditable;

    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') {
        event.preventDefault();
        undo();
        return;
    }
    if (typing) return;
    if (event.key === 'Escape') {
        selectedFieldKey.value = null;
    } else if (event.key === 'Delete' || event.key === 'Backspace') {
        event.preventDefault();
        deleteSelection();
    }
};

onMounted(() => window.addEventListener('keydown', onKeydown));
onUnmounted(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Head :title="`${template.name} - OCR Template`" />

    <AppLayout>
        <template #header>
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div class="flex items-center gap-4">
                    <Link :href="route('document-templates.index')"
                        class="rounded-lg p-2 text-slate-400 transition-all hover:bg-slate-100 hover:text-slate-600">
                        <ArrowLeftIcon class="h-5 w-5" />
                    </Link>
                    <div>
                        <h2 class="text-2xl font-bold leading-tight text-slate-800">{{ template.name }}</h2>
                        <p class="mt-1 text-sm text-slate-500">
                            {{ typeLabel }} · {{ template.vendor?.name || 'Global fallback' }}
                        </p>
                    </div>
                    <StatusBadge :status="template.status" />
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <select v-if="versions.length" v-model="selectedVersionId"
                        class="rounded-xl border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500/30">
                        <option v-for="v in versions" :key="v.id" :value="v.id">
                            v{{ v.version_no }} — {{ v.status }}
                        </option>
                    </select>
                    <label v-if="canEdit"
                        class="flex cursor-pointer items-center gap-2 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-600 transition-all hover:bg-slate-50">
                        <DocumentArrowUpIcon class="h-4 w-4" />
                        New Version
                        <input type="file" accept=".pdf" class="hidden" @change="uploadSample" />
                    </label>
                    <button v-if="editable" type="button" :disabled="!dirty"
                        class="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-semibold text-white shadow-lg shadow-emerald-600/20 transition-all hover:bg-emerald-700 disabled:opacity-50"
                        @click="save">
                        Save Annotations
                    </button>
                    <button v-if="editable" type="button"
                        class="flex items-center gap-1.5 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-lg shadow-indigo-600/20 transition-all hover:bg-indigo-700"
                        @click="activate">
                        <CheckBadgeIcon class="h-4 w-4" />
                        Activate
                    </button>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-[110rem] px-4 sm:px-6">
                <div v-if="!version" class="rounded-2xl border border-dashed border-slate-300 bg-white p-16 text-center">
                    <DocumentArrowUpIcon class="mx-auto h-12 w-12 text-slate-300" />
                    <p class="mt-3 font-semibold text-slate-700">Upload a sample document to start annotating</p>
                    <p class="mt-1 text-sm text-slate-500">A representative PDF from this vendor. DOC/DOCX samples should be converted to PDF first.</p>
                    <label v-if="canEdit" class="mt-5 inline-flex cursor-pointer items-center gap-2 rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">
                        <DocumentArrowUpIcon class="h-5 w-5" />
                        Upload Sample PDF
                        <input type="file" accept=".pdf" class="hidden" @change="uploadSample" />
                    </label>
                </div>

                <div v-else class="grid gap-6 xl:grid-cols-[1fr_22rem]">
                    <!-- Document canvas -->
                    <div class="rounded-2xl border border-slate-100 bg-slate-50 p-4 shadow-sm">
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                            <div class="flex items-center gap-2">
                                <button type="button"
                                    :class="['rounded-lg px-3 py-1.5 text-xs font-bold transition-all', mode === 'field' ? 'bg-emerald-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-100']"
                                    @click="mode = 'field'">
                                    Header Fields
                                </button>
                                <button type="button"
                                    :class="['rounded-lg px-3 py-1.5 text-xs font-bold transition-all', mode === 'table' ? 'bg-indigo-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-100']"
                                    @click="mode = 'table'">
                                    Line-Item Table
                                </button>
                                <button v-if="table && editable" type="button"
                                    class="flex items-center gap-1 rounded-lg bg-white px-2.5 py-1.5 text-xs font-semibold text-red-500 hover:bg-red-50"
                                    @click="clearTable">
                                    <TrashIcon class="h-3.5 w-3.5" /> Clear table
                                </button>
                                <button v-if="editable" type="button" :disabled="!undoStack.length"
                                    class="flex items-center gap-1 rounded-lg bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-100 disabled:opacity-40"
                                    title="Undo (Ctrl+Z)"
                                    @click="undo">
                                    <ArrowUturnLeftIcon class="h-3.5 w-3.5" /> Undo
                                </button>
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
                                <AnnotationOverlay
                                    :fields="fields"
                                    :table="table"
                                    :page="page"
                                    :width="width"
                                    :height="height"
                                    :selected-field-key="selectedFieldKey"
                                    :mode="mode"
                                    :readonly="!editable"
                                    @update:fields="onFieldsUpdate"
                                    @update:table="onTableUpdate"
                                    @select="selectedFieldKey = $event"
                                    @mutate-start="beginInteraction"
                                    @mutate-end="endInteraction"
                                />
                            </template>
                        </PdfPageCanvas>

                    </div>

                    <!-- Side panel -->
                    <div class="space-y-4">
                        <!-- Contextual instructions -->
                        <div :class="['rounded-2xl border p-4 text-sm shadow-sm', mode === 'table' ? 'border-indigo-100 bg-indigo-50/60' : 'border-emerald-100 bg-emerald-50/60']">
                            <h3 class="mb-2 flex items-center gap-2 text-xs font-bold uppercase tracking-widest" :class="mode === 'table' ? 'text-indigo-700' : 'text-emerald-700'">
                                <BookOpenIcon class="h-4 w-4" />
                                {{ mode === 'table' ? 'How to map line items' : 'How to map header fields' }}
                            </h3>
                            <ol v-if="mode === 'table'" class="list-decimal space-y-1.5 pl-4 text-slate-600">
                                <li>Drag <strong>one box over the table's data rows</strong> — start just below the header row (MODULE / DESCRIPTION / etc.) and cover down to the last row.</li>
                                <li>Don't include the header row — the extractor reads each row <em>inside</em> the box and turns it into one line item.</li>
                                <li>Drag the <strong>vertical dividers</strong> on the page to line up each column boundary with the document.</li>
                                <li>Turn on <strong>“Repeat on following pages”</strong> below if the table continues onto later pages.</li>
                            </ol>
                            <ol v-else class="list-decimal space-y-1.5 pl-4 text-slate-600">
                                <li><strong>Click a field</strong> on the right (Document No., Total, …) to select it.</li>
                                <li><strong>Drag a box</strong> around that field's value on the document.</li>
                                <li>Drag a box's body to move it; drag the <strong>corner dots</strong> to resize.</li>
                                <li>Only fields you box are read — leave the ones you don't need empty.</li>
                            </ol>
                            <p class="mt-2.5 border-t pt-2 text-xs text-slate-500" :class="mode === 'table' ? 'border-indigo-100' : 'border-emerald-100'">
                                Use <strong>Test Extract</strong> anytime to preview results. When it looks right, <strong>Save Annotations</strong> then <strong>Activate</strong> to make this version live.
                            </p>
                            <p class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-slate-400">
                                <span><kbd class="rounded bg-white px-1 font-mono shadow-sm">Esc</kbd> deselect</span>
                                <span><kbd class="rounded bg-white px-1 font-mono shadow-sm">Del</kbd> remove box/table</span>
                                <span><kbd class="rounded bg-white px-1 font-mono shadow-sm">Ctrl</kbd>+<kbd class="rounded bg-white px-1 font-mono shadow-sm">Z</kbd> undo</span>
                            </p>
                        </div>

                        <!-- Line-item table settings (always visible while a table exists) -->
                        <div v-if="table" class="rounded-2xl border border-indigo-100 bg-white p-4 shadow-sm">
                            <div class="mb-3 flex items-center justify-between">
                                <h3 class="text-xs font-bold uppercase tracking-widest text-slate-500">Line-Item Table</h3>
                                <button v-if="editable" type="button" class="flex items-center gap-1 text-xs font-semibold text-red-500 hover:text-red-600" @click="clearTable">
                                    <TrashIcon class="h-3.5 w-3.5" /> Clear
                                </button>
                            </div>
                            <p class="mb-2 text-[11px] font-semibold uppercase text-slate-400">Columns (left → right)</p>
                            <div class="space-y-2">
                                <div v-for="(col, index) in table.columns" :key="index"
                                    class="flex items-center gap-1.5 rounded-lg transition-colors"
                                    :class="dragColumnIndex === index ? 'opacity-40' : ''"
                                    @dragover.prevent
                                    @drop="onColumnDrop(index)">
                                    <span v-if="editable" class="flex-shrink-0 cursor-grab text-slate-300 hover:text-slate-500 active:cursor-grabbing"
                                        draggable="true" title="Drag to reorder"
                                        @dragstart="onColumnDragStart($event, index)"
                                        @dragend="dragColumnIndex = null">
                                        <Bars3Icon class="h-4 w-4" />
                                    </span>
                                    <span class="w-4 text-center text-xs font-bold text-slate-400">{{ index + 1 }}</span>
                                    <select :value="col.key" :disabled="!editable"
                                        class="min-w-0 flex-1 rounded-lg border-slate-300 text-xs focus:border-indigo-500 focus:ring-indigo-500/30"
                                        @change="setColumnKey(index, $event.target.value)">
                                        <option v-for="opt in COLUMN_KEYS" :key="opt.key" :value="opt.key"
                                            :disabled="opt.key !== col.key && usedColumnKeys.has(opt.key)">
                                            {{ opt.label }}
                                        </option>
                                    </select>
                                    <button v-if="editable && table.columns.length > 1" type="button"
                                        class="flex-shrink-0 rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-500"
                                        title="Remove column" @click="removeColumn(index)">
                                        <XMarkIcon class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                            <button v-if="editable && table.columns.length < COLUMN_KEYS.length" type="button"
                                class="mt-2 flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:text-indigo-700"
                                @click="addColumn">
                                <PlusIcon class="h-3.5 w-3.5" /> Add column
                            </button>
                            <p class="mt-2 text-[11px] text-slate-400">Match each column to the value in that position of the document. Drag the vertical dividers on the page to adjust boundaries.</p>
                            <label class="mt-3 flex items-center gap-2 text-sm text-slate-600">
                                <input type="checkbox" :checked="table.repeat_on_following_pages" :disabled="!editable"
                                    class="h-4 w-4 rounded border-slate-300 text-indigo-600"
                                    @change="onTableUpdate({ ...table, repeat_on_following_pages: $event.target.checked })" />
                                Repeat on following pages
                            </label>
                        </div>
                        <div v-else-if="mode === 'table'" class="rounded-2xl border border-dashed border-indigo-200 bg-white p-4 text-sm text-slate-500 shadow-sm">
                            Draw a box over the table's data rows on the document to begin.
                        </div>

                        <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                            <h3 class="mb-3 text-xs font-bold uppercase tracking-widest text-slate-500">Header Fields</h3>
                            <FieldPalette
                                :fields="fields"
                                :selected-key="selectedFieldKey"
                                :readonly="!editable"
                                @select="selectedFieldKey = $event; mode = 'field'"
                                @update:fields="onFieldsUpdate"
                            />
                        </div>
                    </div>
                </div>

                <!-- Test Extract — full width so line-item results are readable -->
                <div v-if="version" class="mt-6 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                    <button type="button"
                        class="flex w-full items-center justify-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700 transition-all hover:bg-emerald-100"
                        @click="runTest">
                        <BeakerIcon class="h-5 w-5" />
                        Test Extract
                    </button>
                    <div class="mt-4">
                        <TemplateTester :result="testResult" :loading="testLoading" :error="testError" />
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
