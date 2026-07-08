<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import {
    ArrowRightIcon,
    BuildingOffice2Icon,
    IdentificationIcon,
    SparklesIcon,
    UsersIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    stats: Object,
});

const page = usePage();
const user = computed(() => page.props.auth?.user || {});

const cards = computed(() => [
    {
        label: 'System Members',
        value: props.stats?.users_count ?? 0,
        caption: 'active users',
        route: 'users.index',
        cta: 'Manage Users',
        icon: UsersIcon,
        tone: 'indigo',
    },
    {
        label: 'Companies',
        value: props.stats?.companies_count ?? 0,
        caption: 'registered',
        route: 'companies.index',
        cta: 'Manage Companies',
        icon: BuildingOffice2Icon,
        tone: 'emerald',
    },
    {
        label: 'Roles',
        value: props.stats?.roles_count ?? 0,
        caption: 'defined',
        route: 'roles.index',
        cta: 'Manage Roles',
        icon: IdentificationIcon,
        tone: 'sky',
    },
]);

const toneClasses = {
    indigo: 'bg-indigo-50 text-indigo-600 border-indigo-100',
    emerald: 'bg-emerald-50 text-emerald-600 border-emerald-100',
    sky: 'bg-sky-50 text-sky-600 border-sky-100',
};

const ctaClasses = {
    indigo: 'text-indigo-600 hover:text-indigo-700',
    emerald: 'text-emerald-600 hover:text-emerald-700',
    sky: 'text-sky-600 hover:text-sky-700',
};
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :fluid="true">
        <!-- Welcome banner -->
        <div class="relative overflow-hidden rounded-3xl bg-slate-900 px-8 py-10 shadow-xl mb-8">
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(14,165,233,0.15),transparent_45%)]"></div>
            <div class="relative z-10">
                <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-500/10 px-3 py-1 text-xs font-black uppercase tracking-wider text-sky-400 border border-sky-500/10">
                    <SparklesIcon class="h-3.5 w-3.5" />
                    Portal
                </span>
                <h2 class="mt-3 text-3xl font-black tracking-tight text-white sm:text-4xl">
                    Welcome back, {{ user.name || 'there' }}
                </h2>
                <p class="mt-2 text-sm text-slate-300 max-w-xl font-medium leading-relaxed">
                    Manage users, companies, and roles from your secure administration workspace.
                </p>
            </div>
        </div>

        <!-- KPI cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div
                v-for="card in cards"
                :key="card.label"
                class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm hover:shadow-md hover:border-slate-300 transition-all duration-300"
            >
                <div class="flex items-center justify-between">
                    <span class="text-xs font-black uppercase tracking-widest text-slate-400">{{ card.label }}</span>
                    <span :class="['flex h-10 w-10 items-center justify-center rounded-2xl border', toneClasses[card.tone]]">
                        <component :is="card.icon" class="h-5 w-5 stroke-2" />
                    </span>
                </div>
                <div class="mt-4 flex items-baseline gap-2">
                    <span class="text-3xl font-black text-slate-900 tracking-tight">{{ card.value }}</span>
                    <span class="text-xs font-semibold text-slate-500">{{ card.caption }}</span>
                </div>
                <div class="mt-4 pt-4 border-t border-slate-50">
                    <Link :href="route(card.route)" :class="['text-xs font-black uppercase tracking-wider inline-flex items-center gap-1', ctaClasses[card.tone]]">
                        {{ card.cta }}
                        <ArrowRightIcon class="h-3 w-3 stroke-[2.5]" />
                    </Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
