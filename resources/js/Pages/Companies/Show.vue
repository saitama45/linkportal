<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { 
    BuildingOfficeIcon, 
    ArrowLeftIcon,
    PencilSquareIcon,
    UserGroupIcon,
    ShieldCheckIcon
} from '@heroicons/vue/24/outline';

defineProps({
    company: Object,
});
</script>

<template>
    <Head :title="`${company.name} - Company Details`" />

    <AppLayout>
        <template #header>
            <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                <div>
                    <h2 class="font-bold text-2xl text-slate-800 leading-tight">{{ company.name }}</h2>
                    <p class="text-sm text-slate-500 mt-1">Detailed overview of entity.</p>
                    </div>
                <div class="mt-4 md:mt-0">
                    <Link
                        :href="route('companies.index')"
                        class="inline-flex items-center px-4 py-2 bg-white border border-slate-300 rounded-lg font-semibold text-xs text-slate-700 uppercase tracking-widest shadow-sm hover:bg-slate-50 transition ease-in-out duration-150"
                    >
                        <ArrowLeftIcon class="w-4 h-4 mr-2" />
                        Back to List
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <!-- Company Header Card -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-2xl border border-slate-100 p-6 md:p-8">
                    <div class="flex flex-col md:flex-row items-start md:items-center justify-between">
                        <div class="flex items-center">
                            <div class="h-16 w-16 bg-blue-50 rounded-2xl flex items-center justify-center border border-blue-100 text-blue-600">
                                <BuildingOfficeIcon class="w-8 h-8" />
                            </div>
                            <div class="ml-6">
                                <h1 class="text-2xl font-bold text-slate-900">{{ company.name }}</h1>
                                <div class="flex items-center space-x-3 mt-1">
                                    <span class="text-sm font-mono font-bold text-slate-500 bg-slate-100 px-2 py-0.5 rounded">{{ company.code }}</span>
                                    <span 
                                        :class="[
                                            'px-2 py-0.5 text-xs font-bold rounded-full border',
                                            company.is_active 
                                                ? 'bg-emerald-50 text-emerald-700 border-emerald-100' 
                                                : 'bg-slate-50 text-slate-500 border-slate-200'
                                        ]"
                                    >
                                        {{ company.is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-8 border-t border-slate-100 pt-6">
                        <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wider mb-2">Description</h3>
                        <p class="text-slate-600 leading-relaxed max-w-3xl">
                            {{ company.description || 'No detailed description available for this company.' }}
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Stats / Info -->
                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-2xl border border-slate-100 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-bold text-slate-900">System Metadata</h3>
                            <ShieldCheckIcon class="w-5 h-5 text-slate-400" />
                        </div>
                        <dl class="grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-2">
                            <div>
                                <dt class="text-xs font-bold text-slate-500 uppercase tracking-wider">Created At</dt>
                                <dd class="mt-1 text-sm font-medium text-slate-900">{{ new Date(company.created_at).toLocaleDateString() }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-bold text-slate-500 uppercase tracking-wider">Last Updated</dt>
                                <dd class="mt-1 text-sm font-medium text-slate-900">{{ new Date(company.updated_at).toLocaleDateString() }}</dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Placeholder for future modules linkage -->
                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-2xl border border-slate-100 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-bold text-slate-900">Associated Users</h3>
                            <UserGroupIcon class="w-5 h-5 text-slate-400" />
                        </div>
                        <div class="text-center py-6">
                             <p class="text-sm text-slate-500 italic">User association functionality coming soon.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
