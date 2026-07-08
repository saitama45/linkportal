<script setup>
import StatusBadge from '@/Components/Portal/StatusBadge.vue';
import ApprovalTimeline from '@/Components/Portal/ApprovalTimeline.vue';
import { PaperClipIcon } from '@heroicons/vue/24/outline';

defineProps({
    document: { type: Object, required: true },
    // [{ label, value }] header meta fields specific to the doc type
    meta: { type: Array, default: () => [] },
    title: { type: String, required: true },
});

const money = (value) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fileSize = (bytes) => (bytes ? `${(bytes / 1024 / 1024).toFixed(2)} MB` : '');
</script>

<template>
    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <!-- Header card -->
            <div class="rounded-3xl border border-slate-200 bg-white p-7 shadow-sm">
                <div class="flex flex-col gap-3 border-b border-slate-100 pb-5 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">{{ title }}</p>
                        <h3 class="mt-1 text-2xl font-black tracking-tight text-slate-900">{{ document.reference_no }}</h3>
                    </div>
                    <div class="flex items-center gap-3">
                        <StatusBadge :status="document.status" />
                        <span v-if="document.current_approval_level > 0 && ['submitted', 'under_review'].includes(document.status)"
                            class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-500">
                            Level {{ document.current_approval_level }}
                        </span>
                    </div>
                </div>

                <dl class="mt-5 grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-3">
                    <div v-for="field in meta" :key="field.label">
                        <dt class="text-[10px] font-black uppercase tracking-widest text-slate-400">{{ field.label }}</dt>
                        <dd class="mt-0.5 text-sm font-bold text-slate-800">{{ field.value ?? '—' }}</dd>
                    </div>
                </dl>

                <div v-if="document.remarks" class="mt-5 rounded-xl bg-slate-50 px-4 py-3">
                    <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1">Remarks</p>
                    <p class="text-sm text-slate-600">{{ document.remarks }}</p>
                </div>
            </div>

            <!-- Items -->
            <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-left text-[10px] font-black uppercase tracking-widest text-slate-500">#</th>
                                <th class="px-5 py-3 text-left text-[10px] font-black uppercase tracking-widest text-slate-500">Description</th>
                                <th class="px-5 py-3 text-right text-[10px] font-black uppercase tracking-widest text-slate-500">Qty</th>
                                <th class="px-5 py-3 text-left text-[10px] font-black uppercase tracking-widest text-slate-500">UoM</th>
                                <th class="px-5 py-3 text-right text-[10px] font-black uppercase tracking-widest text-slate-500">Unit Price</th>
                                <th class="px-5 py-3 text-right text-[10px] font-black uppercase tracking-widest text-slate-500">Tax %</th>
                                <th class="px-5 py-3 text-right text-[10px] font-black uppercase tracking-widest text-slate-500">Line Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <tr v-for="(item, i) in document.items" :key="item.id">
                                <td class="px-5 py-3 text-sm text-slate-400">{{ i + 1 }}</td>
                                <td class="px-5 py-3 text-sm font-semibold text-slate-800">{{ item.description }}</td>
                                <td class="px-5 py-3 text-right text-sm text-slate-600">{{ Number(item.quantity) }}</td>
                                <td class="px-5 py-3 text-sm text-slate-500">{{ item.uom?.code || '—' }}</td>
                                <td class="px-5 py-3 text-right text-sm text-slate-600">{{ money(item.unit_price) }}</td>
                                <td class="px-5 py-3 text-right text-sm text-slate-600">{{ item.tax_rate ? Number(item.tax_rate) + '%' : '—' }}</td>
                                <td class="px-5 py-3 text-right text-sm font-bold text-slate-800">{{ money(item.line_total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-slate-100 bg-slate-50/60 px-6 py-4">
                    <div class="ml-auto w-full max-w-xs space-y-1.5 text-sm">
                        <div class="flex justify-between font-semibold text-slate-500"><span>Subtotal</span><span>{{ money(document.subtotal) }}</span></div>
                        <div class="flex justify-between font-semibold text-slate-500"><span>Tax</span><span>{{ money(document.tax_amount) }}</span></div>
                        <div class="flex justify-between border-t border-slate-200 pt-2 text-base font-black text-slate-900">
                            <span>Total ({{ document.currency }})</span><span>{{ money(document.total_amount) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Attachments -->
            <div v-if="document.attachments?.length" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <h4 class="mb-4 text-xs font-black uppercase tracking-widest text-slate-500">Attachments</h4>
                <ul class="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                    <li v-for="file in document.attachments" :key="file.id">
                        <a :href="`/storage/${file.file_path}`" target="_blank"
                            class="flex items-center gap-3 rounded-xl border border-slate-100 bg-slate-50/70 px-4 py-3 transition hover:border-emerald-200 hover:bg-emerald-50/50">
                            <PaperClipIcon class="h-4.5 w-4.5 shrink-0 text-slate-400" />
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-bold text-slate-700">{{ file.file_name }}</span>
                                <span class="text-xs text-slate-400">{{ fileSize(file.file_size) }}</span>
                            </span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Sidebar: actions slot + approval history -->
        <div class="space-y-6">
            <slot name="actions" />
            <ApprovalTimeline :approvals="document.approvals || []" />
        </div>
    </div>
</template>
