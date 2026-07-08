<template>
    <Head title="Role Management - APP" />

    <AppLayout>
        <template #header>
            <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                <div>
                    <h2 class="font-black text-3xl text-slate-900 tracking-tight leading-tight">Governance & Access</h2>
                    <p class="text-sm text-slate-500 font-medium mt-1">Manage accounting security roles and staff permissions.</p>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Data Table Container -->
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
                    <DataTable
                        title="Accounting Security Roles"
                        subtitle="Authorized accounting access levels"
                        search-placeholder="Search by role name..."
                        empty-message="No roles defined. Provision a new role to begin."
                        data-key="roles"
                        route-name="roles.index"
                        :paginator="roles"
                    >
                        <template #actions>
                            <button 
                                v-if="hasPermission('roles.create')"
                                @click="openCreateModal" 
                                class="bg-blue-600 text-white px-5 py-2.5 rounded-xl hover:bg-blue-700 transition-all duration-200 flex items-center space-x-2 text-sm font-black uppercase tracking-widest shadow-lg shadow-blue-600/20"
                            >
                                <ShieldCheckIcon class="w-5 h-5" />
                                <span>Provision Role</span>
                            </button>
                        </template>

                        <template #header>
                            <tr class="bg-slate-50">
                                <th class="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-widest border-b border-slate-100">Role Designation</th>
                                <th class="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-widest border-b border-slate-100">Landing Page</th>
                                <th class="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-widest border-b border-slate-100">Permission Scope</th>
                                <th class="px-6 py-4 text-right text-xs font-bold text-slate-500 uppercase tracking-widest border-b border-slate-100">Actions</th>
                            </tr>
                        </template>

                        <template #body="{ data }">
                            <tr v-for="role in data" :key="role.id" class="hover:bg-slate-50/50 transition-colors border-b border-slate-50 last:border-0">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="h-10 w-10 bg-slate-100 rounded-full flex items-center justify-center text-slate-500 font-bold border border-slate-200">
                                            <ShieldCheckIcon class="w-5 h-5" />
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-bold text-slate-900">{{ role.name }}</div>
                                            <div class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">System Identifier: {{ role.name.toLowerCase().replace(' ', '_') }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span v-if="role.landing_page" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                        {{ landing_page_options.find(opt => opt.route === role.landing_page)?.label || role.landing_page }}
                                    </span>
                                    <span v-else class="text-xs text-slate-400 italic">Default (Dashboard)</span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <button 
                                        @click="viewPermissions(role)" 
                                        class="inline-flex items-center px-3 py-1.5 rounded-lg text-xs font-bold bg-slate-100 text-slate-700 hover:bg-blue-50 hover:text-blue-700 border border-slate-200 hover:border-blue-100 transition-all"
                                    >
                                        <EyeIcon class="w-3.5 h-3.5 mr-1.5" />
                                        {{ role.permissions.length }} Capability Keys
                                    </button>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <div class="flex justify-end space-x-1">
                                        <button 
                                            v-if="hasPermission('roles.edit')"
                                            @click="editRole(role)" 
                                            class="p-2 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-all"
                                            title="Modify Access Scope"
                                        >
                                            <PencilSquareIcon class="w-5 h-5" />
                                        </button>
                                        <button 
                                            v-if="hasPermission('roles.delete')"
                                            @click="deleteRole(role)" 
                                            class="p-2 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-all"
                                            title="Delete Role"
                                        >
                                            <TrashIcon class="w-5 h-5" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </DataTable>
                </div>
            </div>
        </div>

        <!-- Permissions Viewer Modal -->
        <div v-if="showPermissionsModal" class="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center p-4">
            <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm transition-opacity" @click="closePermissionsModal"></div>
            
            <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full relative overflow-hidden animate-in fade-in zoom-in duration-200">
                <div class="px-8 py-6 border-b border-slate-100 bg-slate-50/50">
                    <h3 class="text-xl font-bold text-slate-900">Permission Manifest</h3>
                    <p class="text-sm text-slate-500">Active capabilities for role: <strong class="text-slate-800">{{ selectedRole?.name }}</strong></p>
                </div>
                
                <div class="p-8 max-h-[60vh] overflow-y-auto custom-scrollbar">
                    <div class="flex flex-wrap gap-2">
                        <span v-for="permission in selectedRole?.permissions" :key="permission.id" 
                              class="inline-flex items-center px-3 py-1 rounded-lg text-xs font-bold bg-blue-50 text-blue-700 border border-blue-100">
                            {{ permission.name }}
                        </span>
                        <div v-if="!selectedRole?.permissions.length" class="text-slate-400 italic text-sm py-4">
                            No permissions assigned to this role.
                        </div>
                    </div>
                </div>
                
                <div class="px-8 py-4 bg-slate-50 border-t border-slate-100 flex justify-end">
                    <button @click="closePermissionsModal" 
                            class="px-6 py-2.5 text-slate-600 font-bold bg-white border border-slate-200 rounded-xl hover:bg-slate-50 transition-colors shadow-sm">
                        Close Manifest
                    </button>
                </div>
            </div>
        </div>

        <!-- Create/Edit Role Modal -->
        <RoleFormModal
            :show="showModal"
            :role="currentRole"
            :permissions="permissions"
            :companies="companies"
            :landing-page-options="landing_page_options"
            @close="showModal = false"
            @saved="showModal = false"
        />
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import RoleFormModal from '@/Components/RoleFormModal.vue'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'
import { useErrorHandler } from '@/Composables/useErrorHandler'
import { usePermission } from '@/Composables/usePermission'
import { 
    ShieldCheckIcon, 
    PencilSquareIcon, 
    TrashIcon, 
    EyeIcon
} from '@heroicons/vue/24/outline'

const props = defineProps({
    roles: Object,
    permissions: Object,
    companies: Array,
    landing_page_options: Array,
})

const { showError } = useToast()
const { confirm } = useConfirm()
const { post, put, destroy } = useErrorHandler()
const { hasPermission } = usePermission();

const showModal = ref(false)
const showPermissionsModal = ref(false)
const currentRole = ref(null)
const selectedRole = ref(null)

const viewPermissions = (role) => {
    selectedRole.value = role
    showPermissionsModal.value = true
}

const closePermissionsModal = () => {
    showPermissionsModal.value = false
    selectedRole.value = null
}

const openCreateModal = () => {
    currentRole.value = null
    showModal.value = true
}

const editRole = (role) => {
    currentRole.value = role
    showModal.value = true
}

const deleteRole = async (role) => {
    const confirmed = await confirm({
        title: 'Revoke Security Role',
        message: `Are you sure you want to delete the "${role.name}" role? This will impact all users currently assigned to it.`
    })
    
    if (confirmed) {
        destroy(`/roles/${role.id}`, {
            onSuccess: () => {},
            onError: (errors) => {
                const errorMessage = Object.values(errors).flat().join(', ') || 'Dependencies exist for this role'
                showError(errorMessage)
            }
        })
    }
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
