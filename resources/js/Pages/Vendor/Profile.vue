<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import VendorLayout from '@/Layouts/VendorLayout.vue';
import StatusBadge from '@/Components/Portal/StatusBadge.vue';
import InputError from '@/Components/InputError.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { useErrorHandler } from '@/Composables/useErrorHandler';
import { BanknotesIcon, KeyIcon, PlusIcon, TrashIcon, UserGroupIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    vendor: Object,
    paymentTermsOptions: Array,
    currencyOptions: Array,
    vendorTypeOptions: Array,
});

const { confirm } = useConfirm();
const { destroy } = useErrorHandler();

const profile = props.vendor.profile || {};
const pending = profile.pending_changes || {};
const initial = (field) => pending[field] ?? profile[field] ?? '';

const profileForm = useForm({
    legal_name: initial('legal_name'),
    trade_name: initial('trade_name'),
    tin: initial('tin'),
    rdo_code: initial('rdo_code'),
    business_type: initial('business_type'),
    vat_type: initial('vat_type'),
    address: initial('address'),
    city: initial('city'),
    province: initial('province'),
    zip_code: initial('zip_code'),
    country: initial('country') || 'Philippines',
    website: initial('website'),
    payment_terms: initial('payment_terms'),
    currency: initial('currency') || 'PHP',
});

const contactForm = useForm({ name: '', position: '', email: '', phone: '', is_primary: false });
const bankForm = useForm({ bank_name: '', branch: '', account_name: '', account_number: '', currency: 'PHP', is_default: false });
const passwordForm = useForm({ current_password: '', password: '', password_confirmation: '' });

const showContactModal = ref(false);
const showBankModal = ref(false);

const submitProfile = () => profileForm.put(route('vendor.profile.update'), { preserveScroll: true });

const submitContact = () => {
    contactForm.post(route('vendor.profile.contacts.store'), {
        preserveScroll: true,
        onSuccess: () => { showContactModal.value = false; contactForm.reset(); },
    });
};

const submitBank = () => {
    bankForm.post(route('vendor.bank-accounts.store'), {
        preserveScroll: true,
        onSuccess: () => { showBankModal.value = false; bankForm.reset(); },
    });
};

const submitPassword = () => {
    passwordForm.put(route('vendor.profile.password'), {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
    });
};

const removeContact = async (contact) => {
    const ok = await confirm({ title: 'Remove Contact', message: `Remove contact "${contact.name}"?` });
    if (ok) destroy(route('vendor.profile.contacts.destroy', contact.id), {});
};

const removeBank = async (account) => {
    const ok = await confirm({ title: 'Remove Bank Account', message: `Remove ${account.bank_name} account ending in ${account.account_number.slice(-4)}?` });
    if (ok) destroy(route('vendor.bank-accounts.destroy', account.id), {});
};

const inputClass = 'block w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all';
const businessTypes = ['Sole Proprietorship', 'Partnership', 'Corporation', 'Cooperative'];
const vatTypes = [
    { value: 'vat_registered', label: 'VAT Registered' },
    { value: 'non_vat', label: 'Non-VAT' },
    { value: 'vat_exempt', label: 'VAT Exempt' },
];
</script>

