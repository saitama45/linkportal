<script setup>
/**
 * Vouchers tab — read-only, with one exception: Verify / Use Voucher.
 *
 * Batches are created, activated, printed, suspended, cancelled and voided in
 * the LINK HUB. A till accepts a voucher as payment; it does not administer the
 * campaign, so none of those buttons exist here.
 */
import { computed, nextTick, onUnmounted, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import Modal from '@/Components/Modal.vue';
import Autocomplete from '@/Components/Autocomplete.vue';
import { useToast } from '@/Composables/useToast.js';
import { CheckCircleIcon, XCircleIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    batches: { type: Array, default: () => [] },
    redemptions: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
    store: { type: Object, default: () => ({}) },
    summary: { type: Object, default: () => ({}) },
});

const { addToast } = useToast();

// Opened from the page header (Verify / Use Voucher) as well as from inside
// this panel, so it has to be reachable from the parent.
defineExpose({ openScan: () => openScan() });

const money = (value) => Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const date = (value) => (value ? new Date(`${String(value).slice(0, 10)}T00:00:00`).toLocaleDateString('en-PH') : '—');
const dateTime = (value) => (value ? new Date(value).toLocaleString('en-PH') : '—');

const statusBadgeClass = (status) => ({
    active: 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-600/25',
    not_yet_valid: 'bg-sky-50 text-sky-700 ring-1 ring-sky-600/25',
    expired: 'bg-slate-100 text-slate-600 ring-1 ring-slate-300/50',
    draft: 'bg-amber-50 text-amber-700 ring-1 ring-amber-600/25',
    suspended: 'bg-orange-50 text-orange-700 ring-1 ring-orange-600/25',
    cancelled: 'bg-rose-50 text-rose-700 ring-1 ring-rose-600/25',
}[status] || 'bg-slate-100 text-slate-700');

const statusDotClass = (status) => ({
    active: 'bg-emerald-500',
    not_yet_valid: 'bg-sky-500',
    expired: 'bg-slate-400',
    draft: 'bg-amber-500',
    suspended: 'bg-orange-500',
    cancelled: 'bg-rose-500',
}[status] || 'bg-slate-400');

const statusLabel = (status) => String(status || '').replaceAll('_', ' ').replace(/\b\w/g, (c) => c.toUpperCase());

const batchStatusHelp = (batch) => {
    if (batch.effective_status === 'not_yet_valid') return `Starts ${date(batch.claim_starts_on)}`;
    if (batch.effective_status === 'expired') return `Ended ${date(batch.claim_ends_on)}`;
    if (batch.effective_status === 'active') return `Valid until ${date(batch.claim_ends_on)}`;
    return '';
};

const todayLocal = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

const scan = reactive({ open: false, code: '', loading: false, result: null, error: '' });
const scanInput = ref(null);
const redeeming = ref(false);
const newCustomer = ref(false);
const redemption = reactive({
    customer_id: null,
    new_customer_name: '',
    new_customer_phone: '',
    new_customer_email: '',
    receipt_number: '',
    sale_date: todayLocal(),
    gross_sale_total: null,
});

let autoVerifyTimer = null;
onUnmounted(() => {
    if (autoVerifyTimer) window.clearTimeout(autoVerifyTimer);
});

const openScan = () => {
    Object.assign(scan, { open: true, code: '', loading: false, result: null, error: '' });
    Object.assign(redemption, {
        customer_id: null,
        new_customer_name: '',
        new_customer_phone: '',
        new_customer_email: '',
        receipt_number: '',
        sale_date: todayLocal(),
        gross_sale_total: null,
    });
    newCustomer.value = false;
    nextTick(() => scanInput.value?.focus());
};

const verify = async () => {
    if (!scan.code.trim() || scan.loading) return;
    scan.loading = true;
    scan.error = '';
    try {
        scan.result = (await axios.post(route('vendor.campaigns.vouchers.verify'), { code: scan.code })).data;
    } catch (error) {
        scan.error = error.response?.data?.message || 'Unable to verify the voucher.';
    } finally {
        scan.loading = false;
    }
};

const submitVoucherScan = () => {
    if (autoVerifyTimer) window.clearTimeout(autoVerifyTimer);
    verify();
};

watch(() => scan.code, (value) => {
    if (autoVerifyTimer) window.clearTimeout(autoVerifyTimer);
    const code = String(value || '').trim();
    if (!scan.open || scan.loading || scan.result || !/^VCH-[2-9A-HJ-NP-Z]{4}(?:-[2-9A-HJ-NP-Z]{4}){3}$/i.test(code)) return;
    autoVerifyTimer = window.setTimeout(() => {
        if (scan.code.trim() === code && !scan.result) verify();
    }, 150);
});

