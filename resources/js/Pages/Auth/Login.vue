<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import {
    ArrowRightIcon,
    AtSymbolIcon,
    BuildingStorefrontIcon,
    CheckCircleIcon,
    EyeIcon,
    EyeSlashIcon,
    LinkIcon,
    LockClosedIcon,
    ShieldCheckIcon,
    TruckIcon,
    UsersIcon,
    WrenchScrewdriverIcon,
} from '@heroicons/vue/24/outline';

defineProps({
    canResetPassword: { type: Boolean },
    status: { type: String },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const showPassword = ref(false);

// --- Mascot: eyes follow the cursor ---
const leftEye = ref(null);
const rightEye = ref(null);
const leftPupil = reactive({ x: 0, y: 0 });
const rightPupil = reactive({ x: 0, y: 0 });
const blink = ref(false);

const MAX_OFFSET = 5;

const trackEye = (eyeEl, pupil, mouseX, mouseY) => {
    if (!eyeEl) return;
    const rect = eyeEl.getBoundingClientRect();
    const cx = rect.left + rect.width / 2;
    const cy = rect.top + rect.height / 2;
    const angle = Math.atan2(mouseY - cy, mouseX - cx);
    const distance = Math.min(MAX_OFFSET, Math.hypot(mouseX - cx, mouseY - cy) / 6);
    pupil.x = Math.cos(angle) * distance;
    pupil.y = Math.sin(angle) * distance;
};

const onMouseMove = (event) => {
    trackEye(leftEye.value, leftPupil, event.clientX, event.clientY);
    trackEye(rightEye.value, rightPupil, event.clientX, event.clientY);
};

const pupilStyle = (pupil) => ({
    transform: `translate(calc(-50% + ${pupil.x}px), calc(-50% + ${pupil.y}px))`,
});

let blinkTimer;
onMounted(() => {
    window.addEventListener('mousemove', onMouseMove, { passive: true });
    blinkTimer = window.setInterval(() => {
        blink.value = true;
        window.setTimeout(() => { blink.value = false; }, 160);
    }, 4200);
});
onBeforeUnmount(() => {
    window.removeEventListener('mousemove', onMouseMove);
    window.clearInterval(blinkTimer);
});

const audiences = [
    { label: 'Customers', icon: UsersIcon },
    { label: 'Partners', icon: BuildingStorefrontIcon },
    { label: 'Vendors', icon: TruckIcon },
    { label: 'Service Providers', icon: WrenchScrewdriverIcon },
];

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
    <Head title="Sign in - Link Portal" />

    <div class="login-root relative min-h-screen w-full overflow-hidden bg-emerald-950 font-sans text-white">
        <!-- ===== Animated background layers ===== -->
        <div class="aurora absolute inset-0"></div>
        <div class="pointer-events-none absolute inset-0">
            <span class="blob blob-1"></span>
            <span class="blob blob-2"></span>
            <span class="blob blob-3"></span>
        </div>
        <div class="grid-overlay pointer-events-none absolute inset-0"></div>
        <div class="pointer-events-none absolute inset-0">
            <span class="orb orb-a"></span>
            <span class="orb orb-b"></span>
            <span class="orb orb-c"></span>
            <span class="orb orb-d"></span>
        </div>
        <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-emerald-950/80 via-transparent to-emerald-950/40"></div>

        <!-- ===== Content ===== -->
        <div class="relative z-10 mx-auto flex min-h-screen max-w-[1680px] flex-col lg:flex-row">
            <!-- Brand hero -->
            <section class="flex flex-1 flex-col justify-between px-7 pb-8 pt-10 sm:px-12 sm:pt-14 lg:px-16 lg:py-14">
                <!-- Brand mark -->
                <header class="anim anim-1 flex items-center gap-3.5">
                    <span class="brand-mark relative flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-emerald-400 to-teal-500 shadow-lg shadow-emerald-500/30">
                        <LinkIcon class="h-6 w-6 text-emerald-950" />
                    </span>
                    <div class="leading-tight">
                        <p class="text-lg font-black tracking-tight">Link Portal</p>
                        <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-emerald-300/80">Partner Access</p>
                    </div>
                </header>

                <!-- Center message -->
                <div class="max-w-xl py-12 lg:py-0">
                    <div class="anim anim-2 mb-6 inline-flex items-center gap-2 rounded-full border border-emerald-400/25 bg-emerald-400/10 px-3.5 py-1.5 text-[11px] font-black uppercase tracking-[0.18em] text-emerald-200 backdrop-blur">
                        <span class="relative flex h-1.5 w-1.5">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-300 opacity-75"></span>
                            <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-emerald-300"></span>
                        </span>
                        Unified External Portal
                    </div>

                    <h1 class="anim anim-3 text-4xl font-black leading-[1.05] tracking-tight sm:text-5xl xl:text-6xl">
                        Your one secure gateway
                        <span class="gradient-text">to work with us.</span>
                    </h1>

                    <p class="anim anim-4 mt-6 max-w-md text-base leading-7 text-emerald-100/80">
                        Sign in to manage documents, track requests, and stay in touch with our team —
                        all from one account.
                    </p>

                    <!-- Audience chips -->
                    <div class="anim anim-5 mt-9 flex flex-wrap gap-2.5">
                        <div
                            v-for="item in audiences"
                            :key="item.label"
                            class="chip group flex items-center gap-2 rounded-xl border border-white/10 bg-white/[0.06] px-3.5 py-2.5 backdrop-blur transition hover:border-emerald-300/40 hover:bg-emerald-400/10"
                        >
                            <component :is="item.icon" class="h-4 w-4 text-emerald-300 transition group-hover:scale-110" />
                            <span class="text-xs font-bold text-emerald-50/90">{{ item.label }}</span>
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <footer class="anim anim-6 flex items-center justify-between gap-4 text-xs text-emerald-200/50">
                    <span class="font-semibold">&copy; {{ new Date().getFullYear() }} Link Portal</span>
                    <span class="hidden items-center gap-1.5 font-bold uppercase tracking-widest sm:flex">
                        <ShieldCheckIcon class="h-3.5 w-3.5" />
                        Encrypted connection
                    </span>
                </footer>
            </section>

            <!-- Form area (vertically centered, right-aligned on desktop) -->
            <section class="flex w-full items-center justify-center px-6 pb-10 sm:px-10 lg:w-[44%] lg:justify-end lg:px-14 lg:py-14 xl:px-20">
                <div class="card-in relative w-full max-w-md">
                    <!-- Mascot: googly eyes that follow the cursor -->
                    <div class="mascot absolute -top-8 right-8 z-20" aria-hidden="true">
                        <span class="antenna-stalk absolute -top-2.5 left-1/2 h-2.5 w-px -translate-x-1/2 bg-emerald-300/60"></span>
                        <span class="antenna-dot absolute -top-4 left-1/2 h-2 w-2 rounded-full bg-emerald-300"></span>
                        <div class="mascot-head relative flex h-14 w-[4.5rem] items-center justify-center gap-2 rounded-[46%] bg-gradient-to-br from-emerald-400 to-teal-500 shadow-lg shadow-emerald-500/40 ring-4 ring-white">
                            <div ref="leftEye" class="eye relative h-5 w-5 rounded-full bg-white" :class="{ 'is-blinking': blink }">
                                <span class="pupil absolute left-1/2 top-1/2 h-2.5 w-2.5 rounded-full bg-emerald-950" :style="pupilStyle(leftPupil)"></span>
                            </div>
                            <div ref="rightEye" class="eye relative h-5 w-5 rounded-full bg-white" :class="{ 'is-blinking': blink }">
                                <span class="pupil absolute left-1/2 top-1/2 h-2.5 w-2.5 rounded-full bg-emerald-950" :style="pupilStyle(rightPupil)"></span>
                            </div>
                            <svg class="absolute bottom-1.5 left-1/2 h-2 w-4 -translate-x-1/2 text-emerald-950/70" viewBox="0 0 20 10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                                <path d="M2 2 Q10 9 18 2" />
                            </svg>
                        </div>
                    </div>

                    <div class="relative overflow-hidden rounded-[26px] border border-white/60 bg-white/95 p-7 shadow-2xl shadow-emerald-950/40 backdrop-blur-xl sm:p-9">
                        <!-- top accent -->
                        <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-emerald-400 via-teal-400 to-green-400"></div>

                        <div
                            v-if="status"
                            class="mb-6 flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800"
                        >
                            <CheckCircleIcon class="mt-0.5 h-5 w-5 flex-shrink-0" />
                            <span>{{ status }}</span>
                        </div>

                        <div class="mb-7">
                            <h2 class="text-2xl font-black tracking-tight text-slate-900">Welcome back</h2>
                            <p class="mt-1.5 text-sm font-medium text-slate-500">
                                Sign in to access your Link Portal workspace.
                            </p>
                        </div>

                        <form class="space-y-5" @submit.prevent="submit">
                            <div>
                                <label for="email" class="mb-2 block text-sm font-bold text-slate-700">Email address</label>
                                <div class="relative">
                                    <AtSymbolIcon class="pointer-events-none absolute left-3.5 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" />
                                    <input
                                        id="email"
                                        v-model="form.email"
                                        type="email"
                                        required
                                        autofocus
                                        autocomplete="username"
                                        placeholder="you@company.com"
                                        :class="[
                                            'block h-12 w-full rounded-xl border bg-slate-50 pl-11 pr-4 text-sm font-semibold text-slate-900 outline-none transition focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100',
                                            form.errors.email ? 'border-red-300 ring-4 ring-red-50' : 'border-slate-200',
                                        ]"
                                    />
                                </div>
                                <InputError class="mt-2" :message="form.errors.email" />
                            </div>

                            <div>
                                <div class="mb-2 flex items-center justify-between gap-4">
                                    <label for="password" class="block text-sm font-bold text-slate-700">Password</label>
                                    <Link
                                        v-if="canResetPassword"
                                        :href="route('password.request')"
                                        tabindex="-1"
                                        class="text-sm font-bold text-emerald-700 transition hover:text-emerald-900"
                                    >
                                        Forgot password?
                                    </Link>
                                </div>
                                <div class="relative">
                                    <LockClosedIcon class="pointer-events-none absolute left-3.5 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" />
                                    <input
                                        id="password"
                                        v-model="form.password"
                                        :type="showPassword ? 'text' : 'password'"
                                        required
                                        autocomplete="current-password"
                                        placeholder="Enter your password"
                                        :class="[
                                            'block h-12 w-full rounded-xl border bg-slate-50 pl-11 pr-12 text-sm font-semibold text-slate-900 outline-none transition focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100',
                                            form.errors.password ? 'border-red-300 ring-4 ring-red-50' : 'border-slate-200',
                                        ]"
                                    />
                                    <button
                                        type="button"
                                        class="absolute right-3 top-1/2 -translate-y-1/2 rounded-md p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
                                        tabindex="-1"
                                        :aria-label="showPassword ? 'Hide password' : 'Show password'"
                                        @click="showPassword = !showPassword"
                                    >
                                        <EyeSlashIcon v-if="showPassword" class="h-5 w-5" />
                                        <EyeIcon v-else class="h-5 w-5" />
                                    </button>
                                </div>
                                <InputError class="mt-2" :message="form.errors.password" />
                            </div>

                            <label class="flex cursor-pointer select-none items-center gap-2.5">
                                <Checkbox
                                    v-model:checked="form.remember"
                                    name="remember"
                                    class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                                />
                                <span class="text-sm font-semibold text-slate-600">Keep me signed in</span>
                            </label>

                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="btn-cta group relative flex h-12 w-full items-center justify-center gap-2 overflow-hidden rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 px-5 text-sm font-black text-white shadow-lg shadow-emerald-600/25 transition hover:shadow-emerald-600/40 focus:outline-none focus:ring-4 focus:ring-emerald-200 disabled:cursor-not-allowed disabled:opacity-70"
                            >
                                <svg
                                    v-if="form.processing"
                                    class="h-4 w-4 animate-spin text-white"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                </svg>
                                <span>{{ form.processing ? 'Signing in...' : 'Sign in' }}</span>
                                <ArrowRightIcon v-if="!form.processing" class="h-4 w-4 transition group-hover:translate-x-1" />
                            </button>
                        </form>

                        <div class="mt-7 flex items-start gap-2.5 rounded-xl border border-slate-100 bg-slate-50 px-4 py-3">
                            <ShieldCheckIcon class="mt-0.5 h-4 w-4 flex-shrink-0 text-emerald-500" />
                            <p class="text-xs leading-5 text-slate-500">
                                Access is restricted to authorized accounts. Contact your administrator if you need portal access.
                            </p>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>

