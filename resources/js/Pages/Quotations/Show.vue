<script setup>
import AdminDocShow from '@/Components/Portal/AdminDocShow.vue';

const props = defineProps({
    document: Object,
    documentType: String,
});

const formatDate = (value) => (value ? new Date(value).toLocaleDateString() : '—');

const meta = [
    { label: 'Vendor', value: `${props.document.vendor?.name} (${props.document.vendor?.code})` },
    { label: 'Company', value: props.document.company?.name },
    { label: 'Title', value: props.document.title },
    { label: 'Quote No.', value: props.document.quotation_no },
    { label: 'Quotation Date', value: formatDate(props.document.quotation_date) },
    { label: 'Valid Until', value: formatDate(props.document.valid_until) },
    { label: 'Payment Terms', value: props.document.payment_terms },
];
</script>

<template>
    <AdminDocShow
        :document="document"
        title="Quotation"
        :meta="meta"
        back-route="quotations.index"
        act-route="quotations.act"
        approve-permission="quotations.approve"
    />
</template>
