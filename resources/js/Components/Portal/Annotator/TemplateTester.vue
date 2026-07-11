<script setup>
/**
 * Renders a test-extract result: field values with confidence chips plus the
 * extracted line-item rows, so annotations can be tuned without leaving the page.
 */
defineProps({
    result: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    error: { type: String, default: null },
});

const pct = (value) => `${Math.round((value ?? 0) * 100)}%`;
const chipClass = (confidence) => {
    if (confidence >= 0.9) return 'bg-emerald-100 text-emerald-700';
    if (confidence >= 0.75) return 'bg-amber-100 text-amber-700';
    return 'bg-red-100 text-red-700';
};
</script>

<template>
    <div>
        <div v-if="loading" class="flex items-center gap-2 py-4 text-sm font-semibold text-emerald-600">
            <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
            Running extraction...
        </div>

        <p v-else-if="error" class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>

        <div v-else-if="result" class="space-y-4">
            <div class="flex items-center justify-between">
                <h4 class="text-xs font-bold uppercase tracking-widest text-slate-500">Test Result</h4>
                <span :class="['rounded-full px-2 py-0.5 text-xs font-bold', chipClass(result.overall_confidence)]">
                    overall {{ pct(result.overall_confidence) }}
                </span>
            </div>

            <div class="space-y-1.5">
                <div v-for="field in result.fields" :key="field.key" class="flex items-center justify-between gap-2 text-sm">
                    <span class="text-slate-500">{{ field.key }}</span>
                    <span class="flex min-w-0 items-center gap-2">
                        <span class="truncate font-semibold text-slate-800">{{ field.value ?? '—' }}</span>
                        <span :class="['flex-shrink-0 rounded-full px-1.5 py-0.5 text-[10px] font-bold', chipClass(field.confidence)]">
                            {{ pct(field.confidence) }}
                        </span>
                    </span>
                </div>
            </div>

            <div v-if="result.line_items?.length">
                <h5 class="mb-1.5 text-xs font-bold uppercase tracking-widest text-slate-500">
                    Line Items ({{ result.line_items.length }})
                </h5>
                <div class="overflow-x-auto rounded-lg border border-slate-100">
                    <table class="min-w-full text-xs">
                        <thead class="bg-slate-50">
                            <tr>
                                <th v-for="(cell, key) in result.line_items[0].cells" :key="key"
                                    class="px-2 py-1.5 text-left font-bold uppercase text-slate-500">{{ key }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in result.line_items" :key="row.row_index" class="border-t border-slate-50">
                                <td v-for="(cell, key) in row.cells" :key="key" class="px-2 py-1.5">
                                    <span v-if="cell.value !== null && cell.value !== undefined && cell.value !== ''"
                                        class="text-slate-700">{{ cell.value }}</span>
                                    <span v-else-if="cell.raw_text"
                                        class="italic text-amber-600"
                                        :title="`Captured text, but it isn't a valid ${key} value`">{{ cell.raw_text }}</span>
                                    <span v-else class="text-slate-300">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="result.totals_check" class="rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
                Line sum <strong>{{ result.totals_check.line_sum }}</strong>
                · Extracted total <strong>{{ result.totals_check.extracted_total ?? '—' }}</strong>
                <span v-if="result.totals_check.delta != null"> · Δ {{ result.totals_check.delta }}</span>
            </div>
        </div>
    </div>
</template>
