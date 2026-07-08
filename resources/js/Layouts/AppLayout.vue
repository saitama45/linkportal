<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { Head, Link, usePage, router } from '@inertiajs/vue3';
import Sidebar from '@/Components/Sidebar.vue';
import NotificationDropdown from '@/Components/NotificationDropdown.vue';
import Toast from '@/Components/Toast.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import { useToast } from '@/Composables/useToast.js';
import { useConfirm } from '@/Composables/useConfirm.js';
import { usePermission } from '@/Composables/usePermission.js';
import {
    BellIcon,
    Bars3Icon,
    XMarkIcon,
    UserCircleIcon,
    ChevronDownIcon,
    ShieldCheckIcon,
    ArrowLeftOnRectangleIcon,
    ArrowUpIcon,
    ClockIcon
} from '@heroicons/vue/24/outline';

const props = defineProps({
    fluid: {
        type: Boolean,
        default: false
    }
});

const page = usePage();
const user = computed(() => page.props.auth?.user || {});

const getStoredSidebarState = () => {
    if (typeof window !== 'undefined') {
        return localStorage.getItem('sidebarCollapsed') === 'true';
    }
    return false;
};

const sidebarCollapsed = ref(getStoredSidebarState());
const mobileMenuOpen = ref(false);
const userMenuOpen = ref(false);
const userMenuRef = ref(null);
const mainRef = ref(null);
const showScrollTop = ref(false);

const handleMainScroll = () => {
    showScrollTop.value = (mainRef.value?.scrollTop ?? 0) > 300;
};

const scrollToTop = () => {
    mainRef.value?.scrollTo({ top: 0, behavior: 'smooth' });
};
const { showSuccess, showError, showWarning, showInfo } = useToast();
const { 
    showConfirmModal, 
    confirmTitle, 
    confirmMessage, 
    confirmButtonText, 
    cancelButtonText, 
    confirmType, 
    handleConfirm, 
    handleCancel 
} = useConfirm();
const { hasPermission } = usePermission();

// Watch for Inertia flash messages
watch(() => page.props.flash, (flash) => {
    if (flash?.success) {
        showSuccess(flash.success);
        // Clear flash to prevent duplicates on manual navigation
        page.props.flash.success = null;
    }
    if (flash?.error) {
        showError(flash.error);
        page.props.flash.error = null;
    }
    if (flash?.warning) {
        showWarning(flash.warning);
        page.props.flash.warning = null;
    }
    if (flash?.info) {
        showInfo(flash.info);
        page.props.flash.info = null;
    }
}, { deep: true, immediate: true });

const toggleSidebar = () => {
    sidebarCollapsed.value = !sidebarCollapsed.value;
};

watch(sidebarCollapsed, (newValue) => {
    if (typeof window !== 'undefined') {
        localStorage.setItem('sidebarCollapsed', newValue);
    }
});

const logout = () => {
    router.post(route('logout'));
};

const handleClickOutside = (event) => {
    if (userMenuRef.value && !userMenuRef.value.contains(event.target)) {
        userMenuOpen.value = false;
    }
};

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
});

const currentTime = ref(new Date().toLocaleTimeString());
setInterval(() => {
    currentTime.value = new Date().toLocaleTimeString();
}, 1000);
</script>

