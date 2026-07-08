<script setup>
import { Head, Link } from '@inertiajs/vue3';
import VendorLayout from '@/Layouts/VendorLayout.vue';
import DocShow from '@/Components/Portal/DocShow.vue';
import { ArrowLeftIcon, PencilSquareIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    quotation: Object,
});

const formatDate = (value) => (value ? new Date(value).toLocaleDateString() : '—');

const meta = [
    { label: 'Company', value: props.quotation.company?.name },
    { label: 'Title', value: props.quotation.title },
    { label: 'Quote No.', value: props.quotation.quotation_no },
    { label: 'Quotation Date', value: formatDate(props.quotation.quotation_date) },
    { label: 'Valid Until', value: formatDate(props.quotation.valid_until) },
    { label: 'Payment Terms', value: props.quotation.payment_terms },
    { label: 'Delivery Terms', value: props.quotation.delivery_terms },
];
</script>

<template>
    <Head :title="`Quotation ${quotation.reference_no} - Link Portal`" />

    <VendorLayout>
        <template #header>
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <Link :href="route('vendor.quotations.index')" class="rounded-xl border border-slate-200 bg-white p-2.5 text-slate-500 transition hover:text-emerald-600">
                        <ArrowLeftIcon class="h-5 w-5" />
                    </Link>
                    <h2 class="text-2xl font-black tracking-tight text-slate-900">Quotation Details</h2>
                </div>
                <Link v-if="['draft', 'returned'].includes(quotation.status)" :href="route('vendor.quotations.edit', quotation.id)"
                    class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-700">
                    <PencilSquareIcon class="h-4.5 w-4.5" />
                    Edit
                </Link>
            </div>
        </template>

        <DocShow :document="quotation" :meta="meta" title="Quotation" />
    </VendorLayout>
</template>
