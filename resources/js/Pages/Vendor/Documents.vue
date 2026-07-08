<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import VendorLayout from '@/Layouts/VendorLayout.vue';
import StatusBadge from '@/Components/Portal/StatusBadge.vue';
import Autocomplete from '@/Components/Autocomplete.vue';
import InputError from '@/Components/InputError.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { useErrorHandler } from '@/Composables/useErrorHandler';
import { ArrowUpTrayIcon, DocumentTextIcon, TrashIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    documents: Array,
    documentTypes: Array,
});

const showUpload = ref(false);
const { confirm } = useConfirm();
const { destroy } = useErrorHandler();

const form = useForm({
    document_type_id: '',
    title: '',
    file: null,
    issued_date: '',
    expiry_date: '',
    supersedes_id: '',
});

const typeOptions = props.documentTypes.map((t) => ({ label: t.label, value: t.id }));

const submit = () => {
    form.post(route('vendor.documents.store'), {
        forceFormData: true,
        onSuccess: () => {
            showUpload.value = false;
            form.reset();
        },
    });
};

const removeDocument = async (doc) => {
    const ok = await confirm({
        title: 'Remove Document',
        message: `Remove "${doc.title}"? Only pending documents can be removed.`,
    });
    if (ok) destroy(route('vendor.documents.destroy', doc.id), {});
};

const formatDate = (value) => (value ? new Date(value).toLocaleDateString() : '—');
const fileSize = (bytes) => (bytes ? `${(bytes / 1024 / 1024).toFixed(2)} MB` : '');
const inputClass = 'block w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all';
</script>

<template>
    <Head title="Accreditation Documents - Link Portal" />

    <VendorLayout>
        <template #header>
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <h2 class="text-2xl font-black tracking-tight text-slate-900">Accreditation Documents</h2>
                    <p class="mt-1 text-sm text-slate-500">Upload compliance and accreditation files for review. Approved documents keep your account in good standing.</p>
                </div>
                <button @click="showUpload = true"
                    class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-emerald-600/20 transition hover:bg-emerald-700">
                    <ArrowUpTrayIcon class="h-4.5 w-4.5" />
                    Upload Document
                </button>
            </div>
        </template>

        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Document</th>
                            <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Type</th>
                            <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Issued</th>
                            <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Expiry</th>
                            <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Status</th>
                            <th class="px-6 py-4 text-right text-xs font-bold uppercase tracking-widest text-slate-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <tr v-for="doc in documents" :key="doc.id" class="transition-colors hover:bg-slate-50/50">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 text-slate-500">
                                        <DocumentTextIcon class="h-5 w-5" />
                                    </span>
                                    <div class="min-w-0">
                                        <a :href="`/storage/${doc.file_path}`" target="_blank" class="block truncate text-sm font-bold text-slate-900 hover:text-emerald-700">
                                            {{ doc.title }} <span v-if="doc.version > 1" class="text-xs font-semibold text-slate-400">v{{ doc.version }}</span>
                                        </a>
                                        <p class="text-xs text-slate-400">{{ doc.file_name }} · {{ fileSize(doc.file_size) }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-slate-600">{{ doc.document_type?.label || '—' }}</td>
                            <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-600">{{ formatDate(doc.issued_date) }}</td>
                            <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-600">{{ formatDate(doc.expiry_date) }}</td>
                            <td class="whitespace-nowrap px-6 py-4">
                                <StatusBadge :status="doc.status" />
                                <p v-if="doc.review_remarks" class="mt-1 max-w-[200px] truncate text-xs italic text-slate-400" :title="doc.review_remarks">"{{ doc.review_remarks }}"</p>
                            </td>
                            <td class="whitespace-nowrap px-6 py-4 text-right">
                                <button v-if="doc.status === 'pending'" @click="removeDocument(doc)"
                                    class="rounded-lg p-2 text-slate-400 transition-all hover:bg-red-50 hover:text-red-600" title="Remove">
                                    <TrashIcon class="h-5 w-5" />
                                </button>
                            </td>
                        </tr>
                        <tr v-if="documents.length === 0">
                            <td colspan="6" class="px-6 py-14 text-center">
                                <DocumentTextIcon class="mx-auto mb-3 h-12 w-12 text-slate-200" />
                                <p class="text-sm font-bold text-slate-500">No documents uploaded yet.</p>
                                <p class="mt-1 text-xs text-slate-400">Upload your business permits, BIR registration, and other accreditation files.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Upload modal -->
        <div v-if="showUpload" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-4">
            <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm" @click="showUpload = false"></div>
            <div class="relative w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-100 bg-slate-50/50 px-8 py-6">
                    <h3 class="text-xl font-bold text-slate-900">Upload Accreditation Document</h3>
                    <p class="text-sm text-slate-500">PDF, images, or Office files up to 10 MB. Submitted documents are reviewed by an administrator.</p>
                </div>

                <form class="space-y-5 p-8" @submit.prevent="submit">
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">Document Type</label>
                        <Autocomplete v-model="form.document_type_id" :options="typeOptions" placeholder="Select type..." required />
                        <InputError class="mt-2" :message="form.errors.document_type_id" />
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">Title</label>
                        <input v-model="form.title" type="text" required placeholder="Ex. Mayor's Permit 2026" :class="inputClass" />
                        <InputError class="mt-2" :message="form.errors.title" />
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">File</label>
                        <input type="file" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx"
                            class="block w-full text-sm text-slate-500 file:mr-4 file:rounded-xl file:border-0 file:bg-emerald-50 file:px-4 file:py-2.5 file:text-sm file:font-bold file:text-emerald-700 hover:file:bg-emerald-100"
                            @change="form.file = $event.target.files[0]" />
                        <InputError class="mt-2" :message="form.errors.file" />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">Issued Date</label>
                            <input v-model="form.issued_date" type="date" :class="inputClass" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">Expiry Date</label>
                            <input v-model="form.expiry_date" type="date" :class="inputClass" />
                            <InputError class="mt-2" :message="form.errors.expiry_date" />
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 border-t border-slate-100 pt-6">
                        <button type="button" @click="showUpload = false"
                            class="rounded-xl bg-slate-100 px-6 py-2.5 font-bold text-slate-600 transition-colors hover:bg-slate-200">Cancel</button>
                        <button type="submit" :disabled="form.processing"
                            class="rounded-xl bg-emerald-600 px-6 py-2.5 font-bold text-white shadow-lg shadow-emerald-600/20 transition-all hover:bg-emerald-700 disabled:opacity-50">
                            {{ form.processing ? 'Uploading...' : 'Upload & Submit' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </VendorLayout>
</template>
