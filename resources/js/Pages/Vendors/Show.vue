<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import StatusBadge from '@/Components/Portal/StatusBadge.vue';
import { usePermission } from '@/Composables/usePermission';
import {
    ArrowLeftIcon,
    BanknotesIcon,
    BuildingStorefrontIcon,
    CheckIcon,
    DocumentTextIcon,
    UserGroupIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    vendor: Object,
});

const { hasPermission } = usePermission();
const canApprove = hasPermission('vendors.approve');
const canApproveDocs = hasPermission('vendor-documents.approve');

const statusForm = useForm({ status: '', remarks: '' });
const profileForm = useForm({ action: '', remarks: '' });
const docForm = useForm({ action: '', remarks: '' });
const bankForm = useForm({ action: '', remarks: '' });

const reviewRemarks = ref('');

const setStatus = (status) => {
    statusForm.status = status;
    statusForm.put(route('vendors.status', props.vendor.id), { preserveScroll: true });
};

const reviewProfile = (action) => {
    profileForm.action = action;
    profileForm.remarks = reviewRemarks.value;
    profileForm.put(route('vendors.profile-review', props.vendor.id), {
        preserveScroll: true,
        onSuccess: () => (reviewRemarks.value = ''),
    });
};

const reviewDocument = (doc, action) => {
    docForm.action = action;
    docForm.put(route('vendor-documents.review', doc.id), { preserveScroll: true });
};

const reviewBank = (account, action) => {
    bankForm.action = action;
    bankForm.put(route('vendor-bank-accounts.review', account.id), { preserveScroll: true });
};

const formatDate = (value) => (value ? new Date(value).toLocaleDateString() : '—');
const pendingChanges = props.vendor.profile?.pending_changes || null;

const profileFields = [
    ['legal_name', 'Legal Name'], ['trade_name', 'Trade Name'], ['tin', 'TIN'], ['rdo_code', 'RDO'],
    ['business_type', 'Business Type'], ['vat_type', 'VAT Type'], ['address', 'Address'], ['city', 'City'],
    ['province', 'Province'], ['zip_code', 'ZIP'], ['country', 'Country'], ['website', 'Website'],
    ['payment_terms', 'Payment Terms'], ['currency', 'Currency'],
];
</script>

