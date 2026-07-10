<script setup>
import { Head, Link } from '@inertiajs/vue3';
import VendorLayout from '@/Layouts/VendorLayout.vue';
import DataTable from '@/Components/DataTable.vue';
import StatusBadge from '@/Components/Portal/StatusBadge.vue';
import { PlusIcon, EyeIcon } from '@heroicons/vue/24/outline';

defineProps({
    documents: Object,
    filters: { type: Object, default: () => ({}) },
});

const formatDate = (value) => (value ? new Date(value).toLocaleDateString() : '—');
const typeLabel = (type) => ({ invoice: 'Invoice', purchase_order: 'Purchase Order', quotation: 'Quotation' }[type] || '—');
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

        <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
            <DataTable
                title="Uploaded Documents"
                subtitle="Documents you sent for processing"
                search-placeholder="Search reference, filename, invoice no..."
                empty-message="No documents yet. Upload your first document."
                data-key="documents"
                route-name="vendor.document-uploads.index"
                :paginator="documents"
                :initial-search="filters.search"
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
                        <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-slate-600">{{ doc.invoice_no || doc.po_number || '—' }}</td>
                        <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-600">{{ formatDate(doc.created_at) }}</td>
                        <td class="whitespace-nowrap px-6 py-4"><StatusBadge :status="doc.status" /></td>
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
