<script setup>
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    ArrowLeftOnRectangleIcon,
    BuildingOffice2Icon,
    BuildingStorefrontIcon,
    ChevronDownIcon,
    BanknotesIcon,
    CubeIcon,
    DocumentMagnifyingGlassIcon,
    ExclamationTriangleIcon,
    InboxArrowDownIcon,
    IdentificationIcon,
    LinkIcon,
    Squares2X2Icon,
    UserGroupIcon,
} from '@heroicons/vue/24/outline';
import { usePermission } from '@/Composables/usePermission';

const props = defineProps({
    isCollapsed: { type: Boolean, default: false },
});

defineEmits(['toggle']);

const page = usePage();
const user = computed(() => page.props.auth?.user || {});
const { hasPermission } = usePermission();
const managementOpen = ref(true);
const vendorsOpen = ref(true);
const documentsOpen = ref(true);

const isActive = (...patterns) => patterns.some((pattern) => route().current(pattern));

const linkClass = (active) => [
    'group relative flex items-center rounded-lg px-3 py-2.5 transition',
    active
        ? "bg-emerald-400/15 text-white before:absolute before:left-0 before:top-1/2 before:h-5 before:w-1 before:-translate-y-1/2 before:rounded-r-full before:bg-gradient-to-b before:from-emerald-400 before:to-teal-400 before:content-['']"
        : 'text-emerald-50/55 hover:bg-white/5 hover:text-white',
];

const iconClass = [
    'h-5 w-5 flex-shrink-0 transition group-hover:scale-105',
    props.isCollapsed ? 'mx-auto' : 'mr-3',
];
</script>

