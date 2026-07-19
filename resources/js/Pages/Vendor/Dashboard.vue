<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import VendorLayout from '@/Layouts/VendorLayout.vue';
import StatusBadge from '@/Components/Portal/StatusBadge.vue';
import {
    ArrowRightIcon,
    ArrowUpTrayIcon,
    BellIcon,
    DocumentTextIcon,
    ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    stats: Object,
    expiringDocuments: Array,
    recentNotifications: Array,
    accountStatus: String,
    profileStatus: String,
});

const page = usePage();
const vendor = computed(() => page.props.auth?.vendor || {});

const cards = computed(() => [
    { label: 'Document Uploads', value: props.stats?.uploads ?? 0, sub: `${props.stats?.uploads_returned ?? 0} need your attention`, route: 'vendor.document-uploads.index', icon: ArrowUpTrayIcon, tone: 'emerald' },
    { label: 'Documents', value: props.stats?.documents ?? 0, sub: `${props.stats?.documents_pending ?? 0} under review`, route: 'vendor.documents.index', icon: DocumentTextIcon, tone: 'indigo' },
]);

const tones = {
    emerald: 'bg-emerald-50 text-emerald-600 border-emerald-100',
    teal: 'bg-teal-50 text-teal-600 border-teal-100',
    sky: 'bg-sky-50 text-sky-600 border-sky-100',
    indigo: 'bg-indigo-50 text-indigo-600 border-indigo-100',
};

const formatDate = (value) => (value ? new Date(value).toLocaleDateString() : '');
</script>

<template>
    <Head title="Vendor Dashboard - Link Portal" />

    <VendorLayout>
        <template #header>
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <h2 class="text-2xl font-black tracking-tight text-slate-900">Welcome back, {{ vendor.name }}</h2>
                    <p class="mt-1 text-sm text-slate-500">Your partner workspace at a glance.</p>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">Account</span>
                    <StatusBadge :status="accountStatus" />
                </div>
            </div>
        </template>

        <!-- KPI cards -->
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-4">
            <Link v-for="card in cards" :key="card.label" :href="route(card.route)"
                class="group rounded-3xl border border-slate-200 bg-white p-5 shadow-sm transition-all hover:border-slate-300 hover:shadow-md">
                <div class="flex items-center justify-between">
                    <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">{{ card.label }}</span>
                    <span :class="['flex h-9 w-9 items-center justify-center rounded-xl border', tones[card.tone]]">
                        <component :is="card.icon" class="h-4.5 w-4.5" />
                    </span>
                </div>
                <p class="mt-3 text-3xl font-black tracking-tight text-slate-900">{{ card.value }}</p>
                <p class="mt-1 text-xs font-semibold text-slate-500">{{ card.sub }}</p>
            </Link>
        </div>

        <div class="mt-8 grid grid-cols-1 gap-6 lg:grid-cols-2">
            <!-- Expiring documents -->
            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="mb-4 flex items-center justify-between border-b border-slate-100 pb-4">
                    <h3 class="text-xs font-black uppercase tracking-widest text-slate-900">Expiring Accreditations</h3>
                    <Link :href="route('vendor.documents.index')" class="inline-flex items-center gap-1 text-xs font-black uppercase tracking-wider text-emerald-600 hover:text-emerald-700">
                        Manage <ArrowRightIcon class="h-3 w-3 stroke-[2.5]" />
                    </Link>
                </div>
                <div v-if="expiringDocuments.length === 0" class="py-8 text-center text-sm font-medium text-slate-400">
                    No documents expiring within 30 days.
                </div>
                <ul v-else class="space-y-3">
                    <li v-for="doc in expiringDocuments" :key="doc.id" class="flex items-center justify-between rounded-2xl border border-amber-100 bg-amber-50/50 px-4 py-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <ExclamationTriangleIcon class="h-5 w-5 shrink-0 text-amber-500" />
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold text-slate-800">{{ doc.title }}</p>
                                <p class="text-xs font-semibold text-amber-700">Expires {{ formatDate(doc.expiry_date) }}</p>
                            </div>
                        </div>
                    </li>
                </ul>
            </div>

            <!-- Notifications -->
            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="mb-4 flex items-center justify-between border-b border-slate-100 pb-4">
                    <h3 class="text-xs font-black uppercase tracking-widest text-slate-900">Recent Activity</h3>
                    <BellIcon class="h-4 w-4 text-slate-300" />
                </div>
                <div v-if="recentNotifications.length === 0" class="py-8 text-center text-sm font-medium text-slate-400">
                    Nothing new yet.
                </div>
                <ul v-else class="divide-y divide-slate-50">
                    <li v-for="n in recentNotifications" :key="n.id" class="flex items-start gap-3 py-3">
                        <span :class="['mt-1.5 h-2 w-2 shrink-0 rounded-full', n.read_at ? 'bg-slate-200' : 'bg-emerald-500']"></span>
                        <div class="min-w-0">
                            <p class="text-sm font-bold text-slate-800">{{ n.title }}</p>
                            <p v-if="n.message" class="text-xs leading-5 text-slate-500">{{ n.message }}</p>
                        </div>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Profile status callout -->
        <div v-if="profileStatus !== 'approved'" class="mt-8 flex flex-col items-start justify-between gap-4 rounded-3xl border border-emerald-200 bg-emerald-50/60 p-6 sm:flex-row sm:items-center">
            <div>
                <h3 class="text-sm font-black text-emerald-950">Complete your company profile</h3>
                <p class="mt-1 text-xs font-medium text-emerald-800">
                    Profile status: <span class="font-bold capitalize">{{ profileStatus }}</span>.
                    A complete, approved profile with accreditation documents speeds up transaction processing.
                </p>
            </div>
            <Link :href="route('vendor.profile.edit')"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-xl bg-emerald-600 px-4 py-2.5 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-700">
                Update Profile <ArrowRightIcon class="h-3.5 w-3.5" />
            </Link>
        </div>
    </VendorLayout>
</template>
