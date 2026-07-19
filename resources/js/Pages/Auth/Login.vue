<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import { AtSymbolIcon, CheckCircleIcon, EyeIcon, EyeSlashIcon, LinkIcon, LockClosedIcon, ShieldCheckIcon } from '@heroicons/vue/24/outline';

defineProps({
    canResetPassword: { type: Boolean },
    status: { type: String },
});

const form = useForm({ email: '', password: '', remember: false });
const showPassword = ref(false);

const submit = () => {
    form.post(route('login'), {
        onFinish: () => {
            form.reset('password');
            showPassword.value = false;
        },
    });
};
</script>

<template>
    <Head title="Staff Sign in - Link Portal" />

    <div class="staff-auth relative flex min-h-screen items-center justify-center overflow-hidden bg-emerald-950 px-4 py-10 font-sans">
        <div class="aurora absolute inset-0"></div>
        <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-emerald-950/80 via-transparent to-emerald-950/40"></div>

        <div class="relative z-10 w-full max-w-md">
            <div class="mb-8 flex flex-col items-center text-center">
                <span class="mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-emerald-400 to-teal-500 text-emerald-950 shadow-lg shadow-emerald-500/30">
                    <LinkIcon class="h-6 w-6" />
                </span>
                <h1 class="text-2xl font-black tracking-tight text-white">Welcome back</h1>
                <p class="mt-1 text-sm font-medium text-emerald-100/70">Sign in to access your Link Portal workspace.</p>
            </div>

            <div class="relative overflow-hidden rounded-[26px] border border-white/60 bg-white/95 p-7 shadow-2xl shadow-emerald-950/40 backdrop-blur-xl sm:p-9">
                <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-emerald-400 via-teal-400 to-green-400"></div>

                <div v-if="status" class="mb-6 flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">
                    <CheckCircleIcon class="mt-0.5 h-5 w-5 flex-shrink-0" />
                    <span>{{ status }}</span>
                </div>

                <form class="space-y-5" @submit.prevent="submit">
                    <div>
                        <label for="email" class="mb-2 block text-sm font-bold text-slate-700">Email address</label>
                        <div class="relative">
                            <AtSymbolIcon class="pointer-events-none absolute left-3.5 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" />
                            <input id="email" v-model="form.email" type="email" required autofocus autocomplete="username"
                                placeholder="you@company.com"
                                :class="[
                                    'block h-12 w-full rounded-xl border bg-slate-50 pl-11 pr-4 text-sm font-semibold text-slate-900 outline-none transition focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100',
                                    form.errors.email ? 'border-red-300 ring-4 ring-red-50' : 'border-slate-200',
                                ]" />
                        </div>
                        <InputError class="mt-2" :message="form.errors.email" />
                    </div>

                    <div>
                        <div class="mb-2 flex items-center justify-between gap-4">
                            <label for="password" class="block text-sm font-bold text-slate-700">Password</label>
                            <Link v-if="canResetPassword" :href="route('password.request')" tabindex="-1"
                                class="text-sm font-bold text-emerald-700 transition hover:text-emerald-900">
                                Forgot password?
                            </Link>
                        </div>
                        <div class="relative">
                            <LockClosedIcon class="pointer-events-none absolute left-3.5 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" />
                            <input id="password" v-model="form.password" :type="showPassword ? 'text' : 'password'" required
                                autocomplete="current-password" placeholder="Enter your password"
                                :class="[
                                    'block h-12 w-full rounded-xl border bg-slate-50 pl-11 pr-12 text-sm font-semibold text-slate-900 outline-none transition focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100',
                                    form.errors.password ? 'border-red-300 ring-4 ring-red-50' : 'border-slate-200',
                                ]" />
                            <button type="button" tabindex="-1" @click="showPassword = !showPassword"
                                :aria-label="showPassword ? 'Hide password' : 'Show password'"
                                class="absolute right-3 top-1/2 -translate-y-1/2 rounded-md p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">
                                <EyeSlashIcon v-if="showPassword" class="h-5 w-5" />
                                <EyeIcon v-else class="h-5 w-5" />
                            </button>
                        </div>
                        <InputError class="mt-2" :message="form.errors.password" />
                    </div>

                    <label class="flex cursor-pointer select-none items-center gap-2.5">
                        <Checkbox v-model:checked="form.remember" name="remember" class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" />
                        <span class="text-sm font-semibold text-slate-600">Keep me signed in</span>
                    </label>

                    <button type="submit" :disabled="form.processing"
                        class="flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 px-5 text-sm font-black text-white shadow-lg shadow-emerald-600/25 transition hover:shadow-emerald-600/40 focus:outline-none focus:ring-4 focus:ring-emerald-200 disabled:cursor-not-allowed disabled:opacity-70">
                        {{ form.processing ? 'Signing in...' : 'Sign in' }}
                    </button>
                </form>

                <div class="mt-7 flex items-center justify-center gap-1.5 rounded-xl border border-slate-100 bg-slate-50 px-4 py-3 text-xs text-slate-500">
                    <ShieldCheckIcon class="h-4 w-4 flex-shrink-0 text-emerald-500" />
                    <span>Are you a vendor?</span>
                    <Link :href="route('vendor.login')" class="font-bold text-emerald-700 transition hover:text-emerald-900">Sign in here</Link>
                </div>
            </div>

            <p class="mt-6 text-center text-xs font-semibold text-emerald-200/50">
                Access is restricted to authorized staff accounts.
            </p>
        </div>
    </div>
</template>

<style scoped>
.aurora {
    background:
        radial-gradient(60% 80% at 15% 20%, rgba(16, 185, 129, 0.45), transparent 60%),
        radial-gradient(50% 60% at 85% 30%, rgba(20, 184, 166, 0.4), transparent 55%),
        radial-gradient(70% 70% at 60% 90%, rgba(5, 150, 105, 0.5), transparent 60%),
        linear-gradient(135deg, #022c22 0%, #064e3b 45%, #065f46 100%);
    background-size: 200% 200%;
    animation: gradientShift 18s ease infinite;
}
@keyframes gradientShift {
    0%, 100% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
}
@media (prefers-reduced-motion: reduce) {
    .aurora { animation: none; }
}
</style>
