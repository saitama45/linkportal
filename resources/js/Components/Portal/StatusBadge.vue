<script setup>
import { computed } from 'vue';

const props = defineProps({
    status: { type: String, default: '' },
});

const styles = {
    draft: 'bg-slate-100 text-slate-600 border-slate-200',
    pending: 'bg-amber-50 text-amber-700 border-amber-200',
    submitted: 'bg-sky-50 text-sky-700 border-sky-200',
    under_review: 'bg-indigo-50 text-indigo-700 border-indigo-200',
    approved: 'bg-emerald-50 text-emerald-700 border-emerald-200',
    active: 'bg-emerald-50 text-emerald-700 border-emerald-200',
    acknowledged: 'bg-emerald-50 text-emerald-700 border-emerald-200',
    rejected: 'bg-red-50 text-red-700 border-red-200',
    declined: 'bg-red-50 text-red-700 border-red-200',
    suspended: 'bg-red-50 text-red-700 border-red-200',
    deactivated: 'bg-slate-100 text-slate-500 border-slate-200',
    returned: 'bg-orange-50 text-orange-700 border-orange-200',
    cancelled: 'bg-slate-100 text-slate-500 border-slate-200',
    expired: 'bg-red-50 text-red-600 border-red-200',
};

const badgeClass = computed(() => styles[props.status] || 'bg-slate-100 text-slate-600 border-slate-200');
const label = computed(() => (props.status || 'unknown').replace(/_/g, ' '));
</script>

<template>
    <span :class="['inline-flex items-center px-2.5 py-1 text-xs font-bold rounded-lg border capitalize', badgeClass]">
        {{ label }}
    </span>
</template>
