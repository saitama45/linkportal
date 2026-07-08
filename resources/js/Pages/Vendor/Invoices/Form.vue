<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import VendorLayout from '@/Layouts/VendorLayout.vue';
import LineItemsEditor from '@/Components/Portal/LineItemsEditor.vue';
import Autocomplete from '@/Components/Autocomplete.vue';
import InputError from '@/Components/InputError.vue';
import { ArrowLeftIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    invoice: { type: Object, default: null },
    companies: Array,
    products: Array,
    uoms: Array,
});

const isEdit = !!props.invoice;

const form = useForm({
    company_id: props.invoice?.company_id ?? '',
    invoice_no: props.invoice?.invoice_no ?? '',
    po_number: props.invoice?.po_number ?? '',
    invoice_date: props.invoice?.invoice_date?.substring(0, 10) ?? new Date().toISOString().substring(0, 10),
    due_date: props.invoice?.due_date?.substring(0, 10) ?? '',
    remarks: props.invoice?.remarks ?? '',
    items: props.invoice?.items?.map((i) => ({
        product_id: i.product_id || '',
        description: i.description,
        quantity: Number(i.quantity),
        uom_id: i.uom_id || '',
        unit_price: Number(i.unit_price),
        tax_rate: i.tax_rate != null ? Number(i.tax_rate) : '',
    })) ?? [],
    attachments: [],
    action: 'draft',
});

const companyOptions = props.companies.map((c) => ({ label: c.name, value: c.id }));

const submit = (action) => {
    form.action = action;
    const options = { forceFormData: true, preserveScroll: true };
    if (isEdit) {
        form.transform((data) => ({ ...data, _method: 'put' })).post(route('vendor.invoices.update', props.invoice.id), options);
    } else {
        form.post(route('vendor.invoices.store'), options);
    }
};

const inputClass = 'block w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all';
</script>

<template>
    <Head :title="(isEdit ? 'Edit' : 'New') + ' Invoice - Link Portal'" />

    <VendorLayout>
        <template #header>
            <div class="flex items-center gap-4">
                <Link :href="route('vendor.invoices.index')" class="rounded-xl border border-slate-200 bg-white p-2.5 text-slate-500 transition hover:text-emerald-600">
                    <ArrowLeftIcon class="h-5 w-5" />
                </Link>
                <div>
                    <h2 class="text-2xl font-black tracking-tight text-slate-900">{{ isEdit ? `Edit ${invoice.reference_no}` : 'New Invoice' }}</h2>
                    <p class="mt-1 text-sm text-slate-500">Save as draft or submit directly for approval.</p>
                </div>
            </div>
        </template>

        <form class="space-y-6" @submit.prevent="submit('submit')">
            <!-- Header fields -->
            <div class="rounded-3xl border border-slate-200 bg-white p-7 shadow-sm">
                <h3 class="mb-5 text-xs font-black uppercase tracking-widest text-slate-500">Invoice Details</h3>
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">Bill To (Company)</label>
                        <Autocomplete v-model="form.company_id" :options="companyOptions" placeholder="Select company..." required />
                        <InputError class="mt-1" :message="form.errors.company_id" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">Your Invoice / SI No.</label>
                        <input v-model="form.invoice_no" type="text" required placeholder="Ex. SI-00123" :class="inputClass" />
                        <InputError class="mt-1" :message="form.errors.invoice_no" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">Related PO No. <span class="font-medium text-slate-400">(optional)</span></label>
                        <input v-model="form.po_number" type="text" placeholder="Ex. PO-2026-00010" :class="inputClass" />
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
                <div class="mt-5">
                    <label class="mb-1 block text-sm font-bold text-slate-700">Remarks</label>
                    <textarea v-model="form.remarks" rows="2" placeholder="Notes for the reviewer..." :class="inputClass"></textarea>
                </div>
            </div>

            <!-- Line items -->
            <div>
                <LineItemsEditor v-model="form.items" :products="products" :uoms="uoms" />
                <InputError class="mt-2" :message="form.errors.items" />
            </div>

            <!-- Attachments -->
            <div class="rounded-3xl border border-slate-200 bg-white p-7 shadow-sm">
                <h3 class="mb-3 text-xs font-black uppercase tracking-widest text-slate-500">Attachments</h3>
                <p class="mb-3 text-xs text-slate-400">Attach the scanned invoice, delivery receipts, or supporting files (max 10 MB each).</p>
                <input type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx"
                    class="block w-full text-sm text-slate-500 file:mr-4 file:rounded-xl file:border-0 file:bg-emerald-50 file:px-4 file:py-2.5 file:text-sm file:font-bold file:text-emerald-700 hover:file:bg-emerald-100"
                    @change="form.attachments = Array.from($event.target.files)" />
            </div>

            <!-- Actions -->
            <div class="flex flex-col-reverse items-stretch justify-end gap-3 sm:flex-row sm:items-center">
                <Link :href="route('vendor.invoices.index')" class="rounded-xl bg-slate-100 px-6 py-3 text-center text-sm font-bold text-slate-600 transition hover:bg-slate-200">Cancel</Link>
                <button type="button" :disabled="form.processing" @click="submit('draft')"
                    class="rounded-xl border border-slate-200 bg-white px-6 py-3 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:opacity-50">
                    Save as Draft
                </button>
                <button type="submit" :disabled="form.processing"
                    class="rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 px-6 py-3 text-sm font-black text-white shadow-lg shadow-emerald-600/25 transition hover:shadow-emerald-600/40 disabled:opacity-50">
                    {{ form.processing ? 'Saving...' : 'Submit for Approval' }}
                </button>
            </div>
        </form>
    </VendorLayout>
</template>
