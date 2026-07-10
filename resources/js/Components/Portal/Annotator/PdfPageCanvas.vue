<script setup>
import { ref, watch, onMounted, onUnmounted } from 'vue';

/**
 * Renders one PDF page to a canvas at fit-width scale. The default slot is an
 * absolutely-positioned layer over the page for annotation overlays.
 */
const props = defineProps({
    doc: { type: Object, default: null }, // pdfjs PDFDocumentProxy
    pageNumber: { type: Number, default: 1 },
});

const emit = defineEmits(['rendered']);

const container = ref(null);
const canvas = ref(null);
const size = ref({ width: 0, height: 0 });

let renderTask = null;
let resizeObserver = null;
let renderToken = 0;

const render = async () => {
    if (!props.doc || !canvas.value || !container.value) return;
    const token = ++renderToken;

    renderTask?.cancel?.();
    const page = await props.doc.getPage(props.pageNumber);
    if (token !== renderToken) return;

    const containerWidth = container.value.clientWidth || 600;
    const base = page.getViewport({ scale: 1 });
    const scale = containerWidth / base.width;
    const viewport = page.getViewport({ scale });
    const dpr = window.devicePixelRatio || 1;

    const el = canvas.value;
    el.width = Math.floor(viewport.width * dpr);
    el.height = Math.floor(viewport.height * dpr);
    el.style.width = `${Math.floor(viewport.width)}px`;
    el.style.height = `${Math.floor(viewport.height)}px`;

    size.value = { width: Math.floor(viewport.width), height: Math.floor(viewport.height) };

    const ctx = el.getContext('2d');
    renderTask = page.render({
        canvasContext: ctx,
        viewport,
        transform: dpr !== 1 ? [dpr, 0, 0, dpr, 0, 0] : undefined,
    });
    try {
        await renderTask.promise;
        emit('rendered', size.value);
    } catch (e) {
        if (e?.name !== 'RenderingCancelledException') throw e;
    }
};

watch(() => [props.doc, props.pageNumber], render);

onMounted(() => {
    resizeObserver = new ResizeObserver(() => render());
    if (container.value) resizeObserver.observe(container.value);
    render();
});

onUnmounted(() => {
    resizeObserver?.disconnect();
    renderTask?.cancel?.();
});
</script>

<template>
    <div ref="container" class="relative w-full">
        <canvas ref="canvas" class="block rounded-lg shadow" />
        <div v-if="size.width" class="absolute left-0 top-0" :style="{ width: `${size.width}px`, height: `${size.height}px` }">
            <slot :width="size.width" :height="size.height" />
        </div>
    </div>
</template>
