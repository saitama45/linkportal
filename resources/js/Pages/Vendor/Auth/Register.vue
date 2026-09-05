<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';
import { LinkIcon } from '@heroicons/vue/24/outline';

// Managed in the back office (reference_options), never hard-coded here — a
// type an admin adds on /vendors must appear in this list without a deploy.
const props = defineProps({
    vendorTypes: { type: Array, default: () => [] },
});

const form = useForm({
    name: '',
    email: '',
    phone: '',
    vendor_type: '',
    password: '',
    password_confirmation: '',
});


const submit = () => {
    form.post(route('vendor.register'), { onFinish: () => form.reset('password', 'password_confirmation') });
};

const inputClass = 'block h-12 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm font-semibold text-slate-900 outline-none transition focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100';
</script>

<template>
    <Head title="Register - Link Portal" />

    <div class="vendor-auth relative flex min-h-screen items-center justify-center overflow-hidden bg-emerald-950 px-4 py-10 font-sans">
        <div class="aurora absolute inset-0"></div>
        <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-emerald-950/80 via-transparent to-emerald-950/40"></div>

        <div class="relative z-10 w-full max-w-lg">
            <div class="mb-8 flex flex-col items-center text-center">
                <span class="mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-emerald-400 to-teal-500 text-emerald-950 shadow-lg shadow-emerald-500/30">
                    <LinkIcon class="h-6 w-6" />
                </span>
                <h1 class="text-2xl font-black tracking-tight text-white">Create Your Account</h1>
                <p class="mt-1 max-w-sm text-sm font-medium text-emerald-100/70">
                    Register to work with us on Link Portal — suppliers and service partners transact here,
                    store cashiers run the counter.
                </p>
            </div>

            <div class="overflow-hidden rounded-[26px] border border-white/60 bg-white/95 p-7 shadow-2xl shadow-emerald-950/40 backdrop-blur-xl sm:p-9">
                <form class="space-y-5" @submit.prevent="submit">
                    <div>
                        <label class="mb-2 block text-sm font-bold text-slate-700">Vendor Name</label>
                        <input v-model="form.name" type="text" required autofocus placeholder="Ex. Acme Supplies Inc." :class="inputClass" />
                        <InputError class="mt-2" :message="form.errors.name" />
                    </div>

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <label class="mb-2 block text-sm font-bold text-slate-700">Email address</label>
                            <input v-model="form.email" type="email" required autocomplete="username" placeholder="you@company.com" :class="inputClass" />
                            <InputError class="mt-2" :message="form.errors.email" />
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-bold text-slate-700">Phone <span class="font-medium text-slate-400">(optional)</span></label>
                            <input v-model="form.phone" type="text" placeholder="+63 ..." :class="inputClass" />
                            <InputError class="mt-2" :message="form.errors.phone" />
                        </div>
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-bold text-slate-700">Vendor Type</label>
                        <select v-model="form.vendor_type" :class="inputClass">
                            <option value="">Select type...</option>
                            <option v-for="t in props.vendorTypes" :key="t" :value="t">{{ t }}</option>
                        </select>
                        <InputError class="mt-2" :message="form.errors.vendor_type" />
                    </div>

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <label class="mb-2 block text-sm font-bold text-slate-700">Password</label>
                            <input v-model="form.password" type="password" required autocomplete="new-password" placeholder="Min. 8 characters" :class="inputClass" />
                            <InputError class="mt-2" :message="form.errors.password" />
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-bold text-slate-700">Confirm Password</label>
                            <input v-model="form.password_confirmation" type="password" required autocomplete="new-password" placeholder="Repeat password" :class="inputClass" />
                        </div>
                    </div>

                    <button type="submit" :disabled="form.processing"
                        class="flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 px-5 text-sm font-black text-white shadow-lg shadow-emerald-600/25 transition hover:shadow-emerald-600/40 focus:outline-none focus:ring-4 focus:ring-emerald-200 disabled:cursor-not-allowed disabled:opacity-70">
                        {{ form.processing ? 'Creating account...' : 'Create Vendor Account' }}
                    </button>

                    <p class="text-center text-xs leading-5 text-slate-400">
                        After registering, complete your profile. An administrator reviews and activates
                        every account before it can be used.
                    </p>
                </form>

                <p class="mt-6 text-center text-sm font-semibold text-slate-500">
                    Already registered?
                    <Link :href="route('vendor.login')" class="font-bold text-emerald-700 transition hover:text-emerald-900">Sign in</Link>
                </p>
            </div>
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
