<script setup>
import { Head, Link } from '@inertiajs/vue3';
import VendorLayout from '@/Layouts/VendorLayout.vue';
import DataTable from '@/Components/DataTable.vue';
import StatusBadge from '@/Components/Portal/StatusBadge.vue';
import { PlusIcon, EyeIcon } from '@heroicons/vue/24/outline';

defineProps({
    purchaseOrders: Object,
    filters: { type: Object, default: () => ({}) },
});

const money = (value) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2 });
const formatDate = (value) => (value ? new Date(value).toLocaleDateString() : '—');
</script>

<template>
    <Head title="My Purchase Orders - Link Portal" />

    <VendorLayout>
        <template #header>
            <div>
                <h2 class="text-2xl font-black tracking-tight text-slate-900">My Purchase Orders</h2>
                <p class="mt-1 text-sm text-slate-500">Submit, track, and acknowledge purchase orders.</p>
            </div>
        </template>

        <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
            <DataTable
                title="Purchase Orders"
                subtitle="All purchase orders you have created"
                search-placeholder="Search reference or PO no..."
                empty-message="No purchase orders yet."
                data-key="purchaseOrders"
                route-name="vendor.purchase-orders.index"
                :paginator="purchaseOrders"
                :initial-search="filters.search"
            >
                <template #actions>
                    <Link :href="route('vendor.purchase-orders.create')"
                        class="flex items-center space-x-2 rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-emerald-600/20 transition-all hover:bg-emerald-700">
                        <PlusIcon class="h-5 w-5" />
                        <span>New P.O.</span>
                    </Link>
                </template>

                <template #header>
                    <tr class="bg-slate-50">
                        <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Reference</th>
                        <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">PO No.</th>
                        <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Company</th>
                        <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">PO Date</th>
                        <th class="border-b border-slate-100 px-6 py-4 text-right text-xs font-bold uppercase tracking-widest text-slate-500">Total</th>
                        <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Status</th>
                        <th class="border-b border-slate-100 px-6 py-4 text-right text-xs font-bold uppercase tracking-widest text-slate-500">Actions</th>
                    </tr>
                </template>

                <template #body="{ data }">
                    <tr v-for="po in data" :key="po.id" class="border-b border-slate-50 transition-colors last:border-0 hover:bg-slate-50/50">
                        <td class="whitespace-nowrap px-6 py-4 text-sm font-black text-slate-900">{{ po.reference_no }}</td>
                        <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-slate-600">{{ po.po_number }}</td>
                        <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-600">{{ po.company?.name || '—' }}</td>
                        <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-600">{{ formatDate(po.po_date) }}</td>
                        <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-bold text-slate-800">{{ money(po.total_amount) }}</td>
                        <td class="whitespace-nowrap px-6 py-4">
                            <div class="flex items-center gap-2">
                                <StatusBadge :status="po.status" />
                                <StatusBadge v-if="po.acknowledgment_status" :status="po.acknowledgment_status" />
                            </div>
                        </td>
                        <td class="whitespace-nowrap px-6 py-4 text-right">
                            <Link :href="route('vendor.purchase-orders.show', po.id)"
                                class="inline-flex rounded-lg p-2 text-slate-400 transition-all hover:bg-emerald-50 hover:text-emerald-600" title="View">
                                <EyeIcon class="h-5 w-5" />
                            </Link>
                        </td>
                    </tr>
                </template>
            </DataTable>
        </div>
    </VendorLayout>
</template>
