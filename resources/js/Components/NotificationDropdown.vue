<script setup>
import { computed, ref, onMounted, onUnmounted } from 'vue';
import { usePage, Link } from '@inertiajs/vue3';
import { 
    BellIcon, 
    ClockIcon, 
    ChevronRightIcon,
    DocumentTextIcon
} from '@heroicons/vue/24/outline';

const page = usePage();
const notifications = computed(() => page.props.auth?.notifications || []);

const isOpen = ref(false);
const dropdownRef = ref(null);

const toggleDropdown = () => {
    isOpen.value = !isOpen.value;
};

const closeDropdown = () => {
    isOpen.value = false;
};

const handleClickOutside = (event) => {
    if (dropdownRef.value && !dropdownRef.value.contains(event.target)) {
        closeDropdown();
    }
};

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
});

const iconMap = {
    ClockIcon,
    DocumentTextIcon
};

const getBadgeColor = (type) => {
    return 'bg-emerald-100 text-emerald-600';
};
</script>

<template>
    <div class="relative" ref="dropdownRef">
        <button
            @click="toggleDropdown"
            class="relative p-2 text-emerald-100/70 hover:text-white hover:bg-white/10 rounded-xl transition-all outline-none"
        >
            <BellIcon class="w-6 h-6" />
            <span v-if="notifications.length > 0" class="absolute -top-0.5 -right-0.5 flex h-5 w-5 items-center justify-center rounded-full bg-rose-500 text-[10px] font-black text-white ring-2 ring-emerald-950 shadow-sm">
                {{ notifications.length > 9 ? '9+' : notifications.length }}
            </span>
        </button>

        <transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="translate-y-1 opacity-0 scale-95"
            enter-to-class="translate-y-0 opacity-100 scale-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="translate-y-0 opacity-100 scale-100"
            leave-to-class="translate-y-1 opacity-0 scale-95"
        >
            <div 
                v-if="isOpen"
                class="absolute right-0 z-[100] mt-3 w-80 sm:w-96 transform origin-top-right overflow-hidden rounded-3xl shadow-2xl ring-1 ring-black ring-opacity-5 bg-white border border-slate-100"
            >
                <div class="px-6 py-5 bg-slate-50/50 border-b border-slate-100 flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest">Active Alerts</h3>
                        <p class="text-[10px] text-slate-500 font-bold uppercase mt-0.5">Real-time system notices</p>
                    </div>
                    <span class="px-2 py-0.5 bg-emerald-600 text-white text-[10px] font-black rounded-md">{{ notifications.length }}</span>
                </div>

                <div class="max-h-[400px] overflow-y-auto custom-scrollbar">
                    <div v-if="notifications.length === 0" class="py-12 text-center">
                        <BellIcon class="w-12 h-12 text-slate-100 mx-auto mb-3" />
                        <p class="text-xs text-slate-400 font-black uppercase tracking-widest">All caught up!</p>
                        <p class="text-[10px] text-slate-400 mt-1">No pending actions found.</p>
                    </div>
                    
                    <div v-else class="divide-y divide-slate-50">
                        <Link 
                            v-for="notif in notifications" 
                            :key="notif.id" 
                            :href="notif.url"
                            @click="closeDropdown"
                            class="flex items-start p-5 hover:bg-slate-50 transition-colors group"
                        >
                            <div :class="['p-2 rounded-xl mr-4 flex-shrink-0', getBadgeColor(notif.type)]">
                                <component :is="iconMap[notif.icon]" class="w-5 h-5" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center justify-between">
                                    <p class="text-xs font-black text-slate-900 truncate uppercase tracking-tight">{{ notif.title }}</p>
                                    <span class="text-[9px] font-bold text-slate-400 tabular-nums">{{ notif.created_at }}</span>
                                </div>
                                <p class="text-[11px] text-slate-500 leading-normal mt-1">{{ notif.message }}</p>
                            </div>
                            <ChevronRightIcon class="w-4 h-4 ml-3 text-slate-300 group-hover:text-emerald-500 transition-colors" />
                        </Link>
                    </div>
                </div>

                <div v-if="notifications.length > 0" class="p-4 bg-slate-50 border-t border-slate-100">
                    <div class="flex items-center justify-center space-x-2 text-[10px] font-black text-slate-400 uppercase tracking-widest italic">
                        <ClockIcon class="w-3 h-3" />
                        <span>Live Data from System Records</span>
                    </div>
                </div>
            </div>
        </transition>
    </div>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    width: 4px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background-color: #e2e8f0;
    border-radius: 10px;
}
</style>
