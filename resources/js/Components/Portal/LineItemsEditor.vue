<script setup>
import { computed } from 'vue';
import { PlusIcon, TrashIcon } from '@heroicons/vue/24/outline';
import Autocomplete from '@/Components/Autocomplete.vue';

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    uoms: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:modelValue']);

const items = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value),
});

const productOptions = computed(() => props.products.map((p) => ({ label: `${p.code} — ${p.name}`, value: p.id })));
const uomOptions = computed(() => props.uoms.map((u) => ({ label: u.code, value: u.id })));

const addItem = () => {
    items.value = [
        ...items.value,
        { product_id: '', description: '', quantity: 1, uom_id: '', unit_price: 0, tax_rate: 12 },
    ];
};

const removeItem = (index) => {
    items.value = items.value.filter((_, i) => i !== index);
};

const onProductSelect = (index, productId) => {
    const product = props.products.find((p) => String(p.id) === String(productId));
    const next = [...items.value];
    next[index] = {
        ...next[index],
        product_id: productId,
        description: product ? product.name : next[index].description,
        unit_price: product?.default_price != null ? Number(product.default_price) : next[index].unit_price,
        tax_rate: product?.tax_rate != null ? Number(product.tax_rate) : next[index].tax_rate,
        uom_id: product?.uom_id || next[index].uom_id,
    };
    items.value = next;
};

const lineTotal = (item) => {
    const net = (Number(item.quantity) || 0) * (Number(item.unit_price) || 0);
    const tax = net * ((Number(item.tax_rate) || 0) / 100);
    return net + tax;
};

const subtotal = computed(() => items.value.reduce((sum, i) => sum + (Number(i.quantity) || 0) * (Number(i.unit_price) || 0), 0));
const taxTotal = computed(() => items.value.reduce((sum, i) => sum + (Number(i.quantity) || 0) * (Number(i.unit_price) || 0) * ((Number(i.tax_rate) || 0) / 100), 0));
const grandTotal = computed(() => subtotal.value + taxTotal.value);

const money = (value) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
</script>

<template>
    <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100 bg-slate-50/60">
            <h4 class="text-xs font-black uppercase tracking-widest text-slate-600">Line Items</h4>
            <button type="button" @click="addItem"
                class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-3.5 py-2 text-xs font-bold text-white hover:bg-emerald-700 transition-all shadow-sm">
                <PlusIcon class="h-4 w-4" />
                Add Item
            </button>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-100">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-[10px] font-black uppercase tracking-widest text-slate-500 min-w-[200px]">Product / Item</th>
                        <th class="px-4 py-3 text-left text-[10px] font-black uppercase tracking-widest text-slate-500 min-w-[220px]">Description</th>
                        <th class="px-4 py-3 text-left text-[10px] font-black uppercase tracking-widest text-slate-500 w-24">Qty</th>
                        <th class="px-4 py-3 text-left text-[10px] font-black uppercase tracking-widest text-slate-500 w-28">UoM</th>
                        <th class="px-4 py-3 text-left text-[10px] font-black uppercase tracking-widest text-slate-500 w-32">Unit Price</th>
                        <th class="px-4 py-3 text-left text-[10px] font-black uppercase tracking-widest text-slate-500 w-24">Tax %</th>
                        <th class="px-4 py-3 text-right text-[10px] font-black uppercase tracking-widest text-slate-500 w-32">Line Total</th>
                        <th class="w-12"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <tr v-for="(item, index) in items" :key="index" class="align-top">
                        <td class="px-4 py-3">
                            <Autocomplete
                                :model-value="item.product_id"
                                :options="productOptions"
                                placeholder="Free text or pick..."
                                @update:model-value="onProductSelect(index, $event)"
                            />
                        </td>
                        <td class="px-4 py-3">
                            <input v-model="item.description" type="text" required placeholder="Item description"
                                class="block w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20" />
                        </td>
                        <td class="px-4 py-3">
                            <input v-model.number="item.quantity" type="number" min="0.0001" step="any" required
                                class="block w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20" />
                        </td>
                        <td class="px-4 py-3">
                            <Autocomplete v-model="item.uom_id" :options="uomOptions" placeholder="UoM" />
                        </td>
                        <td class="px-4 py-3">
                            <input v-model.number="item.unit_price" type="number" min="0" step="any" required
                                class="block w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20" />
                        </td>
                        <td class="px-4 py-3">
                            <input v-model.number="item.tax_rate" type="number" min="0" max="100" step="any"
                                class="block w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20" />
                        </td>
                        <td class="px-4 py-3 text-right text-sm font-bold text-slate-800 whitespace-nowrap">
                            {{ money(lineTotal(item)) }}
                        </td>
                        <td class="px-2 py-3">
                            <button type="button" @click="removeItem(index)"
                                class="p-2 text-slate-300 hover:text-red-500 hover:bg-red-50 rounded-lg transition-all">
                                <TrashIcon class="h-4 w-4" />
                            </button>
                        </td>
                    </tr>
                    <tr v-if="items.length === 0">
                        <td colspan="8" class="px-4 py-10 text-center text-sm text-slate-400 font-medium">
                            No line items yet. Click "Add Item" to begin.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="border-t border-slate-100 bg-slate-50/60 px-6 py-4">
            <div class="ml-auto w-full max-w-xs space-y-1.5 text-sm">
                <div class="flex justify-between text-slate-500 font-semibold"><span>Subtotal</span><span>{{ money(subtotal) }}</span></div>
                <div class="flex justify-between text-slate-500 font-semibold"><span>Tax</span><span>{{ money(taxTotal) }}</span></div>
                <div class="flex justify-between border-t border-slate-200 pt-2 text-base font-black text-slate-900"><span>Total</span><span>{{ money(grandTotal) }}</span></div>
            </div>
        </div>
    </div>
</template>
