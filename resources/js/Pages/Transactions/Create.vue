<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Autocomplete from '@/Components/Autocomplete.vue';
import InputError from '@/Components/InputError.vue';
import LineItemsEditor from '@/Components/Portal/LineItemsEditor.vue';
import { ArrowLeftIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    documentType: { type: String, required: true },
    vendors: { type: Array, default: () => [] },
    companies: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    uoms: { type: Array, default: () => [] },
});

const documentConfig = {
    invoices: {
        singular: 'Invoice',
        indexRoute: 'invoices.index',
        storeRoute: 'invoices.store',
    },
    'purchase-orders': {
        singular: 'Purchase Order',
        indexRoute: 'purchase-orders.index',
        storeRoute: 'purchase-orders.store',
    },
    quotations: {
        singular: 'Quotation',
        indexRoute: 'quotations.index',
        storeRoute: 'quotations.store',
    },
};

const config = computed(() => documentConfig[props.documentType]);
const today = new Date();
const localDate = [
    today.getFullYear(),
    String(today.getMonth() + 1).padStart(2, '0'),
    String(today.getDate()).padStart(2, '0'),
].join('-');

const form = useForm({
    vendor_id: '',
    company_id: '',
    invoice_no: '',
    po_number: '',
    invoice_date: localDate,
    due_date: '',
    po_date: localDate,
    expected_delivery_date: '',
    delivery_address: '',
    quotation_no: '',
    title: '',
    quotation_date: localDate,
    valid_until: '',
    payment_terms: '',
    delivery_terms: '',
    remarks: '',
    items: [],
    attachments: [],
});

const vendorOptions = computed(() => props.vendors.map((vendor) => ({
    label: `${vendor.code} — ${vendor.name}`,
    value: vendor.id,
})));

const companyOptions = computed(() => props.companies.map((company) => ({
    label: company.name,
    value: company.id,
})));

const submit = () => {
    form.post(route(config.value.storeRoute), {
        forceFormData: true,
        preserveScroll: true,
    });
};

const inputClass = 'block w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm transition-all focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20';
</script>

