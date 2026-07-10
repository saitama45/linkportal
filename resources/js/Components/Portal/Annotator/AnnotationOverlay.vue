<script setup>
import { computed, ref } from 'vue';

/**
 * Interactive layer over a rendered PDF page. All geometry is normalized 0..1
 * page-relative — the same coordinate space the OCR worker consumes.
 *
 * - mode 'field' + selectedFieldKey: drag on empty space (re)draws that field's box
 * - mode 'table': drag draws the line-item table region
 * - existing boxes: click to select, drag to move, corner handles to resize
 * - table column dividers drag horizontally
 */
const props = defineProps({
    fields: { type: Array, default: () => [] },
    table: { type: Object, default: null },
    page: { type: Number, default: 1 },
    width: { type: Number, required: true },
    height: { type: Number, required: true },
    selectedFieldKey: { type: String, default: null },
    mode: { type: String, default: null }, // field | table | null
    readonly: { type: Boolean, default: false },
});

const emit = defineEmits(['update:fields', 'update:table', 'select', 'mutate-start', 'mutate-end']);

const MIN_SIZE = 0.008;
const clamp = (v) => Math.min(1, Math.max(0, v));

const px = (bbox) => ({
    left: `${bbox[0] * props.width}px`,
    top: `${bbox[1] * props.height}px`,
    width: `${(bbox[2] - bbox[0]) * props.width}px`,
    height: `${(bbox[3] - bbox[1]) * props.height}px`,
});

const pageFields = computed(() => props.fields.filter((f) => f.bbox && f.page === props.page));
const tableOnPage = computed(() => props.table?.bbox && props.table.page === props.page);

// ---- drag state machine ----
const drag = ref(null); // { kind, key?, colIndex?, startX, startY, orig }
const draft = ref(null); // live rect while drawing

const toNorm = (event) => {
    const rect = event.currentTarget.closest('[data-overlay-root]').getBoundingClientRect();
    return [clamp((event.clientX - rect.left) / props.width), clamp((event.clientY - rect.top) / props.height)];
};

const emitField = (key, bbox) => {
    emit('update:fields', props.fields.map((f) => (f.key === key ? { ...f, page: props.page, bbox } : f)));
};

const emitTable = (patch) => {
    emit('update:table', { ...props.table, ...patch });
};

const orderedBbox = (a, b) => [Math.min(a[0], b[0]), Math.min(a[1], b[1]), Math.max(a[0], b[0]), Math.max(a[1], b[1])];

const startDraw = (event) => {
    if (props.readonly || drag.value) return;
    if (props.mode === 'field' && !props.selectedFieldKey) return;
    if (!['field', 'table'].includes(props.mode)) return;

    const [x, y] = toNorm(event);
    emit('mutate-start');
    drag.value = { kind: 'draw', start: [x, y] };
    draft.value = [x, y, x, y];
};

const startMove = (event, kind, key = null) => {
    if (props.readonly) return;
    event.stopPropagation();
    const [x, y] = toNorm(event);
    const bbox = kind === 'table' ? props.table.bbox : props.fields.find((f) => f.key === key)?.bbox;
    if (!bbox) return;
    if (kind === 'field') emit('select', key);
    emit('mutate-start');
    drag.value = { kind: `move-${kind}`, key, start: [x, y], orig: [...bbox] };
};

const startResize = (event, kind, corner, key = null) => {
    if (props.readonly) return;
    event.stopPropagation();
    const bbox = kind === 'table' ? props.table.bbox : props.fields.find((f) => f.key === key)?.bbox;
    if (!bbox) return;
    emit('mutate-start');
    drag.value = { kind: `resize-${kind}`, key, corner, orig: [...bbox] };
};

const startColumnDrag = (event, colIndex) => {
    if (props.readonly) return;
    event.stopPropagation();
    emit('mutate-start');
    drag.value = { kind: 'column', colIndex, origColumns: props.table.columns.map((c) => ({ ...c })) };
};