const resetScan = () => {
    if (autoVerifyTimer) window.clearTimeout(autoVerifyTimer);
    scan.code = '';
    scan.result = null;
    scan.error = '';
    nextTick(() => scanInput.value?.focus());
};

const applyPayment = async () => {
    redeeming.value = true;
    try {
        await axios.post(route('vendor.campaigns.vouchers.redeem'), {
            code: scan.result.voucher.code,
            ...redemption,
            customer_id: newCustomer.value ? null : redemption.customer_id,
            new_customer_name: newCustomer.value ? redemption.new_customer_name : null,
            new_customer_phone: newCustomer.value ? redemption.new_customer_phone : null,
            new_customer_email: newCustomer.value ? redemption.new_customer_email : null,
        });
        addToast('Voucher applied as payment and marked Used.', 'success');
        scan.open = false;
        router.reload({ only: ['customers', 'voucherBatches', 'voucherRedemptions', 'voucherSummary'] });
    } catch (error) {
        const errors = error.response?.data?.errors;
        scan.error = errors ? Object.values(errors).flat()[0] : 'Unable to apply the voucher.';
    } finally {
        redeeming.value = false;
    }
};

const customerOptions = computed(() => props.customers
    .filter((c) => c.is_active)
    .map((c) => ({ value: c.id, label: `${c.name}${c.phone ? ` (${c.phone})` : ''}` })));

const cashierName = (row) => row.cashier?.name || row.cashier_vendor?.name || '—';
</script>