<template>
    <Head :title="`Create ${config.singular} - Link Portal`" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-4">
                <Link
                    :href="route(config.indexRoute)"
                    class="rounded-xl border border-slate-200 bg-white p-2.5 text-slate-500 transition hover:text-emerald-600"
                >
                    <ArrowLeftIcon class="h-5 w-5" />
                </Link>
                <div>
                    <h2 class="text-2xl font-black tracking-tight text-slate-900">Create {{ config.singular }}</h2>
                    <p class="mt-1 text-sm text-slate-500">Create a document for a vendor and submit it directly for approval.</p>
                </div>
            </div>
        </template>

        <form class="space-y-6" @submit.prevent="submit">
            <div class="rounded-3xl border border-slate-200 bg-white p-7 shadow-sm">
                <h3 class="mb-5 text-xs font-black uppercase tracking-widest text-slate-500">Document Parties</h3>
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">Vendor</label>
                        <Autocomplete v-model="form.vendor_id" :options="vendorOptions" placeholder="Select active vendor..." required />
                        <InputError class="mt-1" :message="form.errors.vendor_id" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">Company</label>
                        <Autocomplete v-model="form.company_id" :options="companyOptions" placeholder="Select company..." required />
                        <InputError class="mt-1" :message="form.errors.company_id" />
                    </div>
                </div>
            </div>

            <div class="rounded-3xl border border-slate-200 bg-white p-7 shadow-sm">
                <h3 class="mb-5 text-xs font-black uppercase tracking-widest text-slate-500">{{ config.singular }} Details</h3>

                <div v-if="documentType === 'invoices'" class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">Invoice / SI Number</label>
                        <input v-model="form.invoice_no" type="text" required :class="inputClass" />
                        <InputError class="mt-1" :message="form.errors.invoice_no" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">Related PO Number</label>
                        <input v-model="form.po_number" type="text" :class="inputClass" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">Invoice Date</label>
                        <input v-model="form.invoice_date" type="date" required :class="inputClass" />
                        <InputError class="mt-1" :message="form.errors.invoice_date" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">Due Date</label>
                        <input v-model="form.due_date" type="date" :class="inputClass" />
                        <InputError class="mt-1" :message="form.errors.due_date" />
                    </div>
                </div>

                <div v-else-if="documentType === 'purchase-orders'" class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">PO Number</label>
                        <input v-model="form.po_number" type="text" required :class="inputClass" />
                        <InputError class="mt-1" :message="form.errors.po_number" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">PO Date</label>
                        <input v-model="form.po_date" type="date" required :class="inputClass" />
                        <InputError class="mt-1" :message="form.errors.po_date" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">Expected Delivery</label>
                        <input v-model="form.expected_delivery_date" type="date" :class="inputClass" />
                        <InputError class="mt-1" :message="form.errors.expected_delivery_date" />
                    </div>
                    <div class="sm:col-span-2 lg:col-span-3">
                        <label class="mb-1 block text-sm font-bold text-slate-700">Delivery Address</label>
                        <input v-model="form.delivery_address" type="text" :class="inputClass" />
                    </div>
                </div>

                <div v-else class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <div class="lg:col-span-2">
                        <label class="mb-1 block text-sm font-bold text-slate-700">Title / Subject</label>
                        <input v-model="form.title" type="text" required :class="inputClass" />
                        <InputError class="mt-1" :message="form.errors.title" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">Quotation Number</label>
                        <input v-model="form.quotation_no" type="text" :class="inputClass" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">Quotation Date</label>
                        <input v-model="form.quotation_date" type="date" required :class="inputClass" />
                        <InputError class="mt-1" :message="form.errors.quotation_date" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">Valid Until</label>
                        <input v-model="form.valid_until" type="date" :class="inputClass" />
                        <InputError class="mt-1" :message="form.errors.valid_until" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">Payment Terms</label>
                        <input v-model="form.payment_terms" type="text" :class="inputClass" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">Delivery Terms</label>
                        <input v-model="form.delivery_terms" type="text" :class="inputClass" />
                    </div>
                </div>

                <div class="mt-5">
                    <label class="mb-1 block text-sm font-bold text-slate-700">Remarks</label>
                    <textarea v-model="form.remarks" rows="2" :class="inputClass"></textarea>
                    <InputError class="mt-1" :message="form.errors.remarks" />
                </div>
            </div>

            <div>
                <LineItemsEditor v-model="form.items" :products="products" :uoms="uoms" />
                <InputError class="mt-2" :message="form.errors.items" />
            </div>

            <div class="rounded-3xl border border-slate-200 bg-white p-7 shadow-sm">
                <h3 class="mb-3 text-xs font-black uppercase tracking-widest text-slate-500">Attachments</h3>
                <input
                    type="file"
                    multiple
                    accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx"
                    class="block w-full text-sm text-slate-500 file:mr-4 file:rounded-xl file:border-0 file:bg-emerald-50 file:px-4 file:py-2.5 file:text-sm file:font-bold file:text-emerald-700 hover:file:bg-emerald-100"
                    @change="form.attachments = Array.from($event.target.files)"
                />
                <InputError class="mt-2" :message="form.errors.attachments" />
            </div>

            <div class="flex flex-col-reverse items-stretch justify-end gap-3 sm:flex-row sm:items-center">
                <Link
                    :href="route(config.indexRoute)"
                    class="rounded-xl bg-slate-100 px-6 py-3 text-center text-sm font-bold text-slate-600 transition hover:bg-slate-200"
                >
                    Cancel
                </Link>
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 px-6 py-3 text-sm font-black text-white shadow-lg shadow-emerald-600/25 transition hover:shadow-emerald-600/40 disabled:opacity-50"
                >
                    {{ form.processing ? 'Submitting...' : `Create and Submit ${config.singular}` }}
                </button>
            </div>
        </form>
    </AppLayout>
</template>
