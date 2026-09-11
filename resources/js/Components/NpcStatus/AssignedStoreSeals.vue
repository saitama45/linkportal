<template>
    <div class="space-y-5">
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-xl font-bold text-gray-900 dark:text-gray-100">My NPC Seals</h2>
            <p class="text-sm text-gray-500 dark:text-gray-300">
                Download seals for the store assigned to your Cashier account, then upload proof of use for each document. Hub staff confirm receipt after reviewing your proof.
            </p>
        </div>

        <div v-if="!storeSeals.length" class="rounded-xl border border-dashed border-gray-200 bg-gray-50 px-6 py-12 text-center dark:border-gray-700 dark:bg-gray-900/50">
            <p class="text-sm font-bold text-gray-500 dark:text-gray-300">No stores are assigned to you yet.</p>
        </div>

        <div v-for="store in storeSeals" :key="store.store_id" class="rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="flex items-center gap-3 border-b border-gray-100 p-4 dark:border-gray-700">
                <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-slate-800 text-xs font-black text-white">
                    {{ store.store_code?.slice(0, 2) || 'ST' }}
                </div>
                <div class="min-w-0">
                    <div class="truncate text-sm font-bold text-gray-900 dark:text-gray-100">{{ store.store_name }}</div>
                    <div class="font-mono text-xs text-gray-500 dark:text-gray-300">{{ store.store_code }}</div>
                </div>
            </div>

            <div v-if="!store.years.length" class="p-6 text-center text-sm font-semibold text-gray-500 dark:text-gray-300">
                No NPC records available for this store yet.
            </div>

            <div v-for="yearRow in store.years" :key="yearRow.npc_status_id" class="border-b border-gray-100 p-4 last:border-b-0 dark:border-gray-700">
                <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                    <div>
                        <span class="text-base font-black text-gray-900 dark:text-gray-100">{{ yearRow.year }}</span>
                        <span class="ml-2 text-sm font-semibold text-gray-600 dark:text-gray-300">{{ yearRow.entity_name }}</span>
                    </div>
                    <span class="text-xs font-semibold text-gray-500 dark:text-gray-300">
                        Validity {{ formatDate(yearRow.validity_from) }} – {{ formatDate(yearRow.validity_to) }}
                    </span>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div v-for="seal in yearRow.seals" :key="seal.type" class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <div class="mb-2 text-xs font-black uppercase tracking-wider text-gray-600 dark:text-gray-300">{{ seal.label }}</div>

                        <template v-if="seal.available">
                            <div v-if="seal.name" class="mb-2 truncate text-[11px] font-bold text-blue-600" :title="seal.name">{{ seal.name }}</div>
                            <button
                                type="button"
                                :disabled="isDownloading(store, yearRow, seal)"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-blue-700"
                                :class="{ 'cursor-wait opacity-60': isDownloading(store, yearRow, seal) }"
                                @click="downloadSeal(store, yearRow, seal)"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                </svg>
                                {{ isDownloading(store, yearRow, seal) ? 'Downloading...' : 'Download' }}
                            </button>
                            <div class="mt-2 text-[11px] font-bold">
                                <span v-if="seal.confirmed_at" class="text-green-600">Checked ✓</span>
                                <span v-else-if="wasDownloaded(store, yearRow, seal)" class="text-amber-600">Downloaded — awaiting confirmation</span>
                                <span v-else class="text-gray-400">Not downloaded yet</span>
                            </div>

                            <!-- Proof of use for THIS seal -->
                            <div class="mt-3 border-t border-gray-100 pt-2 dark:border-gray-700">
                                <div class="text-[10px] font-black uppercase tracking-wider text-gray-500 dark:text-gray-400">Proof of Use</div>
                                <p class="mt-0.5 text-[10px] text-gray-400">Screenshot/photo showing this seal was posted/used.</p>
                                <div v-if="seal.proof" class="mt-1 text-[11px] font-bold text-green-600">✓ {{ seal.proof.name }} <span class="font-normal text-gray-500">({{ formatDateTime(seal.proof.uploaded_at) }})</span></div>
                                <div class="mt-1 flex flex-wrap items-center gap-2">
                                    <input
                                        :key="proofKey(store, yearRow, seal)"
                                        type="file"
                                        accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.bmp,.heic,.heif"
                                        :disabled="isUploadingProof(store, yearRow, seal)"
                                        class="text-[11px] text-gray-500 file:mr-2 file:rounded-full file:border-0 file:bg-blue-50 file:px-2 file:py-1 file:text-[11px] file:font-semibold file:text-blue-700 disabled:opacity-50 dark:text-gray-300"
                                        @change="uploadProof(seal, store, yearRow, $event)"
                                    >
                                    <span v-if="isUploadingProof(store, yearRow, seal)" class="text-[11px] font-bold text-blue-600">Uploading…</span>
                                </div>
                                <a v-if="seal.proof?.url" :href="seal.proof.url" class="mt-2 inline-block text-xs font-bold text-blue-600 hover:underline">Download uploaded proof</a>
                            </div>
                        </template>
                        <div v-else class="text-xs font-bold text-gray-400">Not released yet</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue'
