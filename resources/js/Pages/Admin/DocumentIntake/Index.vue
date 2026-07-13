<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from '@/Components/DataTable.vue';
import StatusBadge from '@/Components/Portal/StatusBadge.vue';
import { EyeIcon, PencilSquareIcon, ExclamationTriangleIcon, ArrowUpTrayIcon, XMarkIcon, TrashIcon } from '@heroicons/vue/24/outline';
import { useConfirm } from '@/Composables/useConfirm';

const props = defineProps({
    documents: Object,
    filters: { type: Object, default: () => ({}) },
    statuses: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
    canUpload: { type: Boolean, default: false },
    canDelete: { type: Boolean, default: false },
    vendors: { type: Array, default: () => [] },
});

const { confirm } = useConfirm();

const withAccounting = ['sending', 'pending_external_review'];
// Documents that haven't been validated yet — still editable / re-runnable.
const preValidationStatuses = ['received', 'converting', 'conversion_failed', 'extracting', 'extraction_failed', 'needs_validation'];
const deleteDocument = async (doc) => {
    const ok = await confirm({
        title: 'Delete Document',
        message: `Delete ${doc.reference_no}? This removes it from the intake inbox.`,
        confirmButtonText: 'Delete',
        type: 'danger',
    });
    if (ok) {
        router.delete(route('document-intake.destroy', doc.id), { preserveScroll: true });
    }
};

const showUpload = ref(false);
const uploadForm = useForm({
    vendor_id: '',
    document_type: '',
    file: null,
});

const openUpload = () => {
    uploadForm.reset();
    uploadForm.clearErrors();
    showUpload.value = true;
};

const onFile = (event) => {
    uploadForm.file = event.target.files[0] || null;
};

const submitUpload = () => {
    uploadForm.post(route('document-intake.store'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => { showUpload.value = false; },
    });
};

// ---- Edit header (vendor / document type) then re-run OCR ----
const showEdit = ref(false);
const editDocId = ref(null);
const editRef = ref('');
const editForm = useForm({ vendor_id: '', document_type: '' });

const openEdit = (doc) => {
    editDocId.value = doc.id;
    editRef.value = doc.reference_no;
    editForm.vendor_id = doc.vendor_id ?? '';
    editForm.document_type = doc.document_type ?? '';
    editForm.clearErrors();
    showEdit.value = true;
};

const submitEdit = () => {
    editForm.put(route('document-intake.classify', editDocId.value), {
        preserveScroll: true,
        onSuccess: () => { showEdit.value = false; },
    });
};

const activeFilters = reactive({
    status: props.filters.status || '',
    document_type: props.filters.document_type || '',
    source: props.filters.source || '',
    date_from: props.filters.date_from || '',
    date_to: props.filters.date_to || '',
});

const extraParams = computed(() => {
    const params = {};
    for (const [key, value] of Object.entries(activeFilters)) {
        if (value) params[key] = value;
    }
    return params;
});

const applyFilters = () => {
    router.get(route('document-intake.index'),
        { ...extraParams.value, search: props.filters.search || undefined },
        { preserveState: true, preserveScroll: true });
};

const typeLabel = (type) => ({ invoice: 'Invoice', purchase_order: 'PO', quotation: 'Quotation' }[type] || 'Unclassified');
const formatDate = (value) => (value ? new Date(value).toLocaleDateString() : '—');
const confidencePct = (value) => (value == null ? '—' : `${Math.round(value * 100)}%`);
</script>

