<script setup>
/**
 * Receive tab — the store's side of the LINK HUB's Receiving Stock.
 *
 * A transfer the hub posts to this store lands here as For Receiving. The
 * counter confirms what actually came: scan the labels (a box label accounts
 * for every piece of that box), or count by hand, or both, and mark each line
 * Good, Damaged or Unserved. Posting credits the received units to this
 * store's stock, which is what makes them redeemable as rewards.
 *
 * Scanning is a help, never a gate — the hub does not require it either. A
 * line nobody scanned keeps whatever quantity and condition the counter set.
 *
 * Unserved is not the end of a line: it stays in transit (the source is
 * already deducted) until it turns up here and is received, or the SOURCE
 * settles it. That is the lower table — what this store sent that never
 * arrived, to take back into stock or write off as lost.
 */
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import axios from 'axios';
import Modal from '@/Components/Modal.vue';
import CampaignTable from '@/Components/Campaigns/CampaignTable.vue';
import { QrCodeIcon } from '@heroicons/vue/24/outline';
import { useToast } from '@/Composables/useToast.js';
import { useClientTable } from '@/Composables/useClientTable.js';

const props = defineProps({
    receivings: { type: Array, default: () => [] },
    outgoing: { type: Array, default: () => [] },
    store: { type: Object, default: () => ({}) },
});

const { addToast } = useToast();

const PENDING = 'For Receiving';
const CONDITIONS = ['Good', 'Damaged', 'Unserved'];

/* ------------------------------------------------------------------ *
 | Formatting
 * ------------------------------------------------------------------ */