const onPointerMove = (event) => {
    if (!drag.value) return;
    const [x, y] = toNorm(event);
    const d = drag.value;

    if (d.kind === 'draw') {
        draft.value = orderedBbox(d.start, [x, y]);
    } else if (d.kind === 'move-field' || d.kind === 'move-table') {
        const dx = x - d.start[0];
        const dy = y - d.start[1];
        const [x0, y0, x1, y1] = d.orig;
        const w = x1 - x0;
        const h = y1 - y0;
        const nx0 = clamp(Math.min(x0 + dx, 1 - w));
        const ny0 = clamp(Math.min(y0 + dy, 1 - h));
        const bbox = [nx0, ny0, nx0 + w, ny0 + h];
        if (d.kind === 'move-field') emitField(d.key, bbox);
        else moveTableBbox(bbox);
    } else if (d.kind === 'resize-field' || d.kind === 'resize-table') {
        const bbox = [...d.orig];
        if (d.corner.includes('l')) bbox[0] = Math.min(x, bbox[2] - MIN_SIZE);
        if (d.corner.includes('t')) bbox[1] = Math.min(y, bbox[3] - MIN_SIZE);
        if (d.corner.includes('r')) bbox[2] = Math.max(x, bbox[0] + MIN_SIZE);
        if (d.corner.includes('b')) bbox[3] = Math.max(y, bbox[1] + MIN_SIZE);
        if (d.kind === 'resize-field') emitField(d.key, bbox);
        else moveTableBbox(bbox);
    } else if (d.kind === 'column') {
        const columns = d.origColumns.map((c) => ({ ...c }));
        const i = d.colIndex;
        const min = columns[i].x0 + MIN_SIZE;
        const max = columns[i + 1].x1 - MIN_SIZE;
        const boundary = Math.min(Math.max(x, min), max);
        columns[i].x1 = boundary;
        columns[i + 1].x0 = boundary;
        emitTable({ columns });
    }
};

// Rescale column x-ranges proportionally when the table bbox changes horizontally
const moveTableBbox = (bbox) => {
    const old = props.table.bbox;
    let columns = props.table.columns;
    if (columns?.length && (old[0] !== bbox[0] || old[2] !== bbox[2])) {
        const oldW = old[2] - old[0] || 1;
        const newW = bbox[2] - bbox[0];
        columns = columns.map((c) => ({
            ...c,
            x0: bbox[0] + ((c.x0 - old[0]) / oldW) * newW,
            x1: bbox[0] + ((c.x1 - old[0]) / oldW) * newW,
        }));
    }
    emitTable({ bbox, columns });
};

const onPointerUp = () => {
    if (!drag.value) return;
    if (drag.value.kind === 'draw' && draft.value) {
        const bbox = draft.value;
        if (bbox[2] - bbox[0] >= MIN_SIZE && bbox[3] - bbox[1] >= MIN_SIZE) {
            if (props.mode === 'field' && props.selectedFieldKey) {
                emitField(props.selectedFieldKey, bbox);
            } else if (props.mode === 'table') {
                emitTable({ page: props.page, bbox, columns: defaultColumns(bbox) });
            }
        }
    }
    drag.value = null;
    draft.value = null;
    emit('mutate-end');
};

const defaultColumns = (bbox) => {
    if (props.table?.columns?.length) {
        // keep existing keys, respread across the new region
        return moveColumnsInto(props.table.columns, bbox);
    }
    const keys = ['description', 'quantity', 'uom', 'unit_price', 'line_total'];
    const weights = [0.42, 0.12, 0.12, 0.17, 0.17];
    const w = bbox[2] - bbox[0];
    let x = bbox[0];
    return keys.map((key, i) => {
        const col = { key, x0: x, x1: x + w * weights[i] };
        x = col.x1;
        return col;
    });
};

const moveColumnsInto = (columns, bbox) => {
    const total = columns.reduce((s, c) => s + (c.x1 - c.x0), 0) || 1;
    const w = bbox[2] - bbox[0];
    let x = bbox[0];
    return columns.map((c) => {
        const col = { ...c, x0: x, x1: x + ((c.x1 - c.x0) / total) * w };
        x = col.x1;
        return col;
    });
};

const corners = ['tl', 'tr', 'bl', 'br'];

// Overlay-absolute placement — for handles rendered at the overlay root (table).
const cornerStyle = (corner, bbox) => ({
    left: `${(corner.includes('l') ? bbox[0] : bbox[2]) * props.width - 5}px`,
    top: `${(corner.includes('t') ? bbox[1] : bbox[3]) * props.height - 5}px`,
    cursor: corner === 'tl' || corner === 'br' ? 'nwse-resize' : 'nesw-resize',
});

