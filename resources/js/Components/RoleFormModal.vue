<template>
    <div v-if="show" class="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm transition-opacity" @click="close"></div>
        
        <div class="bg-white rounded-2xl shadow-2xl max-w-4xl w-full relative overflow-hidden animate-in fade-in zoom-in duration-200">
            <div class="px-8 py-6 border-b border-slate-100 bg-slate-50/50">
                <h3 class="text-xl font-bold text-slate-900">
                    {{ role ? 'Modify Security Role' : 'Define Security Role' }}
                </h3>
                <p class="text-sm text-slate-500">Configure role name and granular access permissions.</p>
            </div>
            
            <form @submit.prevent="submitForm">
                <div class="p-8 max-h-[70vh] overflow-y-auto custom-scrollbar">
                    <div class="mb-8">
                        <label class="block text-sm font-bold text-slate-700 mb-2">Role Designation (Display Name)</label>
                        <input v-model="form.name" type="text" required placeholder="Ex. Accounting Manager"
                               class="block w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all font-semibold">
                    </div>

                    <div class="mb-8">
                        <label class="block text-sm font-bold text-slate-700 mb-2">Assigned Landing Page</label>
                        <Autocomplete
                            v-model="form.landing_page"
                            :options="landingPageOptionItems"
                            placeholder="Search landing page..."
                            required
                        />
                        <p class="text-xs text-slate-400 mt-2">The system will redirect the user to this module immediately after successful login.</p>
                    </div>

                    <div class="mb-8">
                        <label class="block text-sm font-bold text-slate-700 mb-2">Associated Companies</label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 max-h-48 overflow-y-auto custom-scrollbar border border-slate-200 rounded-xl p-3 bg-slate-50">
                            <label v-for="company in companies" :key="company.id" class="flex items-center p-2 rounded-lg hover:bg-white transition-colors cursor-pointer group">
                                <input type="checkbox" :value="company.id" v-model="form.company_ids"
                                       class="w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 transition-all">
                                <span class="ml-3 text-sm font-medium text-slate-600 group-hover:text-slate-900">{{ company.name }}</span>
                            </label>
                            <div v-if="companies.length === 0" class="col-span-2 text-center text-sm text-slate-400 py-2">
                                No companies available.
                            </div>
                        </div>
                        <p class="text-xs text-slate-400 mt-2">Users with this role will be associated with selected companies.</p>
                    </div>

                    <div>
                        <div class="flex flex-col md:flex-row md:items-center justify-between mb-6 space-y-4 md:space-y-0">
                            <label class="block text-sm font-bold text-slate-700 flex items-center">
                                <LockClosedIcon class="w-4 h-4 mr-2 text-slate-400" />
                                Module Management
                            </label>
                            
                            <div class="flex flex-col sm:flex-row items-stretch sm:items-center space-y-3 sm:space-y-0 sm:space-x-4">
                                <div class="relative min-w-[240px]">
                                    <MagnifyingGlassIcon class="absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
                                    <input v-model="searchKeyword" type="text" placeholder="Search categories or permissions..."
                                           class="block w-full pl-9 pr-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-semibold focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                                </div>

                                <label class="flex items-center cursor-pointer group whitespace-nowrap">
                                    <input type="checkbox" 
                                           :checked="isAllSelected()"
                                           :indeterminate="isAllIndeterminate()"
                                           @change="toggleAllPermissions()"
                                           class="w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 transition-all">
                                    <span class="ml-2 text-xs font-bold text-slate-500 group-hover:text-blue-600 transition-colors">Select All Permissions</span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="space-y-5">
                            <div
                                v-for="group in permissionMenuGroups"
                                :key="group.label"
                                class="rounded-2xl border border-slate-200 bg-white overflow-hidden"
                            >
                                <div class="flex items-center justify-between bg-slate-50 px-5 py-4 border-b border-slate-100">
                                    <div>
                                        <h4 class="text-sm font-black text-slate-900">{{ group.label }}</h4>
                                        <p class="text-xs font-medium text-slate-500">{{ group.description }}</p>
                                    </div>
                                    <label class="flex items-center cursor-pointer group">
                                        <input type="checkbox"
                                               :checked="isMenuGroupSelected(group)"
                                               :indeterminate="isMenuGroupIndeterminate(group)"
                                               @change="toggleMenuGroupPermissions(group)"
                                               class="w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 transition-all">
                                        <span class="ml-2 text-[10px] font-bold text-slate-400 uppercase tracking-wider group-hover:text-blue-600 transition-colors">Check Parent</span>
                                    </label>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 p-5">
                                    <div
                                        v-for="category in group.children"
                                        :key="category.label"
                                        class="bg-slate-50/70 border border-slate-100 rounded-xl p-4"
                                    >
                                        <div class="flex items-center justify-between mb-4">
                                            <h5 class="font-bold text-slate-800 flex items-center text-sm">
                                                <div class="w-1.5 h-4 bg-blue-500 rounded-full mr-2"></div>
                                                {{ category.label }}
                                            </h5>
                                            <label class="flex items-center cursor-pointer group">
                                                <input type="checkbox"
                                                       :checked="isCategorySelected(category.label, category.permissions)"
                                                       :indeterminate="isCategoryIndeterminate(category.label, category.permissions)"
                                                       @change="toggleCategoryPermissions(category.label, category.permissions)"
                                                       class="w-3.5 h-3.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 transition-all">
                                                <span class="ml-2 text-[10px] font-bold text-slate-400 uppercase tracking-wider group-hover:text-indigo-600 transition-colors">Check All</span>
                                            </label>
                                        </div>
                                        <div class="space-y-2.5">
                                            <label v-for="permission in sortPermissions(category.permissions)" :key="permission.id"
                                                   class="flex items-center group cursor-pointer p-2 hover:bg-white rounded-lg transition-colors border border-transparent hover:border-slate-100">
                                                <input type="checkbox" :value="permission.name" v-model="form.permissions"
                                                       class="w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 transition-all">
                                                <span class="ml-3 text-sm font-medium text-slate-600 group-hover:text-slate-900 transition-colors">
                                                    {{ formatPermissionName(permission.name) }}
                                                </span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div v-if="Object.keys(filteredPermissions).length === 0" class="col-span-2 py-12 text-center">
                                <div class="bg-slate-50 rounded-2xl p-8 border border-dashed border-slate-200">
                                    <MagnifyingGlassIcon class="w-10 h-10 text-slate-300 mx-auto mb-3" />
                                    <p class="text-slate-500 font-bold">No permissions found matching "{{ searchKeyword }}"</p>
                                    <button @click="searchKeyword = ''" class="mt-4 text-blue-600 text-xs font-black uppercase tracking-widest hover:text-blue-700">Clear Search</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="px-8 py-6 bg-slate-50 border-t border-slate-100 flex justify-end space-x-3">
                    <button type="button" @click="close" 
                            class="px-6 py-2.5 text-slate-600 font-bold bg-white border border-slate-200 rounded-xl hover:bg-slate-50 transition-colors shadow-sm">
                        Discard
                    </button>
                    <button type="submit" 
                            class="px-6 py-2.5 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 shadow-lg shadow-blue-600/20 transition-all">
                        {{ role ? 'Update Role Definition' : 'Commit Role Definition' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, watch, computed } from 'vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import { useToast } from '@/Composables/useToast'
import { useErrorHandler } from '@/Composables/useErrorHandler'
import { LockClosedIcon, MagnifyingGlassIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
    show: Boolean,
    role: Object,
    permissions: Object,
    companies: Array,
    landingPageOptions: Array,
})

