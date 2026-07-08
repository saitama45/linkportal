<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import VendorLayout from '@/Layouts/VendorLayout.vue';
import LineItemsEditor from '@/Components/Portal/LineItemsEditor.vue';
import Autocomplete from '@/Components/Autocomplete.vue';
import InputError from '@/Components/InputError.vue';
import { ArrowLeftIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    quotation: { type: Object, default: null },
    companies: Array,
    products: Array,
    uoms: Array,
});

const isEdit = !!props.quotation;

const form = useForm({
    company_id: props.quotation?.company_id ?? '',
    quotation_no: props.quotation?.quotation_no ?? '',
    title: props.quotation?.title ?? '',
    quotation_date: props.quotation?.quotation_date?.substring(0, 10) ?? new Date().toISOString().substring(0, 10),
    valid_until: props.quotation?.valid_until?.substring(0, 10) ?? '',
    payment_terms: props.quotation?.payment_terms ?? '',
    delivery_terms: props.quotation?.delivery_terms ?? '',
    remarks: props.quotation?.remarks ?? '',
    items: props.quotation?.items?.map((i) => ({
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
        form.transform((data) => ({ ...data, _method: 'put' })).post(route('vendor.quotations.update', props.quotation.id), options);
    } else {
        form.post(route('vendor.quotations.store'), options);
    }
};

const inputClass = 'block w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all';
</script>

<template>
    <Head :title="(isEdit ? 'Edit' : 'New') + ' Quotation - Link Portal'" />

    <VendorLayout>
        <template #header>
            <div class="flex items-center gap-4">
                <Link :href="route('vendor.quotations.index')" class="rounded-xl border border-slate-200 bg-white p-2.5 text-slate-500 transition hover:text-emerald-600">
                    <ArrowLeftIcon class="h-5 w-5" />
                </Link>
                <div>
                    <h2 class="text-2xl font-black tracking-tight text-slate-900">{{ isEdit ? `Edit ${quotation.reference_no}` : 'New Quotation' }}</h2>
                    <p class="mt-1 text-sm text-slate-500">Save as draft or submit directly for approval.</p>
                </div>
            </div>
        </template>

        <form class="space-y-6" @submit.prevent="submit('submit')">
            <div class="rounded-3xl border border-slate-200 bg-white p-7 shadow-sm">
                <h3 class="mb-5 text-xs font-black uppercase tracking-widest text-slate-500">Quotation Details</h3>
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">Company</label>
                        <Autocomplete v-model="form.company_id" :options="companyOptions" placeholder="Select company..." required />
                        <InputError class="mt-1" :message="form.errors.company_id" />
                    </div>
                    <div class="lg:col-span-2">
                        <label class="mb-1 block text-sm font-bold text-slate-700">Title / Subject</label>
                        <input v-model="form.title" type="text" required placeholder="Ex. Supply of office equipment Q3 2026" :class="inputClass" />
                        <InputError class="mt-1" :message="form.errors.title" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">Your Quote No. <span class="font-medium text-slate-400">(optional)</span></label>
                        <input v-model="form.quotation_no" type="text" placeholder="Ex. Q-00123" :class="inputClass" />
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
                        <input v-model="form.payment_terms" type="text" placeholder="Ex. Net 30 days" :class="inputClass" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">Delivery Terms</label>
                        <input v-model="form.delivery_terms" type="text" placeholder="Ex. 2 weeks after PO" :class="inputClass" />
                    </div>
                </div>
                <div class="mt-5">
                    <label class="mb-1 block text-sm font-bold text-slate-700">Remarks</label>
                    <textarea v-model="form.remarks" rows="2" placeholder="Notes for the reviewer..." :class="inputClass"></textarea>
                </div>
            </div>

            <div>
                <LineItemsEditor v-model="form.items" :products="products" :uoms="uoms" />
                <InputError class="mt-2" :message="form.errors.items" />
            </div>

            <div class="rounded-3xl border border-slate-200 bg-white p-7 shadow-sm">
                <h3 class="mb-3 text-xs font-black uppercase tracking-widest text-slate-500">Attachments</h3>
                <input type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx"
                    class="block w-full text-sm text-slate-500 file:mr-4 file:rounded-xl file:border-0 file:bg-emerald-50 file:px-4 file:py-2.5 file:text-sm file:font-bold file:text-emerald-700 hover:file:bg-emerald-100"
                    @change="form.attachments = Array.from($event.target.files)" />
            </div>

            <div class="flex flex-col-reverse items-stretch justify-end gap-3 sm:flex-row sm:items-center">
                <Link :href="route('vendor.quotations.index')" class="rounded-xl bg-slate-100 px-6 py-3 text-center text-sm font-bold text-slate-600 transition hover:bg-slate-200">Cancel</Link>
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
