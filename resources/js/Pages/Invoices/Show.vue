<script setup>
import AdminDocShow from '@/Components/Portal/AdminDocShow.vue';

const props = defineProps({
    document: Object,
    documentType: String,
});

const formatDate = (value) => (value ? new Date(value).toLocaleDateString() : '—');

const meta = [
    { label: 'Vendor', value: `${props.document.vendor?.name} (${props.document.vendor?.code})` },
    { label: 'Bill To', value: props.document.company?.name },
    { label: 'Invoice / SI No.', value: props.document.invoice_no },
    { label: 'Related PO', value: props.document.po_number },
    { label: 'Invoice Date', value: formatDate(props.document.invoice_date) },
    { label: 'Due Date', value: formatDate(props.document.due_date) },
];
</script>

<template>
    <AdminDocShow
        :document="document"
        title="Invoice"
        :meta="meta"
        back-route="invoices.index"
        act-route="invoices.act"
        approve-permission="invoices.approve"
    />
</template>
