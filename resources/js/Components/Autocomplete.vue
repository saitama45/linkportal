<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    CheckIcon,
    ChevronDownIcon,
    MagnifyingGlassIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    modelValue: {
        type: [String, Number, null],
        default: '',
    },
    options: {
        type: Array,
        default: () => [],
    },
    placeholder: {
        type: String,
        default: 'Search...',
    },
    labelKey: {
        type: String,
        default: 'label',
    },
    valueKey: {
        type: String,
        default: 'value',
    },
    required: {
        type: Boolean,
        default: false,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['update:modelValue']);

const root = ref(null);
const input = ref(null);
const search = ref('');
const isOpen = ref(false);
const activeIndex = ref(0);

const normalizedOptions = computed(() => props.options.map((option) => {
    if (typeof option === 'string' || typeof option === 'number') {
        return {
            label: String(option),
            value: option,
        };
    }

    return {
        label: option?.[props.labelKey] ?? '',
        value: option?.[props.valueKey] ?? '',
    };
}));

// Loose string comparison: ids arrive as numbers or strings depending on the source
const isSameValue = (a, b) => a !== '' && a !== null && a !== undefined && String(a) === String(b);

const selectedOption = computed(() => normalizedOptions.value.find((option) => isSameValue(props.modelValue, option.value)));

const filteredOptions = computed(() => {
    const term = search.value.trim().toLowerCase();

    if (!term) {
        return normalizedOptions.value;
    }

    return normalizedOptions.value.filter((option) => option.label.toLowerCase().includes(term));
});

watch(() => props.modelValue, () => {
    if (!isOpen.value) {
        search.value = selectedOption.value?.label ?? '';
    }
}, { immediate: true });

// Re-resolve the displayed label once options load/change after the initial value
watch(normalizedOptions, () => {
    if (!isOpen.value) {
        search.value = selectedOption.value?.label ?? '';
    }
});

watch(filteredOptions, () => {
    activeIndex.value = 0;
});

const openList = () => {
    if (props.disabled) return;

    search.value = selectedOption.value?.label ?? '';
    isOpen.value = true;

    nextTick(() => input.value?.focus());
};

const selectOption = (option) => {
    emit('update:modelValue', option.value);
    search.value = option.label;
    isOpen.value = false;
};

const clearSelection = () => {
    emit('update:modelValue', '');
    search.value = '';
    isOpen.value = true;

    nextTick(() => input.value?.focus());
};

const handleInput = (event) => {
    search.value = event.target.value;
    isOpen.value = true;

    if (props.modelValue && event.target.value !== selectedOption.value?.label) {
        emit('update:modelValue', '');
    }
};

const closeList = () => {
    isOpen.value = false;
    search.value = selectedOption.value?.label ?? '';
};

const handleKeydown = (event) => {
    if (!isOpen.value && ['ArrowDown', 'ArrowUp', 'Enter'].includes(event.key)) {
        openList();
        return;
    }

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        activeIndex.value = Math.min(activeIndex.value + 1, filteredOptions.value.length - 1);
    }

    if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex.value = Math.max(activeIndex.value - 1, 0);
    }

    if (event.key === 'Enter' && isOpen.value) {
        event.preventDefault();

        if (filteredOptions.value[activeIndex.value]) {
            selectOption(filteredOptions.value[activeIndex.value]);
        }
    }

    if (event.key === 'Escape') {
        closeList();
    }
};

const handleClickOutside = (event) => {
    if (!root.value?.contains(event.target)) {
        closeList();
    }
};

onMounted(() => {
    document.addEventListener('mousedown', handleClickOutside);
});

onBeforeUnmount(() => {
    document.removeEventListener('mousedown', handleClickOutside);
});
</script>

<template>
    <div ref="root" class="relative">
        <div
            :class="[
                'flex min-h-[42px] items-center rounded-xl border bg-slate-50 transition-all',
                isOpen ? 'border-blue-500 ring-2 ring-blue-500/20' : 'border-slate-200',
                disabled ? 'cursor-not-allowed opacity-60' : ''
            ]"
        >
            <MagnifyingGlassIcon class="ml-3 h-5 w-5 shrink-0 text-slate-400" />

            <input
                ref="input"
                :value="search"
                :required="required && !modelValue"
                :disabled="disabled"
                :placeholder="placeholder"
                type="text"
                autocomplete="off"
                class="min-w-0 flex-1 border-0 bg-transparent px-3 py-2.5 text-sm text-slate-800 placeholder:text-slate-400 focus:ring-0 disabled:cursor-not-allowed"
                @focus="openList"
                @input="handleInput"
                @keydown="handleKeydown"
            >

            <button
                v-if="modelValue && !disabled && !required"
                type="button"
                class="mr-1 rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
                title="Clear selection"
                @click="clearSelection"
            >
                <XMarkIcon class="h-4 w-4" />
            </button>

            <button
                type="button"
                :disabled="disabled"
                class="mr-2 rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 disabled:cursor-not-allowed"
                title="Show options"
                @click="openList"
            >
                <ChevronDownIcon class="h-4 w-4" />
            </button>
        </div>

        <div
            v-if="isOpen"
            class="absolute left-0 right-0 z-[70] mt-2 max-h-56 overflow-auto rounded-xl border border-slate-200 bg-white py-1 shadow-xl shadow-slate-900/10"
        >
            <button
                v-for="(option, index) in filteredOptions"
                :key="`${option.value}-${index}`"
                type="button"
                :class="[
                    'flex w-full items-center justify-between px-3 py-2.5 text-left text-sm transition',
                    index === activeIndex ? 'bg-blue-50 text-blue-700' : 'text-slate-700 hover:bg-slate-50',
                    isSameValue(modelValue, option.value) ? 'font-semibold' : 'font-medium'
                ]"
                @mouseenter="activeIndex = index"
                @click="selectOption(option)"
            >
                <span class="truncate">{{ option.label }}</span>
                <CheckIcon v-if="isSameValue(modelValue, option.value)" class="ml-3 h-4 w-4 shrink-0" />
            </button>

            <div v-if="filteredOptions.length === 0" class="px-3 py-3 text-sm font-medium text-slate-500">
                No matching options
            </div>
        </div>
    </div>
</template>
