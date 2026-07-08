<script setup>
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from '@/Components/DataTable.vue';
import Autocomplete from '@/Components/Autocomplete.vue';
import InputError from '@/Components/InputError.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { useErrorHandler } from '@/Composables/useErrorHandler';
import { usePermission } from '@/Composables/usePermission';
import { useForm } from '@inertiajs/vue3';
import { CubeIcon, PencilSquareIcon, PlusIcon, TrashIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    products: Object,
    categories: Array,
    uoms: Array,
    productTypes: Array,
    filters: { type: Object, default: () => ({}) },
});

const { confirm } = useConfirm();
const { destroy } = useErrorHandler();
const { hasPermission } = usePermission();

const showModal = ref(false);
const editing = ref(null);

const form = useForm({
    code: '',
    name: '',
    description: '',
    product_type: 'good',
    category_id: '',
    uom_id: '',
    default_price: '',
    currency: 'PHP',
    tax_rate: 12,
    is_active: true,
});

const categoryOptions = props.categories.map((c) => ({ label: c.name, value: c.id }));
const uomOptions = props.uoms.map((u) => ({ label: `${u.code} — ${u.name}`, value: u.id }));

const openCreate = () => {
    editing.value = null;
    form.reset();
    showModal.value = true;
};

const openEdit = (product) => {
    editing.value = product;
    form.code = product.code;
    form.name = product.name;
    form.description = product.description || '';
    form.product_type = product.product_type;
    form.category_id = product.category_id || '';
    form.uom_id = product.uom_id || '';
    form.default_price = product.default_price != null ? Number(product.default_price) : '';
    form.currency = product.currency || 'PHP';
    form.tax_rate = product.tax_rate != null ? Number(product.tax_rate) : '';
    form.is_active = !!product.is_active;
    showModal.value = true;
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => { showModal.value = false; form.reset(); } };
    if (editing.value) {
        form.put(route('products.update', editing.value.id), options);
    } else {
        form.post(route('products.store'), options);
    }
};

const remove = async (product) => {
    const ok = await confirm({ title: 'Delete Product', message: `Delete "${product.name}" (${product.code})?` });
    if (ok) destroy(route('products.destroy', product.id), {});
};

const money = (value) => (value != null ? Number(value).toLocaleString(undefined, { minimumFractionDigits: 2 }) : '—');
const typeLabel = (type) => (type || '').replace(/_/g, ' ');
const inputClass = 'block w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all';
</script>

