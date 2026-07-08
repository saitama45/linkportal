<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from '@/Components/DataTable.vue';
import InputError from '@/Components/InputError.vue';
import StatusBadge from '@/Components/Portal/StatusBadge.vue';
import { usePermission } from '@/Composables/usePermission';
import { BuildingStorefrontIcon, EyeIcon, PencilSquareIcon, PlusIcon } from '@heroicons/vue/24/outline';

defineProps({
    vendors: Object,
    filters: { type: Object, default: () => ({}) },
    companies: { type: Array, default: () => [] },
    vendorTypeOptions: { type: Array, default: () => [] },
});

const { hasPermission } = usePermission();
const showCreateModal = ref(false);
const showEditModal = ref(false);
const editingVendor = ref(null);

const createForm = useForm({
    company_id: '',
    name: '',
    email: '',
    phone: '',
    vendor_type: '',
    password: '',
    password_confirmation: '',
});

const closeCreateModal = () => {
    showCreateModal.value = false;
    createForm.reset();
    createForm.clearErrors();
};

const createVendor = () => {
    createForm.post(route('vendors.store'), {
        preserveScroll: true,
        onSuccess: closeCreateModal,
        onFinish: () => createForm.reset('password', 'password_confirmation'),
    });
};

const editForm = useForm({
    company_id: '',
    name: '',
    email: '',
    phone: '',
    vendor_type: '',
});

const openEditModal = (vendor) => {
    editingVendor.value = vendor;
    editForm.company_id = vendor.company_id ?? '';
    editForm.name = vendor.name;
    editForm.email = vendor.email;
    editForm.phone = vendor.phone ?? '';
    editForm.vendor_type = vendor.vendor_type ?? '';
    editForm.clearErrors();
    showEditModal.value = true;
};

const closeEditModal = () => {
    showEditModal.value = false;
    editingVendor.value = null;
    editForm.reset();
    editForm.clearErrors();
};

const updateVendor = () => {
    editForm.put(route('vendors.update', editingVendor.value.id), {
        preserveScroll: true,
        onSuccess: closeEditModal,
    });
};

const formatDate = (value) => (value ? new Date(value).toLocaleDateString() : '—');
</script>

