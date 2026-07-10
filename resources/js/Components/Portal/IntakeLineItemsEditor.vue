<script setup>
import { computed } from 'vue';
import { PlusIcon, TrashIcon } from '@heroicons/vue/24/outline';

/**
 * Line-item editor for OCR intake validation. Free-text UOM, explicit
 * line_total (as extracted), and per-cell confidence tinting from the raw
 * extraction rows so low-confidence cells stand out.
 */
const props = defineProps({
    modelValue: { type: Array, default: () => [] }, // [{description, quantity, uom, unit_price, line_total}]
    extractionRows: { type: Array, default: () => [] }, // raw extraction rows with cells.{key}.confidence
    lowThreshold: { type: Number, default: 0.75 },
    readonly: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

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

const addItem = () => {
    items.value = [...items.value, { description: '', quantity: null, uom: '', unit_price: null, line_total: null }];
};

const removeItem = (index) => {
    items.value = items.value.filter((_, i) => i !== index);
};

const update = (index, key, value) => {
    const next = [...items.value];
    next[index] = { ...next[index], [key]: value };
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
                        <th class="min-w-[240px] px-3 py-2.5 text-left text-[10px] font-black uppercase tracking-widest text-slate-500">Description</th>
                        <th class="w-24 px-3 py-2.5 text-left text-[10px] font-black uppercase tracking-widest text-slate-500">Qty</th>
                        <th class="w-24 px-3 py-2.5 text-left text-[10px] font-black uppercase tracking-widest text-slate-500">UOM</th>
                        <th class="w-32 px-3 py-2.5 text-left text-[10px] font-black uppercase tracking-widest text-slate-500">Unit Price</th>
                        <th class="w-32 px-3 py-2.5 text-left text-[10px] font-black uppercase tracking-widest text-slate-500">Line Total</th>
                        <th class="w-10"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <tr v-for="(item, index) in items" :key="index" class="align-top">
                        <td class="px-3 py-2">
                            <input :value="item.description" type="text" :disabled="readonly" :class="inputClass(index, 'description')"
                                @input="update(index, 'description', $event.target.value)" />
                        </td>
                        <td class="px-3 py-2">
                            <input :value="item.quantity" type="number" step="any" :disabled="readonly" :class="inputClass(index, 'quantity')"
                                @input="update(index, 'quantity', $event.target.value === '' ? null : Number($event.target.value))" />
                        </td>
                        <td class="px-3 py-2">
                            <input :value="item.uom" type="text" :disabled="readonly" :class="inputClass(index, 'uom')"
                                @input="update(index, 'uom', $event.target.value)" />
                        </td>
                        <td class="px-3 py-2">
                            <input :value="item.unit_price" type="number" step="any" :disabled="readonly" :class="inputClass(index, 'unit_price')"
                                @input="update(index, 'unit_price', $event.target.value === '' ? null : Number($event.target.value))" />
                        </td>
                        <td class="px-3 py-2">
                            <input :value="item.line_total" type="number" step="any" :disabled="readonly" :class="inputClass(index, 'line_total')"
                                @input="update(index, 'line_total', $event.target.value === '' ? null : Number($event.target.value))" />
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
                        <td colspan="6" class="px-4 py-8 text-center text-sm font-medium text-slate-400">
                            No line items extracted. Add rows manually if the document has them.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex justify-end border-t border-slate-100 bg-slate-50/60 px-5 py-3 text-sm font-bold text-slate-700">
            Line sum: {{ money(lineSum) }}
        </div>
    </div>
</template>
