<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex min-h-screen items-center justify-center p-4">
                <!-- Backdrop -->
                <div 
                    class="fixed inset-0 bg-black bg-opacity-50 transition-opacity duration-300"
                    :class="show ? 'opacity-100' : 'opacity-0'"
                    @click="cancel"
                ></div>
                
                <!-- Modal -->
                <div 
                    class="relative bg-white rounded-2xl shadow-2xl max-w-md w-full transform transition-all duration-300"
                    :class="show ? 'scale-100 opacity-100' : 'scale-95 opacity-0'"
                >
                    <!-- Icon -->
                    <div 
                        class="flex items-center justify-center w-16 h-16 mx-auto mt-8 rounded-full"
                        :class="[
                            type === 'danger' ? 'bg-red-100 text-red-600' : 
                            type === 'warning' ? 'bg-amber-100 text-amber-600' : 
                            type === 'success' ? 'bg-green-100 text-green-600' :
                            'bg-blue-100 text-blue-600'
                        ]"
                    >
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path v-if="type === 'danger' || type === 'warning'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
                            <path v-else-if="type === 'success'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    
                    <!-- Content -->
                    <div class="px-6 py-4 text-center">
                        <h3 class="text-xl font-semibold text-gray-900 mb-2">{{ title }}</h3>
                        <p class="text-gray-600 mb-6">{{ message }}</p>
                        
                        <!-- Actions -->
                        <div class="flex space-x-3">
                            <button 
                                @click="cancel"
                                class="flex-1 px-4 py-2.5 text-sm font-medium rounded-lg transition-colors duration-200 focus:outline-none focus:ring-2"
                                :class="[
                                    type === 'success' 
                                        ? 'text-red-700 bg-red-50 hover:bg-red-100 focus:ring-red-500 border border-red-200' 
                                        : 'text-gray-700 bg-gray-100 hover:bg-gray-200 focus:ring-gray-300'
                                ]"
                            >
                                {{ cancelButtonText }}
                            </button>
                            <button 
                                @click="confirm"
                                class="flex-1 px-4 py-2.5 text-sm font-medium text-white rounded-lg transition-colors duration-200 focus:outline-none focus:ring-2"
                                :class="[
                                    type === 'danger' ? 'bg-red-600 hover:bg-red-700 focus:ring-red-500' : 
                                    type === 'warning' ? 'bg-amber-600 hover:bg-amber-700 focus:ring-amber-500' : 
                                    type === 'success' ? 'bg-green-600 hover:bg-green-700 focus:ring-green-500' :
                                    'bg-blue-600 hover:bg-blue-700 focus:ring-blue-500'
                                ]"
                            >
                                {{ confirmButtonText }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup>
import { watch, nextTick } from 'vue'

const props = defineProps({
    show: Boolean,
    title: {
        type: String,
        default: 'Confirm Action'
    },
    message: {
        type: String,
        default: 'Are you sure you want to proceed? This action cannot be undone.'
    },
    confirmButtonText: {
        type: String,
        default: 'Confirm'
    },
    cancelButtonText: {
        type: String,
        default: 'Cancel'
    },
    type: {
        type: String,
        default: 'danger' // 'danger', 'warning', 'info'
    }
})

const emit = defineEmits(['confirm', 'cancel'])

const confirm = () => {
    emit('confirm')
}

const cancel = () => {
    emit('cancel')
}

// Handle escape key
watch(() => props.show, (newVal) => {
    if (newVal) {
        nextTick(() => {
            const handleEscape = (e) => {
                if (e.key === 'Escape') {
                    cancel()
                    document.removeEventListener('keydown', handleEscape)
                }
            }
            document.addEventListener('keydown', handleEscape)
        })
    }
})
</script>