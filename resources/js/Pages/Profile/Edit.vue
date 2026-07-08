<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useToast } from '@/Composables/useToast';

const props = defineProps({
    user: Object,
});

const activeTab = ref('profile');
const { showError } = useToast();

const profileForm = useForm({
    name: props.user.name,
    email: props.user.email,
    department: props.user.department || '',
    position: props.user.position || '',
    photo: null,
});

const photoInput = ref(null);
const photoPreview = ref(null);

const selectNewPhoto = () => {
    photoInput.value.click();
};

const updatePhotoPreview = () => {
    const photo = photoInput.value.files[0];

    if (! photo) return;

    const reader = new FileReader();

    reader.onload = (e) => {
        photoPreview.value = e.target.result;
    };

    reader.readAsDataURL(photo);
    profileForm.photo = photo;
};

const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const updateProfile = () => {
    if (profileForm.photo) {
        // Method spoofing for file upload via PUT route
        profileForm.transform((data) => ({
            ...data,
            _method: 'PUT',
        })).post(route('profile.update'), {
            onSuccess: () => {
                photoPreview.value = null;
                const fileInput = document.getElementById('photo');
                if (fileInput) fileInput.value = null;
            },
            onError: (errors) => {
                const errorMessage = Object.values(errors).flat().join(', ') || 'Failed to update profile';
                showError(errorMessage);
            }
        });
        return;
    }

    profileForm.put(route('profile.update'), {
        onSuccess: () => {
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'Failed to update profile';
            showError(errorMessage);
        }
    });
};

const updatePassword = () => {
    passwordForm.put(route('profile.password'), {
        onSuccess: () => {
            passwordForm.reset();
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'Failed to update password';
            showError(errorMessage);
        }
    });
};
</script>

