<script setup>
/**
 * Regular / Holiday choice for a stamp being added. Only shown for a program
 * that reserves holiday slots. The server (StampAllocator) decides where the
 * stamp lands, and records a Holiday stamp as Regular once every holiday slot
 * the member can hold is filled — so this states what was bought, and the
 * cashier never has to work out the member's cards.
 */
defineProps({
    modelValue: { type: String, default: 'regular' },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const options = [
    { value: 'regular', label: 'Regular', hint: 'Any drink or item' },
    { value: 'holiday', label: 'Holiday', hint: 'Holiday drink' },
];
</script>

<template>
    <div class="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Stamp type">
        <button
            v-for="o in options"
            :key="o.value"
            type="button"
            role="radio"
            :aria-checked="modelValue === o.value"
            :disabled="disabled"
            :class="[
                'rounded-xl border px-3.5 py-2 text-left transition-colors disabled:opacity-50',
                modelValue === o.value
                    ? (o.value === 'holiday'
                        ? 'border-rose-500 bg-rose-50 text-rose-900 ring-4 ring-rose-100'
                        : 'border-emerald-500 bg-emerald-50 text-emerald-900 ring-4 ring-emerald-100')
                    : 'border-slate-200 bg-slate-50/70 text-slate-700 hover:bg-white',
            ]"
            @click="emit('update:modelValue', o.value)"
        >
            <span class="block text-sm font-black">{{ o.label }}</span>
            <span class="block text-xs font-medium opacity-75">{{ o.hint }}</span>
        </button>
    </div>
</template>
