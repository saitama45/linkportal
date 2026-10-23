<script setup>
/**
 * Campaigns — the portal's counterpart to the LINK HUB's Loyalty Stamps page.
 *
 * Same five tabs and the same rules (plus Receive, the store's side of the
 * hub's Receiving Stock — see ReceivePanel), with three deliberate differences:
 *   * everything is scoped to the one store bound to this cashier account, so
 *     there is no store picker anywhere — the till IS the location;
 *   * Customers, Programs and Vouchers are read-only (they are configured in
 *     the hub; the counter only earns and spends against them);
 *   * a card is never created from scratch here. It comes into existence by
 *     scanning a member, which is what actually happens at a counter.
 */
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import VendorLayout from '@/Layouts/VendorLayout.vue';
import Modal from '@/Components/Modal.vue';
import Autocomplete from '@/Components/Autocomplete.vue';
import CampaignTable from '@/Components/Campaigns/CampaignTable.vue';
import StampTypePicker from '@/Components/Campaigns/StampTypePicker.vue';
import VoucherPanel from './VoucherPanel.vue';
import ReceivePanel from './ReceivePanel.vue';
import { QrCodeIcon } from '@heroicons/vue/24/outline';
import { useToast } from '@/Composables/useToast.js';
import { useClientTable } from '@/Composables/useClientTable.js';

const props = defineProps({
    store: { type: Object, default: () => ({}) },
    customers: { type: Array, default: () => [] },
    programs: { type: Array, default: () => [] },
    cards: { type: Array, default: () => [] },
    redemptions: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    voucherBatches: { type: Array, default: () => [] },
    voucherRedemptions: { type: Array, default: () => [] },
    voucherSummary: { type: Object, default: () => ({}) },
    receivings: { type: Array, default: () => [] },
    receivingSummary: { type: Object, default: () => ({}) },
    outgoingInTransit: { type: Array, default: () => [] },
    requireUnitScan: { type: Boolean, default: true },
});

const { addToast } = useToast();

const currentTab = ref('cards');
// Receive carries a count of what is waiting on this store — deliveries to
// count, and unserved items it sent that it must settle — so either is
// noticed without opening the tab.
const outgoingReceivingCount = computed(() => new Set(props.outgoingInTransit
    .map((line) => `${line.receiving_no}|${line.receiving_date}|${line.destination_location}`)).size);
const tabList = computed(() => [
    { id: 'cards', label: 'Cards' },
    { id: 'redemptions', label: 'Redemptions' },
    { id: 'customers', label: 'Customers' },
    { id: 'programs', label: 'Programs' },
    { id: 'vouchers', label: 'Vouchers' },
    { id: 'receive', label: 'Receive', badge: (props.receivingSummary.pending || 0) + outgoingReceivingCount.value },
]);

const custTable = useClientTable(() => props.customers, ['name', 'email', 'phone']);
const progTable = useClientTable(() => props.programs, ['name', 'description']);
const cardTable = useClientTable(() => props.cards, ['customer.name', 'program.name', 'status']);
const redeemTable = useClientTable(() => props.redemptions, ['customer.name', 'program.name', 'asset.item_code', 'location']);

/* ------------------------------------------------------------------ *
 | Stat row
 |
 | One row for the page, not one per tab: Vouchers used to render its own
 | five boxes directly under these five, which read as ten unrelated
 | numbers stacked on one screen. The row now follows the tab instead.
 * ------------------------------------------------------------------ */
const loyaltyStats = computed(() => [
    { label: 'Customers', value: props.summary.customers ?? 0, tone: 'text-slate-900' },
    { label: 'Active Cards', value: props.summary.active_cards ?? 0, tone: 'text-blue-600' },
    { label: 'Completed (To Redeem)', value: props.summary.completed_cards ?? 0, tone: 'text-amber-600' },
    { label: 'Redeemed', value: props.summary.redeemed_cards ?? 0, tone: 'text-emerald-600' },
    { label: 'Total Amount', value: `₱${formatAmount(props.summary.total_amount)}`, tone: 'text-indigo-600' },
]);

const voucherStats = computed(() => [
    { label: 'Batches', value: props.voucherSummary.batches ?? 0, tone: 'text-slate-900' },
    { label: 'Available', value: props.voucherSummary.issued ?? 0, tone: 'text-emerald-600' },
    { label: 'Used', value: props.voucherSummary.used ?? 0, tone: 'text-blue-600' },
    { label: 'Void', value: props.voucherSummary.void ?? 0, tone: 'text-slate-500' },
    { label: 'Recognized', value: `₱${formatAmount(props.voucherSummary.recognized)}`, tone: 'text-emerald-700' },
]);

const receivingStats = computed(() => [
    { label: 'To Receive', value: props.receivingSummary.pending ?? 0, tone: 'text-amber-600' },
    { label: 'Units Pending', value: props.receivingSummary.units_pending ?? 0, tone: 'text-slate-900' },
    { label: 'Units Received', value: props.receivingSummary.units_received ?? 0, tone: 'text-emerald-600' },
    { label: 'Damaged', value: props.receivingSummary.units_damaged ?? 0, tone: 'text-orange-600' },
    { label: 'Unserved · In Transit', value: props.receivingSummary.units_in_transit ?? 0, tone: 'text-rose-600' },
]);

const statCards = computed(() => ({
    vouchers: voucherStats.value,
    receive: receivingStats.value,
}[currentTab.value] || loyaltyStats.value));

/* ------------------------------------------------------------------ *
 | Verify / Use Voucher lives in the page header beside the other two
 | scan buttons, but its modal belongs to the vouchers panel — so open
 | the tab first, then reach into the panel once it has mounted.
 * ------------------------------------------------------------------ */
const voucherPanel = ref(null);

const openVoucherScan = async () => {
    currentTab.value = 'vouchers';
    await nextTick();
    voucherPanel.value?.openScan();
};

const programOptions = computed(() => props.programs
    .filter((p) => p.is_active)
    .map((p) => ({
        label: `${p.name} — ${p.stamps_required} stamps${p.holiday_stamps > 0 ? ` (${p.holiday_stamps} holiday)` : ''}`,
        value: p.id,
    })));

// Holiday slots are part of a card's stamps, not extra (StampAllocator), and a
// program edited mid-campaign can leave a card with more holiday stamps than
// slots — those count as regular, so clamp for display the same way.
const holidaySlotsOf = (program) => Math.max(0, Math.min(Number(program?.holiday_stamps || 0), Number(program?.stamps_required || 0)));
const holidayFilledOf = (card) => Math.min(Number(card?.holiday_count || 0), holidaySlotsOf(card?.program));

/* ------------------------------------------------------------------ *
 | Formatting helpers
 * ------------------------------------------------------------------ */
