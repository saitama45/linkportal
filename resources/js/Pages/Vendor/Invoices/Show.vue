<script setup>
import { Head, Link } from '@inertiajs/vue3';
import VendorLayout from '@/Layouts/VendorLayout.vue';
import DocShow from '@/Components/Portal/DocShow.vue';
import { ArrowLeftIcon, PencilSquareIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    invoice: Object,
});

const formatDate = (value) => (value ? new Date(value).toLocaleDateString() : '—');

const meta = [
    { label: 'Bill To', value: props.invoice.company?.name },
    { label: 'Invoice / SI No.', value: props.invoice.invoice_no },
    { label: 'Related PO', value: props.invoice.po_number },
    { label: 'Invoice Date', value: formatDate(props.invoice.invoice_date) },
    { label: 'Due Date', value: formatDate(props.invoice.due_date) },
    { label: 'Submitted', value: props.invoice.submitted_at ? new Date(props.invoice.submitted_at).toLocaleString() : '—' },
];
</script>

<template>
    <Head :title="`Invoice ${invoice.reference_no} - Link Portal`" />

    <VendorLayout>
        <template #header>
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <Link :href="route('vendor.invoices.index')" class="rounded-xl border border-slate-200 bg-white p-2.5 text-slate-500 transition hover:text-emerald-600">
                        <ArrowLeftIcon class="h-5 w-5" />
                    </Link>
                    <h2 class="text-2xl font-black tracking-tight text-slate-900">Invoice Details</h2>
                </div>
                <Link v-if="['draft', 'returned'].includes(invoice.status)" :href="route('vendor.invoices.edit', invoice.id)"
                    class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-700">
                    <PencilSquareIcon class="h-4.5 w-4.5" />
                    Edit
                </Link>
            </div>
        </template>

        <DocShow :document="invoice" :meta="meta" title="Invoice" />
    </VendorLayout>
</template>
