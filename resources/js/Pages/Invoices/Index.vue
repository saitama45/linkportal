<script setup>
import AdminDocIndex from '@/Components/Portal/AdminDocIndex.vue';

defineProps({
    invoices: Object,
    filters: { type: Object, default: () => ({}) },
});

const columns = [
    { label: 'Reference', key: 'reference_no', strong: true },
    { label: 'Invoice No.', key: 'invoice_no' },
    { label: 'Vendor', key: 'vendor.name' },
    { label: 'Company', key: 'company.name' },
    { label: 'Date', key: 'invoice_date', format: 'date' },
    { label: 'Total', key: 'total_amount', format: 'money', align: 'right' },
    { label: 'Status', key: 'status', format: 'status' },
];
</script>

<template>
    <AdminDocIndex
        title="Vendor Invoices"
        subtitle="Review and approve invoices submitted by vendors."
        :paginator="invoices"
        data-key="invoices"
        route-name="invoices.index"
        show-route-name="invoices.show"
        create-route-name="invoices.create"
        create-permission="invoices.create"
        create-label="Create Invoice"
        :filters="filters"
        :columns="columns"
    />
</template>
