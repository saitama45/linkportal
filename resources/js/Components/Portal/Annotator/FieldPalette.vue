<script setup>
import { ref } from 'vue';
import { CheckCircleIcon, TrashIcon, PlusIcon } from '@heroicons/vue/24/outline';
import { keyForFieldLabel } from '../documentFields';

/**
 * Header-field list for the annotator: pick a field, then draw its box on the
 * page. Fields with a bbox show a check. Standard keys map to the promoted
 * columns on portal_intake_documents; custom keys ride along in JSON.
 */
const props = defineProps({
    fields: { type: Array, default: () => [] },
    selectedKey: { type: String, default: null },
    readonly: { type: Boolean, default: false },
});

const emit = defineEmits(['select', 'update:fields']);

const TYPES = ['text', 'date', 'amount', 'qty'];

const newField = ref({ key: '', type: 'text' });

const update = (key, patch) => {
    emit('update:fields', props.fields.map((f) => (f.key === key ? { ...f, ...patch } : f)));
};

const remove = (key) => {
    emit('update:fields', props.fields.filter((f) => f.key !== key));
    if (props.selectedKey === key) emit('select', null);
};

const addField = () => {
    const key = keyForFieldLabel(newField.value.key);
    if (!key || props.fields.some((f) => f.key === key)) return;
    emit('update:fields', [...props.fields, {
        key,
        label: newField.value.key.trim(),
        type: newField.value.type,
        required: false,
        page: 1,
        bbox: null,
    }]);
    emit('select', key);
    newField.value = { key: '', type: 'text' };
};
</script>

<template>
    <div class="space-y-2">
        <div v-for="field in fields" :key="field.key"
            :class="['rounded-xl border p-3 transition-all', selectedKey === field.key
                ? 'border-emerald-500 bg-emerald-50 ring-1 ring-emerald-200'
                : 'border-slate-200 hover:border-slate-300', readonly ? '' : 'cursor-pointer']"
            @click="!readonly && emit('select', selectedKey === field.key ? null : field.key)">
            <div class="flex items-center justify-between gap-2">
                <div class="flex min-w-0 items-center gap-2">
                    <CheckCircleIcon :class="['h-4 w-4 flex-shrink-0', field.bbox ? 'text-emerald-500' : 'text-slate-200']" />
                    <span class="truncate text-sm font-semibold text-slate-800">{{ field.label || field.key }}</span>
                </div>
                <div class="flex flex-shrink-0 items-center gap-1.5">
                    <select :value="field.type" :disabled="readonly"
                        class="rounded border-slate-200 py-0.5 pl-1.5 pr-6 text-xs text-slate-600 focus:border-emerald-500 focus:ring-emerald-500/30"
                        @click.stop @change="update(field.key, { type: $event.target.value })">
                        <option v-for="type in TYPES" :key="type" :value="type">{{ type }}</option>
                    </select>
                    <label class="flex items-center gap-1 text-xs text-slate-500" @click.stop>
                        <input type="checkbox" :checked="field.required" :disabled="readonly"
                            class="h-3.5 w-3.5 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500/30"
                            @change="update(field.key, { required: $event.target.checked })" />
                        req
                    </label>
                    <button v-if="!readonly" type="button" class="rounded p-0.5 text-slate-300 hover:text-red-500"
                        @click.stop="remove(field.key)">
                        <TrashIcon class="h-3.5 w-3.5" />
                    </button>
                </div>
            </div>
            <p v-if="selectedKey === field.key && !field.bbox" class="mt-1.5 text-xs font-medium text-emerald-700">
                Draw a box on the page for this field.
            </p>
        </div>

        <div v-if="!readonly" class="flex items-center gap-2 pt-1">
            <input v-model="newField.key" type="text" placeholder="Add custom field..."
                class="min-w-0 flex-1 rounded-lg border-slate-200 py-1.5 text-xs focus:border-emerald-500 focus:ring-emerald-500/30"
                @keyup.enter="addField" />
            <select v-model="newField.type" class="rounded-lg border-slate-200 py-1.5 pl-2 pr-7 text-xs focus:border-emerald-500 focus:ring-emerald-500/30">
                <option v-for="type in TYPES" :key="type" :value="type">{{ type }}</option>
            </select>
            <button type="button" class="rounded-lg bg-slate-100 p-1.5 text-slate-600 hover:bg-slate-200" @click="addField">
                <PlusIcon class="h-4 w-4" />
            </button>
        </div>
    </div>
</template>