<style scoped>
/* ---------- Aurora / gradient base ---------- */
.aurora {
    background:
        radial-gradient(60% 80% at 15% 20%, rgba(16, 185, 129, 0.45), transparent 60%),
        radial-gradient(50% 60% at 85% 30%, rgba(20, 184, 166, 0.4), transparent 55%),
        radial-gradient(70% 70% at 60% 90%, rgba(5, 150, 105, 0.5), transparent 60%),
        linear-gradient(135deg, #022c22 0%, #064e3b 45%, #065f46 100%);
    background-size: 200% 200%;
    animation: gradientShift 18s ease infinite;
}

/* ---------- Blurred drifting blobs ---------- */
.blob {
    position: absolute;
    border-radius: 9999px;
    filter: blur(70px);
    opacity: 0.55;
    will-change: transform;
}
.blob-1 {
    top: -8%;
    left: -6%;
    height: 34rem;
    width: 34rem;
    background: radial-gradient(circle, rgba(52, 211, 153, 0.75), transparent 70%);
    animation: drift 24s ease-in-out infinite;
}
.blob-2 {
    bottom: -12%;
    right: -4%;
    height: 30rem;
    width: 30rem;
    background: radial-gradient(circle, rgba(45, 212, 191, 0.7), transparent 70%);
    animation: drift2 30s ease-in-out infinite;
}
.blob-3 {
    top: 35%;
    right: 22%;
    height: 22rem;
    width: 22rem;
    background: radial-gradient(circle, rgba(132, 204, 22, 0.5), transparent 70%);
    animation: drift 34s ease-in-out infinite reverse;
}

/* ---------- Subtle grid ---------- */
.grid-overlay {
    background-image:
        linear-gradient(to right, rgba(255, 255, 255, 0.045) 1px, transparent 1px),
        linear-gradient(to bottom, rgba(255, 255, 255, 0.045) 1px, transparent 1px);
    background-size: 46px 46px;
    -webkit-mask-image: radial-gradient(ellipse 80% 80% at 40% 40%, #000 30%, transparent 75%);
    mask-image: radial-gradient(ellipse 80% 80% at 40% 40%, #000 30%, transparent 75%);
}

/* ---------- Floating orbs ---------- */
.orb {
    position: absolute;
    border-radius: 9999px;
    background: rgba(209, 250, 229, 0.7);
    box-shadow: 0 0 14px 2px rgba(110, 231, 183, 0.6);
}
.orb-a { top: 22%; left: 30%; height: 7px; width: 7px; animation: floaty 7s ease-in-out infinite; }
.orb-b { top: 62%; left: 18%; height: 5px; width: 5px; opacity: 0.7; animation: floaty 9s ease-in-out infinite 1s; }
.orb-c { top: 44%; left: 52%; height: 4px; width: 4px; opacity: 0.6; animation: floaty 11s ease-in-out infinite 0.5s; }
.orb-d { top: 74%; left: 46%; height: 6px; width: 6px; opacity: 0.8; animation: floaty 8s ease-in-out infinite 2s; }

/* ---------- Brand mark shimmer ---------- */
.brand-mark::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: inherit;
    background: linear-gradient(120deg, transparent 30%, rgba(255, 255, 255, 0.55) 50%, transparent 70%);
    background-size: 200% 100%;
    animation: shimmer 4.5s ease-in-out infinite;
}

/* ---------- Mascot ---------- */
.mascot-head {
    animation: bob 3.6s ease-in-out infinite;
}
.antenna-dot {
    box-shadow: 0 0 8px 2px rgba(110, 231, 183, 0.85);
    animation: pulseDot 2s ease-in-out infinite;
    transform: translateX(-50%);
}
.pupil {
    transition: transform 0.12s ease-out;
    will-change: transform;
}
.eye {
    transition: transform 0.12s ease;
    transform-origin: center;
}
.eye.is-blinking {
    transform: scaleY(0.12);
}

@keyframes bob {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-5px); }
}
@keyframes pulseDot {
    0%, 100% { opacity: 1; transform: translateX(-50%) scale(1); }
    50% { opacity: 0.5; transform: translateX(-50%) scale(0.75); }
}