import axios from 'axios'

defineProps({
    storeSeals: {
        type: Array,
        default: () => [],
    },
})

const emit = defineEmits(['downloaded', 'download-error', 'uploaded', 'upload-error'])
const downloadingKeys = ref([])
const locallyDownloadedKeys = ref([])
const uploadingProofKeys = ref([])

const sealKey = (store, yearRow, seal) => `${store.store_id}:${yearRow.npc_status_id}:${seal.type}`
const proofKey = (store, yearRow, seal) => `proof:${store.store_id}:${yearRow.npc_status_id}:${seal.type}`
const isUploadingProof = (store, yearRow, seal) => uploadingProofKeys.value.includes(proofKey(store, yearRow, seal))

const uploadProof = async (seal, store, yearRow, event) => {
    const file = event.target.files?.[0]
    if (!file) return
    const key = proofKey(store, yearRow, seal)
    if (uploadingProofKeys.value.includes(key)) return

    uploadingProofKeys.value = [...uploadingProofKeys.value, key]
    try {
        const formData = new FormData()
        formData.append('file', file)
        await axios.post(seal.proof_upload_url, formData, { headers: { Accept: 'application/json' } })
        emit('uploaded')
    } catch (error) {
        emit('upload-error', error)
    } finally {
        uploadingProofKeys.value = uploadingProofKeys.value.filter((item) => item !== key)
        event.target.value = ''
    }
}

const isDownloading = (store, yearRow, seal) => {
    return downloadingKeys.value.includes(sealKey(store, yearRow, seal))
}

const wasDownloaded = (store, yearRow, seal) => {
    return Boolean(seal.downloaded_at) || locallyDownloadedKeys.value.includes(sealKey(store, yearRow, seal))
}

const downloadSeal = async (store, yearRow, seal) => {
    const key = sealKey(store, yearRow, seal)
    if (downloadingKeys.value.includes(key)) return

    downloadingKeys.value = [...downloadingKeys.value, key]

    try {
        const { data } = await axios.get(seal.download_url, {
            headers: { Accept: 'application/json' },
        })
        const link = document.createElement('a')
        link.href = data.download_url
        link.download = seal.name || `${seal.type}-${yearRow.year}`
        document.body.appendChild(link)
        link.click()
        link.remove()

        if (!locallyDownloadedKeys.value.includes(key)) {
            locallyDownloadedKeys.value = [...locallyDownloadedKeys.value, key]
        }

        emit('downloaded')
    } catch (error) {
        emit('download-error', error)
    } finally {
        downloadingKeys.value = downloadingKeys.value.filter((item) => item !== key)
    }
}

const formatDate = (value) => {
    if (!value) return 'Not set'
    return new Date(`${value}T00:00:00`).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: '2-digit',
    })
}

const formatDateTime = (value) => {
    if (!value) return ''
    const date = new Date(value)
    if (Number.isNaN(date.getTime())) return String(value)
    return date.toLocaleString('en-US', { year: 'numeric', month: 'short', day: '2-digit', hour: 'numeric', minute: '2-digit' })
}
</script>
