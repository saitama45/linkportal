<script setup>
import { computed, reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import VendorLayout from '@/Layouts/VendorLayout.vue';
import DataTable from '@/Components/DataTable.vue';
import StatusBadge from '@/Components/Portal/StatusBadge.vue';
import { PlusIcon, EyeIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    documents: Object,
    filters: { type: Object, default: () => ({}) },
    documentTypes: { type: Array, default: () => [] },
});

const activeFilters = reactive({
    document_type: props.filters.document_type || '',
    status: props.filters.status || '',
});

// Preserved across DataTable's search requests so a search doesn't drop the tab.
const extraParams = computed(() => {
    const params = {};
    for (const [key, value] of Object.entries(activeFilters)) {
        if (value) params[key] = value;
    }
    return params;
});

const applyFilters = () => {
    router.get(route('vendor.document-uploads.index'),
        { ...extraParams.value, search: props.filters.search || undefined },
        { preserveState: true, preserveScroll: true });
};

const selectType = (type) => {
    activeFilters.document_type = type;
    applyFilters();
};

const typeLabel = (type) => ({ invoice: 'Invoice', purchase_order: 'Purchase Order', quotation: 'Quotation' }[type] || '—');
const typeTabs = computed(() => [
    { value: '', label: 'All' },
    ...props.documentTypes.map((t) => ({ value: t, label: `${typeLabel(t)}s` })),
]);

// Vendor-facing statuses, grouped into the handful of outcomes a vendor cares
// about rather than every internal pipeline state.
const statusOptions = [
    { value: '', label: 'Any status' },
    { value: 'needs_validation', label: 'In review' },
    { value: 'pending_external_review', label: 'With accounting' },
    { value: 'approved', label: 'Approved' },
    { value: 'returned', label: 'Returned to me' },
    { value: 'rejected', label: 'Rejected' },
];

const formatDate = (value) => (value ? new Date(value).toLocaleDateString() : '—');
const docNo = (doc) => doc.invoice_no || doc.po_number || '—';

// Fulfillment badge for approved POs (attached server-side; absent on other rows).
const fulfillmentBadge = {
    open: { label: 'Awaiting invoice', class: 'bg-slate-100 text-slate-600' },
    partially_invoiced: { label: 'Partially invoiced', class: 'bg-amber-100 text-amber-700' },
    fully_invoiced: { label: 'Fully invoiced', class: 'bg-emerald-100 text-emerald-700' },
    expired: { label: 'Expired', class: 'bg-red-100 text-red-700' },
};
</script>

<template>
    <Head title="Document Uploads - Link Portal" />

    <VendorLayout>
        <template #header>
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <h2 class="text-2xl font-black tracking-tight text-slate-900">Document Uploads</h2>
                    <p class="mt-1 text-sm text-slate-500">Upload invoices, purchase orders, and quotations — we read them for you.</p>
                </div>
            </div>
        </template>

        <!-- Type tabs + status filter -->
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-1.5">
                <button v-for="tab in typeTabs" :key="tab.value" type="button"
                    :class="[
                        'rounded-xl px-4 py-2 text-xs font-bold transition-all',
                        activeFilters.document_type === tab.value
                            ? 'bg-emerald-600 text-white shadow-sm shadow-emerald-600/20'
                            : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50',
                    ]"
                    @click="selectType(tab.value)">
                    {{ tab.label }}
                </button>
            </div>
            <select v-model="activeFilters.status"
                class="rounded-xl border-slate-200 text-xs font-semibold text-slate-600 focus:border-emerald-500 focus:ring-emerald-500/30"
                @change="applyFilters">
                <option v-for="option in statusOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
        </div>

        <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
            <DataTable
                :title="activeFilters.document_type ? `${typeLabel(activeFilters.document_type)}s` : 'Uploaded Documents'"
                subtitle="Documents you sent for processing"
                search-placeholder="Search reference, filename, invoice or PO no..."
                empty-message="No documents match this filter."
                data-key="documents"
                route-name="vendor.document-uploads.index"
                :paginator="documents"
                :initial-search="filters.search"
                :extra-params="extraParams"
            >
                <template #actions>
                    <Link :href="route('vendor.document-uploads.create')"
                        class="flex items-center space-x-2 rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-emerald-600/20 transition-all hover:bg-emerald-700">
                        <PlusIcon class="h-5 w-5" />
                        <span>Upload Document</span>
                    </Link>
                </template>

                <template #header>
                    <tr class="bg-slate-50">
                        <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Reference</th>
                        <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Type</th>
                        <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">File</th>
                        <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Doc No.</th>
                        <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Uploaded</th>
                        <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Status</th>
                        <th class="border-b border-slate-100 px-6 py-4 text-right text-xs font-bold uppercase tracking-widest text-slate-500">Actions</th>
                    </tr>
                </template>

                <template #body="{ data }">
                    <tr v-for="doc in data" :key="doc.id" class="border-b border-slate-50 transition-colors last:border-0 hover:bg-slate-50/50">
                        <td class="whitespace-nowrap px-6 py-4 text-sm font-black text-slate-900">{{ doc.reference_no }}</td>
                        <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-600">{{ typeLabel(doc.document_type) }}</td>
                        <td class="max-w-48 truncate px-6 py-4 text-sm text-slate-600" :title="doc.original_filename">{{ doc.original_filename }}</td>
                        <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-slate-600">{{ docNo(doc) }}</td>
                        <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-600">{{ formatDate(doc.created_at) }}</td>
                        <td class="whitespace-nowrap px-6 py-4">
                            <div class="flex items-center gap-2">
                                <StatusBadge :status="doc.status" />
                                <span v-if="doc.fulfillment"
                                    :class="['rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide', fulfillmentBadge[doc.fulfillment].class]">
                                    {{ fulfillmentBadge[doc.fulfillment].label }}
                                </span>
                            </div>
                        </td>
                        <td class="whitespace-nowrap px-6 py-4 text-right">
                            <Link :href="route('vendor.document-uploads.show', doc.id)"
                                class="inline-flex rounded-lg p-2 text-slate-400 transition-all hover:bg-emerald-50 hover:text-emerald-600" title="Track">
                                <EyeIcon class="h-5 w-5" />
                            </Link>
                        </td>
                    </tr>
                </template>
            </DataTable>
        </div>
    </VendorLayout>
</template>