// Box-relative placement — for handles rendered inside the field box, so the
// box's own offset isn't double-counted.
const fieldCornerStyle = (corner, bbox) => ({
    left: `${(corner.includes('l') ? 0 : (bbox[2] - bbox[0]) * props.width) - 5}px`,
    top: `${(corner.includes('t') ? 0 : (bbox[3] - bbox[1]) * props.height) - 5}px`,
    cursor: corner === 'tl' || corner === 'br' ? 'nwse-resize' : 'nesw-resize',
});
</script>

<template>
    <div
        data-overlay-root
        class="absolute inset-0 select-none"
        :class="{ 'cursor-crosshair': !readonly && ((mode === 'field' && selectedFieldKey) || mode === 'table') }"
        @pointerdown="startDraw"
        @pointermove="onPointerMove"
        @pointerup="onPointerUp"
        @pointerleave="onPointerUp"
    >
        <!-- Field boxes -->
        <div
            v-for="field in pageFields"
            :key="field.key"
            :style="px(field.bbox)"
            :class="['absolute rounded-sm border-2', field.key === selectedFieldKey
                ? 'border-emerald-500 bg-emerald-400/20 ring-2 ring-emerald-300'
                : 'border-emerald-400/80 bg-emerald-300/10 hover:bg-emerald-300/20',
                readonly ? '' : 'cursor-move']"
            @pointerdown="startMove($event, 'field', field.key)"
        >
            <span class="absolute -top-5 left-0 whitespace-nowrap rounded bg-emerald-600 px-1.5 py-0.5 text-[10px] font-bold text-white">
                {{ field.label || field.key }}
            </span>
            <template v-if="!readonly && field.key === selectedFieldKey">
                <span v-for="corner in corners" :key="corner"
                    class="absolute z-10 h-2.5 w-2.5 rounded-full border border-white bg-emerald-600"
                    :style="fieldCornerStyle(corner, field.bbox)"
                    @pointerdown="startResize($event, 'field', corner, field.key)" />
            </template>
        </div>

        <!-- Table region -->
        <div
            v-if="tableOnPage"
            :style="px(table.bbox)"
            :class="['absolute rounded-sm border-2 border-indigo-500 bg-indigo-300/10', readonly ? '' : 'cursor-move']"
            @pointerdown="startMove($event, 'table')"
        >
            <span class="absolute -top-5 left-0 rounded bg-indigo-600 px-1.5 py-0.5 text-[10px] font-bold text-white">Line Items</span>

            <!-- column labels -->
            <span v-for="col in table.columns" :key="`label-${col.key}`"
                class="absolute top-0.5 truncate px-1 text-[9px] font-bold uppercase text-indigo-700"
                :style="{
                    left: `${((col.x0 - table.bbox[0]) / (table.bbox[2] - table.bbox[0])) * 100}%`,
                    width: `${((col.x1 - col.x0) / (table.bbox[2] - table.bbox[0])) * 100}%`,
                }">
                {{ col.key }}
            </span>
        </div>

        <!-- Column dividers (absolute over the page so they extend the full table height) -->
        <template v-if="tableOnPage && !readonly">
            <div v-for="(col, i) in table.columns.slice(0, -1)" :key="`divider-${i}`"
                class="absolute z-10 w-1.5 -translate-x-1/2 cursor-col-resize rounded bg-indigo-500/70 hover:bg-indigo-600"
                :style="{
                    left: `${col.x1 * width}px`,
                    top: `${table.bbox[1] * height}px`,
                    height: `${(table.bbox[3] - table.bbox[1]) * height}px`,
                }"
                @pointerdown="startColumnDrag($event, i)" />
            <span v-for="corner in corners" :key="`table-${corner}`"
                class="absolute z-10 h-2.5 w-2.5 rounded-full border border-white bg-indigo-600"
                :style="cornerStyle(corner, table.bbox)"
                @pointerdown="startResize($event, 'table', corner)" />
        </template>

        <!-- Draft rect while drawing -->
        <div v-if="draft" :style="px(draft)"
            :class="['pointer-events-none absolute rounded-sm border-2 border-dashed',
                mode === 'table' ? 'border-indigo-500 bg-indigo-300/10' : 'border-emerald-500 bg-emerald-300/10']" />
    </div>
</template>