<template>
    <div class="h-screen overflow-hidden bg-[#F8FAFC] flex font-sans antialiased text-slate-900">
        <!-- Mobile Sidebar Drawer -->
        <div v-if="mobileMenuOpen" class="relative z-50 lg:hidden" role="dialog" aria-modal="true">
            <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-md transition-opacity" @click="mobileMenuOpen = false"></div>
            <div class="fixed inset-0 flex">
                <div class="relative flex w-full max-w-xs flex-1">
                    <Sidebar 
                        :is-collapsed="false" 
                        @toggle="mobileMenuOpen = false"
                        class="flex h-full w-full"
                    />
                </div>
            </div>
        </div>

        <!-- Desktop Sidebar -->
        <Sidebar 
            :is-collapsed="sidebarCollapsed" 
            @toggle="toggleSidebar"
            class="hidden lg:flex flex-shrink-0 z-30"
        />

        <!-- Main Content -->
        <div class="flex-1 flex flex-col min-w-0 overflow-hidden relative">
            
            <!-- Top Navigation -->
            <header class="flex-shrink-0 bg-gradient-to-r from-emerald-950 via-emerald-950 to-teal-950 backdrop-blur-xl border-b border-emerald-400/10 h-20 flex items-center justify-between px-6 lg:px-10 z-20 shadow-lg shadow-emerald-950/20">
                
                <!-- Left: Toggle & Page Info -->
                <div class="flex items-center space-x-6">
                    <button
                        @click="mobileMenuOpen = true"
                        type="button"
                        class="lg:hidden p-2.5 text-emerald-100/70 hover:text-white hover:bg-white/10 rounded-xl transition-all"
                    >
                        <Bars3Icon class="h-6 w-6" />
                    </button>

                    <div class="hidden sm:flex items-center space-x-3 bg-white/[0.06] px-4 py-2 rounded-2xl border border-white/10">
                        <ClockIcon class="w-4 h-4 text-emerald-300" />
                        <span class="text-[11px] font-black text-emerald-100/70 uppercase tracking-widest">{{ currentTime }}</span>
                    </div>
                </div>

                <!-- Right: Actions & Profile -->
                <div class="flex items-center space-x-4 lg:space-x-8">
                    <!-- Notifications -->
                    <NotificationDropdown />

                    <!-- Staff Profile -->
                    <div class="relative" ref="userMenuRef">
                        <button
                            @click="userMenuOpen = !userMenuOpen"
                            class="flex items-center space-x-3 p-1.5 pr-4 rounded-2xl hover:bg-white/10 transition-all border border-transparent hover:border-white/10"
                        >
                            <div class="relative">
                                <div v-if="user.profile_photo" class="h-10 w-10 rounded-xl overflow-hidden ring-2 ring-white/20 shadow-sm">
                                    <img :src="'/storage/' + user.profile_photo" class="h-full w-full object-cover" :alt="user.name">
                                </div>
                                <div v-else class="h-10 w-10 rounded-xl flex items-center justify-center ring-2 ring-white/20 shadow-sm bg-gradient-to-br from-emerald-400 to-teal-500">
                                    <span class="text-xs font-black text-emerald-950">{{ user.name?.charAt(0) || 'U' }}</span>
                                </div>
                                <div class="absolute -bottom-0.5 -right-0.5 w-3 h-3 bg-emerald-400 border-2 border-emerald-950 rounded-full"></div>
                            </div>
                            <div class="hidden lg:block text-left">
                                <p class="text-xs font-black text-white leading-none mb-1">{{ user.name }}</p>
                                <p class="text-[9px] font-black uppercase tracking-widest text-emerald-300">Administrator</p>
                            </div>
                            <ChevronDownIcon :class="['hidden lg:block w-3.5 h-3.5 text-emerald-100/60 transition-transform duration-300', userMenuOpen ? 'rotate-180' : '']" />
                        </button>

                        <!-- Dropdown -->
                        <div 
                            v-show="userMenuOpen" 
                            class="absolute right-0 mt-4 w-64 rounded-3xl shadow-2xl bg-white ring-1 ring-slate-200 overflow-hidden z-50 animate-in fade-in zoom-in slide-in-from-top-4 duration-200"
                        >
                            <div class="p-6 bg-slate-50 border-b border-slate-100">
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Authenticated As</p>
                                <p class="text-sm font-bold text-slate-900 truncate">{{ user.email }}</p>
                            </div>
                            
                            <div class="p-2">
                                <Link :href="route('profile.edit')" class="flex items-center space-x-3 px-4 py-3 text-sm font-bold text-slate-600 hover:bg-emerald-50 hover:text-emerald-600 rounded-2xl transition-all">
                                    <UserCircleIcon class="w-5 h-5 opacity-70" />
                                    <span>Profile Settings</span>
                                </Link>
                            </div>
                            
                            <div class="p-2 border-t border-slate-50">
                                <button @click="logout" class="w-full flex items-center space-x-3 px-4 py-3 text-sm font-black text-red-500 hover:bg-red-50 rounded-2xl transition-all uppercase tracking-widest">
                                    <ArrowLeftOnRectangleIcon class="w-5 h-5" />
                                    <span>Sign Out</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Main Content Area -->
            <main ref="mainRef" @scroll="handleMainScroll" class="flex-1 overflow-y-auto focus:outline-none custom-scrollbar pb-20">
                <div class="p-6 lg:p-10">
                     <!-- Header Slot -->
                    <div class="mb-10">
                        <slot name="header"></slot>
                    </div>
                    
                    <!-- Content Slot -->
                    <div :class="[fluid ? 'w-full' : 'max-w-7xl mx-auto']">
                         <slot />
                    </div>
                </div>
            </main>

            <!-- Scroll to top -->
            <transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0 translate-y-2 scale-90"
                enter-to-class="opacity-100 translate-y-0 scale-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100 translate-y-0 scale-100"
                leave-to-class="opacity-0 translate-y-2 scale-90"
            >
                <button
                    v-show="showScrollTop"
                    @click="scrollToTop"
                    type="button"
                    aria-label="Scroll to top"
                    class="absolute bottom-20 right-6 z-40 flex h-11 w-11 items-center justify-center rounded-full bg-gradient-to-br from-emerald-500 to-teal-600 text-white shadow-lg shadow-emerald-600/30 ring-1 ring-white/20 transition-all hover:-translate-y-0.5 hover:from-emerald-400 hover:to-teal-500 hover:shadow-emerald-600/40 active:scale-95"
                >
                    <ArrowUpIcon class="h-5 w-5 stroke-[2.5]" />
                </button>
            </transition>

            <!-- Legal/Security Footer Sticky -->
            <div class="absolute bottom-0 left-0 right-0 bg-white/50 backdrop-blur-md px-10 py-3 border-t border-slate-200 flex items-center justify-between pointer-events-none">
                <div class="flex items-center space-x-4 text-[9px] font-black text-slate-400 uppercase tracking-widest">
                    <span>Link Portal &middot; Secure Partner Access</span>
                </div>
                <div class="text-[9px] font-black text-slate-400 uppercase tracking-widest">
                    &copy; {{ new Date().getFullYear() }}
                </div>
            </div>
        </div>
        
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

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    width: 6px;
    height: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background-color: #E2E8F0;
    border-radius: 20px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background-color: #CBD5E1;
}
</style>