<template>
    <Head title="Vendors - Link Portal" />

    <AppLayout>
        <template #header>
            <div>
                <h2 class="text-2xl font-bold leading-tight text-slate-800">Vendor Management</h2>
                <p class="mt-1 text-sm text-slate-500">Accounts, accreditation, and onboarding of external vendors and partners.</p>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
                    <DataTable
                        title="Vendor Directory"
                        subtitle="Registered external vendors and partners"
                        search-placeholder="Search name, code, or email..."
                        empty-message="No vendors registered yet."
                        data-key="vendors"
                        route-name="vendors.index"
                        :paginator="vendors"
                        :initial-search="filters.search"
                    >
                        <template #actions>
                            <button
                                v-if="hasPermission('vendors.create')"
                                type="button"
                                class="flex items-center space-x-2 rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-emerald-600/20 transition-all duration-200 hover:bg-emerald-700"
                                @click="showCreateModal = true"
                            >
                                <PlusIcon class="h-5 w-5" />
                                <span>Register Vendor</span>
                            </button>
                        </template>

                        <template #header>
                            <tr class="bg-slate-50">
                                <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Vendor</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Code</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Type</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Registered</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Status</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-right text-xs font-bold uppercase tracking-widest text-slate-500">Actions</th>
                            </tr>
                        </template>

                        <template #body="{ data }">
                            <tr v-for="vendor in data" :key="vendor.id" class="border-b border-slate-50 transition-colors last:border-0 hover:bg-slate-50/50">
                                <td class="px-6 py-4">
                                    <div class="flex items-center">
                                        <div class="flex h-10 w-10 items-center justify-center rounded-lg border border-emerald-100 bg-emerald-50 font-bold text-emerald-600">
                                            <BuildingStorefrontIcon class="h-5 w-5" />
                                        </div>
                                        <div class="ml-4 min-w-0">
                                            <div class="text-sm font-bold text-slate-900">{{ vendor.name }}</div>
                                            <div class="truncate text-xs text-slate-500">{{ vendor.email }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4">
                                    <span class="inline-flex rounded-lg border border-slate-200 bg-slate-100 px-2.5 py-1 font-mono text-xs font-bold text-slate-700">{{ vendor.code }}</span>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm capitalize text-slate-600">{{ (vendor.vendor_type || '—').replace(/_/g, ' ') }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-600">{{ formatDate(vendor.created_at) }}</td>
                                <td class="whitespace-nowrap px-6 py-4"><StatusBadge :status="vendor.status" /></td>
                                <td class="whitespace-nowrap px-6 py-4 text-right">
                                    <button
                                        v-if="hasPermission('vendors.edit')"
                                        type="button"
                                        class="inline-flex rounded-lg p-2 text-slate-400 transition-all hover:bg-blue-50 hover:text-blue-600"
                                        title="Edit Vendor"
                                        @click="openEditModal(vendor)"
                                    >
                                        <PencilSquareIcon class="h-5 w-5" />
                                    </button>
                                    <Link :href="route('vendors.show', vendor.id)"
                                        class="inline-flex rounded-lg p-2 text-slate-400 transition-all hover:bg-emerald-50 hover:text-emerald-600" title="Manage Vendor">
                                        <EyeIcon class="h-5 w-5" />
                                    </Link>
                                </td>
                            </tr>
                        </template>
                    </DataTable>
                </div>
            </div>
        </div>

        <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-4">
            <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm" @click="closeCreateModal"></div>

            <div class="relative w-full max-w-2xl overflow-hidden rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-100 bg-slate-50/50 px-8 py-6">
                    <h3 class="text-xl font-bold text-slate-900">Register New Vendor</h3>
                    <p class="text-sm text-slate-500">Create a pending vendor account and temporary sign-in credentials.</p>
                </div>

                <form class="space-y-5 p-8" @submit.prevent="createVendor">
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">Vendor Name</label>
                        <input
                            v-model="createForm.name"
                            type="text"
                            required
                            autofocus
                            placeholder="Ex. Acme Supplies Inc."
                            class="block w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 transition-all focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20"
                        />
                        <InputError class="mt-2" :message="createForm.errors.name" />
                    </div>

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">Email Address</label>
                            <input
                                v-model="createForm.email"
                                type="email"
                                required
                                placeholder="vendor@example.com"
                                class="block w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 transition-all focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20"
                            />
                            <InputError class="mt-2" :message="createForm.errors.email" />
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">Phone</label>
                            <input
                                v-model="createForm.phone"
                                type="text"
                                placeholder="+63 ..."
                                class="block w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 transition-all focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20"
                            />
                            <InputError class="mt-2" :message="createForm.errors.phone" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">Company</label>
                            <select
                                v-model="createForm.company_id"
                                class="block w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 transition-all focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20"
                            >
                                <option value="">Not assigned</option>
                                <option v-for="company in companies" :key="company.id" :value="company.id">
                                    {{ company.name }}
                                </option>
                            </select>
                            <InputError class="mt-2" :message="createForm.errors.company_id" />
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">Vendor Type</label>
                            <select
                                v-model="createForm.vendor_type"
                                class="block w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 transition-all focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20"
                            >
                                <option value="">Select type...</option>
                                <option v-for="option in vendorTypeOptions" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>
                            <InputError class="mt-2" :message="createForm.errors.vendor_type" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">Temporary Password</label>
                            <input
                                v-model="createForm.password"
                                type="password"
                                required
                                autocomplete="new-password"
                                placeholder="At least 8 characters"
                                class="block w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 transition-all focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20"
                            />
                            <InputError class="mt-2" :message="createForm.errors.password" />
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">Confirm Password</label>
                            <input
                                v-model="createForm.password_confirmation"
                                type="password"
                                required
                                autocomplete="new-password"
                                placeholder="Repeat password"
                                class="block w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 transition-all focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20"
                            />
                        </div>
                    </div>

                    <p class="rounded-xl border border-amber-100 bg-amber-50 px-4 py-3 text-xs font-medium text-amber-800">
                        The account will be created with pending status. Activate it from the vendor details page after review.
                    </p>

                    <div class="flex justify-end space-x-3 border-t border-slate-100 pt-6">
                        <button
                            type="button"
                            class="rounded-xl bg-slate-100 px-6 py-2.5 font-bold text-slate-600 transition-colors hover:bg-slate-200"
                            @click="closeCreateModal"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="createForm.processing"
                            class="rounded-xl bg-emerald-600 px-6 py-2.5 font-bold text-white shadow-lg shadow-emerald-600/20 transition-all hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {{ createForm.processing ? 'Registering...' : 'Register Vendor' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div v-if="showEditModal" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-4">
            <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm" @click="closeEditModal"></div>

            <div class="relative w-full max-w-2xl overflow-hidden rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-100 bg-slate-50/50 px-8 py-6">
                    <h3 class="text-xl font-bold text-slate-900">Edit Vendor</h3>
                    <p class="text-sm text-slate-500">Update account details for {{ editingVendor?.code }}.</p>
                </div>

                <form class="space-y-5 p-8" @submit.prevent="updateVendor">
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">Vendor Name</label>
                        <input
                            v-model="editForm.name"
                            type="text"
                            required
                            autofocus
                            class="block w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 transition-all focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                        />
                        <InputError class="mt-2" :message="editForm.errors.name" />
                    </div>

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">Email Address</label>
                            <input
                                v-model="editForm.email"
                                type="email"
                                required
                                class="block w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 transition-all focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                            />
                            <InputError class="mt-2" :message="editForm.errors.email" />
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">Phone</label>
                            <input
                                v-model="editForm.phone"
                                type="text"
                                class="block w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 transition-all focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                            />
                            <InputError class="mt-2" :message="editForm.errors.phone" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">Company</label>
                            <select
                                v-model="editForm.company_id"
                                class="block w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 transition-all focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                            >
                                <option value="">Not assigned</option>
                                <option v-for="company in companies" :key="company.id" :value="company.id">
                                    {{ company.name }}
                                </option>
                            </select>
                            <InputError class="mt-2" :message="editForm.errors.company_id" />
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">Vendor Type</label>
                            <select
                                v-model="editForm.vendor_type"
                                class="block w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 transition-all focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                            >
                                <option value="">Select type...</option>
                                <option v-for="option in vendorTypeOptions" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>
                            <InputError class="mt-2" :message="editForm.errors.vendor_type" />
                        </div>
                    </div>

                    <p class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-xs font-medium text-slate-600">
                        Vendor code, password, and account status are managed separately and are not changed here.
                    </p>

                    <div class="flex justify-end space-x-3 border-t border-slate-100 pt-6">
                        <button
                            type="button"
                            class="rounded-xl bg-slate-100 px-6 py-2.5 font-bold text-slate-600 transition-colors hover:bg-slate-200"
                            @click="closeEditModal"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="editForm.processing"
                            class="rounded-xl bg-blue-600 px-6 py-2.5 font-bold text-white shadow-lg shadow-blue-600/20 transition-all hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {{ editForm.processing ? 'Saving...' : 'Save Changes' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
