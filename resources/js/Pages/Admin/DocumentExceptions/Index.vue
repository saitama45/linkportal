<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from '@/Components/DataTable.vue';
import Modal from '@/Components/Modal.vue';
import { EyeIcon, Cog6ToothIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    exceptions: Object,
    filters: { type: Object, default: () => ({}) },
    rules: { type: Array, default: () => [] },
    canResolve: { type: Boolean, default: false },
});

const activeFilters = reactive({
    status: props.filters.status || 'open',
    rule_key: props.filters.rule_key || '',
    severity: props.filters.severity || '',
});

const extraParams = computed(() => {
    const params = {};
    for (const [key, value] of Object.entries(activeFilters)) {
        if (value) params[key] = value;
    }
    return params;
});

const applyFilters = () => {
    router.get(route('document-exceptions.index'),
        { ...extraParams.value, search: props.filters.search || undefined },
        { preserveState: true, preserveScroll: true });
};

const resolve = (exception, status) => {
    let note = null;
    if (status === 'waived') {
        note = prompt('Waive note (required):');
        if (!note) return;
    }
    router.put(route('document-exceptions.resolve', exception.id), { status, resolution_note: note }, { preserveScroll: true });
};

// ---- rules settings modal ----
const showRules = ref(false);
const updateRule = (rule, patch) => {
    router.put(route('document-exception-rules.update', rule.id), {
        enabled: patch.enabled ?? rule.enabled,
        severity: patch.severity ?? rule.severity,
        config: patch.config ?? rule.config,
    }, { preserveScroll: true });
};

// Time-based rules expose an editable day threshold. Keyed by rule so the
// number input can be labelled meaningfully instead of surfacing raw JSON.
const dayConfig = {
    po_expired: { key: 'validity_days', label: 'Expire approved POs after', placeholder: 'never', hint: 'Leave blank to keep POs open indefinitely.' },
    po_awaiting_invoice_overdue: { key: 'overdue_days', label: 'Nudge on unbilled POs after', placeholder: '7', hint: 'Days after approval before an aging alert is raised.' },
    overdue_review: { key: 'overdue_days', label: 'Flag stalled reviews after', placeholder: '3', hint: 'Days in external review before it is flagged overdue.' },
};

const updateRuleDays = (rule, rawValue) => {
    const meta = dayConfig[rule.rule_key];
    const trimmed = String(rawValue).trim();
    // Empty means "no threshold" (never / disabled), stored as null.
    const value = trimmed === '' ? null : Math.max(0, Math.floor(Number(trimmed))) || null;
    updateRule(rule, { config: { ...rule.config, [meta.key]: value } });
};

const formatDate = (value) => (value ? new Date(value).toLocaleString() : '—');
</script>

