<script setup>
import AdminDocIndex from '@/Components/Portal/AdminDocIndex.vue';

defineProps({
    purchaseOrders: Object,
    filters: { type: Object, default: () => ({}) },
});

const columns = [
    { label: 'Reference', key: 'reference_no', strong: true },
    { label: 'PO No.', key: 'po_number' },
    { label: 'Vendor', key: 'vendor.name' },
    { label: 'Company', key: 'company.name' },
    { label: 'PO Date', key: 'po_date', format: 'date' },
    { label: 'Total', key: 'total_amount', format: 'money', align: 'right' },
    { label: 'Status', key: 'status', format: 'status' },
];
</script>

<template>
    <AdminDocIndex
        title="Vendor Purchase Orders"
        subtitle="Review and approve purchase orders submitted by vendors."
        :paginator="purchaseOrders"
        data-key="purchaseOrders"
        route-name="purchase-orders.index"
        show-route-name="purchase-orders.show"
        create-route-name="purchase-orders.create"
        create-permission="purchase-orders.create"
        create-label="Create Purchase Order"
        :filters="filters"
        :columns="columns"
    />
</template>
