<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import VendorLayout from '@/Layouts/VendorLayout.vue';
import { ArrowLeftIcon, DocumentArrowUpIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    documentTypes: { type: Array, default: () => ['invoice', 'purchase_order', 'quotation'] },
});

const typeLabels = { invoice: 'Invoice', purchase_order: 'Purchase Order', quotation: 'Quotation' };

const form = useForm({
    document_type: 'invoice',
    file: null,
});

const dragging = ref(false);
const fileInput = ref(null);

const setFile = (file) => {
    if (file) form.file = file;
};

const onDrop = (event) => {
    dragging.value = false;
    setFile(event.dataTransfer?.files?.[0]);
};

const submit = () => {
    form.post(route('vendor.document-uploads.store'));
};
</script>

<template>
    <Head title="Upload Document - Link Portal" />

    <VendorLayout>
        <template #header>
            <div class="flex items-center gap-4">
                <Link :href="route('vendor.document-uploads.index')"
                    class="rounded-lg p-2 text-slate-400 transition-all hover:bg-slate-100 hover:text-slate-600">
                    <ArrowLeftIcon class="h-5 w-5" />
                </Link>
                <div>
                    <h2 class="text-2xl font-black tracking-tight text-slate-900">Upload Document</h2>
                    <p class="mt-1 text-sm text-slate-500">PDF, DOC, or DOCX up to 20 MB. We extract the details automatically.</p>
                </div>
            </div>
        </template>

        <form class="mx-auto max-w-2xl" @submit.prevent="submit">
            <div class="space-y-6 rounded-2xl border border-slate-100 bg-white p-8 shadow-sm">
                <div>
                    <label class="mb-2 block text-sm font-bold text-slate-700">Document Type</label>
                    <div class="grid grid-cols-3 gap-3">
                        <button v-for="type in documentTypes" :key="type" type="button"
                            :class="['rounded-xl border px-4 py-3 text-sm font-semibold transition-all',
                                form.document_type === type
                                    ? 'border-emerald-500 bg-emerald-50 text-emerald-700'
                                    : 'border-slate-200 text-slate-600 hover:border-slate-300']"
                            @click="form.document_type = type">
                            {{ typeLabels[type] || type }}
                        </button>
                    </div>
                    <p v-if="form.errors.document_type" class="mt-2 text-sm text-red-600">{{ form.errors.document_type }}</p>
                </div>

                <div>
                    <label class="mb-2 block text-sm font-bold text-slate-700">File</label>
                    <div
                        :class="['flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed px-6 py-10 text-center transition-all',
                            dragging ? 'border-emerald-500 bg-emerald-50' : 'border-slate-300 hover:border-emerald-400']"
                        @click="fileInput?.click()"
                        @dragover.prevent="dragging = true"
                        @dragleave.prevent="dragging = false"
                        @drop.prevent="onDrop">
                        <DocumentArrowUpIcon class="h-10 w-10 text-slate-400" />
                        <p v-if="form.file" class="mt-3 text-sm font-semibold text-slate-800">{{ form.file.name }}</p>
                        <template v-else>
                            <p class="mt-3 text-sm font-semibold text-slate-700">Drop your file here or click to browse</p>
                            <p class="mt-1 text-xs text-slate-500">PDF, DOC, DOCX — max 20 MB</p>
                        </template>
                        <input ref="fileInput" type="file" class="hidden" accept=".pdf,.doc,.docx"
                            @change="setFile($event.target.files[0])" />
                    </div>
                    <p v-if="form.errors.file" class="mt-2 text-sm text-red-600">{{ form.errors.file }}</p>
                </div>

                <div class="flex items-center justify-end gap-3 border-t border-slate-100 pt-6">
                    <Link :href="route('vendor.document-uploads.index')"
                        class="rounded-xl px-5 py-2.5 text-sm font-semibold text-slate-600 transition-all hover:bg-slate-100">
                        Cancel
                    </Link>
                    <button type="submit" :disabled="form.processing || !form.file"
                        class="rounded-xl bg-emerald-600 px-6 py-2.5 text-sm font-semibold text-white shadow-lg shadow-emerald-600/20 transition-all hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50">
                        {{ form.processing ? 'Uploading...' : 'Upload & Process' }}
                    </button>
                </div>
            </div>
        </form>
    </VendorLayout>
</template>
