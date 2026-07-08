<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';

const props = defineProps({
    modelValue: [String, Number],
    options: {
        type: Array,
        default: () => [],
    },
    labelKey: {
        type: String,
        default: 'label',
    },
    valueKey: {
        type: String,
        default: 'value',
    },
    placeholder: {
        type: String,
        default: 'Select an option',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['update:modelValue']);

const isOpen = ref(false);
const containerRef = ref(null);

const selectedOption = computed(() => {
    return props.options.find(opt => {
        const val = typeof opt === 'object' ? opt[props.valueKey] : opt;
        return val == props.modelValue;
    });
});

const toggle = () => {
    if (!props.disabled) {
        isOpen.value = !isOpen.value;
    }
};

const close = () => {
    isOpen.value = false;
};

const select = (option) => {
    const val = typeof option === 'object' ? option[props.valueKey] : option;
    emit('update:modelValue', val);
    close();
};

const handleClickOutside = (event) => {
    if (containerRef.value && !containerRef.value.contains(event.target)) {
        close();
    }
};

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
});
</script>

<template>
    <div ref="containerRef" class="relative">
        <!-- Trigger Button -->
        <button
            type="button"
            @click="toggle"
            :disabled="disabled"
            class="w-full bg-white border border-gray-300 rounded-lg shadow-sm pl-3 pr-10 py-2 text-left cursor-default focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 sm:text-sm transition-colors duration-200 ease-in-out"
            :class="{ 'bg-gray-50 text-gray-500 cursor-not-allowed': disabled, 'hover:border-blue-400': !disabled }"
        >
            <span class="block truncate">
                <slot name="trigger" :selected="selectedOption" :placeholder="placeholder">
                    <span v-if="selectedOption" class="block truncate">
                        {{ typeof selectedOption === 'object' ? selectedOption[labelKey] : selectedOption }}
                    </span>
                    <span v-else class="text-gray-400">{{ placeholder }}</span>
                </slot>
            </span>
            <span class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none">
                <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M10 3a1 1 0 01.707.293l3 3a1 1 0 01-1.414 1.414L10 5.414 7.707 7.707a1 1 0 01-1.414-1.414l3-3A1 1 0 0110 3zm-3.707 9.293a1 1 0 011.414 0L10 14.586l2.293-2.293a1 1 0 011.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd" />
                </svg>
            </span>
        </button>

        <!-- Dropdown Menu -->
        <transition
            leave-active-class="transition ease-in duration-100"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="isOpen" class="absolute z-10 mt-1 w-full bg-white shadow-lg max-h-60 rounded-md py-1 text-base ring-1 ring-black ring-opacity-5 overflow-auto focus:outline-none sm:text-sm">
                <ul tabindex="-1" role="listbox">
                    <li
                        v-for="(option, index) in options"
                        :key="index"
                        class="text-gray-900 cursor-default select-none relative py-2 pl-3 pr-9 hover:bg-blue-50 transition-colors"
                        role="option"
                        @click="select(option)"
                    >
                        <slot name="option" :option="option" :selected="(typeof option === 'object' ? option[valueKey] : option) == modelValue">
                            <span class="block truncate" :class="{ 'font-semibold': (typeof option === 'object' ? option[valueKey] : option) == modelValue, 'font-normal': (typeof option === 'object' ? option[valueKey] : option) != modelValue }">
                                {{ typeof option === 'object' ? option[labelKey] : option }}
                            </span>
                        </slot>

                        <span v-if="(typeof option === 'object' ? option[valueKey] : option) == modelValue" class="text-blue-600 absolute inset-y-0 right-0 flex items-center pr-4">
                            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                            </svg>
                        </span>
                    </li>
                </ul>
            </div>
        </transition>
    </div>
</template>