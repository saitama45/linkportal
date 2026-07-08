<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DocShow from '@/Components/Portal/DocShow.vue';
import ReviewActions from '@/Components/Portal/ReviewActions.vue';
import { usePermission } from '@/Composables/usePermission';
import { ArrowLeftIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    document: { type: Object, required: true },
    title: { type: String, required: true },
    meta: { type: Array, default: () => [] },
    backRoute: { type: String, required: true },
    actRoute: { type: String, required: true },
    approvePermission: { type: String, required: true },
});

const { hasPermission } = usePermission();

const pending = ['submitted', 'under_review'].includes(props.document.status);
</script>

<template>
    <Head :title="`${title} ${document.reference_no} - Link Portal`" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-4">
                <Link :href="route(backRoute)" class="rounded-xl border border-slate-200 bg-white p-2.5 text-slate-500 transition hover:text-emerald-600">
                    <ArrowLeftIcon class="h-5 w-5" />
                </Link>
                <div>
                    <h2 class="text-2xl font-bold leading-tight text-slate-800">{{ title }} Review</h2>
                    <p class="mt-1 text-sm text-slate-500">Submitted by {{ document.vendor?.name }} ({{ document.vendor?.code }})</p>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <DocShow :document="document" :meta="meta" :title="title">
                    <template #actions>
                        <ReviewActions
                            :act-url="route(actRoute, document.id)"
                            :can-act="hasPermission(approvePermission)"
                            :pending="pending"
                            :level="document.current_approval_level"
                        />
                    </template>
                </DocShow>
            </div>
        </div>
    </AppLayout>
</template>