<template>
    <Head title="Document Exceptions - Link Portal" />

    <AppLayout>
        <template #header>
            <div>
                <h2 class="text-2xl font-bold leading-tight text-slate-800">Document Exceptions</h2>
                <p class="mt-1 text-sm text-slate-500">Issues raised by intake rules — resolve or waive to unblock documents.</p>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl space-y-4 sm:px-6 lg:px-8">
                <div class="flex flex-wrap items-end gap-3 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                    <div>
                        <label class="mb-1 block text-xs font-bold uppercase text-slate-400">Status</label>
                        <select v-model="activeFilters.status" class="rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500/30" @change="applyFilters">
                            <option value="open">Open</option>
                            <option value="resolved">Resolved</option>
                            <option value="waived">Waived</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-bold uppercase text-slate-400">Rule</label>
                        <select v-model="activeFilters.rule_key" class="rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500/30" @change="applyFilters">
                            <option value="">All rules</option>
                            <option v-for="rule in rules" :key="rule.rule_key" :value="rule.rule_key">{{ rule.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-bold uppercase text-slate-400">Severity</label>
                        <select v-model="activeFilters.severity" class="rounded-lg border-slate-300 text-sm focus:border-emerald-500 focus:ring-emerald-500/30" @change="applyFilters">
                            <option value="">All</option>
                            <option value="blocker">Blocker</option>
                            <option value="warning">Warning</option>
                        </select>
                    </div>
                    <button v-if="canResolve" type="button"
                        class="ml-auto flex items-center gap-2 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-600 transition-all hover:bg-slate-50"
                        @click="showRules = true">
                        <Cog6ToothIcon class="h-4 w-4" />
                        Rules
                    </button>
                </div>

                <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
                    <DataTable
                        title="Exception Queue"
                        subtitle="Grouped by document"
                        search-placeholder="Search message, reference, vendor..."
                        empty-message="No exceptions match the current filters."
                        data-key="exceptions"
                        route-name="document-exceptions.index"
                        :paginator="exceptions"
                        :initial-search="filters.search"
                        :extra-params="extraParams"
                    >
                        <template #header>
                            <tr class="bg-slate-50">
                                <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Severity</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Rule</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Message</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Document</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Vendor</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-left text-xs font-bold uppercase tracking-widest text-slate-500">Raised</th>
                                <th class="border-b border-slate-100 px-6 py-4 text-right text-xs font-bold uppercase tracking-widest text-slate-500">Actions</th>
                            </tr>
                        </template>

                        <template #body="{ data }">
                            <tr v-for="exception in data" :key="exception.id" class="border-b border-slate-50 transition-colors last:border-0 hover:bg-slate-50/50">
                                <td class="whitespace-nowrap px-6 py-4">
                                    <span :class="['rounded-full px-2 py-0.5 text-[10px] font-bold uppercase',
                                        exception.severity === 'blocker' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700']">
                                        {{ exception.severity }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-xs font-bold text-slate-500">{{ exception.rule_key }}</td>
                                <td class="max-w-md truncate px-6 py-4 text-sm text-slate-700" :title="exception.message">{{ exception.message }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm font-black text-slate-900">{{ exception.intake_document?.reference_no }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-600">{{ exception.intake_document?.vendor?.name || 'Unmatched' }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-slate-600">{{ formatDate(exception.created_at) }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <template v-if="canResolve && exception.status === 'open'">
                                            <button type="button" class="rounded-lg bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700 hover:bg-emerald-100"
                                                @click="resolve(exception, 'resolved')">Resolve</button>
                                            <button type="button" class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600 hover:bg-slate-200"
                                                @click="resolve(exception, 'waived')">Waive</button>
                                        </template>
                                        <Link v-if="exception.intake_document" :href="route('document-intake.show', exception.intake_document.id)"
                                            class="inline-flex rounded-lg p-2 text-slate-400 transition-all hover:bg-emerald-50 hover:text-emerald-600" title="Open document">
                                            <EyeIcon class="h-5 w-5" />
                                        </Link>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </DataTable>
                </div>
            </div>
        </div>

        <Modal :show="showRules" max-width="2xl" @close="showRules = false">
            <div class="p-6">
                <h3 class="text-lg font-bold text-slate-900">Exception Rules</h3>
                <p class="mt-1 text-sm text-slate-500">Toggle rules and set the severity of the exceptions they raise.</p>
                <div class="mt-5 max-h-96 space-y-2 overflow-y-auto pr-1">
                    <div v-for="rule in rules" :key="rule.id" class="rounded-xl border border-slate-100 p-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-slate-800">{{ rule.label }}</p>
                                <p class="text-xs text-slate-400">{{ rule.rule_key }}</p>
                            </div>
                            <div class="flex items-center gap-3">
                                <select :value="rule.severity" class="rounded-lg border-slate-200 py-1 text-xs focus:border-emerald-500 focus:ring-emerald-500/30"
                                    @change="updateRule(rule, { severity: $event.target.value })">
                                    <option value="blocker">blocker</option>
                                    <option value="warning">warning</option>
                                </select>
                                <label class="flex items-center gap-1.5 text-xs font-semibold text-slate-600">
                                    <input type="checkbox" :checked="rule.enabled"
                                        class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500/30"
                                        @change="updateRule(rule, { enabled: $event.target.checked })" />
                                    enabled
                                </label>
                            </div>
                        </div>

                        <!-- Editable day threshold for time-based rules (e.g. PO expiration) -->
                        <div v-if="dayConfig[rule.rule_key]" class="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
                            <label class="text-xs font-semibold text-slate-600">{{ dayConfig[rule.rule_key].label }}</label>
                            <div class="flex items-center gap-1.5">
                                <input type="number" min="0" step="1"
                                    :value="rule.config?.[dayConfig[rule.rule_key].key] ?? ''"
                                    :placeholder="dayConfig[rule.rule_key].placeholder"
                                    class="w-24 rounded-lg border-slate-200 py-1 text-xs focus:border-emerald-500 focus:ring-emerald-500/30"
                                    @change="updateRuleDays(rule, $event.target.value)" />
                                <span class="text-xs text-slate-400">days</span>
                            </div>
                            <p class="w-full text-[11px] text-slate-400">{{ dayConfig[rule.rule_key].hint }}</p>
                        </div>
                    </div>
                </div>
                <div class="mt-5 flex justify-end">
                    <button type="button" class="rounded-xl px-5 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-100" @click="showRules = false">
                        Close
                    </button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
