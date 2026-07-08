<script setup>
import { onMounted, ref } from 'vue';

const model = defineModel({
    type: String,
    required: true,
});

const input = ref(null);

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <input
        class="block w-full rounded-lg border-gray-300 shadow-sm transition-colors duration-200 focus:border-blue-500 focus:ring-blue-500 focus:ring-opacity-50 focus:ring-2 sm:text-sm"
        :class="[
            $attrs.class || '',
            'focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent'
        ]"
        v-model="model"
        ref="input"
    />
</template>
