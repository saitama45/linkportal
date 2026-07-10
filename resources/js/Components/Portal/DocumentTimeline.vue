<script setup>
import { computed } from 'vue';
import { CheckCircleIcon, ClockIcon, ExclamationTriangleIcon, XCircleIcon } from '@heroicons/vue/24/solid';

const props = defineProps({
    events: { type: Array, default: () => [] },
    // vendor mode hides internal plumbing (exceptions, reprocess runs) and uses friendly labels
    audience: { type: String, default: 'vendor' }, // vendor | admin
});

const VENDOR_LABELS = {
    received: 'Document received',
    converted: 'Prepared for reading',
    extracted: 'Details read from document',
    classified: 'Document classified',
    corrections_saved: 'Details reviewed',
    validated: 'Details verified',
    submitted: 'Sent for accounting review',
    external_received: 'Accounting review started',
    approved: 'Approved by accounting',
    returned: 'Returned — needs changes',
    rejected: 'Rejected by accounting',
    cancelled: 'Cancelled',
    conversion_failed: 'Processing delayed',
    extraction_failed: 'Processing delayed',
    handoff_failed: 'Processing delayed',
};

const HIDDEN_FOR_VENDOR = ['exception_raised', 'exception_resolved', 'reprocessed'];

const ICONS = {
    approved: { icon: CheckCircleIcon, class: 'text-emerald-500' },
    validated: { icon: CheckCircleIcon, class: 'text-emerald-500' },
    returned: { icon: ExclamationTriangleIcon, class: 'text-amber-500' },
    rejected: { icon: XCircleIcon, class: 'text-red-500' },
    cancelled: { icon: XCircleIcon, class: 'text-slate-400' },
    conversion_failed: { icon: ExclamationTriangleIcon, class: 'text-amber-500' },
    extraction_failed: { icon: ExclamationTriangleIcon, class: 'text-amber-500' },
    handoff_failed: { icon: ExclamationTriangleIcon, class: 'text-amber-500' },
    exception_raised: { icon: ExclamationTriangleIcon, class: 'text-amber-500' },
};

const visibleEvents = computed(() => {
    const events = props.audience === 'vendor'
        ? props.events.filter((e) => !HIDDEN_FOR_VENDOR.includes(e.event))
        : props.events;

    return events.map((e) => ({
        ...e,
        label: props.audience === 'vendor'
            ? (VENDOR_LABELS[e.event] || e.event)
            : e.event.replaceAll('_', ' '),
        iconDef: ICONS[e.event] || { icon: ClockIcon, class: 'text-slate-300' },
    }));
});

const formatDateTime = (value) => (value ? new Date(value).toLocaleString() : '');
</script>

<template>
    <ol class="relative ml-3 space-y-6 border-l border-slate-200 pl-6">
        <li v-for="(event, index) in visibleEvents" :key="event.id ?? index" class="relative">
            <span class="absolute -left-[31px] flex h-5 w-5 items-center justify-center rounded-full bg-white">
                <component :is="event.iconDef.icon" :class="['h-5 w-5', event.iconDef.class]" />
            </span>
            <p class="text-sm font-semibold capitalize text-slate-800">{{ event.label }}</p>
            <p v-if="audience === 'admin' && event.notes" class="mt-0.5 text-sm text-slate-500">{{ event.notes }}</p>
            <p class="mt-0.5 text-xs text-slate-400">{{ formatDateTime(event.created_at) }}</p>
        </li>
        <li v-if="visibleEvents.length === 0" class="text-sm text-slate-500">No activity yet.</li>
    </ol>
</template>
