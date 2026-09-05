<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import {
    ArrowPathIcon,
    ArrowRightIcon,
    CheckCircleIcon,
    EnvelopeIcon,
    ExclamationCircleIcon,
    LinkIcon,
    ShieldCheckIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    email: { type: String, required: true },
    status: { type: String, default: null },
    resendAvailableIn: { type: Number, default: 0 },
    codeLength: { type: Number, default: 6 },
});

const form = useForm({ code: '' });

const digits = ref(Array(props.codeLength).fill(''));
const inputs = ref([]);
const cooldown = ref(props.resendAvailableIn);
const resending = ref(false);
let timer = null;

const complete = computed(() => digits.value.every((d) => d !== '' && d !== undefined));

// Show the address without putting a full email on screen for onlookers.
const maskedEmail = computed(() => {
    if (!props.email) return '';
    const [user, domain] = props.email.split('@');
    if (!domain) return props.email;
    const head = user.slice(0, 2);
    return head + '••••••••@' + domain;
});

// Check if status is just the redundant initial "We emailed a 6-digit code..." redirect message
const isInitialStatus = computed(() => {
    if (!props.status) return false;
    const s = props.status.toLowerCase();
    return s.includes('enter the 6-digit code') || s.includes('we emailed a 6-digit code');
});

const focusAt = (i) => {
    nextTick(() => {
        if (inputs.value[i]) {
            inputs.value[i].focus();
            inputs.value[i].select?.();
        }
    });
};

const spread = (value, from = 0) => {
    const clean = value.replace(/\D/g, '');
    for (let n = 0; n < clean.length && from + n < props.codeLength; n++) {
        digits.value[from + n] = clean[n];
    }
    const nextIndex = Math.min(from + clean.length, props.codeLength - 1);
    focusAt(nextIndex);
    if (digits.value.every((d) => d !== '')) {
        submit();
    }
};

const onInput = (i, event) => {
    const raw = event.target.value;
    const value = raw.replace(/\D/g, '');

    if (value.length > 1) {
        spread(value, i);
        return;
    }

    digits.value[i] = value;
    event.target.value = value;

    if (value && i < props.codeLength - 1) {
        focusAt(i + 1);
    }
    if (digits.value.every((d) => d !== '')) {
        submit();
    }
};

const onKeydown = (i, event) => {
    if (event.key === 'Backspace') {
        if (digits.value[i]) {
            digits.value[i] = '';
        } else if (i > 0) {
            digits.value[i - 1] = '';
            focusAt(i - 1);
        }
    } else if (event.key === 'Delete') {
        digits.value[i] = '';
    } else if (event.key === 'ArrowLeft' && i > 0) {
        focusAt(i - 1);
    } else if (event.key === 'ArrowRight' && i < props.codeLength - 1) {
        focusAt(i + 1);
    }
};

const onPaste = (event) => {
    event.preventDefault();
    const text = event.clipboardData?.getData('text') || '';
    spread(text, 0);
};

const submit = () => {
    if (form.processing) return;

    form.code = digits.value.join('');
    form.post(route('vendor.otp.verify'), {
        preserveScroll: true,
        onError: () => {
            digits.value = Array(props.codeLength).fill('');
            focusAt(0);
        },
    });
};

const startCooldown = (seconds) => {
    cooldown.value = seconds;
    clearInterval(timer);
    timer = setInterval(() => {
        if (--cooldown.value <= 0) {
            clearInterval(timer);
        }
    }, 1000);
};

const resend = () => {
    if (cooldown.value > 0 || resending.value) return;
    resending.value = true;
    router.post(
        route('vendor.otp.resend'),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                resending.value = false;
                startCooldown(60);
            },
        }
    );
};

onMounted(() => {
    focusAt(0);
    if (cooldown.value > 0) {
        startCooldown(cooldown.value);
    }
});

onBeforeUnmount(() => {
    clearInterval(timer);
});
</script>

