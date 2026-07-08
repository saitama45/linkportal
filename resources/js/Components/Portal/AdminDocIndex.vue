<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from '@/Components/DataTable.vue';
import StatusBadge from '@/Components/Portal/StatusBadge.vue';
import { usePermission } from '@/Composables/usePermission';
import { EyeIcon, PlusIcon } from '@heroicons/vue/24/outline';

defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    paginator: { type: Object, required: true },
    dataKey: { type: String, required: true },
    routeName: { type: String, required: true },
    showRouteName: { type: String, required: true },
    createRouteName: { type: String, default: '' },
    createPermission: { type: String, default: '' },
    createLabel: { type: String, default: 'Create' },
    filters: { type: Object, default: () => ({}) },
    // [{ label, key ('vendor.name' dot-path ok), align, format ('money'|'date'|'status') }]
    columns: { type: Array, required: true },
});

const { hasPermission } = usePermission();

const get = (row, path) => path.split('.').reduce((acc, key) => acc?.[key], row);
const money = (value) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2 });
const formatDate = (value) => (value ? new Date(value).toLocaleDateString() : '—');

const renderCell = (row, col) => {
    const value = get(row, col.key);
    if (col.format === 'money') return money(value);
    if (col.format === 'date') return formatDate(value);
    return value ?? '—';
};
</script>

<template>
    <Head :title="`${title} - Link Portal`" />

    <AppLayout>
        <template #header>
            <div>
                <h2 class="text-2xl font-bold leading-tight text-slate-800">{{ title }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ subtitle }}</p>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
                    <DataTable
                        :title="title"
                        subtitle="Vendor submissions"
                        search-placeholder="Search reference or vendor..."
                        empty-message="No submissions found."
                        :data-key="dataKey"
                        :route-name="routeName"
                        :paginator="paginator"
                        :initial-search="filters.search"
                    >
                        <template #actions>
                            <Link
                                v-if="createRouteName && createPermission && hasPermission(createPermission)"
                                :href="route(createRouteName)"
                                class="flex items-center space-x-2 rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-emerald-600/20 transition-all duration-200 hover:bg-emerald-700"
                            >
                                <PlusIcon class="h-5 w-5" />
                                <span>{{ createLabel }}</span>
                            </Link>
                        </template>

                        <template #header>
                            <tr class="bg-slate-50">
                                <th v-for="col in columns" :key="col.key"
                                    :class="['border-b border-slate-100 px-6 py-4 text-xs font-bold uppercase tracking-widest text-slate-500', col.align === 'right' ? 'text-right' : 'text-left']">
                                    {{ col.label }}
                                </th>
                                <th class="border-b border-slate-100 px-6 py-4 text-right text-xs font-bold uppercase tracking-widest text-slate-500">Actions</th>
                            </tr>
                        </template>

                        <template #body="{ data }">
                            <tr v-for="row in data" :key="row.id" class="border-b border-slate-50 transition-colors last:border-0 hover:bg-slate-50/50">
                                <td v-for="col in columns" :key="col.key"
                                    :class="['whitespace-nowrap px-6 py-4 text-sm', col.align === 'right' ? 'text-right font-bold text-slate-800' : 'text-slate-600', col.strong ? 'font-black text-slate-900' : '']">
                                    <StatusBadge v-if="col.format === 'status'" :status="get(row, col.key)" />
                                    <template v-else>{{ renderCell(row, col) }}</template>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-right">
                                    <Link :href="route(showRouteName, row.id)"
                                        class="inline-flex rounded-lg p-2 text-slate-400 transition-all hover:bg-emerald-50 hover:text-emerald-600" title="Review">
                                        <EyeIcon class="h-5 w-5" />
                                    </Link>
                                </td>
                            </tr>
                        </template>
                    </DataTable>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