<template>
    <div class="sticky top-0 flex h-screen font-sans">
        <aside
            :class="[
                'flex flex-col border-r border-emerald-400/10 bg-gradient-to-b from-emerald-950 via-emerald-950 to-teal-950 transition-all duration-300',
                isCollapsed ? 'w-20' : 'w-72',
            ]"
        >
            <div class="flex h-20 items-center border-b border-emerald-400/10 px-5">
                <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-emerald-400 to-teal-500 text-emerald-950">
                    <LinkIcon class="h-5 w-5" />
                </div>
                <div v-if="!isCollapsed" class="ml-3 min-w-0">
                    <p class="truncate text-sm font-bold text-white">Link Portal</p>
                    <p class="text-[10px] font-bold uppercase tracking-widest text-emerald-300/70">Partner Access</p>
                </div>
            </div>

            <nav class="flex-1 overflow-y-auto px-3 py-5">
                <Link
                    v-if="hasPermission('dashboard.view')"
                    :href="route('dashboard')"
                    :class="linkClass(isActive('dashboard'))"
                >
                    <Squares2X2Icon :class="iconClass" />
                    <span v-if="!isCollapsed" class="text-sm font-semibold">Dashboard</span>
                </Link>

                <!-- Vendors group -->
                <div class="mt-6">
                    <button
                        type="button"
                        class="group flex w-full items-center rounded-lg px-3 py-2.5 text-emerald-50/55 transition hover:bg-white/5 hover:text-white"
                        @click="vendorsOpen = !vendorsOpen"
                    >
                        <BuildingStorefrontIcon :class="iconClass" />
                        <span v-if="!isCollapsed" class="flex-1 text-left text-sm font-semibold">Vendors</span>
                        <ChevronDownIcon
                            v-if="!isCollapsed"
                            :class="['h-4 w-4 transition', vendorsOpen ? 'rotate-180' : '']"
                        />
                    </button>

                    <div v-if="vendorsOpen || isCollapsed" class="mt-1 space-y-1" :class="isCollapsed ? '' : 'pl-4'">
                        <Link
                            v-if="hasPermission('vendors.view')"
                            :href="route('vendors.index')"
                            :class="linkClass(isActive('vendors.*'))"
                        >
                            <BuildingStorefrontIcon :class="isCollapsed ? 'mx-auto h-5 w-5' : 'mr-3 h-4 w-4'" />
                            <span v-if="!isCollapsed" class="text-xs font-semibold">Vendor Directory</span>
                        </Link>
                        <Link
                            v-if="hasPermission('products.view')"
                            :href="route('products.index')"
                            :class="linkClass(isActive('products.*'))"
                        >
                            <CubeIcon :class="isCollapsed ? 'mx-auto h-5 w-5' : 'mr-3 h-4 w-4'" />
                            <span v-if="!isCollapsed" class="text-xs font-semibold">Products</span>
                        </Link>
                    </div>
                </div>

                <!-- Document Processing group (OCR intake) -->
                <div
                    v-if="hasPermission('document-intake.view') || hasPermission('document-templates.view') || hasPermission('document-exceptions.view') || hasPermission('accounts-payable.view')"
                    class="mt-6"
                >
                    <button
                        type="button"
                        class="group flex w-full items-center rounded-lg px-3 py-2.5 text-emerald-50/55 transition hover:bg-white/5 hover:text-white"
                        @click="documentsOpen = !documentsOpen"
                    >
                        <InboxArrowDownIcon :class="iconClass" />
                        <span v-if="!isCollapsed" class="flex-1 text-left text-sm font-semibold">Document Processing</span>
                        <ChevronDownIcon
                            v-if="!isCollapsed"
                            :class="['h-4 w-4 transition', documentsOpen ? 'rotate-180' : '']"
                        />
                    </button>

                    <div v-if="documentsOpen || isCollapsed" class="mt-1 space-y-1" :class="isCollapsed ? '' : 'pl-4'">
                        <Link
                            v-if="hasPermission('document-intake.view')"
                            :href="route('document-intake.index')"
                            :class="linkClass(isActive('document-intake.*'))"
                        >
                            <InboxArrowDownIcon :class="isCollapsed ? 'mx-auto h-5 w-5' : 'mr-3 h-4 w-4'" />
                            <span v-if="!isCollapsed" class="text-xs font-semibold">Document Intake</span>
                        </Link>
                        <Link
                            v-if="hasPermission('document-templates.view')"
                            :href="route('document-templates.index')"
                            :class="linkClass(isActive('document-templates.*'))"
                        >
                            <DocumentMagnifyingGlassIcon :class="isCollapsed ? 'mx-auto h-5 w-5' : 'mr-3 h-4 w-4'" />
                            <span v-if="!isCollapsed" class="text-xs font-semibold">OCR Templates</span>
                        </Link>
                        <Link
                            v-if="hasPermission('document-exceptions.view')"
                            :href="route('document-exceptions.index')"
                            :class="linkClass(isActive('document-exceptions.*'))"
                        >
                            <ExclamationTriangleIcon :class="isCollapsed ? 'mx-auto h-5 w-5' : 'mr-3 h-4 w-4'" />
                            <span v-if="!isCollapsed" class="text-xs font-semibold">Exceptions</span>
                        </Link>
                        <Link
                            v-if="hasPermission('accounts-payable.view')"
                            :href="route('accounts-payable.index')"
                            :class="linkClass(isActive('accounts-payable.*'))"
                        >
                            <BanknotesIcon :class="isCollapsed ? 'mx-auto h-5 w-5' : 'mr-3 h-4 w-4'" />
                            <span v-if="!isCollapsed" class="text-xs font-semibold">Accounts Payable</span>
                        </Link>
                    </div>
                </div>

                <div class="mt-6">
                    <button
                        type="button"
                        class="group flex w-full items-center rounded-lg px-3 py-2.5 text-emerald-50/55 transition hover:bg-white/5 hover:text-white"
                        @click="managementOpen = !managementOpen"
                    >
                        <IdentificationIcon :class="iconClass" />
                        <span v-if="!isCollapsed" class="flex-1 text-left text-sm font-semibold">Management</span>
                        <ChevronDownIcon
                            v-if="!isCollapsed"
                            :class="['h-4 w-4 transition', managementOpen ? 'rotate-180' : '']"
                        />
                    </button>

                    <div v-if="managementOpen || isCollapsed" class="mt-1 space-y-1" :class="isCollapsed ? '' : 'pl-4'">
                        <Link
                            v-if="hasPermission('companies.view')"
                            :href="route('companies.index')"
                            :class="linkClass(isActive('companies.*'))"
                        >
                            <BuildingOffice2Icon :class="isCollapsed ? 'mx-auto h-5 w-5' : 'mr-3 h-4 w-4'" />
                            <span v-if="!isCollapsed" class="text-xs font-semibold">Companies</span>
                        </Link>
                        <Link
                            v-if="hasPermission('users.view')"
                            :href="route('users.index')"
                            :class="linkClass(isActive('users.*'))"
                        >
                            <UserGroupIcon :class="isCollapsed ? 'mx-auto h-5 w-5' : 'mr-3 h-4 w-4'" />
                            <span v-if="!isCollapsed" class="text-xs font-semibold">Users</span>
                        </Link>
                        <Link
                            v-if="hasPermission('roles.view')"
                            :href="route('roles.index')"
                            :class="linkClass(isActive('roles.*'))"
                        >
                            <IdentificationIcon :class="isCollapsed ? 'mx-auto h-5 w-5' : 'mr-3 h-4 w-4'" />
                            <span v-if="!isCollapsed" class="text-xs font-semibold">Roles</span>
                        </Link>
                    </div>
                </div>
            </nav>

            <div class="border-t border-emerald-400/10 p-4">
                <div class="flex items-center">
                    <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-emerald-400 to-teal-500 text-xs font-bold text-emerald-950">
                        {{ user.name?.charAt(0)?.toUpperCase() || 'U' }}
                    </div>
                    <div v-if="!isCollapsed" class="ml-3 min-w-0 flex-1">
                        <p class="truncate text-xs font-bold text-white">{{ user.name || 'System User' }}</p>
                        <p class="truncate text-[10px] text-white/40">{{ user.email }}</p>
                    </div>
                    <Link
                        v-if="!isCollapsed"
                        :href="route('logout')"
                        method="post"
                        as="button"
                        class="ml-2 rounded-lg p-2 text-white/40 transition hover:bg-red-500/10 hover:text-red-300"
                    >
                        <ArrowLeftOnRectangleIcon class="h-4 w-4" />
                    </Link>
                </div>
            </div>
        </aside>
    </div>
</template>
