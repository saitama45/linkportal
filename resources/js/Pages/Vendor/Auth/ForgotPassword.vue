<script setup>
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeftIcon, AtSymbolIcon, EnvelopeOpenIcon } from '@heroicons/vue/24/outline';

defineProps({
    status: { type: String },
});

const form = useForm({
    email: '',
});

const submit = () => form.post(route('vendor.password.email'));
</script>

<template>
    <Head title="Forgot password - Link Portal" />

    <div class="relative flex min-h-screen w-full items-center justify-center overflow-hidden bg-gradient-to-br from-emerald-950 via-emerald-900 to-teal-900 px-4 py-12 font-sans">
        <div class="w-full max-w-md rounded-2xl bg-white p-8 shadow-2xl">
            <div class="mb-6 flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-50">
                <EnvelopeOpenIcon class="h-6 w-6 text-emerald-600" />
            </div>

            <h1 class="text-2xl font-black text-slate-900">Forgot your password?</h1>
            <p class="mt-2 text-sm text-slate-500">
                Enter the email address you registered with and we will send you a link to set a new password.
            </p>

            <div v-if="status" class="mt-5 rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">
                {{ status }}
            </div>

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
                            autofocus
                            autocomplete="username"
                            placeholder="you@company.com"
                            :class="[
                                'h-12 w-full rounded-xl border bg-white pl-11 pr-4 text-sm text-slate-900 transition focus:outline-none focus:ring-4',
                                form.errors.email ? 'border-red-300 ring-4 ring-red-50' : 'border-slate-200 focus:border-emerald-400 focus:ring-emerald-50',
                            ]"
                        />
                    </div>
                    <InputError class="mt-2" :message="form.errors.email" />
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="flex h-12 w-full items-center justify-center rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 px-5 text-sm font-black text-white shadow-lg shadow-emerald-600/25 transition hover:shadow-emerald-600/40 focus:outline-none focus:ring-4 focus:ring-emerald-200 disabled:cursor-not-allowed disabled:opacity-70"
                >
                    {{ form.processing ? 'Sending...' : 'Email password reset link' }}
                </button>
            </form>

            <Link
                :href="route('vendor.login')"
                class="mt-6 flex items-center justify-center gap-1.5 text-sm font-bold text-emerald-700 transition hover:text-emerald-900"
            >
                <ArrowLeftIcon class="h-4 w-4" />
                Back to sign in
            </Link>
        </div>
    </div>
</template>
