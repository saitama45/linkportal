<script setup>
import { useToast } from '@/Composables/useToast.js';

const { toasts, removeToast } = useToast();

const getToastClasses = (type) => {
    const baseClasses = 'flex items-center p-4 mb-4 text-sm rounded-lg shadow-lg transition-all duration-300 ease-in-out';
    
    switch (type) {
        case 'success':
            return `${baseClasses} text-green-800 bg-green-50 border border-green-200`;
        case 'error':
            return `${baseClasses} text-red-800 bg-red-50 border border-red-200`;
        case 'warning':
            return `${baseClasses} text-yellow-800 bg-yellow-50 border border-yellow-200`;
        case 'info':
            return `${baseClasses} text-blue-800 bg-blue-50 border border-blue-200`;
        default:
            return `${baseClasses} text-gray-800 bg-gray-50 border border-gray-200`;
    }
};

const getIcon = (type) => {
    switch (type) {
        case 'success':
            return 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z';
        case 'error':
            return 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z';
        case 'warning':
            return 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z';
        case 'info':
            return 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z';
        default:
            return 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z';
    }
};

const getIconColor = (type) => {
    switch (type) {
        case 'success':
            return 'text-green-500';
        case 'error':
            return 'text-red-500';
        case 'warning':
            return 'text-yellow-500';
        case 'info':
            return 'text-blue-500';
        default:
            return 'text-gray-500';
    }
};
</script>

<template>
    <div class="fixed top-4 right-4 z-50 space-y-2 max-w-sm w-full">
        <transition-group name="toast" tag="div">
            <div
                v-for="toast in toasts"
                :key="toast.id"
                :class="getToastClasses(toast.type)"
            >
                <svg
                    :class="['w-5 h-5 mr-3 flex-shrink-0', getIconColor(toast.type)]"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        :d="getIcon(toast.type)"
                    />
                </svg>
                <div class="flex-1">{{ toast.message }}</div>
                <button
                    @click="removeToast(toast.id)"
                    class="ml-3 text-gray-400 hover:text-gray-600 focus:outline-none"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </transition-group>
    </div>
</template>

<style scoped>
.toast-enter-active,
.toast-leave-active {
    transition: all 0.3s ease;
}

.toast-enter-from {
    opacity: 0;
    transform: translateX(100%);
}

.toast-leave-to {
    opacity: 0;
    transform: translateX(100%);
}

.toast-move {
    transition: transform 0.3s ease;
}
</style>