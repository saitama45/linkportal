<script setup>
import { Head, router } from '@inertiajs/vue3';
import VendorLayout from '@/Layouts/VendorLayout.vue';
import AssignedStoreSeals from '@/Components/NpcStatus/AssignedStoreSeals.vue';
import { useToast } from '@/Composables/useToast';

defineProps({ storeSeals: { type: Array, default: () => [] } });
const toast = useToast();
const reload = () => router.reload({ only: ['storeSeals'] });
const uploaded = () => { toast.success('Proof uploaded successfully.'); reload(); };
const failed = (error) => toast.error(
    Object.values(error.response?.data?.errors || {}).flat()[0]
    || error.response?.data?.message || 'The request failed. Please try again.'
);
</script>

<template>
    <Head title="NPC Monitoring - Link Portal" />
    <VendorLayout>
        <AssignedStoreSeals :store-seals="storeSeals" @downloaded="reload"
            @uploaded="uploaded" @download-error="failed" @upload-error="failed" />
    </VendorLayout>
</template>