<template>
    <div class="space-y-6">
        <!-- No stat row here: the page's own row switches to the voucher
             figures on this tab, so repeating them would be the same five
             numbers twice on one screen. -->

        <!-- Voucher Batches Table Card -->
        <div class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm">
            <div class="border-b border-slate-100 bg-white px-5 py-4 sm:px-6">
                <h3 class="text-base font-black tracking-tight text-slate-900">Campaign Voucher Batches</h3>
                <p class="mt-0.5 text-xs font-medium text-slate-500">
                    Active and expired batches — what you can accept today, and what a customer may still walk in holding.
                    Batches are set up and printed in the LINK HUB.
                </p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-sm">
                    <thead class="bg-slate-50/80 text-left text-xs font-bold uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-5 py-3.5">Batch</th>
                            <th class="px-5 py-3.5">Value / Count</th>
                            <th class="px-5 py-3.5">Claim Period</th>
                            <th class="px-5 py-3.5">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-slate-700">
                        <tr v-for="batch in batches" :key="batch.id" class="transition-colors hover:bg-slate-50/60">
                            <td class="px-5 py-4">
                                <p class="font-bold text-slate-900">{{ batch.title }}</p>
                                <p class="mt-0.5 text-xs font-medium text-slate-500">{{ batch.partner_name }}</p>
                            </td>
                            <td class="px-5 py-4">
                                <p class="font-bold text-slate-900">₱{{ money(batch.face_value) }}</p>
                                <p class="mt-0.5 text-xs font-medium text-slate-500">{{ batch.used_count }} used / {{ batch.vouchers_count }} generated</p>
                            </td>
                            <td class="px-5 py-4 text-xs font-semibold text-slate-600">
                                {{ batch.claim_starts_on && batch.claim_ends_on ? `${date(batch.claim_starts_on)} – ${date(batch.claim_ends_on)}` : 'To follow' }}
                            </td>
                            <td class="px-5 py-4">
                                <span :class="['inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-bold', statusBadgeClass(batch.effective_status)]">
                                    <span class="h-1.5 w-1.5 rounded-full" :class="statusDotClass(batch.effective_status)"></span>
                                    {{ statusLabel(batch.effective_status) }}
                                </span>
                                <p v-if="batchStatusHelp(batch)" class="mt-1 text-[11px] font-medium text-slate-400">{{ batchStatusHelp(batch) }}</p>
                            </td>
                        </tr>
                        <tr v-if="!batches.length">
                            <td colspan="4" class="px-4 py-12 text-center text-xs font-medium text-slate-400">No active or expired voucher batches.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recent Voucher Payments Table Card -->
        <div class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm">
            <div class="border-b border-slate-100 bg-white px-5 py-4 sm:px-6">
                <h3 class="text-base font-black tracking-tight text-slate-900">Recent Voucher Payments</h3>
                <p class="mt-0.5 text-xs font-medium text-slate-500">Latest 100 redemption and reversal records for this entity.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-sm">
                    <thead class="bg-slate-50/80 text-left text-xs font-bold uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-5 py-3.5">Voucher</th>
                            <th class="px-5 py-3.5">Customer</th>
                            <th class="px-5 py-3.5">Sale</th>
                            <th class="px-5 py-3.5">Applied</th>
                            <th class="px-5 py-3.5">Cashier</th>
                            <th class="px-5 py-3.5">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-slate-700">
                        <tr v-for="row in redemptions" :key="row.id" class="transition-colors hover:bg-slate-50/60">
                            <td class="px-5 py-4">
                                <span class="font-mono text-xs font-black text-slate-900">{{ row.voucher?.code }}</span>
                                <p class="mt-0.5 text-xs font-medium text-slate-500">{{ row.voucher?.batch?.title }}</p>
                            </td>
                            <td class="px-5 py-4">
                                <p class="font-bold text-slate-900">{{ row.customer?.name || '—' }}</p>
                                <p class="mt-0.5 text-xs font-medium text-slate-500">{{ row.customer?.phone || '' }}</p>
                            </td>
                            <td class="px-5 py-4">
                                <p class="text-xs font-bold text-slate-800">{{ row.store?.code }} / {{ row.receipt_number }}</p>
                                <p class="mt-0.5 text-xs font-medium text-slate-500">{{ date(row.sale_date) }} · Total ₱{{ money(row.gross_sale_total) }}</p>
                            </td>
                            <td class="px-5 py-4">
                                <p class="font-black text-slate-900">₱{{ money(row.applied_amount) }}</p>
                                <p v-if="Number(row.forfeited_amount)" class="mt-0.5 text-xs font-semibold text-amber-600">₱{{ money(row.forfeited_amount) }} forfeited</p>
                            </td>
                            <td class="px-5 py-4">
                                <p class="font-semibold text-slate-800">{{ cashierName(row) }}</p>
                                <p class="mt-0.5 text-xs font-medium text-slate-500">{{ dateTime(row.redeemed_at) }}</p>
                            </td>
                            <td class="px-5 py-4">
                                <span
                                    v-if="row.voided_at"
                                    class="inline-flex items-center gap-1.5 rounded-full bg-rose-50 px-2.5 py-0.5 text-xs font-bold text-rose-700 ring-1 ring-rose-600/25"
                                >
                                    <span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span>
                                    Voided
                                </span>
                                <span
                                    v-else
                                    class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-bold text-emerald-700 ring-1 ring-emerald-600/25"
                                >
                                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                    Used
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!redemptions.length">
                            <td colspan="6" class="px-4 py-12 text-center text-xs font-medium text-slate-400">No voucher payments recorded.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Verify / Use Voucher Modal -->
        <Modal :show="scan.open" max-width="2xl" @close="scan.open = false">
            <div class="p-6 sm:p-7">
                <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                    <div>
                        <h3 class="text-lg font-black tracking-tight text-slate-900">Verify / Use Voucher</h3>
                        <p class="mt-0.5 text-xs font-medium text-slate-500">Scanning verifies first. The voucher is used only after payment confirmation.</p>
                    </div>
                    <button v-if="scan.result" type="button" class="text-xs font-black uppercase tracking-wider text-emerald-600 hover:text-emerald-700" @click="resetScan">
                        Scan another
                    </button>
                </div>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div>
                        <span class="block text-xs font-bold uppercase tracking-wider text-slate-500">Current store</span>
                        <div class="mt-1.5 flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm font-bold text-slate-800">
                            <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                            <span>{{ store.code }}<span v-if="store.name"> — {{ store.name }}</span></span>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-500">Voucher code</label>
                        <input
                            ref="scanInput"
                            v-model="scan.code"
                            :disabled="!!scan.result"
                            autocomplete="off"
                            class="mt-1.5 w-full rounded-xl border border-slate-200 bg-slate-50/70 px-3.5 py-2.5 font-mono text-sm font-bold uppercase text-slate-900 placeholder:text-slate-400 outline-none transition focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100 disabled:opacity-60"
                            placeholder="Scan barcode or enter code"
                            @keydown.enter.prevent="submitVoucherScan"
                        />
                        <span class="mt-1 block text-[11px] font-medium text-slate-400">A complete barcode scan verifies automatically.</span>
                    </div>
                </div>

                <button
                    v-if="!scan.result"
                    type="button"
                    :disabled="scan.loading || !scan.code"
                    class="mt-4 flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 py-3 text-sm font-black text-white shadow-md shadow-emerald-600/20 transition hover:from-emerald-500 hover:to-teal-500 hover:shadow-lg hover:shadow-emerald-600/30 disabled:cursor-not-allowed disabled:opacity-50"
                    @click="verify"
                >
                    {{ scan.loading ? 'Verifying voucher…' : 'Verify Voucher' }}
                </button>

                <div v-if="scan.error" class="mt-4 flex items-start gap-2.5 rounded-xl border border-rose-200 bg-rose-50 p-3.5 text-xs font-semibold text-rose-800">
                    <XCircleIcon class="h-4 w-4 flex-shrink-0 text-rose-600 mt-0.5" />
                    <span>{{ scan.error }}</span>
                </div>

                <div v-if="scan.result" class="mt-5 rounded-2xl border p-5 shadow-sm" :class="scan.result.result === 'active' ? 'border-emerald-200 bg-emerald-50/50' : 'border-rose-200 bg-rose-50/50'">
                    <div class="flex items-center gap-2">
                        <component :is="scan.result.result === 'active' ? CheckCircleIcon : XCircleIcon" class="h-5 w-5 flex-shrink-0" :class="scan.result.result === 'active' ? 'text-emerald-600' : 'text-rose-600'" />
                        <p class="font-black text-sm" :class="scan.result.result === 'active' ? 'text-emerald-900' : 'text-rose-900'">{{ scan.result.message }}</p>
                    </div>
                    <template v-if="scan.result.voucher">
                        <div class="mt-3 rounded-xl bg-white p-3.5 border border-slate-200/80">
                            <p class="font-mono text-sm font-black text-slate-900">{{ scan.result.voucher.code }}</p>
                            <p class="mt-0.5 text-xs font-bold text-emerald-700">{{ scan.result.voucher.batch.title }} · ₱{{ money(scan.result.voucher.value) }}</p>
                        </div>
                    </template>
                    <div v-if="scan.result.voucher?.redemption" class="mt-3 rounded-xl bg-white p-3.5 border border-slate-200/80 text-xs text-slate-700 space-y-1">
                        <p><strong class="font-bold text-slate-900">Customer:</strong> {{ scan.result.voucher.redemption.customer?.name }}</p>
                        <p><strong class="font-bold text-slate-900">Store / receipt:</strong> {{ scan.result.voucher.redemption.store?.code }} / {{ scan.result.voucher.redemption.receipt_number }}</p>
                        <p><strong class="font-bold text-slate-900">Used:</strong> {{ dateTime(scan.result.voucher.redemption.redeemed_at) }}</p>
                        <p><strong class="font-bold text-slate-900">Processed by cashier:</strong> {{ cashierName(scan.result.voucher.redemption) }}</p>
                    </div>
                </div>

                <div v-if="scan.result?.result === 'active'" class="mt-5 space-y-4 border-t border-slate-100 pt-5">
                    <div class="flex gap-4 text-xs font-bold text-slate-700">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input v-model="newCustomer" :value="false" type="radio" class="text-emerald-600 focus:ring-emerald-500" />
                            <span>Existing customer</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input v-model="newCustomer" :value="true" type="radio" class="text-emerald-600 focus:ring-emerald-500" />
                            <span>New customer</span>
                        </label>
                    </div>

                    <Autocomplete v-if="!newCustomer" v-model="redemption.customer_id" :options="customerOptions" placeholder="Select customer" />

                    <div v-else class="grid gap-3 sm:grid-cols-3">
                        <input v-model="redemption.new_customer_name" class="rounded-xl border border-slate-200 bg-slate-50/70 px-3 py-2 text-xs font-medium text-slate-900 outline-none focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100" placeholder="Customer name *" />
                        <input v-model="redemption.new_customer_phone" class="rounded-xl border border-slate-200 bg-slate-50/70 px-3 py-2 text-xs font-medium text-slate-900 outline-none focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100" placeholder="Mobile number *" />
                        <input v-model="redemption.new_customer_email" type="email" class="rounded-xl border border-slate-200 bg-slate-50/70 px-3 py-2 text-xs font-medium text-slate-900 outline-none focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100" placeholder="Email (optional)" />
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div>
                            <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-500">POS receipt</label>
                            <input v-model="redemption.receipt_number" class="mt-1 w-full rounded-xl border border-slate-200 bg-slate-50/70 px-3 py-2 text-xs font-medium text-slate-900 outline-none focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100" />
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-500">Sale date</label>
                            <input v-model="redemption.sale_date" type="date" class="mt-1 w-full rounded-xl border border-slate-200 bg-slate-50/70 px-3 py-2 text-xs font-medium text-slate-900 outline-none focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100" />
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-500">Gross sale total</label>
                            <input v-model.number="redemption.gross_sale_total" type="number" min="0.01" step="0.01" class="mt-1 w-full rounded-xl border border-slate-200 bg-slate-50/70 px-3 py-2 text-xs font-medium text-slate-900 outline-none focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100" />
                        </div>
                    </div>

                    <button
                        type="button"
                        :disabled="redeeming"
                        class="w-full rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 py-3 text-sm font-black text-white shadow-lg shadow-emerald-600/20 transition hover:from-emerald-500 hover:to-teal-500 hover:shadow-emerald-600/30 disabled:cursor-not-allowed disabled:opacity-50"
                        @click="applyPayment"
                    >
                        {{ redeeming ? 'Applying voucher…' : 'Apply as Payment and Mark Used' }}
                    </button>
                </div>
            </div>
        </Modal>
    </div>
</template>