<template>
    <Head :title="`${vendor.name} - Link Portal`" />

    <AppLayout>
        <template #header>
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div class="flex items-center gap-4">
                    <Link :href="route('vendors.index')" class="rounded-xl border border-slate-200 bg-white p-2.5 text-slate-500 transition hover:text-emerald-600">
                        <ArrowLeftIcon class="h-5 w-5" />
                    </Link>
                    <div class="flex items-center gap-3">
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl border border-emerald-100 bg-emerald-50 text-emerald-600">
                            <BuildingStorefrontIcon class="h-6 w-6" />
                        </span>
                        <div>
                            <h2 class="text-2xl font-bold leading-tight text-slate-800">{{ vendor.name }}</h2>
                            <p class="text-sm text-slate-500">{{ vendor.code }} · {{ vendor.email }} · <StatusBadge :status="vendor.status" /></p>
                        </div>
                    </div>
                </div>

                <!-- Account status actions -->
                <div v-if="canApprove" class="flex gap-2">
                    <button v-if="vendor.status !== 'active'" :disabled="statusForm.processing" @click="setStatus('active')"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-700 disabled:opacity-50">
                        <CheckIcon class="h-4.5 w-4.5" /> Activate Account
                    </button>
                    <button v-if="vendor.status === 'active'" :disabled="statusForm.processing" @click="setStatus('suspended')"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-red-200 bg-white px-4 py-2.5 text-sm font-bold text-red-600 transition hover:bg-red-50 disabled:opacity-50">
                        <XMarkIcon class="h-4.5 w-4.5" /> Suspend
                    </button>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <!-- Pending profile changes (maker-checker) -->
                <div v-if="pendingChanges && vendor.profile?.approval_status === 'pending'" class="rounded-3xl border border-amber-200 bg-amber-50/60 p-6">
                    <h3 class="text-sm font-black text-amber-950">Pending Profile Changes</h3>
                    <p class="mt-1 text-xs font-medium text-amber-800">The vendor submitted the following changes. Approving replaces the live profile values.</p>

                    <div class="mt-4 overflow-x-auto rounded-xl border border-amber-200 bg-white">
                        <table class="min-w-full divide-y divide-amber-100 text-sm">
                            <thead>
                                <tr class="bg-amber-50">
                                    <th class="px-4 py-2.5 text-left text-[10px] font-black uppercase tracking-widest text-amber-800">Field</th>
                                    <th class="px-4 py-2.5 text-left text-[10px] font-black uppercase tracking-widest text-amber-800">Current</th>
                                    <th class="px-4 py-2.5 text-left text-[10px] font-black uppercase tracking-widest text-amber-800">Proposed</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-amber-50">
                                <template v-for="[field, label] in profileFields" :key="field">
                                    <tr v-if="(pendingChanges[field] ?? '') !== (vendor.profile[field] ?? '')">
                                        <td class="px-4 py-2.5 font-bold text-slate-700">{{ label }}</td>
                                        <td class="px-4 py-2.5 text-slate-500">{{ vendor.profile[field] || '—' }}</td>
                                        <td class="px-4 py-2.5 font-semibold text-emerald-700">{{ pendingChanges[field] || '—' }}</td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="canApprove" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center">
                        <input v-model="reviewRemarks" type="text" placeholder="Review remarks (optional)"
                            class="flex-1 rounded-xl border border-amber-200 bg-white px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20" />
                        <div class="flex gap-2">
                            <button :disabled="profileForm.processing" @click="reviewProfile('approved')"
                                class="rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-emerald-700 disabled:opacity-50">Approve</button>
                            <button :disabled="profileForm.processing" @click="reviewProfile('rejected')"
                                class="rounded-xl border border-red-200 bg-white px-5 py-2.5 text-sm font-bold text-red-600 transition hover:bg-red-50 disabled:opacity-50">Reject</button>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
                    <!-- Profile -->
                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm xl:col-span-2">
                        <div class="mb-4 flex items-center justify-between border-b border-slate-100 pb-3">
                            <h3 class="text-xs font-black uppercase tracking-widest text-slate-500">Company Profile</h3>
                            <StatusBadge :status="vendor.profile?.approval_status || 'draft'" />
                        </div>
                        <dl class="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-3">
                            <div v-for="[field, label] in profileFields" :key="field">
                                <dt class="text-[10px] font-black uppercase tracking-widest text-slate-400">{{ label }}</dt>
                                <dd class="mt-0.5 text-sm font-bold text-slate-800">{{ vendor.profile?.[field] || '—' }}</dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Contacts + Banks -->
                    <div class="space-y-6">
                        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            <h3 class="mb-4 flex items-center gap-2 border-b border-slate-100 pb-3 text-xs font-black uppercase tracking-widest text-slate-500">
                                <UserGroupIcon class="h-4 w-4" /> Contacts
                            </h3>
                            <p v-if="!vendor.contacts?.length" class="py-2 text-center text-xs text-slate-400">No contacts.</p>
                            <ul v-else class="space-y-2">
                                <li v-for="c in vendor.contacts" :key="c.id" class="rounded-xl bg-slate-50 px-3.5 py-2.5">
                                    <p class="text-sm font-bold text-slate-800">{{ c.name }} <span v-if="c.is_primary" class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-[9px] font-black uppercase text-emerald-700">Primary</span></p>
                                    <p class="text-xs text-slate-500">{{ [c.position, c.email, c.phone].filter(Boolean).join(' · ') }}</p>
                                </li>
                            </ul>
                        </div>

                        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            <h3 class="mb-4 flex items-center gap-2 border-b border-slate-100 pb-3 text-xs font-black uppercase tracking-widest text-slate-500">
                                <BanknotesIcon class="h-4 w-4" /> Bank Accounts
                            </h3>
                            <p v-if="!vendor.bank_accounts?.length" class="py-2 text-center text-xs text-slate-400">No bank accounts.</p>
                            <ul v-else class="space-y-3">
                                <li v-for="b in vendor.bank_accounts" :key="b.id" class="rounded-xl bg-slate-50 px-3.5 py-3">
                                    <p class="text-sm font-bold text-slate-800">{{ b.bank_name }}</p>
                                    <p class="text-xs text-slate-500">{{ b.account_name }} · {{ b.account_number }} · {{ b.currency }}</p>
                                    <div class="mt-2 flex items-center justify-between">
                                        <StatusBadge :status="b.approval_status" />
                                        <div v-if="canApprove && b.approval_status === 'pending'" class="flex gap-1.5">
                                            <button :disabled="bankForm.processing" @click="reviewBank(b, 'approved')"
                                                class="rounded-lg bg-emerald-600 px-2.5 py-1.5 text-[10px] font-black uppercase text-white transition hover:bg-emerald-700">Verify</button>
                                            <button :disabled="bankForm.processing" @click="reviewBank(b, 'rejected')"
                                                class="rounded-lg border border-red-200 bg-white px-2.5 py-1.5 text-[10px] font-black uppercase text-red-600 transition hover:bg-red-50">Reject</button>
                                        </div>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Documents -->
                <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex items-center gap-2 border-b border-slate-100 bg-slate-50/60 px-6 py-4">
                        <DocumentTextIcon class="h-4 w-4 text-slate-400" />
                        <h3 class="text-xs font-black uppercase tracking-widest text-slate-600">Accreditation Documents</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-100">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-[10px] font-black uppercase tracking-widest text-slate-500">Document</th>
                                    <th class="px-6 py-3 text-left text-[10px] font-black uppercase tracking-widest text-slate-500">Type</th>
                                    <th class="px-6 py-3 text-left text-[10px] font-black uppercase tracking-widest text-slate-500">Issued</th>
                                    <th class="px-6 py-3 text-left text-[10px] font-black uppercase tracking-widest text-slate-500">Expiry</th>
                                    <th class="px-6 py-3 text-left text-[10px] font-black uppercase tracking-widest text-slate-500">Status</th>
                                    <th class="px-6 py-3 text-right text-[10px] font-black uppercase tracking-widest text-slate-500">Review</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <tr v-for="doc in vendor.documents" :key="doc.id">
                                    <td class="px-6 py-3.5">
                                        <a :href="`/storage/${doc.file_path}`" target="_blank" class="text-sm font-bold text-slate-900 hover:text-emerald-700">
                                            {{ doc.title }} <span v-if="doc.version > 1" class="text-xs font-semibold text-slate-400">v{{ doc.version }}</span>
                                        </a>
                                        <p class="text-xs text-slate-400">{{ doc.file_name }}</p>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-3.5 text-sm text-slate-600">{{ doc.document_type?.label || '—' }}</td>
                                    <td class="whitespace-nowrap px-6 py-3.5 text-sm text-slate-600">{{ formatDate(doc.issued_date) }}</td>
                                    <td class="whitespace-nowrap px-6 py-3.5 text-sm text-slate-600">{{ formatDate(doc.expiry_date) }}</td>
                                    <td class="whitespace-nowrap px-6 py-3.5">
                                        <StatusBadge :status="doc.status" />
                                        <p v-if="doc.reviewer" class="mt-1 text-[10px] text-slate-400">by {{ doc.reviewer.name }}</p>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-3.5 text-right">
                                        <div v-if="canApproveDocs && doc.status === 'pending'" class="flex justify-end gap-1.5">
                                            <button :disabled="docForm.processing" @click="reviewDocument(doc, 'approved')"
                                                class="rounded-lg bg-emerald-600 px-3 py-1.5 text-[10px] font-black uppercase text-white transition hover:bg-emerald-700">Approve</button>
                                            <button :disabled="docForm.processing" @click="reviewDocument(doc, 'rejected')"
                                                class="rounded-lg border border-red-200 bg-white px-3 py-1.5 text-[10px] font-black uppercase text-red-600 transition hover:bg-red-50">Reject</button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!vendor.documents?.length">
                                    <td colspan="6" class="px-6 py-10 text-center text-sm text-slate-400">No documents uploaded.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
