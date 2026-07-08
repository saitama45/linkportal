<script setup>
import { CheckCircleIcon, XCircleIcon, ArrowUturnLeftIcon } from '@heroicons/vue/24/outline';

defineProps({
    approvals: { type: Array, default: () => [] },
});

const icons = {
    approved: { icon: CheckCircleIcon, class: 'text-emerald-500 bg-emerald-50 border-emerald-100' },
    rejected: { icon: XCircleIcon, class: 'text-red-500 bg-red-50 border-red-100' },
    returned: { icon: ArrowUturnLeftIcon, class: 'text-orange-500 bg-orange-50 border-orange-100' },
};

const formatDate = (value) => (value ? new Date(value).toLocaleString() : '');
</script>

<template>
    <div class="rounded-2xl border border-slate-200 bg-white p-5">
        <h4 class="text-xs font-black uppercase tracking-widest text-slate-500 mb-4">Approval History</h4>
        <div v-if="approvals.length === 0" class="text-sm text-slate-400 font-medium">No approval actions yet.</div>
        <ol v-else class="space-y-4">
            <li v-for="entry in approvals" :key="entry.id" class="flex items-start gap-3">
                <span :class="['flex h-8 w-8 shrink-0 items-center justify-center rounded-xl border', icons[entry.action]?.class || 'text-slate-400 bg-slate-50 border-slate-100']">
                    <component :is="icons[entry.action]?.icon || CheckCircleIcon" class="h-4 w-4" />
                </span>
                <div class="min-w-0">
                    <p class="text-sm font-bold text-slate-800 capitalize">
                        {{ entry.action }} <span class="font-medium text-slate-400">— Level {{ entry.level }}</span>
                    </p>
                    <p class="text-xs text-slate-500">{{ entry.user?.name || 'System' }} · {{ formatDate(entry.acted_at || entry.created_at) }}</p>
                    <p v-if="entry.remarks" class="mt-1 text-xs text-slate-600 italic">"{{ entry.remarks }}"</p>
                </div>
            </li>
        </ol>
    </div>
</template>