const formatAmount = (v) => Number(v || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const formatDateTime = (v) => (v ? new Date(v).toLocaleString('en-PH', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—');
const statusClass = (s) => ({
    active: 'bg-blue-50 text-blue-700 ring-1 ring-blue-600/25',
    completed: 'bg-amber-50 text-amber-700 ring-1 ring-amber-600/25',
    redeemed: 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-600/25',
    expired: 'bg-slate-100 text-slate-600 ring-1 ring-slate-300/50',
    cancelled: 'bg-rose-50 text-rose-700 ring-1 ring-rose-600/25',
}[s] || 'bg-slate-100 text-slate-700 ring-1 ring-slate-300/40');

const statusDotClass = (s) => ({
    active: 'bg-blue-500',
    completed: 'bg-amber-500',
    redeemed: 'bg-emerald-500',
    expired: 'bg-slate-400',
    cancelled: 'bg-rose-500',
}[s] || 'bg-slate-400');
const assetLabel = (a) => (a ? [a.item_code, a.brand, a.model].filter(Boolean).join(' ') : '—');
const actorName = (row) => row.creator?.name || row.cashier_vendor?.name || '—';

/* ------------------------------------------------------------------ *
 | Scan Customer QR — resolve the member, then add the stamps.
 | Store never appears: it is the cashier's own till. Program is
 | remembered per device, since a counter runs one campaign at a time.
 * ------------------------------------------------------------------ */
const scanModal = reactive({ open: false, step: 'scan', resolving: false, submitting: false, customer: null, cards: [], cardsHeld: {}, error: null });
// Per purchase, like the amount: what was bought decides it, so it starts at
// Regular on every scan rather than being remembered.
const scanStampType = ref('regular');
const scanTokenInput = ref('');
const scanTokenInputRef = ref(null);
const scanPurchaseAmountInputRef = ref(null);
const scanPurchaseAmount = ref(null);
const scanReceiptNumber = ref('');
const scanNote = ref('');
const scanQuantity = ref(1);

/** A ref backed by localStorage — remembering is a convenience, never required. */
const rememberedRef = (key, parse = Number) => {
    const r = ref((() => {
        try {
            const saved = localStorage.getItem(key);
            return saved ? parse(saved) : null;
        } catch { return null; }
    })());
    watch(r, (val) => {
        try {
            if (val) localStorage.setItem(key, String(val));
            else localStorage.removeItem(key);
        } catch { /* private browsing — ignore */ }
    });
    return r;
};
const scanProgramId = rememberedRef('campaigns:currentProgramId');

// A remembered program that is no longer offered — deactivated in the hub, or
// gone — must not linger: the field renders blank while the stale id still
// counts as chosen, which would let Add Stamp fire against it.
watch(programOptions, (options) => {
    if (scanProgramId.value && !options.some((o) => o.value === scanProgramId.value)) {
        scanProgramId.value = null;
    }
}, { immediate: true });

const openScanModal = () => {
    Object.assign(scanModal, { open: true, step: 'scan', resolving: false, submitting: false, customer: null, cards: [], cardsHeld: {}, error: null });
    scanTokenInput.value = '';
    scanPurchaseAmount.value = null;
    scanReceiptNumber.value = '';
    scanNote.value = '';
    scanQuantity.value = 1;
    scanStampType.value = 'regular';
    // A hardware scanner types into whatever holds focus, so the token field
    // must be focused before anyone clicks anything.
    nextTick(() => scanTokenInputRef.value?.focus());
};
const closeScanModal = () => { scanModal.open = false; };

const submitScanToken = async () => {
    const token = scanTokenInput.value.trim();
    if (!token || scanModal.resolving) return;
    scanModal.resolving = true;
    scanModal.error = null;
    try {
        const res = await axios.post(route('vendor.campaigns.scan.resolve'), { token });
        scanModal.customer = res.data.customer;
        scanModal.cards = res.data.cards || [];
        scanModal.cardsHeld = res.data.cards_held || {};
        scanModal.step = 'assign';
        nextTick(() => scanPurchaseAmountInputRef.value?.focus());
    } catch (e) {
        scanModal.error = e.response?.data?.errors?.token?.[0] || 'That code could not be read. Try scanning again.';
        scanTokenInput.value = '';
        nextTick(() => scanTokenInputRef.value?.focus());
    } finally {
        scanModal.resolving = false;
    }
};

// Matches LoyaltyQrService's shape: "LCARD1:{customer_id}:{24 hex}". Auto-submits
// once the payload looks complete, since not every scanner sends an Enter.
const SCAN_TOKEN_PATTERN = /^LCARD1:\d+:[0-9a-f]{24}$/;
let scanAutoSubmitTimer = null;
watch(scanTokenInput, (val) => {
    if (scanAutoSubmitTimer) clearTimeout(scanAutoSubmitTimer);
    const trimmed = val.trim();
    if (scanModal.step !== 'scan' || scanModal.resolving || !SCAN_TOKEN_PATTERN.test(trimmed)) return;
    scanAutoSubmitTimer = setTimeout(() => {
        if (scanTokenInput.value.trim() === trimmed) submitScanToken();
    }, 150);
});

const scanProgram = computed(() => props.programs.find((p) => p.id === scanProgramId.value) || null);
const scanHolidaySlots = computed(() => holidaySlotsOf(scanProgram.value));

// The cards stamps can land on — the ACTIVE ones, mirroring StampAllocator.
// With holiday slots a member can hold several at once (holiday stamps move to
// the next card while regular slots are still empty). A full card awaiting
// redemption is not among them: the server leaves it alone.
const scanOpenCards = computed(() => scanModal.cards.filter((c) => c.stamp_program_id === scanProgramId.value && c.status === 'active'));

// Completed-but-unredeemed cards for the same program. Not a blocker — worth
// saying out loud so the cashier can offer the waiting reward at the till.
const scanFullCardsAwaitingRedemption = computed(() => scanModal.cards.filter((c) => c.stamp_program_id === scanProgramId.value && c.status === 'completed'));

// The program's card limit counts every card the member has ever held for it,
// redeemed ones included (cards_held from the server).
const scanCardLimit = computed(() => Number(scanProgram.value?.max_cards_per_customer) || null);
const scanCardsHeld = computed(() => Number(scanModal.cardsHeld?.[scanProgramId.value] ?? 0));
const scanNewCardsAllowed = computed(() => (scanCardLimit.value === null ? Infinity : Math.max(0, scanCardLimit.value - scanCardsHeld.value)));

// Room left, the way StampAllocator counts it: free slots on the open cards
// plus whole new cards the limit still allows. Infinite without a limit —
// stamps that do not fit one card continue on the next.
const scanRemaining = computed(() => {
    const required = Number(scanProgram.value?.stamps_required || 0);
    const free = scanOpenCards.value.reduce((n, c) => n + Math.max(0, required - (c.stamps_count ?? 0)), 0);
    return free + (scanNewCardsAllowed.value === Infinity ? Infinity : scanNewCardsAllowed.value * required);
});

// Holiday stamps with no holiday slot left anywhere this member may hold one
// are recorded as Regular. Said before it happens, not discovered in the toast.
const scanHolidayNotice = computed(() => {
    if (scanStampType.value !== 'holiday' || scanHolidaySlots.value < 1) return null;
    const free = scanOpenCards.value.reduce((n, c) => n + Math.max(0, scanHolidaySlots.value - (c.holiday_filled ?? 0)), 0);
    const room = free + (scanNewCardsAllowed.value === Infinity ? Infinity : scanNewCardsAllowed.value * scanHolidaySlots.value);
    if (room >= scanQuantity.value) return null;
    return room === 0
        ? 'Every Holiday slot on the cards this member can hold is filled — this will be recorded as a Regular stamp.'
        : `Only ${room} Holiday slot${room === 1 ? '' : 's'} left — the rest will be recorded as Regular.`;
});

// Mirrors StampAllocator, so the limit is said out loud here rather than
// coming back as a failed request.
const scanQuantityError = computed(() => {
    if (!scanProgramId.value) return null;
    if (!scanQuantity.value || scanQuantity.value < 1) return 'Enter at least 1 stamp.';
    if (scanQuantity.value > scanRemaining.value) {
        const cards = `${scanCardLimit.value} card${scanCardLimit.value === 1 ? '' : 's'}`;
        return scanRemaining.value === 0
            ? `This member has reached the limit of ${cards} for this campaign, and every one is full.`
            : `Only ${scanRemaining.value} stamp${scanRemaining.value === 1 ? '' : 's'} left before this member reaches the limit of ${cards}.`;
    }
    return null;
});

const submitScanAddStamp = async () => {
    // Receipt No. is optional at the scan (the POS may not have printed one yet);
    // the server still refuses a receipt already used at this store.
    if (!scanProgramId.value || !scanPurchaseAmount.value || scanModal.submitting) return;
    if (scanQuantityError.value) return;
    scanModal.submitting = true;
    scanModal.error = null;
    try {
        const res = await axios.post(route('vendor.campaigns.scan.add-stamp'), {
            token: scanTokenInput.value.trim(),
            stamp_program_id: scanProgramId.value,
            stamp_type: scanHolidaySlots.value > 0 ? scanStampType.value : 'regular',
            quantity: scanQuantity.value,
            purchase_amount: scanPurchaseAmount.value,
            receipt_number: scanReceiptNumber.value.trim() || null,
            note: scanNote.value || null,
        });
        // The server says what actually happened — fewer stamps than asked past
        // the card limit, Holiday stamps recorded as Regular — so its sentence
        // is the toast, not the number the cashier typed.
        const applied = res.data?.applied ?? scanQuantity.value;
        const surprising = res.data?.converted || res.data?.refused;
        addToast(res.data?.message || `${applied} stamp${applied === 1 ? '' : 's'} added for ${scanModal.customer?.name}.`,
            surprising ? 'warning' : 'success', surprising ? 10000 : null);
        closeScanModal();
        router.reload({ only: ['cards', 'summary'] });
    } catch (e) {
        const errors = e.response?.data?.errors;
        scanModal.error = (errors ? Object.values(errors).flat()[0] : null) || 'Could not add a stamp. Try again.';
    } finally {
        scanModal.submitting = false;
    }
};

const rescan = () => {
    scanModal.step = 'scan';
    scanModal.customer = null;
    scanModal.cards = [];
    scanModal.cardsHeld = {};
    // scanProgramId stays: several members in a row for one running campaign.
    scanTokenInput.value = '';
    scanPurchaseAmount.value = null;
    scanReceiptNumber.value = '';
    scanNote.value = '';
    scanQuantity.value = 1;
    scanStampType.value = 'regular';
    nextTick(() => scanTokenInputRef.value?.focus());
};

/* ------------------------------------------------------------------ *
 | Add stamps / record purchase / stamp history
 * ------------------------------------------------------------------ */
const stampModal = reactive({ open: false, card: null });
const stampForm = useForm({ stamp_type: 'regular', quantity: 1, purchase_amount: null, receipt_number: '', note: '' });
const stampRemaining = computed(() => {
    const card = stampModal.card;
    if (!card?.program) return 0;
    return Math.max(0, (card.program.stamps_required ?? 0) - (card.stamps_count ?? 0));
});
const openStampModal = (card) => {
    stampForm.clearErrors();
    stampForm.reset();
    stampModal.card = card;
    stampModal.open = true;
};
const submitStamp = () => {
    stampForm.post(route('vendor.campaigns.cards.add-stamps', stampModal.card.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { stampModal.open = false; stampForm.reset(); },
    });
};

const purchaseModal = reactive({ open: false, card: null });
const purchaseForm = useForm({ stamp_type: 'regular', purchase_amount: null, receipt_number: '', note: '' });
const purchaseProgram = computed(() => purchaseModal.card?.program || null);
const openPurchaseModal = (card) => {
    purchaseForm.clearErrors();
    purchaseForm.reset();
    purchaseModal.card = card;
    purchaseModal.open = true;
};
const submitPurchase = () => {
    purchaseForm.post(route('vendor.campaigns.cards.record-purchase', purchaseModal.card.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { purchaseModal.open = false; purchaseForm.reset(); },
    });
};

/* ------------------------------------------------------------------ *
 | Deduct stamps — undo a stamp added by mistake. Recorded as its own
 | negative entry with the cashier's reason, never by editing history.
 * ------------------------------------------------------------------ */
const deductModal = reactive({ open: false, card: null });
const deductForm = useForm({ stamp_type: 'regular', quantity: 1, reason: '' });
const deductHolidaySlots = computed(() => holidaySlotsOf(deductModal.card?.program));
// What the chosen kind of slot holds on this card — the server refuses more.
const deductAvailable = computed(() => {
    const card = deductModal.card;
    if (!card) return 0;
    const holiday = Number(card.holiday_count || 0);
    return deductForm.stamp_type === 'holiday' && deductHolidaySlots.value > 0
        ? holiday
        : Math.max(0, Number(card.stamps_count || 0) - holiday);
});
const deductQuantityError = computed(() => {
    if (!deductForm.quantity || deductForm.quantity < 1) return 'Enter at least 1 stamp.';
    if (deductForm.quantity > deductAvailable.value) {
        const kind = deductHolidaySlots.value > 0 ? (deductForm.stamp_type === 'holiday' ? 'Holiday ' : 'Regular ') : '';
        return `This card has only ${deductAvailable.value} ${kind}stamp${deductAvailable.value === 1 ? '' : 's'} to deduct.`;
    }
    return null;
});
const openDeductModal = (card) => {
    deductForm.clearErrors();
    deductForm.reset();
    deductModal.card = card;
    // Default to the kind the card actually holds, so the form opens valid.
    if (holidaySlotsOf(card.program) > 0 && Number(card.holiday_count || 0) > 0
        && Number(card.stamps_count || 0) - Number(card.holiday_count || 0) === 0) {
        deductForm.stamp_type = 'holiday';
    }
    deductModal.open = true;
};
const submitDeduct = () => {
    if (deductQuantityError.value || deductForm.reason.trim().length < 5) return;
    deductForm
        .transform((d) => ({ ...d, stamp_type: deductHolidaySlots.value > 0 ? d.stamp_type : 'regular' }))
        .post(route('vendor.campaigns.cards.deduct-stamps', deductModal.card.id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => { deductModal.open = false; deductForm.reset(); },
        });
};

const entriesModal = reactive({ open: false, card: null, entries: [], loading: false });
const openEntriesModal = async (card) => {
    entriesModal.card = card;
    entriesModal.entries = [];
    entriesModal.loading = true;
    entriesModal.open = true;
    try {
        const res = await axios.get(route('vendor.campaigns.cards.entries', card.id));
        entriesModal.entries = res.data.entries || [];
    } catch {
        addToast('Failed to load stamp history.', 'error');
    } finally {
        entriesModal.loading = false;
    }
};

/* ------------------------------------------------------------------ *
 | Scan Redeem QR — resolves the one full card the member's code
 | authorizes, then hands it to the same Redeem Reward modal the Action
 | column opens. It stops at the modal on purpose: a redemption deducts
 | specific coded units, and only the person at the counter knows which
 | unit leaves the shelf.
 * ------------------------------------------------------------------ */
const redeemScanModal = reactive({ open: false, resolving: false, error: null });
const redeemScanTokenInput = ref('');
const redeemScanTokenInputRef = ref(null);

const openRedeemScanModal = () => {
    Object.assign(redeemScanModal, { open: true, resolving: false, error: null });
    redeemScanTokenInput.value = '';
    nextTick(() => redeemScanTokenInputRef.value?.focus());
};

const submitRedeemScanToken = async () => {
    const token = redeemScanTokenInput.value.trim();
    if (!token || redeemScanModal.resolving) return;
    redeemScanModal.resolving = true;
    redeemScanModal.error = null;
    try {
        const res = await axios.post(route('vendor.campaigns.scan.resolve-redeem'), { token });
        redeemScanModal.open = false;
        openRedeemModal(res.data.card);
    } catch (e) {
        // The server says exactly why (already redeemed, not full, unknown) —
        // surface that, since "already redeemed" is the one members argue about.
        redeemScanModal.error = e.response?.data?.errors?.token?.[0] || 'That code could not be read. Try scanning again.';
        redeemScanTokenInput.value = '';
        nextTick(() => redeemScanTokenInputRef.value?.focus());
    } finally {
        redeemScanModal.resolving = false;
    }
};

const REDEEM_SCAN_TOKEN_PATTERN = /^LRDM1:\d+:[0-9a-f]{24}$/;
let redeemScanAutoSubmitTimer = null;
watch(redeemScanTokenInput, (val) => {
    if (redeemScanAutoSubmitTimer) clearTimeout(redeemScanAutoSubmitTimer);
    const trimmed = val.trim();
    if (!redeemScanModal.open || redeemScanModal.resolving || !REDEEM_SCAN_TOKEN_PATTERN.test(trimmed)) return;
    redeemScanAutoSubmitTimer = setTimeout(() => {
        if (redeemScanTokenInput.value.trim() === trimmed) submitRedeemScanToken();
    }, 150);
});

/* ------------------------------------------------------------------ *
 | Redeem modal. No location field: the reward leaves this till's shelf,
 | and the server states that itself rather than trusting the request.
 * ------------------------------------------------------------------ */
const redeemModal = reactive({ open: false, card: null });
const redeemForm = useForm({ asset_id: null, stock_in_ids: [], quantity: 0, remarks: '' });
const assetOptions = ref([]);
const loadingAssets = ref(false);
const redeemUnitOptions = ref([]);
const loadingRedeemUnits = ref(false);
const redeemCodeSearch = ref('');
const redeemCodeInput = ref(null);

const openRedeemModal = (card) => {
    redeemForm.clearErrors();
    redeemForm.reset();
    assetOptions.value = [];
    redeemUnitOptions.value = [];
    clearRedeemScan();
    redeemModal.card = card;
    redeemModal.open = true;
    loadAssets();
};

let assetsLoading = false;
const loadAssets = async () => {
    if (assetsLoading) return;
    redeemForm.asset_id = null;
    redeemForm.stock_in_ids = [];
    redeemForm.quantity = 0;
    assetOptions.value = [];
    redeemUnitOptions.value = [];
    clearRedeemScan();

    assetsLoading = true;
    loadingAssets.value = true;
    try {
        const res = await axios.get(route('vendor.campaigns.assets-at-location'));
        assetOptions.value = (res.data || []).map((a) => ({ label: `${assetLabel(a)} — SOH ${a.soh}`, value: a.id }));
        if (!assetOptions.value.length) addToast('No stock available at this store.', 'error');
    } catch {
        addToast('Failed to load available items.', 'error');
    } finally {
        assetsLoading = false;
        loadingAssets.value = false;
    }
};

const loadRedeemUnits = async (assetId) => {
    redeemForm.stock_in_ids = [];
    redeemForm.quantity = 0;
    redeemUnitOptions.value = [];
    redeemCodeSearch.value = '';
    if (!assetId) return;
    // Scan step switched off: one reward, and the server assigns the unit.
    if (!props.requireUnitScan) {
        redeemForm.quantity = 1;
        return;
    }
    loadingRedeemUnits.value = true;
    try {
        const res = await axios.get(route('vendor.campaigns.assets.units-at-location', { asset: assetId }));
        redeemUnitOptions.value = res.data || [];
        if (!redeemUnitOptions.value.length) addToast('No coded stock units are available for this item at this store.', 'error');
        else nextTick(() => redeemCodeInput.value?.focus());
    } catch {
        addToast('Failed to load available barcode/QR codes.', 'error');
    } finally {
        loadingRedeemUnits.value = false;
    }
};
watch(() => redeemForm.asset_id, (assetId) => loadRedeemUnits(assetId));

const normalizeRedeemCode = (value) => String(value || '').trim().toLowerCase();
const selectedRedeemUnits = computed(() => {
    const selected = new Set(redeemForm.stock_in_ids.map(Number));
    return redeemUnitOptions.value.filter((unit) => selected.has(Number(unit.stock_in_id)));
});
const filteredRedeemUnits = computed(() => {
    const query = normalizeRedeemCode(redeemCodeSearch.value);
    if (!query) return [];
    const selected = new Set(redeemForm.stock_in_ids.map(Number));
    return redeemUnitOptions.value.filter((unit) => {
        if (selected.has(Number(unit.stock_in_id))) return false;
        return [unit.serial_no, unit.barcode, unit.qrcode].some((value) => normalizeRedeemCode(value).includes(query));
    }).slice(0, 20);
});
const addRedeemUnit = (unit) => {
    const stockInId = Number(unit.stock_in_id);
    if (redeemForm.stock_in_ids.map(Number).includes(stockInId)) {
        addToast('This barcode/QR code is already selected.', 'error');
        redeemCodeSearch.value = '';
        nextTick(() => redeemCodeInput.value?.focus());
        return;
    }
    redeemForm.stock_in_ids = [...redeemForm.stock_in_ids, stockInId];
    redeemForm.quantity = redeemForm.stock_in_ids.length;
    redeemScanBuffer.value = '';
    redeemCodeSearch.value = '';
    redeemForm.clearErrors('stock_in_ids', 'quantity');
    nextTick(() => redeemCodeInput.value?.focus());
};
const removeRedeemUnit = (stockInId) => {
    redeemForm.stock_in_ids = redeemForm.stock_in_ids.filter((id) => Number(id) !== Number(stockInId));
    redeemForm.quantity = redeemForm.stock_in_ids.length;
    nextTick(() => redeemCodeInput.value?.focus());
};

/* ------------------------------------------------------------------ *
 | Scanning a unit's code.
 |
 | The QR labels this system prints are MULTI-LINE (the whole asset card),
 | and a single-line <input> cannot hold newlines — each embedded newline
 | arrives as Enter. So a scan ACCUMULATES rather than being decided by
 | each Enter: Enter banks a line, every candidate the payload contains is
 | tried, and "not found" is only reported once the scan goes quiet or the
 | cashier explicitly presses Add.
 * ------------------------------------------------------------------ */
const redeemScanBuffer = ref('');
let redeemScanIdleTimer = null;

/** Every string in a scanned payload that could BE the code. */
const redeemCodeCandidates = (text) => {
    const raw = String(text || '');
    const out = [raw, ...raw.split(/[\r\n]+/)];

    for (const line of raw.split(/[\r\n]+/)) {
        const labelled = line.match(/^\s*(?:barcode|serial\s*no\.?|qr\s*code|item\s*code)\s*:\s*(.+)$/i);
        if (labelled) out.push(labelled[1]);
    }

    return out
        .map((value) => normalizeRedeemCode(value))
        // "n/a" is what the label prints for a missing serial; it would
        // otherwise match every unit that has no serial number.
        .filter((value) => value && value !== 'n/a');
};

const findRedeemUnitFor = (text) => {
    const candidates = redeemCodeCandidates(text);
    if (!candidates.length) return null;

    return redeemUnitOptions.value.find((unit) => [unit.serial_no, unit.barcode, unit.qrcode].some((value) => {
        const normalized = normalizeRedeemCode(value);
        return normalized && candidates.includes(normalized);
    })) || null;
};

function clearRedeemScan() {
    redeemScanBuffer.value = '';
    redeemCodeSearch.value = '';
    if (redeemScanIdleTimer) { clearTimeout(redeemScanIdleTimer); redeemScanIdleTimer = null; }
}

const failRedeemScan = () => {
    addToast(filteredRedeemUnits.value.length > 1
        ? 'Multiple codes match. Continue scanning or choose the correct result.'
        : 'Barcode/QR code not found in available stock at this store.', 'error');
    clearRedeemScan();
    nextTick(() => redeemCodeInput.value?.focus());
};

/**
 * @param {boolean} explicit the cashier pressed Add (report failure now)
 *                           rather than a scanner still mid-payload.
 */
const scanRedeemCode = (explicit = true) => {
    if (redeemScanIdleTimer) { clearTimeout(redeemScanIdleTimer); redeemScanIdleTimer = null; }

    const text = [redeemScanBuffer.value, redeemCodeSearch.value]
        .filter((part) => String(part || '').trim())
        .join('\n');
    if (!text.trim()) return;

    const unit = findRedeemUnitFor(text);
    if (unit) {
        redeemScanBuffer.value = '';
        addRedeemUnit(unit);
        return;
    }

    // Typed by hand and narrowed to exactly one — keep the convenience.
    if (filteredRedeemUnits.value.length === 1) {
        redeemScanBuffer.value = '';
        addRedeemUnit(filteredRedeemUnits.value[0]);
        return;
    }

    if (explicit) {
        failRedeemScan();
        return;
    }

    // Mid-scan: stay quiet and let the rest of the payload land.
    redeemScanIdleTimer = setTimeout(() => {
        redeemScanIdleTimer = null;
        const settled = [redeemScanBuffer.value, redeemCodeSearch.value]
            .filter((part) => String(part || '').trim())
            .join('\n');
        if (!settled.trim()) return;

        const late = findRedeemUnitFor(settled);
        if (late) {
            redeemScanBuffer.value = '';
            addRedeemUnit(late);
        } else {
            failRedeemScan();
        }
    }, 700);
};

/** Enter, from a scanner or a person: bank the line, then try what we have. */
const captureRedeemScanLine = () => {
    const line = String(redeemCodeSearch.value || '');
    redeemScanBuffer.value = redeemScanBuffer.value ? `${redeemScanBuffer.value}\n${line}` : line;
    redeemCodeSearch.value = '';
    scanRedeemCode(false);
};

// Scanners that send no terminating Enter still resolve once typing stops.
watch(redeemCodeSearch, (value) => {
    if (!String(value || '').trim()) return;
    if (redeemScanIdleTimer) clearTimeout(redeemScanIdleTimer);
    redeemScanIdleTimer = setTimeout(() => {
        redeemScanIdleTimer = null;
        const unit = findRedeemUnitFor([redeemScanBuffer.value, redeemCodeSearch.value].filter(Boolean).join('\n'));
        if (unit) {
            redeemScanBuffer.value = '';
            addRedeemUnit(unit);
        }
    }, 400);
});

const submitRedeem = () => {
    redeemForm.post(route('vendor.campaigns.cards.redeem', redeemModal.card.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { redeemModal.open = false; redeemForm.reset(); },
    });
};
</script>

<template>
    <Head title="Campaigns - Link Portal" />

    <VendorLayout>
        <template #header>
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-2.5">
                        <h2 class="text-2xl font-black tracking-tight text-slate-900">Campaigns</h2>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-800 ring-1 ring-emerald-600/20">
                            <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                            <span>{{ store.code }}<span v-if="store.name"> · {{ store.name }}</span></span>
                        </span>
                    </div>
                    <p class="mt-1 text-sm font-medium text-slate-500">
                        Loyalty stamp cards, reward redemptions and campaign vouchers at your store counter.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2.5">
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 px-4 py-2.5 text-xs font-black uppercase tracking-wider text-white shadow-md shadow-emerald-600/20 transition hover:from-emerald-500 hover:to-teal-500 hover:shadow-lg hover:shadow-emerald-600/30"
                        @click="openScanModal()"
                    >
                        <QrCodeIcon class="h-4 w-4" />
                        Scan Customer QR
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-black uppercase tracking-wider text-slate-700 shadow-sm transition hover:bg-slate-50 hover:text-slate-900 hover:border-slate-300"
                        @click="openRedeemScanModal()"
                    >
                        <QrCodeIcon class="h-4 w-4 text-amber-500" />
                        Scan Redeem QR
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-black uppercase tracking-wider text-slate-700 shadow-sm transition hover:bg-slate-50 hover:text-slate-900 hover:border-slate-300"
                        @click="openVoucherScan()"
                    >
                        <QrCodeIcon class="h-4 w-4 text-emerald-600" />
                        Verify / Use Voucher
                    </button>
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Stat row: loyalty figures, or the voucher figures on that tab. -->
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                <div
                    v-for="(card, i) in statCards"
                    :key="card.label"
                    :class="[
                        'rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm transition hover:border-slate-300 hover:shadow-md',
                        i === statCards.length - 1 ? 'col-span-2 sm:col-span-1' : '',
                    ]"
                >
                    <p class="text-[11px] font-black uppercase tracking-widest text-slate-400">{{ card.label }}</p>
                    <p class="mt-2 text-2xl sm:text-3xl font-black tracking-tight" :class="card.tone">{{ card.value }}</p>
                </div>
            </div>

            <!-- Tabs -->
            <div class="border-b border-slate-200">
                <nav class="-mb-px flex space-x-6 overflow-x-auto">
                    <button
                        v-for="t in tabList"
                        :key="t.id"
                        type="button"
                        :class="[
                            'whitespace-nowrap border-b-2 py-3 px-1 text-sm font-bold transition-all',
                            currentTab === t.id
                                ? 'border-emerald-600 text-emerald-700'
                                : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800',
                        ]"
                        @click="currentTab = t.id"
                    >
                        {{ t.label }}
                        <span v-if="t.badge" class="ml-1.5 inline-flex min-w-5 items-center justify-center rounded-full bg-amber-100 px-1.5 py-0.5 text-[11px] font-black text-amber-800">{{ t.badge }}</span>
                    </button>
                </nav>
            </div>

            <!-- CARDS TAB -->
            <CampaignTable
                v-if="currentTab === 'cards'"
                title="Stamp Cards"
                subtitle="Cards issued or stamped at this store."
                search-placeholder="Search customer, program, status..."
                empty-message="Scan a member's QR to start their first card."
                :data="cardTable.data"
                :search="cardTable.search"
                :current-page="cardTable.currentPage"
                :last-page="cardTable.lastPage"
                :per-page="cardTable.perPage"
                :showing-text="cardTable.showingText"
                @update:search="cardTable.search = $event"
                @goToPage="cardTable.goToPage"
                @changePerPage="cardTable.changePerPage"
            >
                <template #actions>
                    <div class="flex items-center gap-2">
                        <button type="button" title="Scan a member's QR code to add a stamp" class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 px-3.5 py-2 text-xs font-bold text-white shadow-sm transition hover:from-emerald-500 hover:to-teal-500" @click="openScanModal()">
                            <QrCodeIcon class="h-3.5 w-3.5" />
                            Scan Member
                        </button>
                        <button type="button" title="Scan the code the member's app shows when they tap Redeem Now" class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 shadow-sm transition hover:bg-slate-50" @click="openRedeemScanModal()">
                            <QrCodeIcon class="h-3.5 w-3.5 text-amber-500" />
                            Scan Redeem
                        </button>
                    </div>
                </template>
                <template #header>
                    <tr class="text-left text-xs font-bold uppercase tracking-wider text-slate-500">
                        <th class="px-6 py-3.5">Customer</th>
                        <th class="px-6 py-3.5">Program</th>
                        <th class="px-6 py-3.5">Issued</th>
                        <th class="px-6 py-3.5">Progress</th>
                        <th class="px-6 py-3.5">Status</th>
                        <th class="px-6 py-3.5 text-right">Actions</th>
                    </tr>
                </template>
                <template #body="{ data }">
                    <tr v-for="card in data" :key="card.id" class="transition-colors hover:bg-slate-50/60">
                        <td class="px-6 py-4 text-sm font-bold text-slate-900">{{ card.customer?.name || '—' }}</td>
                        <td class="px-6 py-4 text-sm font-medium text-slate-700">{{ card.program?.name || '—' }}</td>
                        <td class="whitespace-nowrap px-6 py-4 text-xs font-medium text-slate-500">
                            {{ formatDateTime(card.created_at) }}
                            <!-- A card opened at another branch and stamped here: say so, or the row looks misfiled. -->
                            <span v-if="card.store_id !== store.id" class="block text-[11px] font-semibold text-slate-400">at {{ card.store?.code || 'another store' }}</span>
                        </td>
                        <td class="px-6 py-4 text-sm">
                            <button type="button" class="group flex items-center gap-2.5" title="View stamp history" @click="openEntriesModal(card)">
                                <div class="h-2.5 w-28 overflow-hidden rounded-full bg-slate-100 border border-slate-200/60">
                                    <div class="h-full bg-gradient-to-r from-emerald-500 to-teal-500 transition-all group-hover:from-emerald-600 group-hover:to-teal-600" :style="{ width: Math.min(100, ((card.stamps_count / (card.program?.stamps_required || 1)) * 100)) + '%' }"></div>
                                </div>
                                <span class="whitespace-nowrap text-xs font-bold text-slate-600 group-hover:text-emerald-700 transition-colors">{{ card.stamps_count }} / {{ card.program?.stamps_required }}</span>
                            </button>
                            <p v-if="holidaySlotsOf(card.program) > 0" class="mt-1 text-xs font-bold text-rose-700">
                                Holiday {{ holidayFilledOf(card) }}/{{ holidaySlotsOf(card.program) }}
                            </p>
                        </td>
                        <td class="px-6 py-4 text-sm">
                            <span :class="['inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-bold capitalize', statusClass(card.status)]">
                                <span class="h-1.5 w-1.5 rounded-full" :class="statusDotClass(card.status)"></span>
                                {{ card.status }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-sm text-right">
                            <div class="flex justify-end gap-1.5">
                                <button v-if="card.status === 'active'" type="button" title="Add Stamp" class="rounded-lg p-1.5 text-emerald-600 transition hover:bg-emerald-50 hover:text-emerald-800" @click="openStampModal(card)">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M12 4v16m8-8H4" /></svg>
                                </button>
                                <!-- Beside "+" on purpose: it undoes what "+" (or a scan) added by mistake. -->
                                <button v-if="['active', 'completed'].includes(card.status) && card.stamps_count > 0" type="button" title="Deduct Stamps" class="rounded-lg p-1.5 text-rose-600 transition hover:bg-rose-50 hover:text-rose-800" @click="openDeductModal(card)">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M20 12H4" /></svg>
                                </button>
                                <button v-if="card.status === 'active' && card.program?.auto_stamp_amount" type="button" title="Record Purchase" class="rounded-lg p-1.5 text-indigo-600 transition hover:bg-indigo-50 hover:text-indigo-800" @click="openPurchaseModal(card)">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
                                </button>
                                <button v-if="card.status === 'completed'" type="button" title="Redeem Reward" class="rounded-lg p-1.5 text-amber-600 transition hover:bg-amber-50 hover:text-amber-800" @click="openRedeemModal(card)">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 11.25v8.25a1.5 1.5 0 01-1.5 1.5H5.25a1.5 1.5 0 01-1.5-1.5v-8.25M12 4.875A2.625 2.625 0 109.375 7.5H12m0-2.625V7.5m0-2.625A2.625 2.625 0 1114.625 7.5H12m0 0V21m-9-9.75h18" /></svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                </template>
            </CampaignTable>

            <!-- REDEMPTIONS TAB -->
            <CampaignTable
                v-if="currentTab === 'redemptions'"
                title="Redemptions"
                subtitle="Rewards released from this store, or claimed on its cards."
                search-placeholder="Search customer, program, item, location..."
                empty-message="No rewards have been redeemed here yet."
                :data="redeemTable.data"
                :search="redeemTable.search"
                :current-page="redeemTable.currentPage"
                :last-page="redeemTable.lastPage"
                :per-page="redeemTable.perPage"
                :showing-text="redeemTable.showingText"
                @update:search="redeemTable.search = $event"
                @goToPage="redeemTable.goToPage"
                @changePerPage="redeemTable.changePerPage"
            >
                <template #header>
                    <tr class="text-left text-xs font-bold uppercase tracking-wider text-slate-500">
                        <th class="px-6 py-3.5">Date</th>
                        <th class="px-6 py-3.5">Customer</th>
                        <th class="px-6 py-3.5">Program</th>
                        <th class="px-6 py-3.5">Reward Item</th>
                        <th class="px-6 py-3.5">Qty</th>
                        <th class="px-6 py-3.5">Barcode / QR Code</th>
                        <th class="px-6 py-3.5">Total Purchase</th>
                        <th class="px-6 py-3.5">By</th>
                    </tr>
                </template>
                <template #body="{ data }">
                    <tr v-for="r in data" :key="r.id" class="transition-colors hover:bg-slate-50/60">
                        <td class="whitespace-nowrap px-6 py-4 text-xs font-medium text-slate-500">{{ formatDateTime(r.created_at) }}</td>
                        <td class="px-6 py-4 text-sm font-bold text-slate-900">{{ r.customer?.name || '—' }}</td>
                        <td class="px-6 py-4 text-sm font-medium text-slate-700">{{ r.program?.name || '—' }}</td>
                        <td class="px-6 py-4 text-sm text-slate-800">{{ assetLabel(r.asset) }}</td>
                        <td class="px-6 py-4 text-sm font-black text-slate-900">{{ r.quantity }}</td>
                        <td class="px-6 py-4 text-xs text-slate-600">
                            <div v-if="r.units?.length" class="space-y-1">
                                <div v-for="unit in r.units" :key="unit.id" class="font-mono">
                                    <div v-if="unit.barcode"><span class="font-bold text-slate-700">Barcode:</span> {{ unit.barcode }}</div>
                                    <div v-if="unit.qrcode" class="max-w-xs truncate" :title="unit.qrcode"><span class="font-bold text-slate-700">QR:</span> {{ unit.qrcode }}</div>
                                </div>
                            </div>
                            <span v-else class="text-slate-400">Legacy quantity record</span>
                        </td>
                        <td class="whitespace-nowrap px-6 py-4 text-sm font-bold text-slate-900">₱{{ formatAmount(r.total_purchase_amount) }}</td>
                        <td class="px-6 py-4 text-xs font-medium text-slate-500">{{ actorName(r) }}</td>
                    </tr>
                </template>
            </CampaignTable>

            <!-- CUSTOMERS TAB -->
            <CampaignTable
                v-if="currentTab === 'customers'"
                title="Customers"
                subtitle="Loyalty members. Maintained in the LINK HUB."
                search-placeholder="Search customers by name, email, phone..."
                empty-message="No loyalty members recorded."
                :data="custTable.data"
                :search="custTable.search"
                :current-page="custTable.currentPage"
                :last-page="custTable.lastPage"
                :per-page="custTable.perPage"
                :showing-text="custTable.showingText"
                @update:search="custTable.search = $event"
                @goToPage="custTable.goToPage"
                @changePerPage="custTable.changePerPage"
            >
                <template #header>
                    <tr class="text-left text-xs font-bold uppercase tracking-wider text-slate-500">
                        <th class="px-6 py-3.5">Name</th>
                        <th class="px-6 py-3.5">Email</th>
                        <th class="px-6 py-3.5">Phone</th>
                        <th class="px-6 py-3.5">Status</th>
                    </tr>
                </template>
                <template #body="{ data }">
                    <tr v-for="c in data" :key="c.id" class="transition-colors hover:bg-slate-50/60">
                        <td class="px-6 py-4 text-sm font-bold text-slate-900">{{ c.name }}</td>
                        <td class="px-6 py-4 text-sm font-medium text-slate-600">{{ c.email || '—' }}</td>
                        <td class="px-6 py-4 text-sm font-medium text-slate-600">{{ c.phone || '—' }}</td>
                        <td class="px-6 py-4 text-sm">
                            <span :class="['inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-bold', c.is_active ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-600/25' : 'bg-slate-100 text-slate-600 ring-1 ring-slate-300/50']">
                                <span class="h-1.5 w-1.5 rounded-full" :class="c.is_active ? 'bg-emerald-500' : 'bg-slate-400'"></span>
                                {{ c.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                    </tr>
                </template>
            </CampaignTable>

            <!-- PROGRAMS TAB -->
            <CampaignTable
                v-if="currentTab === 'programs'"
                title="Stamp Programs"
                subtitle="Campaign rules. Configured in the LINK HUB."
                search-placeholder="Search programs..."
                empty-message="No programs are available at this store."
                :data="progTable.data"
                :search="progTable.search"
                :current-page="progTable.currentPage"
                :last-page="progTable.lastPage"
                :per-page="progTable.perPage"
                :showing-text="progTable.showingText"
                @update:search="progTable.search = $event"
                @goToPage="progTable.goToPage"
                @changePerPage="progTable.changePerPage"
            >
                <template #header>
                    <tr class="text-left text-xs font-bold uppercase tracking-wider text-slate-500">
                        <th class="px-6 py-3.5">Name</th>
                        <th class="px-6 py-3.5">Company</th>
                        <th class="px-6 py-3.5">Year</th>
                        <th class="px-6 py-3.5">Stamps Required</th>
                        <th class="px-6 py-3.5">Cards / Customer</th>
                        <th class="px-6 py-3.5">Auto Rule</th>
                        <th class="px-6 py-3.5">Status</th>
                    </tr>
                </template>
                <template #body="{ data }">
                    <tr v-for="p in data" :key="p.id" class="transition-colors hover:bg-slate-50/60">
                        <td class="px-6 py-4">
                            <p class="text-sm font-bold text-slate-900">{{ p.name }}</p>
                            <p v-if="p.description" class="mt-0.5 text-xs font-medium text-slate-500">{{ p.description }}</p>
                        </td>
                        <td class="px-6 py-4 text-sm">
                            <span v-if="p.company" class="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-bold text-blue-700 ring-1 ring-blue-600/20">{{ p.company.code }}</span>
                            <span v-else class="text-xs italic text-slate-400">Shared</span>
                        </td>
                        <td class="px-6 py-4 text-sm font-semibold text-slate-700">{{ p.year }}</td>
                        <td class="px-6 py-4 text-sm font-black text-slate-900">
                            {{ p.stamps_required }}
                            <p v-if="holidaySlotsOf(p) > 0" class="mt-0.5 text-xs font-bold text-rose-700">{{ holidaySlotsOf(p) }} Holiday · {{ p.stamps_required - holidaySlotsOf(p) }} Regular</p>
                        </td>
                        <td class="px-6 py-4 text-sm font-semibold text-slate-700">
                            <span v-if="p.max_cards_per_customer">Up to {{ p.max_cards_per_customer }}</span>
                            <span v-else class="text-slate-400">No limit</span>
                        </td>
                        <td class="px-6 py-4 text-sm font-medium text-slate-600">
                            <span v-if="p.auto_stamp_amount">1 stamp / ₱{{ formatAmount(p.auto_stamp_amount) }}</span>
                            <span v-else class="text-slate-400">Manual only</span>
                        </td>
                        <td class="px-6 py-4 text-sm">
                            <span :class="['inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-bold', p.is_active ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-600/25' : 'bg-slate-100 text-slate-600 ring-1 ring-slate-300/50']">
                                <span class="h-1.5 w-1.5 rounded-full" :class="p.is_active ? 'bg-emerald-500' : 'bg-slate-400'"></span>
                                {{ p.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                    </tr>
                </template>
            </CampaignTable>

            <!-- VOUCHERS TAB -->
            <VoucherPanel
                v-if="currentTab === 'vouchers'"
                ref="voucherPanel"
                :batches="voucherBatches"
                :redemptions="voucherRedemptions"
                :customers="customers"
                :store="store"
                :summary="voucherSummary"
            />

            <!-- RECEIVE TAB -->
            <ReceivePanel
                v-if="currentTab === 'receive'"
                :receivings="receivings"
                :outgoing="outgoingInTransit"
                :store="store"
            />
        </div>

        <!-- Scan Customer QR Modal -->
        <!-- Every modal on this page is :closeable="false": a stray click on the
             backdrop at a busy till must not throw away a half-entered sale, so
             each one closes only through its own Cancel / Close button. -->
        <Modal :show="scanModal.open" max-width="lg" :closeable="false" @close="closeScanModal">
            <div class="space-y-5 p-6 sm:p-7">
                <div class="border-b border-slate-100 pb-3">
                    <h3 class="text-lg font-black tracking-tight text-slate-900">Scan Customer QR</h3>
                    <p class="mt-0.5 text-xs font-medium text-slate-500">Scan member code at the counter to record stamps or purchases.</p>
                </div>

                <template v-if="scanModal.step === 'scan'">
                    <p class="text-xs font-medium text-slate-600">Scan the member's loyalty QR with a barcode reader, or type the token below and press Enter.</p>
                    <input
                        ref="scanTokenInputRef"
                        v-model="scanTokenInput"
                        type="text"
                        autocomplete="off"
                        class="w-full rounded-xl border border-slate-200 bg-slate-50/70 px-3.5 py-2.5 font-mono text-sm font-bold text-slate-900 placeholder:text-slate-400 outline-none transition focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100"
                        placeholder="LCARD1:…"
                        @keydown.enter.prevent="submitScanToken"
                    />
                    <p v-if="scanModal.error" class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs font-semibold text-rose-800">{{ scanModal.error }}</p>
                    <div class="flex justify-end gap-2.5 pt-2">
                        <button type="button" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="closeScanModal">Cancel</button>
                        <button type="button" :disabled="scanModal.resolving || !scanTokenInput.trim()" class="rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 px-4 py-2 text-xs font-black uppercase tracking-wider text-white shadow-md shadow-emerald-600/20 hover:from-emerald-500 hover:to-teal-500 disabled:opacity-50" @click="submitScanToken">
                            {{ scanModal.resolving ? 'Looking up…' : 'Look Up' }}
                        </button>
                    </div>
                </template>

                <template v-else>
                    <div class="rounded-xl border border-emerald-200 bg-emerald-50/70 p-4 text-xs">
                        <p class="text-sm font-black text-emerald-900">{{ scanModal.customer?.name }}</p>
                        <p class="mt-0.5 text-xs font-medium text-emerald-700">{{ scanModal.customer?.email || scanModal.customer?.phone || 'No contact details' }}</p>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-600">Program <span class="text-rose-500">*</span></label>
                        <Autocomplete v-model="scanProgramId" :options="programOptions" placeholder="Select running campaign..." />
                        <template v-if="scanProgramId">
                            <p v-for="c in scanOpenCards" :key="c.id" class="mt-1.5 text-xs font-semibold text-emerald-700">
                                Card {{ c.card_number }}<template v-if="scanCardLimit"> of {{ scanCardLimit }}</template>:
                                {{ c.stamps_count }} / {{ scanProgram?.stamps_required }} stamps
                                <template v-if="scanHolidaySlots > 0">
                                    — <span class="text-rose-700">Holiday {{ c.holiday_filled ?? 0 }}/{{ scanHolidaySlots }}</span>,
                                    Regular {{ c.regular_filled ?? 0 }}/{{ (scanProgram?.stamps_required ?? 0) - scanHolidaySlots }}
                                </template>
                            </p>
                            <p v-if="!scanOpenCards.length" class="mt-1.5 text-xs font-semibold text-emerald-700">
                                No open card yet — one will be created automatically<template v-if="scanCardLimit"> (card {{ scanCardsHeld + 1 }} of {{ scanCardLimit }})</template>.
                            </p>
                            <p v-if="Number.isFinite(scanRemaining)" class="mt-1 text-xs font-semibold text-slate-500">{{ scanRemaining }} stamp(s) left before the card limit.</p>
                        </template>
                        <p v-if="scanFullCardsAwaitingRedemption.length" class="mt-1.5 text-xs font-semibold text-amber-700">
                            {{ scanFullCardsAwaitingRedemption.length === 1 ? 'A full card is' : `${scanFullCardsAwaitingRedemption.length} full cards are` }} waiting to be redeemed — still claimable, and not stamped further.
                        </p>
                    </div>

                    <div v-if="scanHolidaySlots > 0">
                        <label class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-600">Stamp type <span class="text-rose-500">*</span></label>
                        <StampTypePicker v-model="scanStampType" />
                        <p v-if="scanHolidayNotice" class="mt-1.5 text-xs font-semibold text-amber-700">{{ scanHolidayNotice }}</p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-600">Purchase amount <span class="text-rose-500">*</span></label>
                            <input ref="scanPurchaseAmountInputRef" v-model.number="scanPurchaseAmount" type="number" min="0.01" step="0.01" class="w-full rounded-xl border border-slate-200 bg-slate-50/70 px-3.5 py-2 text-sm font-bold text-slate-900 outline-none focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100" />
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-600">Stamps</label>
                            <input v-model.number="scanQuantity" type="number" min="1" class="w-full rounded-xl border border-slate-200 bg-slate-50/70 px-3.5 py-2 text-sm font-bold text-slate-900 outline-none focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100" />
                        </div>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-600">Receipt No. <span class="font-medium normal-case tracking-normal text-slate-400">(optional)</span></label>
                        <input v-model="scanReceiptNumber" type="text" maxlength="100" placeholder="Leave blank if there's no receipt yet" class="w-full rounded-xl border border-slate-200 bg-slate-50/70 px-3.5 py-2 text-sm font-bold text-slate-900 placeholder:font-medium placeholder:text-slate-400 outline-none focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100" />
                    </div>

                    <div>
                        <label class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-600">Note</label>
                        <input v-model="scanNote" type="text" maxlength="255" class="w-full rounded-xl border border-slate-200 bg-slate-50/70 px-3.5 py-2 text-xs font-medium text-slate-900 outline-none focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100" />
                    </div>

                    <p v-if="scanQuantityError" class="text-xs font-bold text-rose-600">{{ scanQuantityError }}</p>
                    <p v-if="scanModal.error" class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs font-semibold text-rose-800">{{ scanModal.error }}</p>

                    <div class="flex items-center justify-between pt-2">
                        <button type="button" class="text-xs font-bold text-emerald-700 underline underline-offset-4 hover:text-emerald-900" @click="rescan">Scan another member</button>
                        <div class="flex gap-2">
                            <button type="button" class="rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="closeScanModal">Cancel</button>
                            <button
                                type="button"
                                :disabled="scanModal.submitting || !!scanQuantityError || !scanProgramId || !scanPurchaseAmount"
                                class="rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 px-4 py-2 text-xs font-black uppercase tracking-wider text-white shadow-md shadow-emerald-600/20 hover:from-emerald-500 hover:to-teal-500 disabled:opacity-50"
                                @click="submitScanAddStamp"
                            >
                                {{ scanModal.submitting ? 'Adding…' : 'Add Stamp' }}
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </Modal>

        <!-- Scan Redeem QR Modal -->
        <Modal :show="redeemScanModal.open" max-width="lg" :closeable="false" @close="redeemScanModal.open = false">
            <div class="space-y-4 p-6 sm:p-7">
                <div class="border-b border-slate-100 pb-3">
                    <h3 class="text-lg font-black tracking-tight text-slate-900">Scan Redeem QR</h3>
                    <p class="mt-0.5 text-xs font-medium text-slate-500">Scan the voucher or reward code generated in member's app.</p>
                </div>
                <input
                    ref="redeemScanTokenInputRef"
                    v-model="redeemScanTokenInput"
                    type="text"
                    autocomplete="off"
                    class="w-full rounded-xl border border-slate-200 bg-slate-50/70 px-3.5 py-2.5 font-mono text-sm font-bold text-slate-900 placeholder:text-slate-400 outline-none transition focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100"
                    placeholder="LRDM1:…"
                    @keydown.enter.prevent="submitRedeemScanToken"
                />
                <p v-if="redeemScanModal.error" class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs font-semibold text-rose-800">{{ redeemScanModal.error }}</p>
                <div class="flex justify-end gap-2.5 pt-2">
                    <button type="button" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="redeemScanModal.open = false">Cancel</button>
                    <button type="button" :disabled="redeemScanModal.resolving || !redeemScanTokenInput.trim()" class="rounded-xl bg-gradient-to-r from-amber-500 to-orange-500 px-4 py-2 text-xs font-black uppercase tracking-wider text-white shadow-md shadow-amber-500/20 hover:from-amber-600 hover:to-orange-600 disabled:opacity-50" @click="submitRedeemScanToken">
                        {{ redeemScanModal.resolving ? 'Looking up…' : 'Look Up' }}
                    </button>
                </div>
            </div>
        </Modal>

        <!-- Add Stamps Modal -->
        <Modal :show="stampModal.open" max-width="lg" :closeable="false" @close="stampModal.open = false">
            <div class="space-y-4 p-6 sm:p-7">
                <div class="border-b border-slate-100 pb-3">
                    <h3 class="text-lg font-black tracking-tight text-slate-900">Add Stamps</h3>
                    <p class="mt-0.5 text-xs font-medium text-slate-500">
                        {{ stampModal.card?.customer?.name }} · {{ stampModal.card?.program?.name }} ·
                        <span class="font-bold text-emerald-700">{{ stampRemaining }} remaining on this card</span>
                        <template v-if="holidaySlotsOf(stampModal.card?.program) > 0">
                            · <span class="font-bold text-rose-700">Holiday {{ holidayFilledOf(stampModal.card) }}/{{ holidaySlotsOf(stampModal.card?.program) }}</span>
                        </template>
                    </p>
                </div>
                <div v-if="holidaySlotsOf(stampModal.card?.program) > 0">
                    <label class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-600">Stamp type <span class="text-rose-500">*</span></label>
                    <StampTypePicker v-model="stampForm.stamp_type" />
                    <p v-if="stampForm.errors.stamp_type" class="mt-1 text-xs font-bold text-rose-600">{{ stampForm.errors.stamp_type }}</p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-600">Stamps <span class="text-rose-500">*</span></label>
                        <input v-model.number="stampForm.quantity" type="number" min="1" class="w-full rounded-xl border border-slate-200 bg-slate-50/70 px-3.5 py-2 text-sm font-bold text-slate-900 outline-none focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100" />
                        <p v-if="stampForm.errors.quantity" class="mt-1 text-xs font-bold text-rose-600">{{ stampForm.errors.quantity }}</p>
                        <p v-else-if="stampForm.quantity > stampRemaining" class="mt-1 text-xs font-medium text-slate-500">Extra stamps continue on the member's next card, up to the campaign's card limit.</p>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-600">Purchase amount <span class="text-rose-500">*</span></label>
                        <input v-model.number="stampForm.purchase_amount" type="number" min="0.01" step="0.01" class="w-full rounded-xl border border-slate-200 bg-slate-50/70 px-3.5 py-2 text-sm font-bold text-slate-900 outline-none focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100" />
                        <p v-if="stampForm.errors.purchase_amount" class="mt-1 text-xs font-bold text-rose-600">{{ stampForm.errors.purchase_amount }}</p>
                    </div>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-600">Receipt No. <span class="text-rose-500">*</span></label>
                    <input v-model="stampForm.receipt_number" type="text" maxlength="100" class="w-full rounded-xl border border-slate-200 bg-slate-50/70 px-3.5 py-2 text-sm font-bold text-slate-900 outline-none focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100" />
                    <p v-if="stampForm.errors.receipt_number" class="mt-1 text-xs font-bold text-rose-600">{{ stampForm.errors.receipt_number }}</p>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-600">Note</label>
                    <input v-model="stampForm.note" type="text" maxlength="255" class="w-full rounded-xl border border-slate-200 bg-slate-50/70 px-3.5 py-2 text-xs font-medium text-slate-900 outline-none focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100" />
                </div>
                <div class="flex justify-end gap-2.5 pt-2">
                    <button type="button" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="stampModal.open = false">Cancel</button>
                    <button type="button" :disabled="stampForm.processing" class="rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 px-4 py-2 text-xs font-black uppercase tracking-wider text-white shadow-md shadow-emerald-600/20 hover:from-emerald-500 hover:to-teal-500 disabled:opacity-50" @click="submitStamp">
                        {{ stampForm.processing ? 'Saving…' : 'Add Stamps' }}
                    </button>
                </div>
            </div>
        </Modal>

        <!-- Deduct Stamps Modal -->
        <Modal :show="deductModal.open" max-width="lg" :closeable="false" @close="deductModal.open = false">
            <div class="space-y-4 p-6 sm:p-7">
                <div class="border-b border-slate-100 pb-3">
                    <h3 class="text-lg font-black tracking-tight text-slate-900">Deduct Stamps</h3>
                    <p class="mt-0.5 text-xs font-medium text-slate-500">
                        {{ deductModal.card?.customer?.name }} · {{ deductModal.card?.program?.name }} ·
                        <span class="font-bold text-slate-700">{{ deductModal.card?.stamps_count }} / {{ deductModal.card?.program?.stamps_required }} stamps</span>
                        <template v-if="deductHolidaySlots > 0">
                            · <span class="font-bold text-rose-700">Holiday {{ holidayFilledOf(deductModal.card) }}/{{ deductHolidaySlots }}</span>
                        </template>
                    </p>
                </div>

                <p class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs font-semibold text-rose-800">
                    Use this only to correct a mistake. The stamps are taken off the member's card, and the
                    deduction is kept in the card's history with your name and the reason.
                </p>
                <p v-if="deductModal.card?.status === 'completed'" class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs font-semibold text-amber-800">
                    This card is full and waiting to be redeemed. Deducting reopens it, and its redeem code stops working until it is full again.
                </p>

                <div v-if="deductHolidaySlots > 0">
                    <label class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-600">Deduct from <span class="text-rose-500">*</span></label>
                    <StampTypePicker v-model="deductForm.stamp_type" />
                    <p v-if="deductForm.errors.stamp_type" class="mt-1 text-xs font-bold text-rose-600">{{ deductForm.errors.stamp_type }}</p>
                </div>

                <div>
                    <label class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-600">Stamps to deduct <span class="text-rose-500">*</span></label>
                    <input v-model.number="deductForm.quantity" type="number" min="1" :max="Math.max(deductAvailable, 1)" class="w-full rounded-xl border border-slate-200 bg-slate-50/70 px-3.5 py-2 text-sm font-bold text-slate-900 outline-none focus:border-rose-500 focus:bg-white focus:ring-4 focus:ring-rose-100" />
                    <p v-if="deductForm.errors.quantity" class="mt-1 text-xs font-bold text-rose-600">{{ deductForm.errors.quantity }}</p>
                    <p v-else-if="deductQuantityError" class="mt-1 text-xs font-bold text-rose-600">{{ deductQuantityError }}</p>
                    <p v-else class="mt-1 text-xs font-medium text-slate-500">Up to {{ deductAvailable }} on this card.</p>
                </div>

                <div>
                    <label class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-600">Reason <span class="text-rose-500">*</span></label>
                    <textarea v-model="deductForm.reason" rows="2" maxlength="255" placeholder="e.g. Scanned the wrong member · Typed 3 stamps instead of 1 · Should have been Regular" class="w-full rounded-xl border border-slate-200 bg-slate-50/70 px-3.5 py-2 text-sm font-medium text-slate-900 placeholder:text-slate-400 outline-none focus:border-rose-500 focus:bg-white focus:ring-4 focus:ring-rose-100"></textarea>
                    <p v-if="deductForm.errors.reason" class="mt-1 text-xs font-bold text-rose-600">{{ deductForm.errors.reason }}</p>
                    <p v-else class="mt-1 text-xs font-medium text-slate-500">Required — at least a few words, so anyone reviewing the card knows why.</p>
                </div>

                <div class="flex justify-end gap-2.5 pt-2">
                    <button type="button" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="deductModal.open = false">Cancel</button>
                    <button
                        type="button"
                        :disabled="deductForm.processing || !!deductQuantityError || deductForm.reason.trim().length < 5"
                        class="rounded-xl bg-gradient-to-r from-rose-600 to-red-600 px-4 py-2 text-xs font-black uppercase tracking-wider text-white shadow-md shadow-rose-600/20 hover:from-rose-500 hover:to-red-500 disabled:opacity-50"
                        @click="submitDeduct"
                    >
                        {{ deductForm.processing ? 'Saving…' : `Deduct ${deductForm.quantity || ''} ${deductForm.quantity === 1 ? 'Stamp' : 'Stamps'}` }}
                    </button>
                </div>
            </div>
        </Modal>

        <!-- Record Purchase Modal -->
        <Modal :show="purchaseModal.open" max-width="lg" :closeable="false" @close="purchaseModal.open = false">
            <div class="space-y-4 p-6 sm:p-7">
                <div class="border-b border-slate-100 pb-3">
                    <h3 class="text-lg font-black tracking-tight text-slate-900">Record Purchase</h3>
                    <p class="mt-0.5 text-xs font-medium text-slate-500">
                        {{ purchaseModal.card?.customer?.name }} · 1 stamp per ₱{{ formatAmount(purchaseProgram?.auto_stamp_amount) }}
                    </p>
                </div>
                <div v-if="holidaySlotsOf(purchaseProgram) > 0">
                    <label class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-600">Stamp type <span class="text-rose-500">*</span></label>
                    <StampTypePicker v-model="purchaseForm.stamp_type" />
                    <p v-if="purchaseForm.errors.stamp_type" class="mt-1 text-xs font-bold text-rose-600">{{ purchaseForm.errors.stamp_type }}</p>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-600">Purchase amount <span class="text-rose-500">*</span></label>
                    <input v-model.number="purchaseForm.purchase_amount" type="number" min="0.01" step="0.01" class="w-full rounded-xl border border-slate-200 bg-slate-50/70 px-3.5 py-2 text-sm font-bold text-slate-900 outline-none focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100" />
                    <p v-if="purchaseForm.errors.purchase_amount" class="mt-1 text-xs font-bold text-rose-600">{{ purchaseForm.errors.purchase_amount }}</p>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-600">Receipt No. <span class="text-rose-500">*</span></label>
                    <input v-model="purchaseForm.receipt_number" type="text" maxlength="100" class="w-full rounded-xl border border-slate-200 bg-slate-50/70 px-3.5 py-2 text-sm font-bold text-slate-900 outline-none focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100" />
                    <p v-if="purchaseForm.errors.receipt_number" class="mt-1 text-xs font-bold text-rose-600">{{ purchaseForm.errors.receipt_number }}</p>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-600">Note</label>
                    <input v-model="purchaseForm.note" type="text" maxlength="255" class="w-full rounded-xl border border-slate-200 bg-slate-50/70 px-3.5 py-2 text-xs font-medium text-slate-900 outline-none focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100" />
                </div>
                <div class="flex justify-end gap-2.5 pt-2">
                    <button type="button" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="purchaseModal.open = false">Cancel</button>
                    <button type="button" :disabled="purchaseForm.processing" class="rounded-xl bg-gradient-to-r from-indigo-600 to-sky-600 px-4 py-2 text-xs font-black uppercase tracking-wider text-white shadow-md shadow-indigo-600/20 hover:from-indigo-500 hover:to-sky-500 disabled:opacity-50" @click="submitPurchase">
                        {{ purchaseForm.processing ? 'Saving…' : 'Record Purchase' }}
                    </button>
                </div>
            </div>
        </Modal>

        <!-- Stamp History Modal -->
        <Modal :show="entriesModal.open" max-width="2xl" :closeable="false" @close="entriesModal.open = false">
            <div class="p-6 sm:p-7">
                <div class="border-b border-slate-100 pb-3">
                    <h3 class="text-lg font-black tracking-tight text-slate-900">Stamp History</h3>
                    <p class="mt-0.5 text-xs font-medium text-slate-500">{{ entriesModal.card?.customer?.name }} · {{ entriesModal.card?.program?.name }}</p>
                </div>

                <p v-if="entriesModal.loading" class="mt-6 text-center text-xs font-medium text-slate-400">Loading history…</p>
                <div v-else class="mt-4 overflow-x-auto rounded-xl border border-slate-100">
                    <table class="min-w-full divide-y divide-slate-100">
                        <thead class="bg-slate-50/80">
                            <tr class="text-left text-xs font-bold uppercase tracking-wider text-slate-500">
                                <th class="px-3.5 py-2.5">Date</th>
                                <th class="px-3.5 py-2.5">Qty</th>
                                <th class="px-3.5 py-2.5">Source</th>
                                <th class="px-3.5 py-2.5">Amount</th>
                                <th class="px-3.5 py-2.5">Receipt No.</th>
                                <th class="px-3.5 py-2.5">Store</th>
                                <th class="px-3.5 py-2.5">By</th>
                                <th class="px-3.5 py-2.5">Note</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-xs text-slate-700">
                            <tr v-for="entry in entriesModal.entries" :key="entry.id" class="hover:bg-slate-50/60">
                                <td class="px-3.5 py-2.5 font-medium text-slate-500 whitespace-nowrap">{{ formatDateTime(entry.created_at) }}</td>
                                <td class="px-3.5 py-2.5 font-black whitespace-nowrap" :class="entry.quantity < 0 ? 'text-rose-700' : 'text-slate-900'">
                                    {{ entry.quantity < 0 ? `−${Math.abs(entry.quantity)}` : entry.quantity }}
                                    <span v-if="entry.stamp_type === 'holiday'" class="ml-1 rounded-full bg-rose-50 px-1.5 py-0.5 text-[10px] font-bold text-rose-700 ring-1 ring-rose-600/20">Holiday</span>
                                </td>
                                <td class="px-3.5 py-2.5 capitalize font-semibold text-slate-800">{{ entry.source }}</td>
                                <!-- One purchase spread over two cards records its amount once; the other entry has none. -->
                                <td class="px-3.5 py-2.5 font-bold text-slate-900">{{ entry.purchase_amount ? '₱' + formatAmount(entry.purchase_amount) : '—' }}</td>
                                <td class="px-3.5 py-2.5 font-medium text-slate-700">{{ entry.receipt_number || '—' }}</td>
                                <td class="px-3.5 py-2.5 font-medium text-slate-700">{{ entry.store?.code || '—' }}</td>
                                <td class="px-3.5 py-2.5 text-slate-600">{{ actorName(entry) }}</td>
                                <td class="px-3.5 py-2.5 text-slate-500">{{ entry.note || '—' }}</td>
                            </tr>
                            <tr v-if="!entriesModal.entries.length">
                                <td colspan="8" class="px-4 py-8 text-center text-xs font-medium text-slate-400">No stamps recorded yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-5 flex justify-end">
                    <button type="button" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="entriesModal.open = false">Close</button>
                </div>
            </div>
        </Modal>

        <!-- Redeem Reward Modal -->
        <Modal :show="redeemModal.open" max-width="2xl" :closeable="false" @close="redeemModal.open = false">
            <div class="space-y-4 p-6 sm:p-7">
                <div class="border-b border-slate-100 pb-3">
                    <h3 class="text-lg font-black tracking-tight text-slate-900">Redeem Reward</h3>
                    <p class="mt-0.5 text-xs font-medium text-slate-500">
                        {{ redeemModal.card?.customer?.name }} · {{ redeemModal.card?.program?.name }} ·
                        released from <strong class="text-slate-800">{{ store.code }}</strong>
                    </p>
                </div>

                <div>
                    <label class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-600">Reward item <span class="text-rose-500">*</span></label>
                    <Autocomplete v-model="redeemForm.asset_id" :options="assetOptions" :placeholder="loadingAssets ? 'Loading items…' : 'Select the item leaving the shelf...'" />
                    <p v-if="redeemForm.errors.asset_id" class="mt-1 text-xs font-bold text-rose-600">{{ redeemForm.errors.asset_id }}</p>
                </div>

                <div v-if="requireUnitScan && redeemForm.asset_id">
                    <label class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-600">
                        Scan the unit's barcode / QR code <span class="text-rose-500">*</span>
                    </label>
                    <input
                        ref="redeemCodeInput"
                        v-model="redeemCodeSearch"
                        type="text"
                        autocomplete="off"
                        :disabled="loadingRedeemUnits"
                        class="w-full rounded-xl border border-slate-200 bg-slate-50/70 px-3.5 py-2 font-mono text-xs font-bold text-slate-900 outline-none focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100"
                        :placeholder="loadingRedeemUnits ? 'Loading available units…' : 'Scan or type a code, then press Enter'"
                        @keydown.enter.prevent="captureRedeemScanLine"
                    />
                    <div class="mt-1.5 flex justify-end">
                        <button type="button" class="text-xs font-bold text-emerald-700 hover:text-emerald-900" @click="scanRedeemCode(true)">Add code</button>
                    </div>

                    <ul v-if="filteredRedeemUnits.length" class="mt-2 max-h-40 divide-y divide-slate-100 overflow-y-auto rounded-xl border border-slate-200 bg-white text-xs">
                        <li v-for="unit in filteredRedeemUnits" :key="unit.stock_in_id">
                            <button type="button" class="w-full px-3.5 py-2 text-left font-mono hover:bg-slate-50" @click="addRedeemUnit(unit)">
                                {{ unit.barcode || unit.qrcode || unit.serial_no }}
                            </button>
                        </li>
                    </ul>

                    <div v-if="selectedRedeemUnits.length" class="mt-3 space-y-1.5">
                        <p class="text-[11px] font-black uppercase tracking-wider text-slate-400">Selected units ({{ selectedRedeemUnits.length }})</p>
                        <div v-for="unit in selectedRedeemUnits" :key="unit.stock_in_id" class="flex items-center justify-between rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs">
                            <span class="font-mono font-bold text-slate-800">{{ unit.barcode || unit.qrcode || unit.serial_no }}</span>
                            <button type="button" class="font-bold text-rose-600 hover:text-rose-800" @click="removeRedeemUnit(unit.stock_in_id)">Remove</button>
                        </div>
                    </div>
                </div>
                <p v-if="redeemForm.errors.stock_in_ids" class="text-xs font-bold text-rose-600">{{ redeemForm.errors.stock_in_ids }}</p>
                <p v-if="redeemForm.errors.quantity" class="text-xs font-bold text-rose-600">{{ redeemForm.errors.quantity }}</p>

                <div>
                    <label class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-600">Remarks</label>
                    <input v-model="redeemForm.remarks" type="text" maxlength="255" class="w-full rounded-xl border border-slate-200 bg-slate-50/70 px-3.5 py-2 text-xs font-medium text-slate-900 outline-none focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100" />
                </div>

                <div class="flex justify-end gap-2.5 pt-2">
                    <button type="button" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="redeemModal.open = false">Cancel</button>
                    <button
                        type="button"
                        :disabled="redeemForm.processing || !(requireUnitScan ? redeemForm.stock_in_ids.length : redeemForm.asset_id)"
                        class="rounded-xl bg-gradient-to-r from-amber-500 to-orange-500 px-4 py-2 text-xs font-black uppercase tracking-wider text-white shadow-md shadow-amber-500/20 hover:from-amber-600 hover:to-orange-600 disabled:opacity-50"
                        @click="submitRedeem"
                    >
                        {{ redeemForm.processing ? 'Redeeming…' : (requireUnitScan ? `Redeem ${redeemForm.stock_in_ids.length || ''} unit(s)` : 'Redeem reward') }}
                    </button>
                </div>
            </div>
        </Modal>
    </VendorLayout>
</template>
