<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import Toast from '@/Components/Toast.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import { useToast } from '@/Composables/useToast.js';
import { useConfirm } from '@/Composables/useConfirm.js';
import {
    ArrowLeftOnRectangleIcon,
    Bars3Icon,
    ChevronDownIcon,
    ClipboardDocumentListIcon,
    DocumentTextIcon,
    LinkIcon,
    ReceiptPercentIcon,
    Squares2X2Icon,
    UserCircleIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';

const page = usePage();
const vendor = computed(() => page.props.auth?.vendor || {});
const mobileOpen = ref(false);
const menuOpen = ref(false);
const menuRef = ref(null);

const { showSuccess, showError, showWarning, showInfo } = useToast();
const {
    showConfirmModal, confirmTitle, confirmMessage, confirmButtonText,
    cancelButtonText, confirmType, handleConfirm, handleCancel,
} = useConfirm();

watch(() => page.props.flash, (flash) => {
    if (flash?.success) { showSuccess(flash.success); page.props.flash.success = null; }
    if (flash?.error) { showError(flash.error); page.props.flash.error = null; }
    if (flash?.warning) { showWarning(flash.warning); page.props.flash.warning = null; }
    if (flash?.info) { showInfo(flash.info); page.props.flash.info = null; }
}, { deep: true, immediate: true });

const nav = [
    { label: 'Dashboard', route: 'vendor.dashboard', active: 'vendor.dashboard', icon: Squares2X2Icon },
    { label: 'Invoices', route: 'vendor.invoices.index', active: 'vendor.invoices.*', icon: ReceiptPercentIcon },
    { label: 'Purchase Orders', route: 'vendor.purchase-orders.index', active: 'vendor.purchase-orders.*', icon: ClipboardDocumentListIcon },
    { label: 'Quotations', route: 'vendor.quotations.index', active: 'vendor.quotations.*', icon: DocumentTextIcon },
    { label: 'Documents', route: 'vendor.documents.index', active: 'vendor.documents.*', icon: DocumentTextIcon },
];

const isActive = (pattern) => route().current(pattern);
const logout = () => router.post(route('vendor.logout'));

const closeOnOutside = (event) => {
    if (menuRef.value && !menuRef.value.contains(event.target)) menuOpen.value = false;
};
onMounted(() => document.addEventListener('click', closeOnOutside));
onUnmounted(() => document.removeEventListener('click', closeOnOutside));
</script>

<template>
    <div class="min-h-screen bg-[#F8FAFC] font-sans antialiased text-slate-900">
        <!-- Top nav -->
        <header class="sticky top-0 z-30 bg-gradient-to-r from-emerald-950 via-emerald-950 to-teal-950 border-b border-emerald-400/10 shadow-lg shadow-emerald-950/20">
            <div class="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
                <div class="flex items-center gap-8">
                    <Link :href="route('vendor.dashboard')" class="flex items-center gap-2.5">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-emerald-400 to-teal-500 text-emerald-950">
                            <LinkIcon class="h-4.5 w-4.5" />
                        </span>
                        <span class="leading-tight">
                            <span class="block text-sm font-black text-white">Link Portal</span>
                            <span class="block text-[9px] font-bold uppercase tracking-[0.2em] text-emerald-300/70">Vendor Center</span>
                        </span>
                    </Link>

                    <nav class="hidden lg:flex items-center gap-1">
                        <Link v-for="item in nav" :key="item.route" :href="route(item.route)"
                            :class="[
                                'rounded-lg px-3 py-2 text-xs font-bold transition',
                                isActive(item.active) ? 'bg-emerald-400/15 text-white' : 'text-emerald-100/60 hover:bg-white/5 hover:text-white',
                            ]">
                            {{ item.label }}
                        </Link>
                    </nav>
                </div>

                <div class="flex items-center gap-2">
                    <div class="relative" ref="menuRef">
                        <button @click="menuOpen = !menuOpen"
                            class="flex items-center gap-2.5 rounded-xl p-1.5 pr-3 hover:bg-white/10 transition-all border border-transparent hover:border-white/10">
                            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-emerald-400 to-teal-500 text-xs font-black text-emerald-950">
                                {{ vendor.name?.charAt(0)?.toUpperCase() || 'V' }}
                            </span>
                            <span class="hidden sm:block text-left">
                                <span class="block text-xs font-black text-white leading-none mb-0.5">{{ vendor.name }}</span>
                                <span class="block text-[9px] font-black uppercase tracking-widest text-emerald-300">{{ vendor.code }}</span>
                            </span>
                            <ChevronDownIcon class="h-3.5 w-3.5 text-emerald-100/60" />
                        </button>

                        <div v-show="menuOpen"
                            class="absolute right-0 mt-3 w-56 overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-slate-200 z-50">
                            <div class="border-b border-slate-100 bg-slate-50 p-4">
                                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1">Signed in as</p>
                                <p class="truncate text-sm font-bold text-slate-900">{{ vendor.email }}</p>
                            </div>
                            <div class="p-2">
                                <Link :href="route('vendor.profile.edit')"
                                    class="flex items-center gap-3 rounded-xl px-4 py-2.5 text-sm font-bold text-slate-600 hover:bg-emerald-50 hover:text-emerald-600 transition-all">
                                    <UserCircleIcon class="h-5 w-5 opacity-70" />
                                    Company Profile
                                </Link>
                                <button @click="logout"
                                    class="flex w-full items-center gap-3 rounded-xl px-4 py-2.5 text-sm font-bold text-red-500 hover:bg-red-50 transition-all">
                                    <ArrowLeftOnRectangleIcon class="h-5 w-5" />
                                    Sign Out
                                </button>
                            </div>
                        </div>
                    </div>

                    <button @click="mobileOpen = !mobileOpen" class="lg:hidden rounded-xl p-2 text-emerald-100/70 hover:bg-white/10 hover:text-white">
                        <XMarkIcon v-if="mobileOpen" class="h-6 w-6" />
                        <Bars3Icon v-else class="h-6 w-6" />
                    </button>
                </div>
            </div>

            <!-- Mobile nav -->
            <nav v-if="mobileOpen" class="lg:hidden border-t border-emerald-400/10 px-4 py-3 space-y-1">
                <Link v-for="item in nav" :key="item.route" :href="route(item.route)" @click="mobileOpen = false"
                    :class="[
                        'flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-bold transition',
                        isActive(item.active) ? 'bg-emerald-400/15 text-white' : 'text-emerald-100/60 hover:bg-white/5 hover:text-white',
                    ]">
                    <component :is="item.icon" class="h-4 w-4" />
                    {{ item.label }}
                </Link>
            </nav>
        </header>

        <!-- Pending-account banner -->
        <div v-if="vendor.status === 'pending'" class="bg-amber-50 border-b border-amber-200 px-4 py-2.5 text-center">
            <p class="text-xs font-bold text-amber-800">
                Your account is pending activation. Complete your profile and upload accreditation documents — transactions unlock once an administrator approves your account.
            </p>
        </div>

        <!-- Content -->
        <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <div v-if="$slots.header" class="mb-8">
                <slot name="header" />
            </div>
            <slot />
        </main>

        <footer class="mx-auto max-w-7xl px-4 pb-8 sm:px-6 lg:px-8">
            <div class="border-t border-slate-200 pt-4 flex items-center justify-between text-[9px] font-black uppercase tracking-widest text-slate-400">
                <span>Link Portal · Secure Partner Access</span>
                <span>&copy; {{ new Date().getFullYear() }}</span>
            </div>
        </footer>

        <Toast />
        <ConfirmModal
            :show="showConfirmModal"
            :title="confirmTitle"
            :message="confirmMessage"
            :confirm-button-text="confirmButtonText"
            :cancel-button-text="cancelButtonText"
            :type="confirmType"
            @confirm="handleConfirm"
            @cancel="handleCancel"
        />
    </div>
</template>