const emit = defineEmits(['close', 'saved'])

const { showError } = useToast()
const { post, put } = useErrorHandler()

const searchKeyword = ref('')

const form = reactive({
    name: '',
    permissions: [],
    company_ids: [],
    landing_page: 'dashboard',
})

const landingPageOptionItems = computed(() => (props.landingPageOptions || []).map((option) => ({
    label: option.label,
    value: option.route,
})));

const filteredPermissions = computed(() => {
    if (!searchKeyword.value) return props.permissions;

    const keyword = searchKeyword.value.toLowerCase();
    const result = {};

    Object.entries(props.permissions).forEach(([category, perms]) => {
        const categoryMatches = category.toLowerCase().includes(keyword);
        const filteredPerms = perms.filter(p => 
            p.name.toLowerCase().includes(keyword) || 
            formatPermissionName(p.name).toLowerCase().includes(keyword)
        );

        if (categoryMatches) {
            result[category] = perms;
        } else if (filteredPerms.length > 0) {
            result[category] = filteredPerms;
        }
    });

    return result;
});

const sidebarPermissionMap = [
    {
        label: 'Dashboard',
        description: 'Main workspace access.',
        children: ['Dashboard'],
    },
    {
        label: 'Vendors',
        description: 'Vendor records, compliance documents, products, and approval workflows.',
        children: [
            { category: 'Vendors', label: 'Vendors' },
            { category: 'Vendor Documents', label: 'Vendor Documents' },
            { category: 'Products', label: 'Products' },
            { category: 'Approvals Inbox', label: 'Approvals Inbox' },
        ],
    },
    {
        label: 'Transactions',
        description: 'Commercial documents submitted and reviewed through the portal.',
        children: [
            { category: 'Invoices', label: 'Invoices' },
            { category: 'Purchase Orders', label: 'Purchase Orders' },
            { category: 'Quotations', label: 'Quotations' },
        ],
    },
    {
        label: 'Document Processing',
        description: 'OCR intake, templates, exception handling, and AP visibility.',
        children: [
            { category: 'Document Intake', label: 'Document Intake' },
            { category: 'Document Templates', label: 'OCR Templates' },
            { category: 'Document Exceptions', label: 'Document Exceptions' },
            { category: 'Accounts Payable', label: 'Accounts Payable' },
        ],
    },
    {
        label: 'Management',
        description: 'Administrative records shown under the Management sidebar menu.',
        children: [
            { category: 'Companies', label: 'Companies' },
            { category: 'Users', label: 'Users' },
            { category: 'Roles & Permissions', label: 'Roles' },
        ],
    },
];

