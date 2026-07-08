<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { CheckBadgeIcon, InboxIcon } from '@heroicons/vue/24/outline';

defineProps({
    items: Array,
});

const money = (value) => (value != null ? Number(value).toLocaleString(undefined, { minimumFractionDigits: 2 }) : null);
const formatDate = (value) => (value ? new Date(value).toLocaleString() : '—');

const typeTones = {
    invoice: 'bg-emerald-50 text-emerald-700 border-emerald-100',
    purchase_order: 'bg-teal-50 text-teal-700 border-teal-100',
    quotation: 'bg-sky-50 text-sky-700 border-sky-100',
    vendor_account: 'bg-indigo-50 text-indigo-700 border-indigo-100',
    vendor_profile: 'bg-violet-50 text-violet-700 border-violet-100',
    vendor_document: 'bg-amber-50 text-amber-700 border-amber-100',
    vendor_bank_account: 'bg-rose-50 text-rose-700 border-rose-100',
};
</script>

<template>
    <Head title="Approvals Inbox - Link Portal" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <span class="flex h-11 w-11 items-center justify-center rounded-xl border border-emerald-100 bg-emerald-50 text-emerald-600">
                    <CheckBadgeIcon class="h-6 w-6" />
                </span>
                <div>
                    <h2 class="text-2xl font-bold leading-tight text-slate-800">Approvals Inbox</h2>
                    <p class="mt-1 text-sm text-slate-500">Everything currently waiting for your action, across all vendor modules.</p>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
                    <div class="border-b border-slate-100 bg-slate-50/60 px-6 py-4">
                        <h3 class="text-xs font-black uppercase tracking-widest text-slate-600">
                            Pending Items <span class="ml-2 rounded-lg bg-emerald-600 px-2 py-0.5 text-[10px] font-black text-white">{{ items.length }}</span>
                        </h3>
                    </div>

                    <div v-if="items.length === 0" class="py-16 text-center">
                        <InboxIcon class="mx-auto mb-3 h-12 w-12 text-slate-200" />
                        <p class="text-sm font-bold text-slate-500">All caught up!</p>
                        <p class="mt-1 text-xs text-slate-400">Nothing is waiting for your approval right now.</p>
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-100">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-[10px] font-black uppercase tracking-widest text-slate-500">Type</th>
                                    <th class="px-6 py-3 text-left text-[10px] font-black uppercase tracking-widest text-slate-500">Reference</th>
                                    <th class="px-6 py-3 text-left text-[10px] font-black uppercase tracking-widest text-slate-500">Vendor</th>
                                    <th class="px-6 py-3 text-right text-[10px] font-black uppercase tracking-widest text-slate-500">Amount</th>
                                    <th class="px-6 py-3 text-left text-[10px] font-black uppercase tracking-widest text-slate-500">Level</th>
                                    <th class="px-6 py-3 text-left text-[10px] font-black uppercase tracking-widest text-slate-500">Submitted</th>
                                    <th class="px-6 py-3 text-right text-[10px] font-black uppercase tracking-widest text-slate-500">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <tr v-for="item in items" :key="`${item.type}-${item.id}`" class="transition-colors hover:bg-slate-50/50">
                                    <td class="whitespace-nowrap px-6 py-4">
                                        <span :class="['inline-flex rounded-lg border px-2.5 py-1 text-xs font-bold', typeTones[item.type] || 'bg-slate-50 text-slate-600 border-slate-200']">
                                            {{ item.label }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm font-black text-slate-900">{{ item.reference || '—' }}</td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-600">{{ item.vendor || '—' }}</td>
                                    <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-bold text-slate-800">{{ money(item.amount) || '—' }}</td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-500">L{{ item.level }}</td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-500">{{ formatDate(item.submitted_at) }}</td>
                                    <td class="whitespace-nowrap px-6 py-4 text-right">
                                        <Link v-if="item.url" :href="item.url"
                                            class="inline-flex rounded-xl bg-emerald-600 px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-700">
                                            Review
                                        </Link>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
