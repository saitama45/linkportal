<template>
    <div class="bg-white rounded-lg border border-slate-200 relative">
        <!-- Header with Search and Actions -->
        <div class="px-6 py-4 border-b border-slate-200 bg-slate-50">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div class="flex items-center space-x-4">
                    <div>
                        <h3 class="text-lg font-semibold text-slate-900">{{ title }}</h3>
                        <p v-if="subtitle" class="text-sm text-slate-600">{{ subtitle }}</p>
                    </div>
                </div>
                <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
                    <!-- Search Box -->
                    <div class="relative min-w-64">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="h-4 w-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>
                        <input
                            v-model="searchValue"
                            type="text"
                            :placeholder="searchPlaceholder"
                            class="block w-full pl-10 pr-9 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500 transition-colors"
                        />
                        <button
                            v-if="searchValue"
                            type="button"
                            class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600"
                            @click="searchValue = ''"
                        >
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <!-- Action Button -->
                    <slot name="actions"></slot>
                </div>
            </div>
        </div>

        <!-- Infinite-scroll Table Content -->
        <InfiniteScroll :data="dataKey" :buffer="300" only-next preserve-url>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <slot name="header"></slot>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-200">
                        <slot name="body" :data="rows" :isLoading="false"></slot>
                    </tbody>
                </table>
            </div>

            <!-- Empty State -->
            <div v-if="rows.length === 0" class="text-center py-12">
                <svg class="mx-auto h-12 w-12 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <h3 class="mt-2 text-sm font-medium text-slate-900">No records found</h3>
                <p class="mt-1 text-sm text-slate-500">{{ emptyMessage }}</p>
            </div>

            <!-- Loading indicator shown while the next page streams in -->
            <template #loading>
                <div class="flex items-center justify-center gap-2.5 py-5 text-sm font-semibold text-emerald-600">
                    <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span>Loading more...</span>
                </div>
            </template>
        </InfiniteScroll>

        <!-- Footer: record count -->
        <div class="px-6 py-3 border-t border-slate-200 bg-slate-50 text-xs font-semibold text-slate-500">
            <span v-if="total > 0">Showing {{ rows.length }} of {{ total }} {{ total === 1 ? 'record' : 'records' }}</span>
            <span v-else>No records found</span>
        </div>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { InfiniteScroll, router } from '@inertiajs/vue3'

const props = defineProps({
    title: {
        type: String,
        required: true,
    },
    subtitle: String,
    searchPlaceholder: {
        type: String,
        default: 'Search...',
    },
    emptyMessage: {
        type: String,
        default: 'Get started by creating a new record.',
    },
    // Inertia prop name that holds the scroll paginator (e.g. 'users')
    dataKey: {
        type: String,
        required: true,
    },
    // Route name used for the search request (e.g. 'users.index')
    routeName: {
        type: String,
        required: true,
    },
    // The scroll paginator object: { data: [...], total, current_page, ... }
    paginator: {
        type: Object,
        default: () => ({}),
    },
    initialSearch: {
        type: String,
        default: '',
    },
})

const rows = computed(() => props.paginator?.data ?? [])
const total = computed(() => props.paginator?.total ?? rows.value.length)

// --- Search (a full visit resets the scroll prop back to page 1) ---
const searchValue = ref(props.initialSearch)

let searchTimeout
watch(searchValue, (value) => {
    clearTimeout(searchTimeout)
    searchTimeout = setTimeout(() => {
        router.get(
            route(props.routeName),
            { search: value || undefined },
            { preserveState: true, preserveScroll: true },
        )
    }, 300)
})
</script>
