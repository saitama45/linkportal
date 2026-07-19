<script setup>
import { ref, computed, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from '@/Components/DataTable.vue';
import StatusBadge from '@/Components/Portal/StatusBadge.vue';
import Modal from '@/Components/Modal.vue';
import Autocomplete from '@/Components/Autocomplete.vue';
import { usePermission } from '@/Composables/usePermission';
import { PencilSquareIcon, PlusIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    templates: Object,
    filters: { type: Object, default: () => ({}) },
    vendors: { type: Array, default: () => [] },
    existingScopes: { type: Array, default: () => [] },
});

const { hasPermission } = usePermission();

const showCreate = ref(false);
const form = useForm({
    vendor_id: null,
    document_type: 'invoice',
    name: '',
    description: '',
});

// Vendor keys (id, or null for Global) that already have a template of the
// chosen type — one template per vendor+type, so hide the taken ones.
const takenForType = computed(() => {
    const set = new Set();
    for (const s of props.existingScopes) {
        if (s.document_type === form.document_type) set.add(s.vendor_id ?? null);
    }
    return set;
});
const globalTaken = computed(() => takenForType.value.has(null));
const availableVendors = computed(() => props.vendors.filter((v) => !takenForType.value.has(v.id)));
const noneAvailable = computed(() => globalTaken.value && availableVendors.value.length === 0);

// Keep the selection valid when the type changes or the modal opens.
const pickValidVendor = () => {
    if (takenForType.value.has(form.vendor_id ?? null)) {
        form.vendor_id = globalTaken.value ? (availableVendors.value[0]?.id ?? null) : null;
    }
};
watch(() => form.document_type, pickValidVendor);
watch(showCreate, (open) => { if (open) pickValidVendor(); });

// Autocomplete treats null/'' as "nothing selected", but Global (vendor_id:
// null) is a real, meaningful choice here — give it a stand-in value that's
// selectable/highlightable, translated back to null at the model boundary.
const GLOBAL_VENDOR_VALUE = '__global__';
const vendorOptions = computed(() => [
    ...(globalTaken.value ? [] : [{ value: GLOBAL_VENDOR_VALUE, label: 'Global (fallback for all vendors)' }]),
    ...availableVendors.value.map((v) => ({ value: v.id, label: `${v.name} (${v.code})` })),
]);
const formVendorModel = computed({
    get: () => (form.vendor_id === null ? GLOBAL_VENDOR_VALUE : form.vendor_id),
    set: (value) => { form.vendor_id = (!value || value === GLOBAL_VENDOR_VALUE) ? null : value; },
});

const documentTypeOptions = [
    { value: 'invoice', label: 'Invoice' },
    { value: 'purchase_order', label: 'Purchase Order' },
    { value: 'quotation', label: 'Quotation' },
];

const submit = () => {
    form.post(route('document-templates.store'), {
        onSuccess: () => { showCreate.value = false; form.reset(); },
    });
};

const typeLabel = (type) => ({ invoice: 'Invoice', purchase_order: 'Purchase Order', quotation: 'Quotation' }[type] || type);
const formatDate = (value) => (value ? new Date(value).toLocaleDateString() : '—');
</script>

<template>
    <Head title="OCR Templates - Link Portal" />

    <AppLayout>
        <template #header>
            <div>
                <h2 class="text-2xl font-bold leading-tight text-slate-800">OCR Templates</h2>
                <p class="mt-1 text-sm text-slate-500">Field and line-item annotations per vendor and document type.</p>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
                    <DataTable
                        title="Templates"
                        subtitle="Vendor-specific templates override global fallbacks"
                        search-placeholder="Search template or vendor..."
                        empty-message="No templates yet. Create one to start extracting fields."
                        data-key="templates"
                        route-name="document-templates.index"
                        :paginator="templates"
                        :initial-search="filters.search"
                    >
                        <template #actions>
                            <button v-if="hasPermission('document-templates.create')" type="button"
                                class="flex items-center space-x-2 rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-emerald-600/20 transition-all hover:bg-emerald-700"
                                @click="showCreate = true">
                                <PlusIcon class="h-5 w-5" />
                                <span>New Template</span>
                            </button>
                        </template>

                        <template #header>
                            <tr class="bg-slate-50">
                                <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Name</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Scope</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Type</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Active Version</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Status</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-right text-xs font-bold uppercase tracking-widest text-slate-500">Actions</th>
                            </tr>
                        </template>

                        <template #body="{ data }">
                            <tr v-for="template in data" :key="template.id" class="border-b border-slate-50 transition-colors last:border-0 hover:bg-slate-50/50">
                                <td class="whitespace-nowrap px-6 py-4 text-sm font-black text-slate-900">{{ template.name }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-600">
                                    <span v-if="template.vendor">{{ template.vendor.name }}</span>
                                    <span v-else class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-500">Global</span>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-600">{{ typeLabel(template.document_type) }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-600">
                                    <template v-if="template.active_version">
                                        v{{ template.active_version.version_no }} · {{ formatDate(template.active_version.activated_at) }}
                                    </template>
                                    <span v-else class="text-slate-400">—</span>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4"><StatusBadge :status="template.status" /></td>
                                <td class="whitespace-nowrap px-6 py-4 text-right">
                                    <Link :href="route('document-templates.edit', template.id)"
                                        class="inline-flex rounded-lg p-2 text-slate-400 transition-all hover:bg-emerald-50 hover:text-emerald-600" title="Annotate">
                                        <PencilSquareIcon class="h-5 w-5" />
                                    </Link>
                                </td>
                            </tr>
                        </template>
                    </DataTable>
                </div>
            </div>
        </div>

        <Modal :show="showCreate" @close="showCreate = false">
            <form class="p-6" @submit.prevent="submit">
                <h3 class="text-lg font-bold text-slate-900">New OCR Template</h3>
                <div class="mt-5 space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-slate-700">Vendor</label>
                        <Autocomplete
                            v-model="formVendorModel"
                            :options="vendorOptions"
                            :disabled="noneAvailable"
                            placeholder="Select a vendor…"
                            required
                        />
                        <p v-if="noneAvailable" class="mt-1 text-sm text-amber-600">
                            Every vendor already has a template for this type — add a <span class="font-semibold">New Version</span> to the existing template instead.
                        </p>
                        <p v-else class="mt-1 text-xs text-slate-400">Vendors that already have a template for this type are hidden. Use New Version for other layouts.</p>
                        <p v-if="form.errors.vendor_id" class="mt-1 text-sm text-red-600">{{ form.errors.vendor_id }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-slate-700">Document Type</label>
                        <Autocomplete
                            v-model="form.document_type"
                            :options="documentTypeOptions"
                            placeholder="Select a document type…"
                            required
                        />
                        <p v-if="form.errors.document_type" class="mt-1 text-sm text-red-600">{{ form.errors.document_type }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-slate-700">Name</label>
                        <input v-model="form.name" type="text" placeholder="e.g. Acme Supplies — Standard Invoice"
                            class="w-full rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500/30" />
                        <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-slate-700">Description</label>
                        <input v-model="form.description" type="text"
                            class="w-full rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500/30" />
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-xl px-5 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-100" @click="showCreate = false">
                        Cancel
                    </button>
                    <button type="submit" :disabled="form.processing || noneAvailable"
                        class="rounded-xl bg-emerald-600 px-6 py-2.5 text-sm font-semibold text-white shadow-lg shadow-emerald-600/20 hover:bg-emerald-700 disabled:opacity-50">
                        Create Template
                    </button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
