<script setup>
import AdminDocIndex from '@/Components/Portal/AdminDocIndex.vue';

defineProps({
    quotations: Object,
    filters: { type: Object, default: () => ({}) },
});

const columns = [
    { label: 'Reference', key: 'reference_no', strong: true },
    { label: 'Title', key: 'title' },
    { label: 'Vendor', key: 'vendor.name' },
    { label: 'Company', key: 'company.name' },
    { label: 'Valid Until', key: 'valid_until', format: 'date' },
    { label: 'Total', key: 'total_amount', format: 'money', align: 'right' },
    { label: 'Status', key: 'status', format: 'status' },
];
</script>

<template>
    <AdminDocIndex
        title="Vendor Quotations"
        subtitle="Review and approve quotations submitted by vendors."
        :paginator="quotations"
        data-key="quotations"
        route-name="quotations.index"
        show-route-name="quotations.show"
        create-route-name="quotations.create"
        create-permission="quotations.create"
        create-label="Create Quotation"
        :filters="filters"
        :columns="columns"
    />
</template>
