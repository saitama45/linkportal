<script setup>
import { Head, Link } from '@inertiajs/vue3';
import VendorLayout from '@/Layouts/VendorLayout.vue';
import DataTable from '@/Components/DataTable.vue';
import StatusBadge from '@/Components/Portal/StatusBadge.vue';
import { PlusIcon, EyeIcon } from '@heroicons/vue/24/outline';

defineProps({
    quotations: Object,
    filters: { type: Object, default: () => ({}) },
});

const money = (value) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2 });
const formatDate = (value) => (value ? new Date(value).toLocaleDateString() : '—');
</script>

<template>
    <Head title="My Quotations - Link Portal" />

    <VendorLayout>
        <template #header>
            <div>
                <h2 class="text-2xl font-black tracking-tight text-slate-900">My Quotations</h2>
                <p class="mt-1 text-sm text-slate-500">Submit and track price quotations.</p>
            </div>
        </template>

        <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
            <DataTable
                title="Quotations"
                subtitle="All quotations you have created"
                search-placeholder="Search reference, quote no, or title..."
                empty-message="No quotations yet."
                data-key="quotations"
                route-name="vendor.quotations.index"
                :paginator="quotations"
                :initial-search="filters.search"
            >
                <template #actions>
                    <Link :href="route('vendor.quotations.create')"
                        class="flex items-center space-x-2 rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-emerald-600/20 transition-all hover:bg-emerald-700">
                        <PlusIcon class="h-5 w-5" />
                        <span>New Quotation</span>
                    </Link>
                </template>

                <template #header>
                    <tr class="bg-slate-50">
                        <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Reference</th>
                        <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Title</th>
                        <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Company</th>
                        <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Valid Until</th>
                        <th class="border-b border-slate-100 px-6 py-4 text-right text-xs font-bold uppercase tracking-widest text-slate-500">Total</th>
                        <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Status</th>
                        <th class="border-b border-slate-100 px-6 py-4 text-right text-xs font-bold uppercase tracking-widest text-slate-500">Actions</th>
                    </tr>
                </template>

                <template #body="{ data }">
                    <tr v-for="q in data" :key="q.id" class="border-b border-slate-50 transition-colors last:border-0 hover:bg-slate-50/50">
                        <td class="whitespace-nowrap px-6 py-4 text-sm font-black text-slate-900">{{ q.reference_no }}</td>
                        <td class="max-w-[240px] truncate px-6 py-4 text-sm font-medium text-slate-600">{{ q.title }}</td>
                        <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-600">{{ q.company?.name || '—' }}</td>
                        <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-600">{{ formatDate(q.valid_until) }}</td>
                        <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-bold text-slate-800">{{ money(q.total_amount) }}</td>
                        <td class="whitespace-nowrap px-6 py-4"><StatusBadge :status="q.status" /></td>
                        <td class="whitespace-nowrap px-6 py-4 text-right">
                            <Link :href="route('vendor.quotations.show', q.id)"
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
