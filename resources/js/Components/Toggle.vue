<script setup>
const emit = defineEmits(['update:modelValue']);

const props = defineProps({
    modelValue: {
        type: [Boolean, Number],
        required: true,
    },
    label: {
        type: String,
        default: '',
    },
});

const toggle = () => {
    emit('update:modelValue', !!props.modelValue ? false : true);
};
</script>

<template>
    <div class="flex items-center">
        <!-- Switch Track -->
        <div 
            @click="toggle"
            :class="!!modelValue ? 'bg-green-500' : 'bg-red-500'"
            class="relative inline-flex h-6 w-12 shrink-0 cursor-pointer items-center rounded-full transition-colors duration-300 ease-in-out shadow-inner"
        >
            <!-- Switch Knob -->
            <span
                aria-hidden="true"
                :style="{ transform: !!modelValue ? 'translateX(26px)' : 'translateX(2px)' }"
                class="pointer-events-none inline-block h-4 w-4 rounded-full bg-white shadow-lg transition-transform duration-300 ease-in-out"
            ></span>
        </div>
        
        <!-- Label -->
        <span 
            v-if="label" 
            @click="toggle"
            class="ms-3 text-sm font-bold text-slate-700 cursor-pointer select-none hover:text-blue-600 transition-colors"
        >
            {{ label }}
        </span>
    </div>
</template>