const formatDate = (v) => (v ? new Date(`${String(v).slice(0, 10)}T00:00:00`).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: '2-digit' }) : '—');
const formatDateTime = (v) => (v ? new Date(v).toLocaleString('en-PH', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—');
const assetLabel = (a) => (a ? [a.item_code, a.brand, a.model].filter(Boolean).join(' ') : '—');
const plural = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`;
// A QR-only piece shows the first line of its (multi-line) label text.
const pieceLabel = (row) => row.serial_no || row.barcode || String(row.qrcode || '').split(/\r?\n/)[0] || '—';

const statusClass = (s) => ({
    'For Receiving': 'bg-amber-50 text-amber-700 ring-1 ring-amber-600/25',
    Received: 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-600/25',
    Declined: 'bg-rose-50 text-rose-700 ring-1 ring-rose-600/25',
}[s] || 'bg-slate-100 text-slate-700 ring-1 ring-slate-300/40');

const statusDotClass = (s) => ({
    'For Receiving': 'bg-amber-500',
    Received: 'bg-emerald-500',
    Declined: 'bg-rose-500',
}[s] || 'bg-slate-400');

const conditionBadgeClass = (c) => ({
    Good: 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-600/25',
    Damaged: 'bg-orange-50 text-orange-700 ring-1 ring-orange-600/25',
    Unserved: 'bg-rose-50 text-rose-700 ring-1 ring-rose-600/25',
}[c] || 'bg-slate-100 text-slate-700');

const conditionButtonClass = (c, active) => {
    if (!active) return 'text-slate-500 hover:bg-slate-50 hover:text-slate-800';
    return {
        Good: 'bg-emerald-600 text-white shadow-sm',
        Damaged: 'bg-orange-500 text-white shadow-sm',
        Unserved: 'bg-rose-600 text-white shadow-sm',
    }[c];
};

/* ------------------------------------------------------------------ *
 | List — what is still to be received comes first, and is what the tab
 | opens on whenever something is waiting.
 * ------------------------------------------------------------------ */
const pendingCount = computed(() => props.receivings.filter((r) => r.status === PENDING).length);
const statusFilter = ref(pendingCount.value ? PENDING : 'all');
const statusFilters = computed(() => [
    { id: PENDING, label: pendingCount.value ? `To receive (${pendingCount.value})` : 'To receive' },
    { id: 'Received', label: 'Received' },
    { id: 'all', label: 'All' },
]);

const table = useClientTable(
    () => (statusFilter.value === 'all' ? props.receivings : props.receivings.filter((r) => r.status === statusFilter.value)),
    ['receiving_no', 'origin_location', 'status', 'received_by'],
);
watch(statusFilter, () => { table.currentPage = 1; });

/* ------------------------------------------------------------------ *
 | Receive / view modal
 * ------------------------------------------------------------------ */
const modal = reactive({ open: false, loading: false, readOnly: false, confirming: false, header: {} });
const rows = ref([]);
const form = useForm({ entries: [], remarks: '' });

const openReceiving = async (receiving, readOnly) => {
    form.clearErrors();
    form.reset();
    resetScan();
    rows.value = [];
    Object.assign(modal, { open: true, loading: true, readOnly, confirming: false, header: { ...receiving } });

    try {
        const res = await axios.get(route('vendor.campaigns.receivings.show', receiving.id));
        modal.header = res.data.header || modal.header;
        form.remarks = modal.header.remarks || '';
        rows.value = (res.data.rows || []).map((row) => {
            const quantity = Number(row.received_quantity ?? row.transferred_quantity) || 0;
            return {
                ...row,
                received_quantity: quantity,
                // Nothing received IS unserved, whatever the hub left on the line.
                condition: quantity < 1 ? 'Unserved' : (row.condition || 'Good'),
                damage_notes: row.damage_notes || '',
            };
        });
        if (!readOnly) nextTick(() => scanInputRef.value?.focus());
    } catch {
        addToast('Failed to load this receiving.', 'error');
        modal.open = false;
    } finally {
        modal.loading = false;
    }
};

const closeModal = () => {
    modal.open = false;
    modal.confirming = false;
    resetScan();
};

/** Lines grouped under their item, the way the hub's receiving modal shows them. */
const assetGroups = computed(() => {
    const groups = new Map();
    for (const row of rows.value) {
        if (!groups.has(row.asset_id)) groups.set(row.asset_id, { key: row.asset_id, asset: row.asset, rows: [] });
        groups.get(row.asset_id).rows.push(row);
    }
    return [...groups.values()];
});
const groupSum = (group, field) => group.rows.reduce((sum, row) => sum + (Number(row[field]) || 0), 0);
const inTransitCount = computed(() => rows.value.filter((row) => row.in_transit).length);

const totals = computed(() => rows.value.reduce((t, row) => {
    const sent = Number(row.transferred_quantity) || 0;
    const got = Number(row.received_quantity) || 0;
    t.sent += sent;
    if (row.condition === 'Good') t.good += got;
    if (row.condition === 'Damaged') t.damaged += got;
    t.unserved += Math.max(0, sent - got);
    return t;
}, { sent: 0, good: 0, damaged: 0, unserved: 0 }));

/* ------------------------------------------------------------------ *
 | Line edits. Quantity and condition keep each other honest: Unserved
 | means nothing arrived, and nothing arriving means Unserved.
 * ------------------------------------------------------------------ */
const setCondition = (row, condition) => {
    if (condition === 'Unserved') {
        row.received_quantity = 0;
    } else if (row.condition === 'Unserved' || row.received_quantity < 1) {
        row.received_quantity = row.transferred_quantity;
    }
    row.condition = condition;
};

const setQuantity = (row, value) => {
    const quantity = Math.max(0, Math.min(Number(row.transferred_quantity) || 0, Math.floor(Number(value) || 0)));
    row.received_quantity = quantity;
    if (quantity === 0) row.condition = 'Unserved';
    else if (row.condition === 'Unserved') row.condition = 'Good';
};

const receiveAllGood = () => {
    rows.value.forEach((row) => {
        row.received_quantity = row.transferred_quantity;
        row.condition = 'Good';
        row.damage_notes = '';
    });
};

const markUnscannedUnserved = () => {
    rows.value.forEach((row) => {
        if (scanned.value.has(row.id)) return;
        row.received_quantity = 0;
        row.condition = 'Unserved';
    });
};

/* ------------------------------------------------------------------ *
 | Scanning. A piece label (barcode, serial or QR) accounts for that piece;
 | a box label for every piece of that box sent in THIS receiving. A scan
 | sets the line to its full quantity and leaves a Damaged mark alone.
 |
 | The QR labels this system prints are multi-line, and a single-line
 | input receives each line as its own Enter — so the lines of one scan
 | are gathered and only reported "not found" once the scan goes quiet.
 * ------------------------------------------------------------------ */
const scanned = ref(new Set());
const scanInput = ref('');
const scanInputRef = ref(null);
const scanFeedback = ref(null);
let feedbackTimer = null;
let burst = { lines: [], matched: false };
let burstTimer = null;
let idleTimer = null;

const normalizeCode = (value) => String(value ?? '').replace(/\r\n?/g, '\n').trim().toLowerCase();

/** Every string in a scanned payload that could BE the code. */
const codeCandidates = (text) => {
    const raw = String(text || '');
    const lines = raw.split(/[\r\n]+/);
    const out = [raw, ...lines];
    for (const line of lines) {
        const labelled = line.match(/^\s*(?:barcode|serial\s*no\.?|qr\s*code)\s*:\s*(.+)$/i);
        if (labelled) out.push(labelled[1]);
    }
    // "n/a" is what a label prints for a missing serial; it would match every
    // piece that has none.
    return out.map(normalizeCode).filter((value) => value && value !== 'n/a');
};

const scanLookup = computed(() => {
    const map = new Map();
    rows.value.forEach((row) => {
        [row.barcode, row.serial_no, row.qrcode].forEach((code) => {
            const key = normalizeCode(code);
            if (key && key !== 'n/a' && !map.has(key)) map.set(key, { ids: [row.id], pack: null });
        });
    });
    rows.value.forEach((row) => {
        const key = normalizeCode(row.pack?.barcode);
        if (!key) return;
        if (!map.get(key)?.pack) map.set(key, { ids: [], pack: row.pack });
        map.get(key).ids.push(row.id);
    });
    return map;
});

const matchScan = (text) => {
    for (const candidate of codeCandidates(text)) {
        const hit = scanLookup.value.get(candidate);
        if (hit) return hit;
    }
    return null;
};

const say = (type, message) => {
    scanFeedback.value = { type, message };
    if (feedbackTimer) clearTimeout(feedbackTimer);
    feedbackTimer = setTimeout(() => { scanFeedback.value = null; }, 3000);
};

const applyScan = (hit) => {
    const next = new Set(scanned.value);
    let fresh = 0;
    hit.ids.forEach((id) => {
        if (!next.has(id)) fresh += 1;
        next.add(id);
        const row = rows.value.find((r) => r.id === id);
        if (!row) return;
        row.received_quantity = row.transferred_quantity;
        if (row.condition === 'Unserved') row.condition = 'Good';
    });
    scanned.value = next;

    if (!fresh) say('info', 'Already scanned.');
    else if (hit.pack) say('success', `✓ ${hit.pack.bulk_uom || 'Box'} ${hit.pack.barcode} · ${plural(hit.ids.length, 'piece')}`);
    else say('success', '✓ Scanned');
};

const settleBurst = () => {
    burstTimer = null;
    if (!burst.matched && burst.lines.length) {
        const late = matchScan(burst.lines.join('\n'));
        if (late) applyScan(late);
        else say('error', 'That code is not in this receiving.');
    }
    burst = { lines: [], matched: false };
};

/** Enter, from a scanner or a person: try the line now, judge the scan when it ends. */
const onScanEnter = () => {
    if (idleTimer) { clearTimeout(idleTimer); idleTimer = null; }
    const line = scanInput.value;
    scanInput.value = '';
    if (!line.trim()) return;

    burst.lines.push(line);
    const hit = matchScan(line);
    if (hit) {
        burst.matched = true;
        applyScan(hit);
    }
    if (burstTimer) clearTimeout(burstTimer);
    burstTimer = setTimeout(settleBurst, 350);
};

// Scanners that send no Enter still land once typing stops. Silent on a
// miss: it may be a person halfway through typing a code.
watch(scanInput, (value) => {
    if (idleTimer) clearTimeout(idleTimer);
    if (!String(value || '').trim()) return;
    idleTimer = setTimeout(() => {
        idleTimer = null;
        const hit = matchScan(scanInput.value);
        if (hit) {
            scanInput.value = '';
            applyScan(hit);
        }
    }, 400);
});

function resetScan() {
    scanned.value = new Set();
    scanInput.value = '';
    scanFeedback.value = null;
    burst = { lines: [], matched: false };
    [feedbackTimer, burstTimer, idleTimer].forEach((timer) => timer && clearTimeout(timer));
    feedbackTimer = burstTimer = idleTimer = null;
}

/* ------------------------------------------------------------------ *
 | Post. Confirmed inline rather than through the shared confirm dialog,
 | which would open underneath this modal.
 * ------------------------------------------------------------------ */
const errorMessages = computed(() => [...new Set(Object.values(form.errors))]);

const askToPost = () => {
    form.clearErrors();
    modal.confirming = true;
};

const post = () => {
    form.entries = rows.value.map((row) => ({
        id: row.id,
        received_quantity: row.condition === 'Unserved' ? 0 : row.received_quantity,
        condition: row.condition,
        damage_notes: row.condition === 'Good' ? null : (row.damage_notes || null),
    }));
    form.post(route('vendor.campaigns.receivings.receive', modal.header.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => closeModal(),
        onError: () => { modal.confirming = false; },
    });
};

/* ------------------------------------------------------------------ *
 | Sent from this store, reported unserved by the destination. One row per
 | receiving; the modal settles some or all of its lines.
 * ------------------------------------------------------------------ */
const outgoingGroups = computed(() => {
    const groups = new Map();
    for (const line of props.outgoing) {
        const key = `${line.receiving_no}|${line.receiving_date}|${line.destination_location}`;
        if (!groups.has(key)) {
            groups.set(key, {
                key,
                receiving_no: line.receiving_no,
                receiving_date: line.receiving_date,
                destination_location: line.destination_location,
                lines: [],
                units: 0,
                reporters: new Set(),
            });
        }
        const group = groups.get(key);
        group.lines.push(line);
        group.units += Number(line.transferred_quantity) || 0;
        if (line.reported_by) group.reporters.add(line.reported_by);
    }
    return [...groups.values()].map((group) => ({
        ...group,
        reported_by: [...group.reporters].join(', '),
        items: [...new Set(group.lines.map((line) => assetLabel(line.asset)))].join(', '),
    }));
});

const outgoingTable = useClientTable(() => outgoingGroups.value, ['receiving_no', 'destination_location', 'items', 'reported_by']);

const resolveModal = reactive({ open: false, group: null, confirming: null });
const resolveForm = useForm({ action: null, ids: [], reason: '' });

const openResolve = (group) => {
    resolveForm.clearErrors();
    resolveForm.reset();
    resolveForm.ids = group.lines.map((line) => line.id);
    Object.assign(resolveModal, { open: true, group, confirming: null });
};
const closeResolve = () => { resolveModal.open = false; resolveModal.confirming = null; };

const toggleResolveLine = (id) => {
    resolveForm.ids = resolveForm.ids.includes(id)
        ? resolveForm.ids.filter((x) => x !== id)
        : [...resolveForm.ids, id];
};

const resolveUnits = computed(() => (resolveModal.group?.lines || [])
    .filter((line) => resolveForm.ids.includes(line.id))
    .reduce((sum, line) => sum + (Number(line.transferred_quantity) || 0), 0));

const resolveErrors = computed(() => [...new Set(Object.values(resolveForm.errors))]);

const askToResolve = (action) => {
    resolveForm.clearErrors();
    if (!resolveForm.ids.length) {
        resolveForm.setError('ids', 'Tick at least one line.');
        return;
    }
    if (!resolveForm.reason.trim()) {
        resolveForm.setError('reason', 'Say why, so the trail explains it.');
        return;
    }
    resolveModal.confirming = action;
};

const submitResolve = () => {
    resolveForm.action = resolveModal.confirming;
    resolveForm.post(route('vendor.campaigns.outgoing.resolve'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => closeResolve(),
        onError: () => { resolveModal.confirming = null; },
    });
};
</script>

<template>
    <div>
        <CampaignTable
            title="Receive Stock"
            subtitle="Reward stock transferred to this store. Confirm what arrived before it can be redeemed."
            search-placeholder="Search receiving no., origin..."
            empty-message="No stock has been transferred to this store yet."
            :data="table.data"
            :search="table.search"
            :current-page="table.currentPage"
            :last-page="table.lastPage"
            :per-page="table.perPage"
            :showing-text="table.showingText"
            @update:search="table.search = $event"
            @goToPage="table.goToPage"
            @changePerPage="table.changePerPage"
        >
            <template #actions>
                <div class="inline-flex rounded-xl border border-slate-200 bg-slate-50/70 p-0.5">
                    <button
                        v-for="f in statusFilters"
                        :key="f.id"
                        type="button"
                        :class="[
                            'whitespace-nowrap rounded-lg px-3 py-1.5 text-xs font-bold transition',
                            statusFilter === f.id ? 'bg-white text-emerald-700 shadow-sm ring-1 ring-slate-200' : 'text-slate-500 hover:text-slate-800',
                        ]"
                        @click="statusFilter = f.id"
                    >
                        {{ f.label }}
                    </button>
                </div>
            </template>
            <template #header>
                <tr class="text-left text-xs font-bold uppercase tracking-wider text-slate-500">
                    <th class="px-6 py-3.5">Receiving Date</th>
                    <th class="px-6 py-3.5">Receiving No.</th>
                    <th class="px-6 py-3.5">Status</th>
                    <th class="px-6 py-3.5">Items</th>
                    <th class="px-6 py-3.5">Route</th>
                    <th class="px-6 py-3.5">Qty</th>
                    <th class="px-6 py-3.5 text-right">Actions</th>
                </tr>
            </template>
            <template #body="{ data }">
                <tr v-for="r in data" :key="r.id" class="transition-colors hover:bg-slate-50/60">
                    <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-slate-700">{{ formatDate(r.receiving_date) }}</td>
                    <td class="whitespace-nowrap px-6 py-4 text-sm font-bold text-slate-900">{{ r.receiving_no || '—' }}</td>
                    <td class="px-6 py-4 text-sm">
                        <span :class="['inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-bold', statusClass(r.status)]">
                            <span class="h-1.5 w-1.5 rounded-full" :class="statusDotClass(r.status)"></span>
                            {{ r.status }}
                        </span>
                        <span v-if="r.status === PENDING && r.in_transit_quantity" class="mt-1 block text-[11px] font-semibold text-rose-500">reported unserved<template v-if="r.received_by"> by {{ r.received_by }}</template></span>
                        <span v-else-if="r.received_by" class="mt-1 block text-[11px] font-semibold text-slate-400">by {{ r.received_by }} · {{ formatDateTime(r.received_at) }}</span>
                    </td>
                    <td class="whitespace-nowrap px-6 py-4">
                        <p class="text-sm font-bold text-slate-900">{{ plural(r.asset_count, 'item') }}</p>
                        <p class="text-xs font-medium text-slate-500">{{ plural(r.transferred_quantity, 'unit') }}</p>
                    </td>
                    <td class="whitespace-nowrap px-6 py-4 text-sm font-semibold text-slate-700">
                        {{ r.origin_location || '—' }}
                        <span class="mx-1 text-slate-400">→</span>
                        <span class="text-emerald-700">{{ r.destination_location }}</span>
                    </td>
                    <td class="whitespace-nowrap px-6 py-4 text-sm">
                        <template v-if="r.status === PENDING">
                            <span v-if="r.transferred_quantity > r.in_transit_quantity" class="block font-bold text-amber-700">{{ r.transferred_quantity - r.in_transit_quantity }} to receive</span>
                            <span v-if="r.in_transit_quantity" class="block text-[11px] font-bold text-rose-600">{{ r.in_transit_quantity }} unserved · in transit, waiting on {{ r.origin_location }}</span>
                        </template>
                        <span v-else-if="r.status === 'Declined'" class="text-xs font-bold text-slate-500">{{ r.transferred_quantity }} returned to {{ r.origin_location }}</span>
                        <template v-else>
                            <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-black text-slate-700">{{ r.received_quantity }} / {{ r.transferred_quantity }}</span>
                            <span v-if="r.damaged_quantity" class="ml-1.5 text-[11px] font-bold text-orange-600">{{ r.damaged_quantity }} damaged</span>
                            <span v-if="r.written_off_quantity" class="ml-1.5 text-[11px] font-bold text-rose-600">{{ r.written_off_quantity }} written off</span>
                        </template>
                    </td>
                    <td class="px-6 py-4 text-right text-sm">
                        <button
                            v-if="r.status === PENDING"
                            type="button"
                            class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 px-3.5 py-2 text-xs font-bold text-white shadow-sm transition hover:from-emerald-500 hover:to-teal-500"
                            @click="openReceiving(r, false)"
                        >
                            <QrCodeIcon class="h-3.5 w-3.5" />
                            Receive
                        </button>
                        <button v-else type="button" title="View" class="rounded-lg p-1.5 text-slate-500 transition hover:bg-slate-100 hover:text-slate-800" @click="openReceiving(r, true)">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                        </button>
                    </td>
                </tr>
            </template>
        </CampaignTable>

        <!-- Sent from this store, reported unserved: the source settles them. -->
        <div class="mt-6">
            <CampaignTable
                title="Sent From This Store — Unserved"
                :subtitle="`Items ${store.code} transferred that the destination reported as not received. They are still deducted here. If one never left (or came back), return it to stock; if it is lost, write it off.`"
                search-placeholder="Search receiving no., destination, item..."
                empty-message="Nothing this store sent is waiting to be settled."
                :data="outgoingTable.data"
                :search="outgoingTable.search"
                :current-page="outgoingTable.currentPage"
                :last-page="outgoingTable.lastPage"
                :per-page="outgoingTable.perPage"
                :showing-text="outgoingTable.showingText"
                @update:search="outgoingTable.search = $event"
                @goToPage="outgoingTable.goToPage"
                @changePerPage="outgoingTable.changePerPage"
            >
                <template #header>
                    <tr class="text-left text-xs font-bold uppercase tracking-wider text-slate-500">
                        <th class="px-6 py-3.5">Receiving Date</th>
                        <th class="px-6 py-3.5">Receiving No.</th>
                        <th class="px-6 py-3.5">Route</th>
                        <th class="px-6 py-3.5">Items</th>
                        <th class="px-6 py-3.5">Unserved</th>
                        <th class="px-6 py-3.5">Reported By</th>
                        <th class="px-6 py-3.5 text-right">Actions</th>
                    </tr>
                </template>
                <template #body="{ data }">
                    <tr v-for="g in data" :key="g.key" class="transition-colors hover:bg-slate-50/60">
                        <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-slate-700">{{ formatDate(g.receiving_date) }}</td>
                        <td class="whitespace-nowrap px-6 py-4 text-sm font-bold text-slate-900">{{ g.receiving_no || '—' }}</td>
                        <td class="whitespace-nowrap px-6 py-4 text-sm font-semibold text-slate-700">
                            <span class="text-emerald-700">{{ store.code }}</span>
                            <span class="mx-1 text-slate-400">→</span>
                            {{ g.destination_location }}
                        </td>
                        <td class="px-6 py-4 text-sm text-slate-800">{{ g.items }}</td>
                        <td class="whitespace-nowrap px-6 py-4 text-sm font-black text-rose-600">{{ plural(g.units, 'unit') }}</td>
                        <td class="px-6 py-4 text-xs font-medium text-slate-500">{{ g.reported_by || '—' }}</td>
                        <td class="px-6 py-4 text-right">
                            <button
                                type="button"
                                class="whitespace-nowrap rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 shadow-sm transition hover:bg-slate-50"
                                @click="openResolve(g)"
                            >
                                Settle
                            </button>
                        </td>
                    </tr>
                </template>
            </CampaignTable>
        </div>

        <!-- Settle (return to stock / write off) Modal -->
        <Modal :show="resolveModal.open" max-width="2xl" :closeable="false" @close="closeResolve">
            <div class="space-y-4 p-6 sm:p-7">
                <div class="border-b border-slate-100 pb-3">
                    <h3 class="text-lg font-black tracking-tight text-slate-900">Settle Unserved — {{ resolveModal.group?.receiving_no || '—' }}</h3>
                    <p class="mt-0.5 text-xs font-medium text-slate-500">
                        Sent from <strong class="text-slate-800">{{ store.code }}</strong> to <strong class="text-slate-800">{{ resolveModal.group?.destination_location }}</strong>,
                        reported not received{{ resolveModal.group?.reported_by ? ` by ${resolveModal.group.reported_by}` : '' }}.
                    </p>
                </div>

                <div class="overflow-hidden rounded-xl border border-slate-200">
                    <table class="min-w-full divide-y divide-slate-100 text-xs">
                        <thead class="bg-slate-50/80">
                            <tr class="text-left text-[11px] font-bold uppercase tracking-wider text-slate-500">
                                <th class="w-10 px-3.5 py-2.5"></th>
                                <th class="px-3.5 py-2.5">Item</th>
                                <th class="px-3.5 py-2.5">Serial / Barcode</th>
                                <th class="px-3.5 py-2.5">Qty</th>
                                <th class="px-3.5 py-2.5">Reason given</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="line in resolveModal.group?.lines || []" :key="line.id" class="cursor-pointer hover:bg-slate-50/60" @click="toggleResolveLine(line.id)">
                                <td class="px-3.5 py-2.5">
                                    <input type="checkbox" :checked="resolveForm.ids.includes(line.id)" class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" @click.stop="toggleResolveLine(line.id)" />
                                </td>
                                <td class="px-3.5 py-2.5 font-semibold text-slate-800">{{ assetLabel(line.asset) }}</td>
                                <td class="px-3.5 py-2.5 font-mono font-bold text-slate-700">{{ pieceLabel(line) }}</td>
                                <td class="px-3.5 py-2.5 font-black text-slate-900">{{ line.transferred_quantity }}</td>
                                <td class="px-3.5 py-2.5 text-slate-500">{{ line.reason || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div>
                    <label class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-600">Reason <span class="text-rose-500">*</span></label>
                    <textarea
                        v-model="resolveForm.reason"
                        rows="2"
                        maxlength="2000"
                        placeholder="e.g. Found in the stockroom — never loaded on the truck"
                        class="w-full rounded-xl border border-slate-200 bg-slate-50/70 px-3.5 py-2 text-xs font-medium text-slate-900 outline-none focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100"
                    ></textarea>
                </div>

                <div v-if="resolveErrors.length" class="space-y-1 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs font-semibold text-rose-800">
                    <p v-for="message in resolveErrors" :key="message">{{ message }}</p>
                </div>

                <div v-if="resolveModal.confirming === 'return'" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-xs font-medium text-emerald-900">
                    <p class="text-sm font-black">Return {{ plural(resolveUnits, 'unit') }} to {{ store.code }} stock?</p>
                    <p class="mt-1">They are credited back to {{ store.code }} and can be transferred or redeemed again. The lines close as returned to source.</p>
                </div>
                <div v-else-if="resolveModal.confirming === 'write_off'" class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-xs font-medium text-rose-900">
                    <p class="text-sm font-black">Write off {{ plural(resolveUnits, 'unit') }} as lost in transit?</p>
                    <p class="mt-1">{{ store.code }} stays deducted and the loss is recorded on the trail with your reason. This cannot be undone here.</p>
                </div>

                <div class="flex flex-wrap justify-end gap-2.5 pt-1">
                    <template v-if="!resolveModal.confirming">
                        <button type="button" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="closeResolve">Cancel</button>
                        <button type="button" class="rounded-xl border border-rose-300 bg-white px-4 py-2 text-xs font-black uppercase tracking-wider text-rose-700 hover:bg-rose-50" @click="askToResolve('write_off')">
                            Write Off
                        </button>
                        <button
                            type="button"
                            class="rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 px-4 py-2 text-xs font-black uppercase tracking-wider text-white shadow-md shadow-emerald-600/20 hover:from-emerald-500 hover:to-teal-500"
                            @click="askToResolve('return')"
                        >
                            Return to Stock
                        </button>
                    </template>
                    <template v-else>
                        <button type="button" :disabled="resolveForm.processing" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-50" @click="resolveModal.confirming = null">Back</button>
                        <button
                            type="button"
                            :disabled="resolveForm.processing"
                            :class="[
                                'rounded-xl px-4 py-2 text-xs font-black uppercase tracking-wider text-white shadow-md disabled:opacity-50',
                                resolveModal.confirming === 'return'
                                    ? 'bg-gradient-to-r from-emerald-600 to-teal-600 shadow-emerald-600/20 hover:from-emerald-500 hover:to-teal-500'
                                    : 'bg-rose-600 shadow-rose-600/20 hover:bg-rose-500',
                            ]"
                            @click="submitResolve"
                        >
                            {{ resolveForm.processing ? 'Saving…' : resolveModal.confirming === 'return' ? 'Confirm Return' : 'Confirm Write Off' }}
                        </button>
                    </template>
                </div>
            </div>
        </Modal>

        <!-- Receive / View Modal. Not closeable from the backdrop: a stray click
             must not throw away a half-counted delivery. -->
        <Modal :show="modal.open" max-width="6xl" :closeable="false" @close="closeModal">
            <div class="space-y-5 p-6 sm:p-7">
                <div class="flex flex-col gap-3 border-b border-slate-100 pb-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h3 class="text-lg font-black tracking-tight text-slate-900">
                            {{ modal.readOnly ? 'Receiving' : 'Receive Stock' }} — {{ modal.header.receiving_no || '—' }}
                        </h3>
                        <p class="mt-0.5 text-xs font-medium text-slate-500">
                            {{ formatDate(modal.header.receiving_date) }} · from
                            <strong class="text-slate-800">{{ modal.header.origin_location || '—' }}</strong> to
                            <strong class="text-emerald-700">{{ modal.header.destination_location }}</strong>
                        </p>
                    </div>
                    <span :class="['inline-flex items-center gap-1.5 self-start whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-bold', statusClass(modal.header.status)]">
                        <span class="h-1.5 w-1.5 rounded-full" :class="statusDotClass(modal.header.status)"></span>
                        {{ modal.header.status }}
                    </span>
                </div>

                <p v-if="modal.loading" class="py-10 text-center text-xs font-medium text-slate-400">Loading items…</p>

                <template v-else>
                    <!-- Scan strip + bulk actions -->
                    <div v-if="!modal.readOnly" class="space-y-3 rounded-2xl border border-emerald-200 bg-emerald-50/50 p-4">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                            <div class="relative flex-1">
                                <QrCodeIcon class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-emerald-600" />
                                <input
                                    ref="scanInputRef"
                                    v-model="scanInput"
                                    type="text"
                                    autocomplete="off"
                                    class="w-full rounded-xl border border-emerald-200 bg-white py-2.5 pl-10 pr-3.5 font-mono text-sm font-bold text-slate-900 placeholder:font-sans placeholder:font-medium placeholder:text-slate-400 outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100"
                                    placeholder="Scan a barcode, serial or box label — or type it and press Enter"
                                    @keydown.enter.prevent="onScanEnter"
                                />
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" class="rounded-xl border border-emerald-300 bg-white px-3.5 py-2 text-xs font-bold text-emerald-800 shadow-sm transition hover:bg-emerald-50" @click="receiveAllGood">
                                    Receive all as Good
                                </button>
                                <button
                                    type="button"
                                    :disabled="!scanned.size"
                                    title="Set every line you have not scanned to Unserved"
                                    class="rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
                                    @click="markUnscannedUnserved"
                                >
                                    Unscanned → Unserved
                                </button>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                            <p class="font-semibold text-emerald-900">
                                Scanned {{ scanned.size }} of {{ plural(rows.length, 'line') }}.
                                <span class="font-medium text-emerald-800/80">Scanning is optional — you can also set each quantity and condition by hand.</span>
                            </p>
                            <p
                                v-if="scanFeedback"
                                :class="[
                                    'rounded-lg px-2.5 py-1 font-bold',
                                    scanFeedback.type === 'error' ? 'bg-rose-100 text-rose-800' : scanFeedback.type === 'info' ? 'bg-slate-100 text-slate-700' : 'bg-emerald-100 text-emerald-800',
                                ]"
                            >
                                {{ scanFeedback.message }}
                            </p>
                        </div>
                    </div>

                    <p v-if="!modal.readOnly && inTransitCount" class="rounded-xl border border-rose-200 bg-rose-50/70 p-3 text-xs font-medium text-rose-900">
                        {{ plural(inTransitCount, 'line') }} {{ inTransitCount === 1 ? 'was' : 'were' }} already reported unserved and {{ inTransitCount === 1 ? 'is' : 'are' }} still in transit.
                        If {{ inTransitCount === 1 ? 'it has' : 'they have' }} arrived, scan {{ inTransitCount === 1 ? 'it' : 'them' }} or set Good. Otherwise leave {{ inTransitCount === 1 ? 'it' : 'them' }} —
                        <strong>{{ modal.header.origin_location }}</strong> will return {{ inTransitCount === 1 ? 'it' : 'them' }} to stock or write {{ inTransitCount === 1 ? 'it' : 'them' }} off.
                    </p>

                    <!-- Items -->
                    <div class="max-h-[55vh] space-y-4 overflow-y-auto pr-1">
                        <div v-for="group in assetGroups" :key="group.key" class="overflow-hidden rounded-2xl border border-slate-200">
                            <div class="flex items-center justify-between gap-3 bg-slate-50/80 px-4 py-3">
                                <div>
                                    <p class="text-sm font-black text-slate-900">{{ assetLabel(group.asset) }}</p>
                                    <p v-if="group.asset?.description" class="text-xs font-medium text-slate-500">{{ group.asset.description }}</p>
                                </div>
                                <span class="whitespace-nowrap text-xs font-bold text-slate-600">
                                    {{ groupSum(group, 'received_quantity') }} / {{ plural(groupSum(group, 'transferred_quantity'), 'unit') }}
                                </span>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-slate-100 text-xs">
                                    <thead class="bg-white">
                                        <tr class="text-left text-[11px] font-bold uppercase tracking-wider text-slate-500">
                                            <th class="px-4 py-2.5">Serial / Barcode</th>
                                            <th class="px-4 py-2.5">Sent</th>
                                            <th class="px-4 py-2.5">Received</th>
                                            <th class="px-4 py-2.5">Condition</th>
                                            <th class="px-4 py-2.5">Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <tr v-for="row in group.rows" :key="row.id" :class="scanned.has(row.id) ? 'bg-emerald-50/50' : ''">
                                            <td class="px-4 py-2.5">
                                                <div class="font-mono font-bold text-slate-800">{{ pieceLabel(row) }}</div>
                                                <div v-if="row.serial_no && row.barcode" class="font-mono text-[11px] text-slate-500">{{ row.barcode }}</div>
                                                <div v-if="row.pack" class="text-[11px] font-semibold text-slate-400">{{ row.pack.bulk_uom || 'Box' }} {{ row.pack.barcode }}</div>
                                                <span v-if="scanned.has(row.id)" class="mt-1 inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider text-emerald-800">✓ Scanned</span>
                                                <span v-else-if="row.in_transit" class="mt-1 inline-flex rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider text-rose-700 ring-1 ring-rose-600/20">In transit · reported unserved</span>
                                            </td>
                                            <td class="px-4 py-2.5 text-sm font-black text-slate-900">{{ row.transferred_quantity }}</td>
                                            <td class="px-4 py-2.5">
                                                <input
                                                    v-if="!modal.readOnly"
                                                    type="number"
                                                    min="0"
                                                    :max="row.transferred_quantity"
                                                    :value="row.received_quantity"
                                                    :disabled="row.condition === 'Unserved'"
                                                    class="w-20 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm font-bold text-slate-900 outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 disabled:bg-slate-100 disabled:text-slate-400"
                                                    @change="setQuantity(row, $event.target.value)"
                                                />
                                                <span v-else class="text-sm font-black text-slate-900">{{ row.received_quantity }}</span>
                                            </td>
                                            <td class="px-4 py-2.5">
                                                <div v-if="!modal.readOnly" class="inline-flex rounded-xl border border-slate-200 bg-white p-0.5">
                                                    <button
                                                        v-for="c in CONDITIONS"
                                                        :key="c"
                                                        type="button"
                                                        :class="['rounded-lg px-2.5 py-1 text-[11px] font-bold transition', conditionButtonClass(c, row.condition === c)]"
                                                        @click="setCondition(row, c)"
                                                    >
                                                        {{ c }}
                                                    </button>
                                                </div>
                                                <span v-else :class="['inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-bold', conditionBadgeClass(row.condition)]">{{ row.condition }}</span>
                                            </td>
                                            <td class="px-4 py-2.5">
                                                <input
                                                    v-if="!modal.readOnly && row.condition !== 'Good'"
                                                    v-model="row.damage_notes"
                                                    type="text"
                                                    maxlength="1000"
                                                    :placeholder="row.condition === 'Damaged' ? 'Describe the damage' : 'Why it did not arrive (optional)'"
                                                    class="w-full min-w-44 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-900 outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100"
                                                />
                                                <span v-else class="text-slate-500">{{ row.damage_notes || '—' }}</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Totals -->
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div class="rounded-xl border border-slate-200 bg-white p-3">
                            <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Sent</p>
                            <p class="mt-1 text-xl font-black text-slate-900">{{ totals.sent }}</p>
                        </div>
                        <div class="rounded-xl border border-emerald-200 bg-emerald-50/50 p-3">
                            <p class="text-[10px] font-black uppercase tracking-widest text-emerald-700">Good</p>
                            <p class="mt-1 text-xl font-black text-emerald-700">{{ totals.good }}</p>
                        </div>
                        <div class="rounded-xl border border-orange-200 bg-orange-50/50 p-3">
                            <p class="text-[10px] font-black uppercase tracking-widest text-orange-700">Damaged</p>
                            <p class="mt-1 text-xl font-black text-orange-600">{{ totals.damaged }}</p>
                        </div>
                        <div class="rounded-xl border border-rose-200 bg-rose-50/50 p-3">
                            <p class="text-[10px] font-black uppercase tracking-widest text-rose-700">Unserved</p>
                            <p class="mt-1 text-xl font-black text-rose-600">{{ totals.unserved }}</p>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-600">Remarks</label>
                        <textarea
                            v-if="!modal.readOnly"
                            v-model="form.remarks"
                            rows="2"
                            maxlength="2000"
                            class="w-full rounded-xl border border-slate-200 bg-slate-50/70 px-3.5 py-2 text-xs font-medium text-slate-900 outline-none focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100"
                        ></textarea>
                        <p v-else class="text-xs font-medium text-slate-700">{{ modal.header.remarks || '—' }}</p>
                    </div>

                    <p v-if="modal.readOnly && modal.header.received_by" class="text-xs font-medium text-slate-500">
                        Received by <strong class="text-slate-800">{{ modal.header.received_by }}</strong> on {{ formatDateTime(modal.header.received_at) }}.
                    </p>

                    <div v-if="errorMessages.length" class="space-y-1 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs font-semibold text-rose-800">
                        <p v-for="message in errorMessages" :key="message">{{ message }}</p>
                    </div>

                    <div v-if="modal.confirming" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs font-medium text-amber-900">
                        <p class="text-sm font-black">Post {{ modal.header.receiving_no || 'this receiving' }}?</p>
                        <p class="mt-1">
                            {{ totals.good + totals.damaged }} of {{ plural(totals.sent, 'unit') }} will be added to {{ store.code }}'s stock
                            <span v-if="totals.damaged">({{ totals.damaged }} damaged — kept off the reward shelf)</span>.
                            <template v-if="totals.unserved">
                                {{ totals.unserved }} unserved stay in transit — recorded on the trail — until {{ totals.unserved === 1 ? 'it arrives' : 'they arrive' }} or {{ modal.header.origin_location }} returns {{ totals.unserved === 1 ? 'it' : 'them' }} to stock or writes {{ totals.unserved === 1 ? 'it' : 'them' }} off.
                            </template>
                            Received units cannot be changed here.
                        </p>
                    </div>
                </template>

                <div class="flex justify-end gap-2.5 pt-1">
                    <button v-if="modal.readOnly || modal.loading" type="button" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="closeModal">Close</button>
                    <template v-else-if="!modal.confirming">
                        <button type="button" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="closeModal">Cancel</button>
                        <button
                            type="button"
                            :disabled="!rows.length"
                            class="rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 px-4 py-2 text-xs font-black uppercase tracking-wider text-white shadow-md shadow-emerald-600/20 hover:from-emerald-500 hover:to-teal-500 disabled:opacity-50"
                            @click="askToPost"
                        >
                            Post Receiving
                        </button>
                    </template>
                    <template v-else>
                        <button type="button" :disabled="form.processing" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-50" @click="modal.confirming = false">Back</button>
                        <button
                            type="button"
                            :disabled="form.processing"
                            class="rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 px-4 py-2 text-xs font-black uppercase tracking-wider text-white shadow-md shadow-emerald-600/20 hover:from-emerald-500 hover:to-teal-500 disabled:opacity-50"
                            @click="post"
                        >
                            {{ form.processing ? 'Posting…' : 'Confirm & Post' }}
                        </button>
                    </template>
                </div>
            </div>
        </Modal>
    </div>
</template>
