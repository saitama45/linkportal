<template>
    <div class="min-h-screen bg-[#f5f7fb] font-sans text-slate-950 antialiased">
        <div class="grid min-h-screen lg:grid-cols-[minmax(0,1.08fr)_minmax(460px,0.92fr)]">
            <section class="relative hidden overflow-hidden bg-[#0b1220] text-white lg:flex">
                <div class="absolute inset-0 opacity-[0.18]" style="background-image: linear-gradient(rgba(255,255,255,0.08) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.08) 1px, transparent 1px); background-size: 48px 48px;"></div>
                <div class="absolute inset-0" style="background: radial-gradient(circle at 18% 18%, rgba(14,165,233,0.22), transparent 30%), radial-gradient(circle at 82% 72%, rgba(16,185,129,0.14), transparent 28%);"></div>

                <div class="relative z-10 flex w-full flex-col justify-between px-12 py-10 xl:px-16">
                    <div class="flex items-center gap-4">
                        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-400 to-teal-500 text-emerald-950 shadow-lg shadow-emerald-500/25">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-6 w-6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />
                            </svg>
                        </div>
                        <div>
                            <p class="text-xl font-black leading-none tracking-tight">Link Portal</p>
                            <p class="mt-1 text-[11px] font-bold uppercase tracking-[0.24em] text-emerald-200/70">Partner Access Portal</p>
                        </div>
                    </div>

                    <div class="grid items-center gap-10 xl:grid-cols-[0.9fr_1.1fr]">
                        <div class="max-w-xl">
                            <p class="text-xs font-black uppercase tracking-[0.28em] text-sky-300">Analytics-ready access</p>
                            <h1 class="mt-5 text-5xl font-black leading-[1.02] tracking-normal xl:text-6xl">
                                One secure entry point for every decision.
                            </h1>
                            <p class="mt-6 max-w-md text-base leading-7 text-slate-300">
                                Sign in to manage users, companies, and roles across your secure partner portal.
                            </p>

                            <div class="mt-9 grid max-w-md grid-cols-3 gap-3">
                                <div class="rounded-lg border border-white/10 bg-white/[0.04] p-4">
                                    <p class="text-2xl font-black">3</p>
                                    <p class="mt-1 text-[10px] font-bold uppercase tracking-widest text-slate-400">Core modules</p>
                                </div>
                                <div class="rounded-lg border border-white/10 bg-white/[0.04] p-4">
                                    <p class="text-2xl font-black">RBAC</p>
                                    <p class="mt-1 text-[10px] font-bold uppercase tracking-widest text-slate-400">Permissions</p>
                                </div>
                                <div class="rounded-lg border border-white/10 bg-white/[0.04] p-4">
                                    <p class="text-2xl font-black">BI</p>
                                    <p class="mt-1 text-[10px] font-bold uppercase tracking-widest text-slate-400">Next phase</p>
                                </div>
                            </div>
                        </div>

                        <div class="relative min-h-[560px]">
                            <div class="absolute left-4 top-4 w-[86%] rounded-lg border border-white/10 bg-white/[0.06] p-4 shadow-2xl backdrop-blur">
                                <div class="flex items-center justify-between border-b border-white/10 pb-4">
                                    <div>
                                        <p class="text-xs font-black uppercase tracking-widest text-slate-400">Workspace Health</p>
                                        <p class="mt-1 text-lg font-black">Executive Overview</p>
                                    </div>
                                    <div class="flex gap-1.5">
                                        <span class="h-2.5 w-2.5 rounded-full bg-emerald-400"></span>
                                        <span class="h-2.5 w-2.5 rounded-full bg-sky-400"></span>
                                        <span class="h-2.5 w-2.5 rounded-full bg-violet-400"></span>
                                    </div>
                                </div>

                                <div class="mt-5 grid grid-cols-3 gap-3">
                                    <div class="rounded-lg bg-white/[0.07] p-3">
                                        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400">Users</p>
                                        <p class="mt-3 text-2xl font-black">128</p>
                                        <div class="mt-3 h-1.5 rounded-full bg-slate-700">
                                            <div class="h-1.5 w-3/4 rounded-full bg-sky-400"></div>
                                        </div>
                                    </div>
                                    <div class="rounded-lg bg-white/[0.07] p-3">
                                        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400">Roles</p>
                                        <p class="mt-3 text-2xl font-black">12</p>
                                        <div class="mt-3 h-1.5 rounded-full bg-slate-700">
                                            <div class="h-1.5 w-2/3 rounded-full bg-emerald-400"></div>
                                        </div>
                                    </div>
                                    <div class="rounded-lg bg-white/[0.07] p-3">
                                        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400">Companies</p>
                                        <p class="mt-3 text-2xl font-black">9</p>
                                        <div class="mt-3 h-1.5 rounded-full bg-slate-700">
                                            <div class="h-1.5 w-1/2 rounded-full bg-violet-400"></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-5 rounded-lg bg-slate-950/40 p-4">
                                    <div class="flex h-44 items-end gap-2">
                                        <div class="h-[34%] flex-1 rounded-t bg-sky-400/70"></div>
                                        <div class="h-[58%] flex-1 rounded-t bg-sky-400"></div>
                                        <div class="h-[45%] flex-1 rounded-t bg-emerald-400/80"></div>
                                        <div class="h-[72%] flex-1 rounded-t bg-sky-400"></div>
                                        <div class="h-[52%] flex-1 rounded-t bg-violet-400/80"></div>
                                        <div class="h-[82%] flex-1 rounded-t bg-emerald-400"></div>
                                        <div class="h-[64%] flex-1 rounded-t bg-sky-300"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="absolute bottom-12 right-2 w-72 rounded-lg border border-white/10 bg-white p-5 text-slate-950 shadow-2xl">
                                <div class="flex items-center justify-between">
                                    <p class="text-xs font-black uppercase tracking-widest text-slate-500">Access Control</p>
                                    <span class="rounded-full bg-emerald-50 px-2 py-1 text-[10px] font-black uppercase tracking-widest text-emerald-700">Live</span>
                                </div>
                                <div class="mt-5 space-y-3">
                                    <div class="flex items-center justify-between rounded-lg bg-slate-50 p-3">
                                        <span class="text-sm font-bold">Admin</span>
                                        <span class="text-xs font-bold text-slate-500">Full access</span>
                                    </div>
                                    <div class="flex items-center justify-between rounded-lg bg-slate-50 p-3">
                                        <span class="text-sm font-bold">Manager</span>
                                        <span class="text-xs font-bold text-slate-500">Scoped</span>
                                    </div>
                                    <div class="flex items-center justify-between rounded-lg bg-slate-50 p-3">
                                        <span class="text-sm font-bold">Partner</span>
                                        <span class="text-xs font-bold text-slate-500">Scoped</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between border-t border-white/10 pt-6 text-xs text-slate-500">
                        <span>&copy; {{ new Date().getFullYear() }} Link Portal</span>
                        <span>Secure partner portal</span>
                    </div>
                </div>
            </section>

            <main class="flex min-h-screen items-center justify-center px-5 py-8 sm:px-8 lg:px-12">
                <div class="w-full max-w-[440px]">
                    <div class="mb-8 flex items-center gap-3 lg:hidden">
                        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-400 to-teal-500 text-emerald-950 shadow-sm">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-6 w-6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />
                            </svg>
                        </div>
                        <div>
                            <p class="text-xl font-black tracking-tight text-slate-950">Link Portal</p>
                            <p class="text-[10px] font-bold uppercase tracking-[0.24em] text-slate-500">Partner Access</p>
                        </div>
                    </div>

                    <slot />
                </div>
            </main>
        </div>
    </div>
</template>
