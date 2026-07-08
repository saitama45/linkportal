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
    { label: 'PO No.', value: props.document.po_number },
    { label: 'PO Date', value: formatDate(props.document.po_date) },
    { label: 'Expected Delivery', value: formatDate(props.document.expected_delivery_date) },
    { label: 'Acknowledgment', value: props.document.acknowledgment_status || 'Not yet' },
];
</script>

<template>
    <AdminDocShow
        :document="document"
        title="Purchase Order"
        :meta="meta"
        back-route="purchase-orders.index"
        act-route="purchase-orders.act"
        approve-permission="purchase-orders.approve"
    />
</template>
