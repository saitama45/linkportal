<script setup>
import { computed, reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import VendorLayout from '@/Layouts/VendorLayout.vue';
import DataTable from '@/Components/DataTable.vue';
import StatusBadge from '@/Components/Portal/StatusBadge.vue';

const props = defineProps({
    statuses: Object,
    filters: { type: Object, default: () => ({}) },
    statusOptions: { type: Array, default: () => [] },
});

const activeFilters = reactive({ status: props.filters.status || '' });
const extraParams = computed(() => (activeFilters.status ? { status: activeFilters.status } : {}));

const applyFilters = () => {
    router.get(route('vendor.accounts-payable.index'),
        { ...extraParams.value, search: props.filters.search || undefined },
        { preserveState: true, preserveScroll: true });
};

const money = (value) => (value == null ? '—' : Number(value).toLocaleString(undefined, { minimumFractionDigits: 2 }));
const formatDate = (value) => (value ? new Date(value).toLocaleDateString() : '—');
</script>

<template>
    <Head title="Payment Status - Link Portal" />

    <VendorLayout>
        <template #header>
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <h2 class="text-2xl font-black tracking-tight text-slate-900">Payment Status</h2>
                    <p class="mt-1 text-sm text-slate-500">Where your approved invoices stand with accounting.</p>
                </div>
                <select v-model="activeFilters.status" class="rounded-xl border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500/30" @change="applyFilters">
                    <option value="">All statuses</option>
                    <option v-for="status in statusOptions" :key="status" :value="status">{{ status.replaceAll('_', ' ') }}</option>
                </select>
            </div>
        </template>

        <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
            <DataTable
                title="Accounts Payable"
                subtitle="Status snapshot provided by accounting"
                search-placeholder="Search invoice no. or payment reference..."
                empty-message="No invoices in the payment pipeline yet."
                data-key="statuses"
                route-name="vendor.accounts-payable.index"
                :paginator="statuses"
                :initial-search="filters.search"
                :extra-params="extraParams"
            >
                <template #header>
                    <tr class="bg-slate-50">
                        <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Invoice No.</th>
                        <th class="border-b border-slate-100 px-6 py-4 text-right text-xs font-bold uppercase tracking-widest text-slate-500">Amount</th>
                        <th class="border-b border-slate-100 px-6 py-4 text-right text-xs font-bold uppercase tracking-widest text-slate-500">Paid</th>
                        <th class="border-b border-slate-100 px-6 py-4 text-right text-xs font-bold uppercase tracking-widest text-slate-500">Outstanding</th>
                        <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Status</th>
                        <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Mode</th>
                        <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Reference</th>
                        <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Paid Date</th>
                    </tr>
                </template>

                <template #body="{ data }">
                    <tr v-for="row in data" :key="row.id" class="border-b border-slate-50 transition-colors last:border-0 hover:bg-slate-50/50">
                        <td class="whitespace-nowrap px-6 py-4 text-sm font-black text-slate-900">{{ row.invoice_no }}</td>
                        <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-semibold text-slate-800">{{ money(row.invoice_amount) }}</td>
                        <td class="whitespace-nowrap px-6 py-4 text-right text-sm text-slate-600">{{ money(row.paid_amount) }}</td>
                        <td class="whitespace-nowrap px-6 py-4 text-right text-sm text-slate-600">{{ money(row.outstanding_amount) }}</td>
                        <td class="whitespace-nowrap px-6 py-4"><StatusBadge :status="row.status" /></td>
                        <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-600">{{ row.mode_of_payment || '—' }}</td>
                        <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-600">{{ row.payment_reference_no || '—' }}</td>
                        <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-600">{{ formatDate(row.paid_date) }}</td>
                    </tr>
                </template>
            </DataTable>
        </div>
    </VendorLayout>
</template>