/* ---------- Gradient headline text ---------- */
.gradient-text {
    background: linear-gradient(100deg, #6ee7b7, #5eead4 45%, #a3e635);
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
    color: transparent;
}

/* ---------- Keyframes ---------- */
@keyframes gradientShift {
    0%, 100% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
}
@keyframes drift {
    0%, 100% { transform: translate(0, 0) scale(1); }
    50% { transform: translate(50px, -34px) scale(1.14); }
}
@keyframes drift2 {
    0%, 100% { transform: translate(0, 0) scale(1); }
    50% { transform: translate(-46px, 30px) scale(1.1); }
}
@keyframes floaty {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-20px); }
}
@keyframes shimmer {
    0%, 100% { background-position: 150% 0; }
    50% { background-position: -50% 0; }
}
@keyframes fadeUp {
    from { opacity: 0; transform: translateY(22px); }
    to { opacity: 1; transform: translateY(0); }
}
@keyframes cardIn {
    from { opacity: 0; transform: translateY(28px) scale(0.98); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

/* ---------- Entrance animations ---------- */
.anim { opacity: 0; animation: fadeUp 0.7s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
.anim-1 { animation-delay: 0.05s; }
.anim-2 { animation-delay: 0.18s; }
.anim-3 { animation-delay: 0.28s; }
.anim-4 { animation-delay: 0.4s; }
.anim-5 { animation-delay: 0.52s; }
.anim-6 { animation-delay: 0.64s; }
.card-in { opacity: 0; animation: cardIn 0.8s cubic-bezier(0.16, 1, 0.3, 1) 0.3s forwards; }

/* ---------- Reduced motion ---------- */
@media (prefers-reduced-motion: reduce) {
    .aurora, .blob, .orb, .brand-mark::after, .mascot-head, .antenna-dot { animation: none; }
    .anim, .card-in { animation: none; opacity: 1; transform: none; }
}
</style>
