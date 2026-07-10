<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import VendorLayout from '@/Layouts/VendorLayout.vue';
import StatusBadge from '@/Components/Portal/StatusBadge.vue';
import DocumentTimeline from '@/Components/Portal/DocumentTimeline.vue';
import { ArrowLeftIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    document: { type: Object, required: true },
});

const typeLabel = (type) => ({ invoice: 'Invoice', purchase_order: 'Purchase Order', quotation: 'Quotation' }[type] || 'Document');
const money = (value) => (value == null ? '—' : Number(value).toLocaleString(undefined, { minimumFractionDigits: 2 }));
const formatDate = (value) => (value ? new Date(value).toLocaleDateString() : '—');

const cancellable = ['received', 'conversion_failed', 'extraction_failed', 'needs_validation', 'returned'];

const cancel = () => {
    if (confirm('Cancel this document? It will no longer be processed.')) {
        router.put(route('vendor.document-uploads.cancel', props.document.id));
    }
};
</script>

<template>
    <Head :title="`${document.reference_no} - Link Portal`" />

    <VendorLayout>
        <template #header>
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div class="flex items-center gap-4">
                    <Link :href="route('vendor.document-uploads.index')"
                        class="rounded-lg p-2 text-slate-400 transition-all hover:bg-slate-100 hover:text-slate-600">
                        <ArrowLeftIcon class="h-5 w-5" />
                    </Link>
                    <div>
                        <h2 class="text-2xl font-black tracking-tight text-slate-900">{{ document.reference_no }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ typeLabel(document.document_type) }} · {{ document.original_filename }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <StatusBadge :status="document.status" />
                    <button v-if="cancellable.includes(document.status)" type="button"
                        class="rounded-xl border border-red-200 px-4 py-2 text-sm font-semibold text-red-600 transition-all hover:bg-red-50"
                        @click="cancel">
                        Cancel Document
                    </button>
                </div>
            </div>
        </template>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <div class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
                    <h3 class="text-sm font-bold uppercase tracking-widest text-slate-500">Extracted Details</h3>
                    <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-3">
                        <div>
                            <dt class="text-xs font-semibold uppercase text-slate-400">Document No.</dt>
                            <dd class="mt-1 text-sm font-semibold text-slate-800">{{ document.invoice_no || '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase text-slate-400">PO Number</dt>
                            <dd class="mt-1 text-sm font-semibold text-slate-800">{{ document.po_number || '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase text-slate-400">Date</dt>
                            <dd class="mt-1 text-sm font-semibold text-slate-800">{{ formatDate(document.document_date) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase text-slate-400">Subtotal</dt>
                            <dd class="mt-1 text-sm font-semibold text-slate-800">{{ money(document.subtotal) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase text-slate-400">Tax</dt>
                            <dd class="mt-1 text-sm font-semibold text-slate-800">{{ money(document.tax_amount) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase text-slate-400">Total</dt>
                            <dd class="mt-1 text-sm font-black text-slate-900">{{ document.currency }} {{ money(document.total_amount) }}</dd>
                        </div>
                    </dl>
                    <p class="mt-4 text-xs text-slate-400">
                        Details are read automatically and verified by our team before review. You'll be notified if anything needs your attention.
                    </p>
                </div>

                <div v-if="document.external_decision" class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
                    <h3 class="text-sm font-bold uppercase tracking-widest text-slate-500">Review Outcome</h3>
                    <div class="mt-4 flex items-start gap-3">
                        <StatusBadge :status="document.external_decision" />
                        <div>
                            <p v-if="document.external_decision_remarks" class="text-sm text-slate-700">{{ document.external_decision_remarks }}</p>
                            <p class="mt-1 text-xs text-slate-400">{{ formatDate(document.external_decided_at) }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
                <h3 class="mb-6 text-sm font-bold uppercase tracking-widest text-slate-500">Progress</h3>
                <DocumentTimeline :events="document.events" audience="vendor" />
            </div>
        </div>
    </VendorLayout>
</template>
