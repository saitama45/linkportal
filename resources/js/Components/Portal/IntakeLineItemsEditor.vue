<script setup>
import { computed } from 'vue';
import { PlusIcon, TrashIcon } from '@heroicons/vue/24/outline';

/**
 * Line-item editor for OCR intake validation. Columns are driven by the
 * template (custom names + unlimited count are supported); the five standard
 * keys carry numeric parsing and the Line Total drives the row-sum footer.
 * Per-cell confidence tinting comes from the raw extraction rows.
 */
const DEFAULT_COLUMNS = [
    { key: 'description', label: 'Description' },
    { key: 'quantity', label: 'Quantity' },
    { key: 'uom', label: 'UOM' },
    { key: 'unit_price', label: 'Unit Price' },
    { key: 'line_total', label: 'Line Total' },
];

const NUMERIC_KEYS = new Set(['quantity', 'unit_price', 'line_total']);

const props = defineProps({
    modelValue: { type: Array, default: () => [] }, // [{<colKey>: value, ...}]
    columns: { type: Array, default: () => ([]) }, // [{key, label, numeric?}] — empty falls back to DEFAULT_COLUMNS
    extractionRows: { type: Array, default: () => [] }, // raw extraction rows with cells.{key}.confidence
    lowThreshold: { type: Number, default: 0.75 },
    readonly: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const cols = computed(() => (props.columns?.length ? props.columns : DEFAULT_COLUMNS));
const isNumeric = (col) => (col.numeric ?? NUMERIC_KEYS.has(col.key));
const hasLineTotal = computed(() => cols.value.some((c) => c.key === 'line_total'));

const items = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value),
});

const confidence = (rowIndex, key) => props.extractionRows[rowIndex]?.cells?.[key]?.confidence;

const cellClass = (rowIndex, key) => {
    const conf = confidence(rowIndex, key);
    if (conf == null) return 'border-slate-200 bg-slate-50';
    if (conf >= 0.9) return 'border-emerald-200 bg-emerald-50/60';
    if (conf >= props.lowThreshold) return 'border-amber-300 bg-amber-50';
    return 'border-red-300 bg-red-50';
};

const inputClass = (rowIndex, key) => [
    'block w-full rounded-lg border px-2.5 py-1.5 text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20',
    cellClass(rowIndex, key),
];

const blankItem = () => Object.fromEntries(cols.value.map((c) => [c.key, isNumeric(c) ? null : '']));

const addItem = () => {
    items.value = [...items.value, blankItem()];
};

const removeItem = (index) => {
    items.value = items.value.filter((_, i) => i !== index);
};

const update = (index, col, rawValue) => {
    const value = isNumeric(col) ? (rawValue === '' ? null : Number(rawValue)) : rawValue;
    const next = [...items.value];
    next[index] = { ...next[index], [col.key]: value };
    items.value = next;
};

const lineSum = computed(() => items.value.reduce((sum, item) => sum + (Number(item.line_total) || 0), 0));
const money = (value) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

defineExpose({ lineSum });
</script>

<template>
    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
        <div class="flex items-center justify-between border-b border-slate-100 bg-slate-50/60 px-5 py-3">
            <h4 class="text-xs font-black uppercase tracking-widest text-slate-600">Line Items</h4>
            <button v-if="!readonly" type="button"
                class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-3 py-1.5 text-xs font-bold text-white shadow-sm transition-all hover:bg-emerald-700"
                @click="addItem">
                <PlusIcon class="h-4 w-4" />
                Add Row
            </button>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-100">
                <thead class="bg-slate-50">
                    <tr>
                        <th v-for="col in cols" :key="col.key"
                            class="px-3 py-2.5 text-left text-[10px] font-black uppercase tracking-widest text-slate-500"
                            :class="col.key === 'description' ? 'min-w-[240px]' : 'min-w-[7rem]'">
                            {{ col.label }}
                        </th>
                        <th class="w-10"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <tr v-for="(item, index) in items" :key="index" class="align-top">
                        <td v-for="col in cols" :key="col.key" class="px-3 py-2">
                            <input :value="item[col.key]" :type="isNumeric(col) ? 'number' : 'text'"
                                :step="isNumeric(col) ? 'any' : undefined" :disabled="readonly"
                                :class="inputClass(index, col.key)"
                                @input="update(index, col, $event.target.value)" />
                        </td>
                        <td class="px-1 py-2">
                            <button v-if="!readonly" type="button"
                                class="rounded-lg p-1.5 text-slate-300 transition-all hover:bg-red-50 hover:text-red-500"
                                @click="removeItem(index)">
                                <TrashIcon class="h-4 w-4" />
                            </button>
                        </td>
                    </tr>
                    <tr v-if="items.length === 0">
                        <td :colspan="cols.length + 1" class="px-4 py-8 text-center text-sm font-medium text-slate-400">
                            No line items extracted. Add rows manually if the document has them.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="hasLineTotal" class="flex justify-end border-t border-slate-100 bg-slate-50/60 px-5 py-3 text-sm font-bold text-slate-700">
            Line sum: {{ money(lineSum) }}
        </div>
    </div>
</template>