<template>
    <Head title="Products - Link Portal" />

    <AppLayout>
        <template #header>
            <div>
                <h2 class="text-2xl font-bold leading-tight text-slate-800">Product Master Data</h2>
                <p class="mt-1 text-sm text-slate-500">Flexible catalog of assets, goods, services, raw materials, and consumables.</p>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
                    <DataTable
                        title="Product Catalog"
                        subtitle="Items available for transactions"
                        search-placeholder="Search name or code..."
                        empty-message="No products defined yet."
                        data-key="products"
                        route-name="products.index"
                        :paginator="products"
                        :initial-search="filters.search"
                    >
                        <template #actions>
                            <button v-if="hasPermission('products.create')" @click="openCreate"
                                class="flex items-center space-x-2 rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-emerald-600/20 transition-all hover:bg-emerald-700">
                                <PlusIcon class="h-5 w-5" />
                                <span>New Product</span>
                            </button>
                        </template>

                        <template #header>
                            <tr class="bg-slate-50">
                                <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Product</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Type</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Category</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">UoM</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-right text-xs font-bold uppercase tracking-widest text-slate-500">Price</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Status</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-right text-xs font-bold uppercase tracking-widest text-slate-500">Actions</th>
                            </tr>
                        </template>

                        <template #body="{ data }">
                            <tr v-for="product in data" :key="product.id" class="border-b border-slate-50 transition-colors last:border-0 hover:bg-slate-50/50">
                                <td class="px-6 py-4">
                                    <div class="flex items-center">
                                        <div class="flex h-10 w-10 items-center justify-center rounded-lg border border-slate-200 bg-slate-100 text-slate-500">
                                            <CubeIcon class="h-5 w-5" />
                                        </div>
                                        <div class="ml-4 min-w-0">
                                            <div class="text-sm font-bold text-slate-900">{{ product.name }}</div>
                                            <div class="font-mono text-xs text-slate-500">{{ product.code }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4">
                                    <span class="inline-flex rounded-lg border border-emerald-100 bg-emerald-50 px-2.5 py-1 text-xs font-bold capitalize text-emerald-700">{{ typeLabel(product.product_type) }}</span>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-600">{{ product.category?.name || '—' }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-600">{{ product.uom?.code || '—' }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-bold text-slate-800">{{ money(product.default_price) }}</td>
                                <td class="whitespace-nowrap px-6 py-4">
                                    <span :class="['inline-flex rounded-lg border px-2.5 py-1 text-xs font-bold', product.is_active ? 'border-emerald-100 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-50 text-slate-500']">
                                        {{ product.is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-right">
                                    <div class="flex justify-end space-x-1">
                                        <button v-if="hasPermission('products.edit')" @click="openEdit(product)"
                                            class="rounded-lg p-2 text-slate-400 transition-all hover:bg-emerald-50 hover:text-emerald-600" title="Edit">
                                            <PencilSquareIcon class="h-5 w-5" />
                                        </button>
                                        <button v-if="hasPermission('products.delete')" @click="remove(product)"
                                            class="rounded-lg p-2 text-slate-400 transition-all hover:bg-red-50 hover:text-red-600" title="Delete">
                                            <TrashIcon class="h-5 w-5" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </DataTable>
                </div>
            </div>
        </div>

        <!-- Create/Edit modal -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-4">
            <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm" @click="showModal = false"></div>
            <div class="relative w-full max-w-2xl overflow-hidden rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-100 bg-slate-50/50 px-8 py-6">
                    <h3 class="text-xl font-bold text-slate-900">{{ editing ? 'Edit Product' : 'New Product' }}</h3>
                    <p class="text-sm text-slate-500">Flexible master data — the type determines how the item is used.</p>
                </div>

                <form class="max-h-[70vh] space-y-5 overflow-y-auto p-8" @submit.prevent="submit">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">Code / SKU</label>
                            <input v-model="form.code" type="text" required placeholder="Ex. ITM-0001" :class="inputClass" />
                            <InputError class="mt-1" :message="form.errors.code" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">Product Type</label>
                            <select v-model="form.product_type" required :class="inputClass">
                                <option v-for="t in productTypes" :key="t" :value="t" class="capitalize">{{ typeLabel(t) }}</option>
                            </select>
                            <InputError class="mt-1" :message="form.errors.product_type" />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-sm font-bold text-slate-700">Name</label>
                            <input v-model="form.name" type="text" required placeholder="Product / item name" :class="inputClass" />
                            <InputError class="mt-1" :message="form.errors.name" />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-sm font-bold text-slate-700">Description</label>
                            <textarea v-model="form.description" rows="2" :class="inputClass"></textarea>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">Category</label>
                            <Autocomplete v-model="form.category_id" :options="categoryOptions" placeholder="Select category..." />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">Unit of Measure</label>
                            <Autocomplete v-model="form.uom_id" :options="uomOptions" placeholder="Select UoM..." />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">Default Price</label>
                            <input v-model.number="form.default_price" type="number" min="0" step="any" :class="inputClass" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">Default Tax %</label>
                            <input v-model.number="form.tax_rate" type="number" min="0" max="100" step="any" :class="inputClass" />
                        </div>
                    </div>

                    <label class="flex items-center gap-2.5">
                        <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" />
                        <span class="text-sm font-semibold text-slate-600">Active</span>
                    </label>

                    <div class="flex justify-end gap-3 border-t border-slate-100 pt-6">
                        <button type="button" @click="showModal = false" class="rounded-xl bg-slate-100 px-6 py-2.5 font-bold text-slate-600 transition-colors hover:bg-slate-200">Cancel</button>
                        <button type="submit" :disabled="form.processing"
                            class="rounded-xl bg-emerald-600 px-6 py-2.5 font-bold text-white shadow-lg shadow-emerald-600/20 transition-all hover:bg-emerald-700 disabled:opacity-50">
                            {{ editing ? 'Save Changes' : 'Create Product' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
