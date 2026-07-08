<template>
    <div class="flex items-center justify-between">
        <!-- Records info -->
        <div class="text-sm text-gray-700">
            {{ showingText }}
        </div>

        <!-- Pagination controls -->
        <div class="flex items-center space-x-2">
            <!-- Per page selector -->
            <div class="flex items-center space-x-2">
                <span class="text-sm text-gray-700">Show</span>
                <select
                    :value="perPage"
                    @change="$emit('changePerPage', parseInt($event.target.value))"
                    class="border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-blue-500"
                >
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
                <span class="text-sm text-gray-700">per page</span>
            </div>

            <!-- Page navigation -->
            <div class="flex items-center space-x-1 ml-4">
                <!-- Previous button -->
                <button
                    @click="$emit('goToPage', currentPage - 1)"
                    :disabled="currentPage <= 1"
                    class="px-3 py-1 text-sm border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    Previous
                </button>

                <!-- Page numbers -->
                <template v-for="page in visiblePages" :key="page">
                    <button
                        v-if="page !== '...'"
                        @click="$emit('goToPage', page)"
                        :class="[
                            'px-3 py-1 text-sm border rounded',
                            page === currentPage
                                ? 'bg-blue-600 text-white border-blue-600'
                                : 'border-gray-300 hover:bg-gray-50'
                        ]"
                    >
                        {{ page }}
                    </button>
                    <span v-else class="px-2 py-1 text-sm text-gray-500">...</span>
                </template>

                <!-- Next button -->
                <button
                    @click="$emit('goToPage', currentPage + 1)"
                    :disabled="currentPage >= lastPage"
                    class="px-3 py-1 text-sm border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    Next
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    currentPage: Number,
    lastPage: Number,
    perPage: Number,
    showingText: String
})

defineEmits(['goToPage', 'changePerPage'])

const visiblePages = computed(() => {
    const pages = []
    const current = props.currentPage
    const last = props.lastPage
    
    if (last <= 7) {
        for (let i = 1; i <= last; i++) {
            pages.push(i)
        }
    } else {
        if (current <= 4) {
            for (let i = 1; i <= 5; i++) pages.push(i)
            pages.push('...')
            pages.push(last)
        } else if (current >= last - 3) {
            pages.push(1)
            pages.push('...')
            for (let i = last - 4; i <= last; i++) pages.push(i)
        } else {
            pages.push(1)
            pages.push('...')
            for (let i = current - 1; i <= current + 1; i++) pages.push(i)
            pages.push('...')
            pages.push(last)
        }
    }
    
    return pages
})
</script>