const permissionMenuGroups = computed(() => sidebarPermissionMap
    .map((group) => ({
        ...group,
        children: group.children
            .map((child) => typeof child === 'string' ? { category: child, label: child } : child)
            .filter((child) => filteredPermissions.value[child.category])
            .map((child) => ({
                label: child.label,
                category: child.category,
                permissions: filteredPermissions.value[child.category],
            })),
    }))
    .filter((group) => group.children.length > 0));

watch(() => props.show, (isVisible) => {
    if (isVisible) {
        searchKeyword.value = '';
        if (props.role) {
            form.name = props.role.name
            form.permissions = props.role.permissions.map(p => p.name)
            form.company_ids = props.role.companies ? props.role.companies.map(c => c.id) : []
            form.landing_page = props.role.landing_page || 'dashboard'
        } else {
            form.name = ''
            form.permissions = []
            form.landing_page = 'dashboard'
            form.company_ids = props.companies?.[0] ? [props.companies[0].id] : []
        }
    }
})

const close = () => {
    emit('close')
}

const submitForm = () => {
    const url = props.role ? `/roles/${props.role.id}` : '/roles'
    const method = props.role ? put : post
    
    method(url, form, {
        onSuccess: () => {
            emit('saved')
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'Validation error'
            showError(errorMessage)
        }
    })
}

const toggleCategoryPermissions = (category, perms) => {
    const permNames = perms.map(p => p.name);
    const allSelected = isCategorySelected(category, perms);
    
    if (allSelected) {
        form.permissions = form.permissions.filter(p => !permNames.includes(p));
    } else {
        const otherPermissions = form.permissions.filter(p => !permNames.includes(p));
        form.permissions = [...otherPermissions, ...permNames];
    }
};

const menuGroupPermissions = (group) => group.children.flatMap((category) => category.permissions);

const toggleMenuGroupPermissions = (group) => {
    const perms = menuGroupPermissions(group);
    const permNames = perms.map(p => p.name);

    if (isMenuGroupSelected(group)) {
        form.permissions = form.permissions.filter(p => !permNames.includes(p));
    } else {
        const otherPermissions = form.permissions.filter(p => !permNames.includes(p));
        form.permissions = [...otherPermissions, ...permNames];
    }
};

const isMenuGroupSelected = (group) => {
    const perms = menuGroupPermissions(group);
    if (!perms.length) return false;

    return perms.every(permission => form.permissions.includes(permission.name));
};

const isMenuGroupIndeterminate = (group) => {
    const perms = menuGroupPermissions(group);
    if (!perms.length) return false;

    const selected = perms.filter(permission => form.permissions.includes(permission.name));
    return selected.length > 0 && selected.length < perms.length;
};

const isCategorySelected = (category, perms) => {
    if (!perms || perms.length === 0) return false;
    const permNames = perms.map(p => p.name);
    return permNames.every(name => form.permissions.includes(name));
};

const isCategoryIndeterminate = (category, perms) => {
    if (!perms || perms.length === 0) return false;
    const permNames = perms.map(p => p.name);
    const selectedInCat = permNames.filter(name => form.permissions.includes(name));
    return selectedInCat.length > 0 && selectedInCat.length < permNames.length;
};

const toggleAllPermissions = () => {
    const allPerms = Object.values(props.permissions).flat().map(p => p.name);
    if (isAllSelected()) {
        form.permissions = [];
    } else {
        form.permissions = allPerms;
    }
};

const isAllSelected = () => {
    const allPerms = Object.values(props.permissions).flat().map(p => p.name);
    return allPerms.length > 0 && allPerms.every(p => form.permissions.includes(p));
};

const isAllIndeterminate = () => {
    const allPerms = Object.values(props.permissions).flat().map(p => p.name);
    return form.permissions.length > 0 && form.permissions.length < allPerms.length;
};

const sortPermissions = (permissions) => {
    const order = ['view', 'create', 'edit', 'delete', 'export', 'approve', 'cancel'];
    return permissions.sort((a, b) => {
        const aAction = a.name.split('.')[1];
        const bAction = b.name.split('.')[1];
        const aIndex = order.indexOf(aAction);
        const bIndex = order.indexOf(bAction);
        
        if (aIndex === -1 && bIndex === -1) return aAction.localeCompare(bAction);
        if (aIndex === -1) return 1;
        if (bIndex === -1) return -1;
        return aIndex - bIndex;
    });
}

const formatPermissionName = (name) => {
    const parts = name.split('.');
    if (parts.length < 2) return name;
    
    const action = parts[1];
    const mapping = {
        'view': 'View',
        'create': 'Create',
        'edit': 'Edit',
        'delete': 'Delete',
        'export': 'Export',
        'approve': 'Approve',
        'cancel': 'Cancel'
    };
    
    return mapping[action] || action.charAt(0).toUpperCase() + action.slice(1);
}
</script>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: #f8fafc;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 10px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}
</style>
