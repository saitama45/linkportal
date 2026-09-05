<script setup>
import InputError from '@/Components/InputError.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { AtSymbolIcon, LockClosedIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    token: { type: String, required: true },
    email: { type: String, default: '' },
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = () => form.post(route('vendor.password.update'), {
    onFinish: () => form.reset('password', 'password_confirmation'),
});
</script>

<template>
    <Head title="Reset password - Link Portal" />

    <div class="relative flex min-h-screen w-full items-center justify-center overflow-hidden bg-gradient-to-br from-emerald-950 via-emerald-900 to-teal-900 px-4 py-12 font-sans">
        <div class="w-full max-w-md rounded-2xl bg-white p-8 shadow-2xl">
            <div class="mb-6 flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-50">
                <LockClosedIcon class="h-6 w-6 text-emerald-600" />
            </div>

            <h1 class="text-2xl font-black text-slate-900">Set a new password</h1>
            <p class="mt-2 text-sm text-slate-500">
                Choose a password of at least 8 characters, mixing letters and numbers.
            </p>

            <form @submit.prevent="submit" class="mt-6 space-y-5">
                <div>
                    <label for="email" class="mb-2 block text-sm font-bold text-slate-700">Email address</label>
                    <div class="relative">
                        <AtSymbolIcon class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" />
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            required
                            autocomplete="username"
                            :class="[
                                'h-12 w-full rounded-xl border bg-white pl-11 pr-4 text-sm text-slate-900 transition focus:outline-none focus:ring-4',
                                form.errors.email ? 'border-red-300 ring-4 ring-red-50' : 'border-slate-200 focus:border-emerald-400 focus:ring-emerald-50',
                            ]"
                        />
                    </div>
                    <InputError class="mt-2" :message="form.errors.email" />
                </div>

                <div>
                    <label for="password" class="mb-2 block text-sm font-bold text-slate-700">New password</label>
                    <input
                        id="password"
                        v-model="form.password"
                        type="password"
                        required
                        autofocus
                        autocomplete="new-password"
                        :class="[
                            'h-12 w-full rounded-xl border bg-white px-4 text-sm text-slate-900 transition focus:outline-none focus:ring-4',
                            form.errors.password ? 'border-red-300 ring-4 ring-red-50' : 'border-slate-200 focus:border-emerald-400 focus:ring-emerald-50',
                        ]"
                    />
                    <InputError class="mt-2" :message="form.errors.password" />
                </div>

                <div>
                    <label for="password_confirmation" class="mb-2 block text-sm font-bold text-slate-700">Confirm new password</label>
                    <input
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        type="password"
                        required
                        autocomplete="new-password"
                        class="h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-900 transition focus:border-emerald-400 focus:outline-none focus:ring-4 focus:ring-emerald-50"
                    />
                    <InputError class="mt-2" :message="form.errors.password_confirmation" />
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="flex h-12 w-full items-center justify-center rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 px-5 text-sm font-black text-white shadow-lg shadow-emerald-600/25 transition hover:shadow-emerald-600/40 focus:outline-none focus:ring-4 focus:ring-emerald-200 disabled:cursor-not-allowed disabled:opacity-70"
                >
                    {{ form.processing ? 'Saving...' : 'Reset password' }}
                </button>
            </form>
        </div>
    </div>
</template>
