<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { CheckIcon, XMarkIcon, ArrowUturnLeftIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    actUrl: { type: String, required: true },
    // whether the current user may act (permission check done by caller)
    canAct: { type: Boolean, default: false },
    pending: { type: Boolean, default: false },
    level: { type: Number, default: 1 },
});

const form = useForm({ action: '', remarks: '' });
const confirmAction = ref('');

const act = (action) => {
    if (confirmAction.value !== action) {
        confirmAction.value = action;
        return;
    }
    form.action = action;
    form.put(props.actUrl, {
        preserveScroll: true,
        onSuccess: () => { form.reset(); confirmAction.value = ''; },
    });
};
</script>

<template>
    <div v-if="pending && canAct" class="rounded-2xl border border-emerald-200 bg-emerald-50/60 p-5">
        <h4 class="mb-1 text-xs font-black uppercase tracking-widest text-emerald-900">Your Action — Level {{ level }}</h4>
        <p class="mb-4 text-xs font-medium text-emerald-800">This document is awaiting your review.</p>

        <textarea v-model="form.remarks" rows="2" placeholder="Remarks (optional, required context for reject/return)"
            class="mb-3 block w-full rounded-xl border border-emerald-200 bg-white px-3.5 py-2.5 text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20"></textarea>

        <div class="grid grid-cols-1 gap-2">
            <button :disabled="form.processing" @click="act('approved')"
                :class="['inline-flex items-center justify-center gap-1.5 rounded-xl px-4 py-2.5 text-xs font-bold transition disabled:opacity-50',
                    confirmAction === 'approved' ? 'bg-emerald-700 text-white' : 'bg-emerald-600 text-white hover:bg-emerald-700']">
                <CheckIcon class="h-4 w-4" />
                {{ confirmAction === 'approved' ? 'Click again to confirm approval' : 'Approve' }}
            </button>
            <div class="grid grid-cols-2 gap-2">
                <button :disabled="form.processing" @click="act('returned')"
                    :class="['inline-flex items-center justify-center gap-1.5 rounded-xl border px-4 py-2.5 text-xs font-bold transition disabled:opacity-50',
                        confirmAction === 'returned' ? 'border-orange-400 bg-orange-100 text-orange-800' : 'border-orange-200 bg-white text-orange-600 hover:bg-orange-50']">
                    <ArrowUturnLeftIcon class="h-4 w-4" />
                    {{ confirmAction === 'returned' ? 'Confirm return' : 'Return' }}
                </button>
                <button :disabled="form.processing" @click="act('rejected')"
                    :class="['inline-flex items-center justify-center gap-1.5 rounded-xl border px-4 py-2.5 text-xs font-bold transition disabled:opacity-50',
                        confirmAction === 'rejected' ? 'border-red-400 bg-red-100 text-red-800' : 'border-red-200 bg-white text-red-600 hover:bg-red-50']">
                    <XMarkIcon class="h-4 w-4" />
                    {{ confirmAction === 'rejected' ? 'Confirm reject' : 'Reject' }}
                </button>
            </div>
        </div>
    </div>
</template>