<template>
    <Head title="Verify Email - Link Portal" />

    <div class="vendor-auth relative flex min-h-screen items-center justify-center overflow-hidden bg-[#022c22] px-4 py-10 font-sans">
        <!-- Aurora background matching Login and Register -->
        <div class="aurora absolute inset-0"></div>
        <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-[#022c22]/80 via-transparent to-[#022c22]/40"></div>

        <div class="relative z-10 w-full max-w-md">
            <!-- Brand mark -->
            <div class="mb-7 flex flex-col items-center text-center">
                <Link :href="route('vendor.login')" class="group mb-3 flex items-center gap-3">
                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-emerald-400 to-teal-500 text-[#022c22] shadow-lg shadow-emerald-500/30 transition group-hover:scale-105">
                        <LinkIcon class="h-6 w-6" />
                    </span>
                </Link>
                <div class="leading-tight">
                    <p class="text-xl font-black tracking-tight text-white">Link Portal</p>
                    <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-emerald-300/80">Partner Access</p>
                </div>
            </div>

            <!-- Card -->
            <div class="relative overflow-hidden rounded-[26px] border border-white/60 bg-white/95 p-7 shadow-2xl shadow-emerald-950/40 backdrop-blur-xl sm:p-9">
                <!-- Top gradient stripe -->
                <div class="absolute inset-x-0 top-0 h-1.5 bg-gradient-to-r from-emerald-400 via-teal-400 to-green-400"></div>

                <div class="mb-6 text-center">
                    <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-emerald-50 to-teal-50 ring-8 ring-emerald-50/70 border border-emerald-200/60 shadow-sm">
                        <EnvelopeIcon class="h-7 w-7 text-emerald-600" />
                    </div>
                    <h1 class="text-2xl font-black tracking-tight text-slate-900">Check your email</h1>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">
                        We sent a {{ codeLength }}-digit verification code to
                    </p>
                    <div class="mt-2 inline-flex items-center gap-1.5 rounded-full bg-slate-100 border border-slate-200/80 px-3.5 py-1 text-xs sm:text-sm font-bold text-slate-800">
                        <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                        <span>{{ maskedEmail }}</span>
                    </div>
                    <p class="mt-2 text-xs font-medium text-slate-400">
                        Enter the code below to complete your verification.
                    </p>
                </div>

                <!-- Flash Status (e.g. resend confirmation) -->
                <div
                    v-if="($page.props.flash?.status || status) && !isInitialStatus"
                    class="mb-6 flex items-start gap-2.5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-xs font-semibold text-emerald-800"
                >
                    <CheckCircleIcon class="h-4 w-4 flex-shrink-0 text-emerald-600 mt-0.5" />
                    <span>{{ $page.props.flash?.status || status }}</span>
                </div>

                <!-- Flash Error -->
                <div
                    v-if="$page.props.flash?.error"
                    class="mb-6 flex items-start gap-2.5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs font-semibold text-rose-800"
                >
                    <ExclamationCircleIcon class="h-4 w-4 flex-shrink-0 text-rose-600 mt-0.5" />
                    <span>{{ $page.props.flash.error }}</span>
                </div>

                <!-- Form -->
                <form @submit.prevent="submit">
                    <div class="flex justify-center gap-2 sm:gap-2.5" @paste="onPaste">
                        <input
                            v-for="(digit, i) in digits"
                            :key="i"
                            :ref="(el) => (inputs[i] = el)"
                            :value="digit"
                            type="text"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            maxlength="1"
                            :aria-label="'Digit ' + (i + 1) + ' of ' + codeLength"
                            :disabled="form.processing"
                            class="h-14 w-11 sm:h-16 sm:w-13 rounded-xl border-2 text-center text-2xl sm:text-3xl font-black outline-none transition-all duration-150 disabled:opacity-50"
                            :class="[
                                form.errors.code
                                    ? 'border-rose-400 bg-rose-50/60 text-rose-900 focus:border-rose-500 focus:ring-4 focus:ring-rose-100'
                                    : digit
                                        ? 'border-emerald-500 bg-emerald-50/30 text-slate-900 focus:border-emerald-600 focus:bg-white focus:ring-4 focus:ring-emerald-100'
                                        : 'border-slate-200 bg-slate-50 text-slate-900 focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100',
                            ]"
                            @focus="$event.target.select()"
                            @input="onInput(i, $event)"
                            @keydown="onKeydown(i, $event)"
                        />
                    </div>

                    <div v-if="form.errors.code" class="mt-3 flex items-center justify-center gap-1.5 text-center text-xs font-semibold text-rose-600">
                        <ExclamationCircleIcon class="h-4 w-4 flex-shrink-0" />
                        <span>{{ form.errors.code }}</span>
                    </div>

                    <button
                        type="submit"
                        :disabled="!complete || form.processing"
                        class="mt-7 flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 px-5 text-sm font-black text-white shadow-lg shadow-emerald-600/25 transition hover:shadow-emerald-600/40 focus:outline-none focus:ring-4 focus:ring-emerald-200 active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-50 disabled:shadow-none"
                    >
                        <ArrowPathIcon v-if="form.processing" class="h-4 w-4 animate-spin" />
                        <span>{{ form.processing ? 'Verifying code...' : 'Verify and continue' }}</span>
                        <ArrowRightIcon v-if="!form.processing" class="h-4 w-4" />
                    </button>
                </form>

                <!-- Resend & help -->
                <div class="mt-6 border-t border-slate-100 pt-5 text-center">
                    <p class="text-xs font-medium text-slate-500">
                        Didn't receive the email?
                    </p>
                    <div class="mt-2">
                        <button
                            v-if="cooldown <= 0"
                            type="button"
                            :disabled="resending"
                            class="inline-flex items-center gap-1.5 text-xs font-bold text-emerald-700 underline-offset-4 transition hover:text-emerald-900 hover:underline disabled:cursor-not-allowed disabled:opacity-50"
                            @click="resend"
                        >
                            <ArrowPathIcon class="h-3.5 w-3.5" :class="resending ? 'animate-spin' : ''" />
                            <span>{{ resending ? 'Sending new code...' : 'Resend verification code' }}</span>
                        </button>
                        <span v-else class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-500">
                            <ArrowPathIcon class="h-3.5 w-3.5" />
                            <span>Resend code in {{ cooldown }}s</span>
                        </span>
                    </div>
                    <p class="mt-3 text-[11px] leading-normal text-slate-400">
                        Be sure to check your spam or junk folder. The code expires in 10 minutes.
                    </p>
                </div>
            </div>

            <!-- Bottom Navigation -->
            <div class="mt-6 flex items-center justify-between text-xs text-emerald-200/60">
                <Link :href="route('vendor.login')" class="font-semibold transition hover:text-white flex items-center gap-1">
                    &larr; Back to sign in
                </Link>
                <span class="flex items-center gap-1.5 font-bold uppercase tracking-widest text-[11px]">
                    <ShieldCheckIcon class="h-3.5 w-3.5 text-emerald-300" />
                    Encrypted connection
                </span>
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
