<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import VendorLayout from '@/Layouts/VendorLayout.vue';
import DocShow from '@/Components/Portal/DocShow.vue';
import { ArrowLeftIcon, CheckIcon, PencilSquareIcon, XMarkIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    purchaseOrder: Object,
});

const formatDate = (value) => (value ? new Date(value).toLocaleDateString() : '—');

const meta = [
    { label: 'Company', value: props.purchaseOrder.company?.name },
    { label: 'PO No.', value: props.purchaseOrder.po_number },
    { label: 'PO Date', value: formatDate(props.purchaseOrder.po_date) },
    { label: 'Expected Delivery', value: formatDate(props.purchaseOrder.expected_delivery_date) },
    { label: 'Delivery Address', value: props.purchaseOrder.delivery_address },
    { label: 'Acknowledgment', value: props.purchaseOrder.acknowledgment_status || 'Not yet' },
];

const ackForm = useForm({ acknowledgment_status: '', acknowledgment_remarks: '' });

const acknowledge = (status) => {
    ackForm.acknowledgment_status = status;
    ackForm.post(route('vendor.purchase-orders.acknowledge', props.purchaseOrder.id), { preserveScroll: true });
};

const canAcknowledge = props.purchaseOrder.status === 'approved' && !props.purchaseOrder.acknowledgment_status;
</script>

<template>
    <Head :title="`PO ${purchaseOrder.reference_no} - Link Portal`" />

    <VendorLayout>
        <template #header>
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <Link :href="route('vendor.purchase-orders.index')" class="rounded-xl border border-slate-200 bg-white p-2.5 text-slate-500 transition hover:text-emerald-600">
                        <ArrowLeftIcon class="h-5 w-5" />
                    </Link>
                    <h2 class="text-2xl font-black tracking-tight text-slate-900">Purchase Order Details</h2>
                </div>
                <Link v-if="['draft', 'returned'].includes(purchaseOrder.status)" :href="route('vendor.purchase-orders.edit', purchaseOrder.id)"
                    class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-700">
                    <PencilSquareIcon class="h-4.5 w-4.5" />
                    Edit
                </Link>
            </div>
        </template>

        <DocShow :document="purchaseOrder" :meta="meta" title="Purchase Order">
            <template #actions>
                <div v-if="canAcknowledge" class="rounded-2xl border border-emerald-200 bg-emerald-50/60 p-5">
                    <h4 class="text-xs font-black uppercase tracking-widest text-emerald-900 mb-2">Confirm this PO</h4>
                    <p class="mb-4 text-xs font-medium text-emerald-800">This purchase order was approved. Please acknowledge or decline it.</p>
                    <textarea v-model="ackForm.acknowledgment_remarks" rows="2" placeholder="Remarks (optional)"
                        class="mb-3 block w-full rounded-xl border border-emerald-200 bg-white px-3.5 py-2.5 text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20"></textarea>
                    <div class="flex gap-2">
                        <button :disabled="ackForm.processing" @click="acknowledge('acknowledged')"
                            class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-xl bg-emerald-600 px-4 py-2.5 text-xs font-bold text-white transition hover:bg-emerald-700 disabled:opacity-50">
                            <CheckIcon class="h-4 w-4" /> Acknowledge
                        </button>
                        <button :disabled="ackForm.processing" @click="acknowledge('declined')"
                            class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-xl border border-red-200 bg-white px-4 py-2.5 text-xs font-bold text-red-600 transition hover:bg-red-50 disabled:opacity-50">
                            <XMarkIcon class="h-4 w-4" /> Decline
                        </button>
                    </div>
                </div>
            </template>
        </DocShow>
    </VendorLayout>
</template>
