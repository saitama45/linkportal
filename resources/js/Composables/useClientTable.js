import { computed, reactive, watch } from 'vue';

/**
 * Lightweight client-side table (search + pagination) for pages that ship
 * their whole dataset in one Inertia payload, such as the Campaigns tabs.
 * Feeds CampaignTable's props and events.
 */
const getField = (row, path) => path.split('.').reduce((o, k) => (o == null ? o : o[k]), row);

export function useClientTable(rowsGetter, searchFields) {
    const t = reactive({ search: '', perPage: 10, currentPage: 1 });
    const filtered = computed(() => {
        const q = t.search.trim().toLowerCase();
        let rows = rowsGetter() || [];
        if (q) {
            rows = rows.filter((r) => searchFields.some((f) => String(getField(r, f) ?? '').toLowerCase().includes(q)));
        }
        return rows;
    });
    t.total = computed(() => filtered.value.length);
    t.lastPage = computed(() => Math.max(1, Math.ceil(filtered.value.length / t.perPage)));
    t.data = computed(() => {
        const start = (t.currentPage - 1) * t.perPage;
        return filtered.value.slice(start, start + t.perPage);
    });
    t.showingText = computed(() => {
        const total = filtered.value.length;
        if (total === 0) return 'No records found';
        const from = (t.currentPage - 1) * t.perPage + 1;
        const to = Math.min(t.currentPage * t.perPage, total);
        return `Showing ${from} to ${to} of ${total} records`;
    });
    t.goToPage = (p) => { if (p >= 1 && p <= t.lastPage) t.currentPage = p; };
    t.changePerPage = (n) => { t.perPage = n; t.currentPage = 1; };
    watch(() => t.search, () => { t.currentPage = 1; });
    return t;
}
