import { ref, shallowRef, onUnmounted } from 'vue';

// pdfjs-dist is loaded lazily so pages without a PDF viewer don't pay for it.
let pdfjsPromise = null;
const loadPdfjs = () => {
    if (!pdfjsPromise) {
        pdfjsPromise = Promise.all([
            import('pdfjs-dist'),
            import('pdfjs-dist/build/pdf.worker.min.mjs?url'),
        ]).then(([pdfjs, worker]) => {
            pdfjs.GlobalWorkerOptions.workerSrc = worker.default;
            return pdfjs;
        });
    }
    return pdfjsPromise;
};

/**
 * Load a PDF from a URL and expose the document + page count.
 * All annotator coordinates are normalized 0..1 against each page's viewport,
 * matching the OCR worker's coordinate space.
 */
export function usePdfDocument() {
    const doc = shallowRef(null);
    const numPages = ref(0);
    const loading = ref(false);
    const error = ref(null);

    let loadingTask = null;

    const load = async (url) => {
        loading.value = true;
        error.value = null;
        doc.value = null;
        try {
            const pdfjs = await loadPdfjs();
            loadingTask = pdfjs.getDocument({ url });
            doc.value = await loadingTask.promise;
            numPages.value = doc.value.numPages;
        } catch (e) {
            error.value = e?.message || 'Failed to load PDF';
        } finally {
            loading.value = false;
        }
    };

    onUnmounted(() => {
        loadingTask?.destroy?.();
    });

    return { doc, numPages, loading, error, load };
}