<template>
    <Head title="Company Profile - Link Portal" />

    <VendorLayout>
        <template #header>
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <h2 class="text-2xl font-black tracking-tight text-slate-900">Company Profile</h2>
                    <p class="mt-1 text-sm text-slate-500">Profile changes are reviewed by an administrator before going live (maker-checker).</p>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">Profile status</span>
                    <StatusBadge :status="vendor.profile?.approval_status || 'draft'" />
                </div>
            </div>
        </template>

        <div v-if="vendor.profile?.approval_status === 'pending'" class="mb-6 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-3.5 text-sm font-semibold text-amber-800">
            You have profile changes awaiting administrator approval. Submitting again will replace the pending changes.
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            <!-- Profile form -->
            <div class="xl:col-span-2">
                <form class="rounded-3xl border border-slate-200 bg-white p-7 shadow-sm" @submit.prevent="submitProfile">
                    <h3 class="mb-5 text-xs font-black uppercase tracking-widest text-slate-500">Legal & Business Details</h3>

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">Legal Name</label>
                            <input v-model="profileForm.legal_name" type="text" :class="inputClass" placeholder="Registered legal name" />
                            <InputError class="mt-1" :message="profileForm.errors.legal_name" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">Trade Name</label>
                            <input v-model="profileForm.trade_name" type="text" :class="inputClass" placeholder="Doing business as..." />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">TIN</label>
                            <input v-model="profileForm.tin" type="text" :class="inputClass" placeholder="000-000-000-000" />
                            <InputError class="mt-1" :message="profileForm.errors.tin" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">RDO Code</label>
                            <input v-model="profileForm.rdo_code" type="text" :class="inputClass" placeholder="Ex. 049" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">Business Type</label>
                            <select v-model="profileForm.business_type" :class="inputClass">
                                <option value="">Select...</option>
                                <option v-for="t in businessTypes" :key="t" :value="t">{{ t }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">VAT Type</label>
                            <select v-model="profileForm.vat_type" :class="inputClass">
                                <option value="">Select...</option>
                                <option v-for="t in vatTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-sm font-bold text-slate-700">Address</label>
                            <textarea v-model="profileForm.address" rows="2" :class="inputClass" placeholder="Street, building, barangay..."></textarea>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">City</label>
                            <input v-model="profileForm.city" type="text" :class="inputClass" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">Province</label>
                            <input v-model="profileForm.province" type="text" :class="inputClass" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">ZIP Code</label>
                            <input v-model="profileForm.zip_code" type="text" :class="inputClass" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">Country</label>
                            <input v-model="profileForm.country" type="text" :class="inputClass" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">Website</label>
                            <input v-model="profileForm.website" type="text" :class="inputClass" placeholder="https://..." />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">Default Payment Terms</label>
                            <select v-model="profileForm.payment_terms" :class="inputClass">
                                <option value="">Select...</option>
                                <option v-for="t in paymentTermsOptions" :key="t.value" :value="t.value">{{ t.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-bold text-slate-700">Currency</label>
                            <select v-model="profileForm.currency" :class="inputClass">
                                <option v-for="c in currencyOptions" :key="c.value" :value="c.value">{{ c.label }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="mt-7 flex justify-end border-t border-slate-100 pt-5">
                        <button type="submit" :disabled="profileForm.processing"
                            class="rounded-xl bg-emerald-600 px-6 py-2.5 text-sm font-bold text-white shadow-lg shadow-emerald-600/20 transition-all hover:bg-emerald-700 disabled:opacity-50">
                            {{ profileForm.processing ? 'Submitting...' : 'Submit Changes for Approval' }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- Right column: contacts, banks, password -->
            <div class="space-y-6">
                <!-- Contacts -->
                <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="mb-4 flex items-center justify-between border-b border-slate-100 pb-3">
                        <h3 class="flex items-center gap-2 text-xs font-black uppercase tracking-widest text-slate-500">
                            <UserGroupIcon class="h-4 w-4" /> Contacts
                        </h3>
                        <button @click="showContactModal = true" class="rounded-lg p-1.5 text-emerald-600 transition hover:bg-emerald-50">
                            <PlusIcon class="h-4.5 w-4.5" />
                        </button>
                    </div>
                    <p v-if="!vendor.contacts?.length" class="py-3 text-center text-xs font-medium text-slate-400">No contacts added.</p>
                    <ul v-else class="space-y-2.5">
                        <li v-for="c in vendor.contacts" :key="c.id" class="flex items-start justify-between rounded-xl bg-slate-50 px-3.5 py-2.5">
                            <div class="min-w-0">
                                <p class="text-sm font-bold text-slate-800">
                                    {{ c.name }}
                                    <span v-if="c.is_primary" class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-[9px] font-black uppercase text-emerald-700">Primary</span>
                                </p>
                                <p class="truncate text-xs text-slate-500">{{ c.position || '' }} {{ c.email ? '· ' + c.email : '' }}</p>
                            </div>
                            <button @click="removeContact(c)" class="ml-2 shrink-0 rounded-lg p-1.5 text-slate-300 transition hover:bg-red-50 hover:text-red-500">
                                <TrashIcon class="h-4 w-4" />
                            </button>
                        </li>
                    </ul>
                </div>

                <!-- Bank accounts -->
                <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="mb-4 flex items-center justify-between border-b border-slate-100 pb-3">
                        <h3 class="flex items-center gap-2 text-xs font-black uppercase tracking-widest text-slate-500">
                            <BanknotesIcon class="h-4 w-4" /> Bank Accounts
                        </h3>
                        <button @click="showBankModal = true" class="rounded-lg p-1.5 text-emerald-600 transition hover:bg-emerald-50">
                            <PlusIcon class="h-4.5 w-4.5" />
                        </button>
                    </div>
                    <p class="mb-3 text-[11px] leading-4 text-slate-400">Bank details require verification before payments can be released against them.</p>
                    <p v-if="!vendor.bank_accounts?.length" class="py-3 text-center text-xs font-medium text-slate-400">No bank accounts added.</p>
                    <ul v-else class="space-y-2.5">
                        <li v-for="b in vendor.bank_accounts" :key="b.id" class="rounded-xl bg-slate-50 px-3.5 py-2.5">
                            <div class="flex items-start justify-between">
                                <div class="min-w-0">
                                    <p class="text-sm font-bold text-slate-800">{{ b.bank_name }}</p>
                                    <p class="text-xs text-slate-500">{{ b.account_name }} · ····{{ b.account_number.slice(-4) }} · {{ b.currency }}</p>
                                </div>
                                <button @click="removeBank(b)" class="ml-2 shrink-0 rounded-lg p-1.5 text-slate-300 transition hover:bg-red-50 hover:text-red-500">
                                    <TrashIcon class="h-4 w-4" />
                                </button>
                            </div>
                            <div class="mt-1.5"><StatusBadge :status="b.approval_status" /></div>
                        </li>
                    </ul>
                </div>

                <!-- Password -->
                <form class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" @submit.prevent="submitPassword">
                    <h3 class="mb-4 flex items-center gap-2 border-b border-slate-100 pb-3 text-xs font-black uppercase tracking-widest text-slate-500">
                        <KeyIcon class="h-4 w-4" /> Change Password
                    </h3>
                    <div class="space-y-3.5">
                        <div>
                            <input v-model="passwordForm.current_password" type="password" placeholder="Current password" required :class="inputClass" />
                            <InputError class="mt-1" :message="passwordForm.errors.current_password" />
                        </div>
                        <div>
                            <input v-model="passwordForm.password" type="password" placeholder="New password" required :class="inputClass" />
                            <InputError class="mt-1" :message="passwordForm.errors.password" />
                        </div>
                        <input v-model="passwordForm.password_confirmation" type="password" placeholder="Confirm new password" required :class="inputClass" />
                        <button type="submit" :disabled="passwordForm.processing"
                            class="w-full rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:opacity-50">
                            Update Password
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Contact modal -->
        <div v-if="showContactModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm" @click="showContactModal = false"></div>
            <div class="relative w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-100 bg-slate-50/50 px-8 py-5">
                    <h3 class="text-lg font-bold text-slate-900">Add Contact Person</h3>
                </div>
                <form class="space-y-4 p-8" @submit.prevent="submitContact">
                    <input v-model="contactForm.name" type="text" required placeholder="Full name" :class="inputClass" />
                    <input v-model="contactForm.position" type="text" placeholder="Position" :class="inputClass" />
                    <input v-model="contactForm.email" type="email" placeholder="Email" :class="inputClass" />
                    <input v-model="contactForm.phone" type="text" placeholder="Phone" :class="inputClass" />
                    <label class="flex items-center gap-2.5">
                        <input v-model="contactForm.is_primary" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" />
                        <span class="text-sm font-semibold text-slate-600">Primary contact</span>
                    </label>
                    <div class="flex justify-end gap-3 border-t border-slate-100 pt-5">
                        <button type="button" @click="showContactModal = false" class="rounded-xl bg-slate-100 px-5 py-2.5 font-bold text-slate-600 hover:bg-slate-200">Cancel</button>
                        <button type="submit" :disabled="contactForm.processing" class="rounded-xl bg-emerald-600 px-5 py-2.5 font-bold text-white hover:bg-emerald-700 disabled:opacity-50">Save</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Bank modal -->
        <div v-if="showBankModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm" @click="showBankModal = false"></div>
            <div class="relative w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl">
                <div class="border-b border-slate-100 bg-slate-50/50 px-8 py-5">
                    <h3 class="text-lg font-bold text-slate-900">Add Bank Account</h3>
                    <p class="text-xs text-slate-500">Submitted details go through verification before use.</p>
                </div>
                <form class="space-y-4 p-8" @submit.prevent="submitBank">
                    <div>
                        <input v-model="bankForm.bank_name" type="text" required placeholder="Bank name" :class="inputClass" />
                        <InputError class="mt-1" :message="bankForm.errors.bank_name" />
                    </div>
                    <input v-model="bankForm.branch" type="text" placeholder="Branch" :class="inputClass" />
                    <div>
                        <input v-model="bankForm.account_name" type="text" required placeholder="Account name" :class="inputClass" />
                        <InputError class="mt-1" :message="bankForm.errors.account_name" />
                    </div>
                    <div>
                        <input v-model="bankForm.account_number" type="text" required placeholder="Account number" :class="inputClass" />
                        <InputError class="mt-1" :message="bankForm.errors.account_number" />
                    </div>
                    <select v-model="bankForm.currency" :class="inputClass">
                        <option v-for="c in currencyOptions" :key="c.value" :value="c.value">{{ c.label }}</option>
                    </select>
                    <label class="flex items-center gap-2.5">
                        <input v-model="bankForm.is_default" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" />
                        <span class="text-sm font-semibold text-slate-600">Default account</span>
                    </label>
                    <div class="flex justify-end gap-3 border-t border-slate-100 pt-5">
                        <button type="button" @click="showBankModal = false" class="rounded-xl bg-slate-100 px-5 py-2.5 font-bold text-slate-600 hover:bg-slate-200">Cancel</button>
                        <button type="submit" :disabled="bankForm.processing" class="rounded-xl bg-emerald-600 px-5 py-2.5 font-bold text-white hover:bg-emerald-700 disabled:opacity-50">Submit for Verification</button>
                    </div>
                </form>
            </div>
        </div>
    </VendorLayout>
</template>
