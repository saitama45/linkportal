<script setup>
import { computed, reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
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
    router.get(route('accounts-payable.index'),
        { ...extraParams.value, search: props.filters.search || undefined },
        { preserveState: true, preserveScroll: true });
};

const money = (value) => (value == null ? '—' : Number(value).toLocaleString(undefined, { minimumFractionDigits: 2 }));
const formatDate = (value) => (value ? new Date(value).toLocaleDateString() : '—');
const formatDateTime = (value) => (value ? new Date(value).toLocaleString() : '—');
</script>

<template>
    <Head title="Accounts Payable - Link Portal" />

    <AppLayout>
        <template #header>
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <h2 class="text-2xl font-bold leading-tight text-slate-800">Accounts Payable Snapshot</h2>
                    <p class="mt-1 text-sm text-slate-500">Vendor invoice payment statuses synced from accounting. Display only — not the ledger.</p>
                </div>
                <select v-model="activeFilters.status" class="rounded-xl border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500/30" @change="applyFilters">
                    <option value="">All statuses</option>
                    <option v-for="status in statusOptions" :key="status" :value="status">{{ status.replaceAll('_', ' ') }}</option>
                </select>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
                    <DataTable
                        title="AP Statuses"
                        subtitle="One row per vendor invoice"
                        search-placeholder="Search invoice, reference, vendor..."
                        empty-message="No AP records yet."
                        data-key="statuses"
                        route-name="accounts-payable.index"
                        :paginator="statuses"
                        :initial-search="filters.search"
                        :extra-params="extraParams"
                    >
                        <template #header>
                            <tr class="bg-slate-50">
                                <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Vendor</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Invoice No.</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-right text-xs font-bold uppercase tracking-widest text-slate-500">Amount</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-right text-xs font-bold uppercase tracking-widest text-slate-500">Paid</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-right text-xs font-bold uppercase tracking-widest text-slate-500">Outstanding</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Status</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Paid Date</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Last Sync</th>
                            </tr>
                        </template>

                        <template #body="{ data }">
                            <tr v-for="row in data" :key="row.id" class="border-b border-slate-50 transition-colors last:border-0 hover:bg-slate-50/50">
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-600">{{ row.vendor?.name || '—' }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm font-black text-slate-900">{{ row.invoice_no }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-semibold text-slate-800">{{ money(row.invoice_amount) }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-right text-sm text-slate-600">{{ money(row.paid_amount) }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-right text-sm text-slate-600">{{ money(row.outstanding_amount) }}</td>
                                <td class="whitespace-nowrap px-6 py-4"><StatusBadge :status="row.status" /></td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-600">{{ formatDate(row.paid_date) }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-500">{{ formatDateTime(row.last_synced_at) }}</td>
                            </tr>
                        </template>
                    </DataTable>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