<template>
    <Head title="Profile - APP" />

    <AppLayout>
        <template #header>
            Profile Management
        </template>

        <div class="max-w-4xl mx-auto space-y-6 pb-12">
            <!-- Profile Header -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 md:p-8">
                <div class="flex items-center space-x-6">
                    <div class="relative group cursor-pointer flex-shrink-0" @click="selectNewPhoto">
                        <div v-if="photoPreview" class="h-24 w-24 rounded-full overflow-hidden border-4 border-slate-50 shadow-md">
                            <img :src="photoPreview" class="h-full w-full object-cover">
                        </div>
                        <div v-else-if="user.profile_photo" class="h-24 w-24 rounded-full overflow-hidden border-4 border-slate-50 shadow-md">
                            <img :src="'/storage/' + user.profile_photo" class="h-full w-full object-cover">
                        </div>
                        <div v-else class="h-24 w-24 bg-blue-600 rounded-full flex items-center justify-center border-4 border-slate-50 shadow-md text-white text-3xl font-bold">
                            {{ user.name.charAt(0) }}
                        </div>
                        
                        <!-- Overlay -->
                        <div class="absolute inset-0 bg-slate-900/50 rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-all duration-200">
                            <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                        </div>
                        <input ref="photoInput" type="file" class="hidden" @change="updatePhotoPreview" accept="image/*">
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold text-slate-900">{{ user.name }}</h2>
                        <p class="text-slate-500">{{ user.email }}</p>
                        <div class="flex gap-2 mt-2">
                            <span class="inline-flex px-2.5 py-0.5 text-xs font-bold rounded-lg bg-blue-50 text-blue-700 border border-blue-100">
                                {{ user.roles[0]?.name || 'Standard User' }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-100 overflow-hidden">
                <div class="border-b border-slate-100">
                    <nav class="flex overflow-x-auto">
                        <button
                            @click="activeTab = 'profile'"
                            :class="[
                                activeTab === 'profile' 
                                    ? 'border-blue-600 text-blue-600 bg-blue-50/50' 
                                    : 'border-transparent text-slate-500 hover:text-slate-700 hover:bg-slate-50',
                                'whitespace-nowrap py-4 px-6 border-b-2 font-semibold text-sm transition-colors duration-200'
                            ]"
                        >
                            Personal Info
                        </button>
                        <button
                            @click="activeTab = 'password'"
                            :class="[
                                activeTab === 'password' 
                                    ? 'border-blue-600 text-blue-600 bg-blue-50/50' 
                                    : 'border-transparent text-slate-500 hover:text-slate-700 hover:bg-slate-50',
                                'whitespace-nowrap py-4 px-6 border-b-2 font-semibold text-sm transition-colors duration-200'
                            ]"
                        >
                            Security
                        </button>
                    </nav>
                </div>

                <!-- Profile Information Tab -->
                <div v-show="activeTab === 'profile'" class="p-6 md:p-8 animate-in fade-in slide-in-from-bottom-2 duration-300">
                    <form @submit.prevent="updateProfile" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-2">Full Name</label>
                                <input 
                                    v-model="profileForm.name" 
                                    type="text" 
                                    required 
                                    class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all"
                                >
                                <div v-if="profileForm.errors.name" class="text-red-600 text-sm mt-1 font-medium">{{ profileForm.errors.name }}</div>
                            </div>

                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-2">Email Address</label>
                                <input 
                                    v-model="profileForm.email" 
                                    type="email" 
                                    required 
                                    class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all"
                                >
                                <div v-if="profileForm.errors.email" class="text-red-600 text-sm mt-1 font-medium">{{ profileForm.errors.email }}</div>
                            </div>

                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-2">Department</label>
                                <input 
                                    v-model="profileForm.department" 
                                    type="text" 
                                    class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all"
                                >
                                <div v-if="profileForm.errors.department" class="text-red-600 text-sm mt-1 font-medium">{{ profileForm.errors.department }}</div>
                            </div>

                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-2">Position</label>
                                <input 
                                    v-model="profileForm.position" 
                                    type="text" 
                                    class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all"
                                >
                                <div v-if="profileForm.errors.position" class="text-red-600 text-sm mt-1 font-medium">{{ profileForm.errors.position }}</div>
                            </div>
                        </div>

                        <div class="flex justify-end pt-4 border-t border-slate-100">
                            <button 
                                type="submit" 
                                :disabled="profileForm.processing"
                                class="bg-blue-600 text-white px-6 py-2.5 rounded-xl hover:bg-blue-700 disabled:opacity-50 flex items-center space-x-2 font-bold shadow-lg shadow-blue-600/20 transition-all"
                            >
                                <svg v-if="profileForm.processing" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span>{{ profileForm.processing ? 'Saving...' : 'Save Changes' }}</span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Change Password Tab -->
                <div v-show="activeTab === 'password'" class="p-6 md:p-8 animate-in fade-in slide-in-from-bottom-2 duration-300">
                    <form @submit.prevent="updatePassword" class="space-y-6 max-w-2xl">
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">Current Password</label>
                            <input 
                                v-model="passwordForm.current_password" 
                                type="password" 
                                required 
                                class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all"
                            >
                            <div v-if="passwordForm.errors.current_password" class="text-red-600 text-sm mt-1 font-medium">{{ passwordForm.errors.current_password }}</div>
                        </div>

                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">New Password</label>
                            <input 
                                v-model="passwordForm.password" 
                                type="password" 
                                required 
                                class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all"
                            >
                            <div v-if="passwordForm.errors.password" class="text-red-600 text-sm mt-1 font-medium">{{ passwordForm.errors.password }}</div>
                        </div>

                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">Confirm New Password</label>
                            <input 
                                v-model="passwordForm.password_confirmation" 
                                type="password" 
                                required 
                                class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all"
                            >
                        </div>

                        <div class="flex justify-end pt-4 border-t border-slate-100">
                            <button 
                                type="submit" 
                                :disabled="passwordForm.processing"
                                class="bg-amber-600 text-white px-6 py-2.5 rounded-xl hover:bg-amber-700 disabled:opacity-50 flex items-center space-x-2 font-bold shadow-lg shadow-amber-600/20 transition-all"
                            >
                                <svg v-if="passwordForm.processing" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span>{{ passwordForm.processing ? 'Updating...' : 'Update Password' }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