<template>
    <Head title="Document Intake - Link Portal" />

    <AppLayout>
        <template #header>
            <div>
                <h2 class="text-2xl font-bold leading-tight text-slate-800">Document Intake</h2>
                <p class="mt-1 text-sm text-slate-500">Vendor documents received for OCR processing and validation.</p>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl space-y-4 sm:px-6 lg:px-8">
                <!-- Summary cards -->
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                        <p class="text-xs font-bold uppercase tracking-widest text-slate-400">Pending Validation</p>
                        <p class="mt-1 text-3xl font-black text-slate-900">{{ stats.pending_validation ?? 0 }}</p>
                    </div>
                    <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                        <p class="text-xs font-bold uppercase tracking-widest text-slate-400">With Accounting</p>
                        <p class="mt-1 text-3xl font-black text-slate-900">{{ stats.pending_review ?? 0 }}</p>
                    </div>
                    <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                        <p class="text-xs font-bold uppercase tracking-widest text-slate-400">Open Exceptions</p>
                        <div class="mt-1 flex items-baseline gap-3">
                            <p class="text-3xl font-black text-slate-900">{{ stats.open_exceptions ?? 0 }}</p>
                            <p v-if="stats.exception_aging" class="text-xs font-semibold text-slate-400">
                                0-3d: {{ stats.exception_aging['0-3'] }} ·
                                4-7d: <span :class="stats.exception_aging['4-7'] ? 'text-amber-600' : ''">{{ stats.exception_aging['4-7'] }}</span> ·
                                8+d: <span :class="stats.exception_aging['8+'] ? 'text-red-600' : ''">{{ stats.exception_aging['8+'] }}</span>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="flex flex-wrap items-end gap-3 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                    <div>
                        <label class="mb-1 block text-xs font-bold uppercase text-slate-400">Status</label>
                        <select v-model="activeFilters.status" class="rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500/30" @change="applyFilters">
                            <option value="">All</option>
                            <option v-for="status in statuses" :key="status" :value="status">{{ status.replaceAll('_', ' ') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-bold uppercase text-slate-400">Type</label>
                        <select v-model="activeFilters.document_type" class="rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500/30" @change="applyFilters">
                            <option value="">All</option>
                            <option value="invoice">Invoice</option>
                            <option value="purchase_order">Purchase Order</option>
                            <option value="quotation">Quotation</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-bold uppercase text-slate-400">Source</label>
                        <select v-model="activeFilters.source" class="rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500/30" @change="applyFilters">
                            <option value="">All</option>
                            <option value="portal_upload">Portal Upload</option>
                            <option value="email">Email</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-bold uppercase text-slate-400">From</label>
                        <input v-model="activeFilters.date_from" type="date" class="rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500/30" @change="applyFilters" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-bold uppercase text-slate-400">To</label>
                        <input v-model="activeFilters.date_to" type="date" class="rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500/30" @change="applyFilters" />
                    </div>
                </div>

                <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
                    <DataTable
                        title="Intake Inbox"
                        subtitle="Uploaded and emailed vendor documents"
                        search-placeholder="Search reference, vendor, filename..."
                        empty-message="No documents match the current filters."
                        data-key="documents"
                        route-name="document-intake.index"
                        :paginator="documents"
                        :initial-search="filters.search"
                        :extra-params="extraParams"
                    >
                        <template #actions>
                            <button v-if="canUpload" type="button"
                                class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition-all hover:bg-emerald-700"
                                @click="openUpload">
                                <ArrowUpTrayIcon class="h-4 w-4" />
                                Upload Document
                            </button>
                        </template>

                        <template #header>
                            <tr class="bg-slate-50">
                                <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Reference</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Vendor</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Type</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Source</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Received</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-right text-xs font-bold uppercase tracking-widest text-slate-500">Confidence</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Status</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-right text-xs font-bold uppercase tracking-widest text-slate-500">Actions</th>
                            </tr>
                        </template>

                        <template #body="{ data }">
                            <tr v-for="doc in data" :key="doc.id" class="border-b border-slate-50 transition-colors last:border-0 hover:bg-slate-50/50">
                                <td class="whitespace-nowrap px-6 py-4 text-sm font-black text-slate-900">
                                    <div class="flex items-center gap-2">
                                        {{ doc.reference_no }}
                                        <span v-if="doc.open_exceptions_count > 0"
                                            class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-700"
                                            :title="`${doc.open_exceptions_count} open exception(s)`">
                                            <ExclamationTriangleIcon class="h-3.5 w-3.5" />
                                            {{ doc.open_exceptions_count }}
                                        </span>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-600">{{ doc.vendor?.name || 'Unmatched' }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-600">{{ typeLabel(doc.document_type) }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm capitalize text-slate-600">{{ doc.source.replaceAll('_', ' ') }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-600">{{ formatDate(doc.created_at) }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-semibold text-slate-700">{{ confidencePct(doc.overall_confidence) }}</td>
                                <td class="whitespace-nowrap px-6 py-4"><StatusBadge :status="doc.status" /></td>
                                <td class="whitespace-nowrap px-6 py-4 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <button v-if="preValidationStatuses.includes(doc.status)" type="button"
                                            class="inline-flex rounded-lg p-2 text-slate-400 transition-all hover:bg-indigo-50 hover:text-indigo-600" title="Edit vendor/type & re-run OCR"
                                            @click="openEdit(doc)">
                                            <PencilSquareIcon class="h-5 w-5" />
                                        </button>
                                        <Link :href="route('document-intake.show', doc.id)"
                                            class="inline-flex rounded-lg p-2 text-slate-400 transition-all hover:bg-emerald-50 hover:text-emerald-600" title="View">
                                            <EyeIcon class="h-5 w-5" />
                                        </Link>
                                        <button v-if="canDelete && !withAccounting.includes(doc.status)" type="button"
                                            class="inline-flex rounded-lg p-2 text-slate-400 transition-all hover:bg-red-50 hover:text-red-600" title="Delete"
                                            @click="deleteDocument(doc)">
                                            <TrashIcon class="h-5 w-5" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </DataTable>
                </div>
            </div>
        </div>

        <!-- Admin upload modal (uploads on behalf of a vendor into the same pipeline) -->
        <Teleport to="body">
            <div v-if="showUpload" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4" @click.self="showUpload = false">
                <div class="w-full max-w-lg rounded-2xl bg-white shadow-xl">
                    <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                        <div>
                            <h3 class="text-lg font-bold text-slate-900">Upload Document</h3>
                            <p class="text-sm text-slate-500">Submit a document on behalf of a vendor.</p>
                        </div>
                        <button type="button" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600" @click="showUpload = false">
                            <XMarkIcon class="h-5 w-5" />
                        </button>
                    </div>

                    <form class="space-y-4 px-6 py-5" @submit.prevent="submitUpload">
                        <div>
                            <label class="mb-1 block text-xs font-bold uppercase text-slate-400">Vendor</label>
                            <select v-model="uploadForm.vendor_id"
                                class="block w-full rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500/30">
                                <option value="" disabled>Select a vendor…</option>
                                <option v-for="vendor in vendors" :key="vendor.id" :value="vendor.id">
                                    {{ vendor.name }}<span v-if="vendor.code"> ({{ vendor.code }})</span>
                                </option>
                            </select>
                            <p v-if="uploadForm.errors.vendor_id" class="mt-1 text-xs text-red-600">{{ uploadForm.errors.vendor_id }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-bold uppercase text-slate-400">Document Type</label>
                            <select v-model="uploadForm.document_type"
                                class="block w-full rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500/30">
                                <option value="" disabled>Select a type…</option>
                                <option value="invoice">Invoice</option>
                                <option value="purchase_order">Purchase Order</option>
                                <option value="quotation">Quotation</option>
                            </select>
                            <p v-if="uploadForm.errors.document_type" class="mt-1 text-xs text-red-600">{{ uploadForm.errors.document_type }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-bold uppercase text-slate-400">File</label>
                            <input type="file" accept=".pdf,.doc,.docx" class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-emerald-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-emerald-700 hover:file:bg-emerald-100"
                                @change="onFile" />
                            <p class="mt-1 text-xs text-slate-400">PDF, DOC, or DOCX · max 20 MB</p>
                            <p v-if="uploadForm.errors.file" class="mt-1 text-xs text-red-600">{{ uploadForm.errors.file }}</p>
                        </div>

                        <div class="flex items-center justify-end gap-3 pt-2">
                            <button type="button" class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100" @click="showUpload = false">
                                Cancel
                            </button>
                            <button type="submit" :disabled="uploadForm.processing"
                                class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-5 py-2 text-sm font-semibold text-white transition-all hover:bg-emerald-700 disabled:opacity-60">
                                <ArrowUpTrayIcon class="h-4 w-4" />
                                {{ uploadForm.processing ? 'Uploading…' : 'Upload' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <!-- Edit header (vendor / document type) then re-run OCR -->
        <Teleport to="body">
            <div v-if="showEdit" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4" @click.self="showEdit = false">
                <div class="w-full max-w-lg rounded-2xl bg-white shadow-xl">
                    <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                        <div>
                            <h3 class="text-lg font-bold text-slate-900">Edit Document</h3>
                            <p class="text-sm text-slate-500">
                                Correct the vendor / type for <span class="font-semibold">{{ editRef }}</span> — this re-resolves the OCR template and re-runs extraction.
                            </p>
                        </div>
                        <button type="button" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600" @click="showEdit = false">
                            <XMarkIcon class="h-5 w-5" />
                        </button>
                    </div>

                    <form class="space-y-4 px-6 py-5" @submit.prevent="submitEdit">
                        <div>
                            <label class="mb-1 block text-xs font-bold uppercase text-slate-400">Vendor</label>
                            <select v-model="editForm.vendor_id"
                                class="block w-full rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500/30">
                                <option value="" disabled>Select a vendor…</option>
                                <option v-for="vendor in vendors" :key="vendor.id" :value="vendor.id">
                                    {{ vendor.name }}<span v-if="vendor.code"> ({{ vendor.code }})</span>
                                </option>
                            </select>
                            <p v-if="editForm.errors.vendor_id" class="mt-1 text-xs text-red-600">{{ editForm.errors.vendor_id }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-bold uppercase text-slate-400">Document Type</label>
                            <select v-model="editForm.document_type"
                                class="block w-full rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500/30">
                                <option value="" disabled>Select a type…</option>
                                <option value="invoice">Invoice</option>
                                <option value="purchase_order">Purchase Order</option>
                                <option value="quotation">Quotation</option>
                            </select>
                            <p v-if="editForm.errors.document_type" class="mt-1 text-xs text-red-600">{{ editForm.errors.document_type }}</p>
                        </div>

                        <p class="rounded-lg bg-indigo-50 px-3 py-2 text-xs text-indigo-700">
                            Saving re-runs OCR using the template that matches this vendor + type. Make sure the version you want is <span class="font-semibold">Active</span> in OCR Templates.
                        </p>

                        <div class="flex items-center justify-end gap-3 pt-2">
                            <button type="button" class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100" @click="showEdit = false">
                                Cancel
                            </button>
                            <button type="submit" :disabled="editForm.processing"
                                class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-5 py-2 text-sm font-semibold text-white transition-all hover:bg-indigo-700 disabled:opacity-60">
                                <PencilSquareIcon class="h-4 w-4" />
                                {{ editForm.processing ? 'Saving…' : 'Save & re-run OCR' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
