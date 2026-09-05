<script setup>
/**
 * Client-side table shell for the Campaigns tabs.
 *
 * Deliberately NOT the portal's own DataTable: that one is server-driven
 * (dataKey + routeName + paginator, infinite scroll), while Campaigns ships its
 * whole dataset in one Inertia payload the way the LINK HUB's Loyalty Stamps
 * page does. The slot API (`actions` / `header` / `body`) matches the hub's
 * DataTable so the ported row markup reads identically in both apps.
 */
defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    searchPlaceholder: { type: String, default: 'Search...' },
    search: { type: String, default: '' },
    data: { type: Array, default: () => [] },
    currentPage: { type: Number, default: 1 },
    lastPage: { type: Number, default: 1 },
    perPage: { type: Number, default: 10 },
    showingText: { type: String, default: '' },
    emptyMessage: { type: String, default: 'Nothing to show yet.' },
});

const emit = defineEmits(['update:search', 'goToPage', 'changePerPage']);
</script>

<template>
    <div class="w-full overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm">
        <div class="border-b border-slate-100 bg-white px-5 py-4 sm:px-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h3 class="text-base font-black tracking-tight text-slate-900">{{ title }}</h3>
                    <p v-if="subtitle" class="mt-0.5 text-xs font-medium text-slate-500">{{ subtitle }}</p>
                </div>
                <div class="flex flex-col items-stretch gap-3 sm:flex-row sm:items-center">
                    <div class="relative w-full sm:min-w-64">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5">
                            <svg class="h-4 w-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>
                        <input
                            :value="search"
                            type="text"
                            :placeholder="searchPlaceholder"
                            class="block w-full rounded-xl border border-slate-200 bg-slate-50/70 py-2 pl-10 pr-4 text-xs sm:text-sm font-medium text-slate-900 placeholder:text-slate-400 outline-none transition focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100"
                            @input="emit('update:search', $event.target.value)"
                        />
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <slot name="actions"></slot>
                    </div>
                </div>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-100">
                <thead class="bg-slate-50/80">
                    <slot name="header"></slot>
                </thead>
                <tbody class="divide-y divide-slate-100 text-sm text-slate-700">
                    <slot name="body" :data="data"></slot>
                </tbody>
            </table>

            <div v-if="!data.length" class="px-4 py-14 text-center">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                </div>
                <h3 class="mt-3 text-sm font-bold text-slate-800">No records found</h3>
                <p class="mt-1 text-xs text-slate-500">{{ emptyMessage }}</p>
            </div>
        </div>

        <div v-if="data.length" class="flex flex-col gap-3 border-t border-slate-100 bg-slate-50/40 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <div class="flex items-center gap-2 text-xs font-medium text-slate-500">
                <span>{{ showingText }}</span>
                <select
                    :value="perPage"
                    class="rounded-lg border border-slate-200 bg-white py-1 pl-2.5 pr-7 text-xs font-semibold text-slate-700 shadow-sm focus:border-emerald-500 focus:ring-emerald-500/20"
                    @change="emit('changePerPage', Number($event.target.value))"
                >
                    <option v-for="size in [10, 25, 50, 100]" :key="size" :value="size">{{ size }} / page</option>
                </select>
            </div>
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 shadow-sm transition hover:bg-slate-50 hover:text-slate-900 disabled:cursor-not-allowed disabled:opacity-40"
                    :disabled="currentPage <= 1"
                    @click="emit('goToPage', currentPage - 1)"
                >
                    Previous
                </button>
                <span class="px-1 text-xs font-semibold text-slate-500">Page {{ currentPage }} of {{ lastPage }}</span>
                <button
                    type="button"
                    class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 shadow-sm transition hover:bg-slate-50 hover:text-slate-900 disabled:cursor-not-allowed disabled:opacity-40"
                    :disabled="currentPage >= lastPage"
                    @click="emit('goToPage', currentPage + 1)"
                >
                    Next
                </button>
            </div>
        </div>
    </div>
</template>
