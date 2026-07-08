<template>
    <div v-if="isLoading" class="absolute inset-0 bg-white bg-opacity-75 flex items-center justify-center z-10">
        <div class="flex flex-col items-center space-y-3">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
            <p class="text-sm text-gray-600">{{ message }}</p>
        </div>
    </div>
</template>

<script setup>
defineProps({
    isLoading: {
        type: Boolean,
        default: false
    },
    message: {
        type: String,
        default: 'Loading...'
    }
})
</script>