import { computed, ref } from 'vue';

/**
 * Zoom + pan for a scrollable PDF canvas area.
 *
 * Zoom is a multiplier on the canvas's fit-width size, so 1 always means "fits
 * the column" regardless of the page's own dimensions. Panning is bound to the
 * right mouse button, which leaves the left button free for whatever the host
 * page does on the document (drawing annotation boxes, selecting text).
 *
 * Bind the returned handlers to the scroll container:
 *
 *   <div ref="canvasScroll" class="overflow-auto"
 *        @wheel="onCanvasWheel"
 *        @pointerdown="onCanvasPointerDown"
 *        @pointermove="onCanvasPointerMove"
 *        @pointerup="endPan" @pointercancel="endPan"
 *        @contextmenu.prevent>
 */
export function usePdfViewport({ min = 0.5, max = 4, step = 0.25 } = {}) {
    const zoom = ref(1);
    const zoomPercent = computed(() => Math.round(zoom.value * 100));

    const setZoom = (value) => { zoom.value = Math.min(max, Math.max(min, value)); };
    const zoomIn = () => setZoom(zoom.value + step);
    const zoomOut = () => setZoom(zoom.value - step);
    const zoomReset = () => setZoom(1);

    // Ctrl/Cmd + scroll wheel zooms instead of scrolling, matching common PDF viewers.
    const onCanvasWheel = (event) => {
        if (!event.ctrlKey && !event.metaKey) return;
        event.preventDefault();
        setZoom(zoom.value + (event.deltaY < 0 ? step : -step));
    };

    const canvasScroll = ref(null);
    const isPanning = ref(false);
    let panStart = null; // { x, y, scrollLeft, scrollTop }

    const onCanvasPointerDown = (event) => {
        if (event.button !== 2 || !canvasScroll.value) return; // right button only
        event.preventDefault();
        isPanning.value = true;
        panStart = {
            x: event.clientX,
            y: event.clientY,
            scrollLeft: canvasScroll.value.scrollLeft,
            scrollTop: canvasScroll.value.scrollTop,
        };
        event.currentTarget.setPointerCapture(event.pointerId);
    };

    const onCanvasPointerMove = (event) => {
        if (!isPanning.value || !panStart) return;
        canvasScroll.value.scrollLeft = panStart.scrollLeft - (event.clientX - panStart.x);
        canvasScroll.value.scrollTop = panStart.scrollTop - (event.clientY - panStart.y);
    };

    const endPan = (event) => {
        if (!isPanning.value) return;
        isPanning.value = false;
        panStart = null;
        if (event?.currentTarget?.releasePointerCapture && event.pointerId != null) {
            event.currentTarget.releasePointerCapture(event.pointerId);
        }
    };

    return {
        ZOOM_MIN: min,
        ZOOM_MAX: max,
        zoom,
        zoomPercent,
        setZoom,
        zoomIn,
        zoomOut,
        zoomReset,
        onCanvasWheel,
        canvasScroll,
        isPanning,
        onCanvasPointerDown,
        onCanvasPointerMove,
        endPan,
    };
}
