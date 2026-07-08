import { ref } from 'vue'

const showConfirmModal = ref(false)
const confirmTitle = ref('')
const confirmMessage = ref('')
const confirmButtonText = ref('Confirm')
const cancelButtonText = ref('Cancel')
const confirmType = ref('danger')
let confirmCallback = null
let cancelCallback = null

export function useConfirm() {
    const confirm = (options = {}) => {
        return new Promise((resolve, reject) => {
            confirmTitle.value = options.title || 'Confirm Action'
            confirmMessage.value = options.message || 'Are you sure you want to proceed?'
            confirmButtonText.value = options.confirmButtonText || 'Confirm'
            cancelButtonText.value = options.cancelButtonText || 'Cancel'
            confirmType.value = options.type || 'danger'
            
            confirmCallback = () => {
                showConfirmModal.value = false
                resolve(true)
            }
            
            cancelCallback = () => {
                showConfirmModal.value = false
                resolve(false)
            }
            
            showConfirmModal.value = true
        })
    }

    const handleConfirm = () => {
        if (confirmCallback) confirmCallback()
    }

    const handleCancel = () => {
        if (cancelCallback) cancelCallback()
    }

    return {
        showConfirmModal,
        confirmTitle,
        confirmMessage,
        confirmButtonText,
        cancelButtonText,
        confirmType,
        confirm,
        handleConfirm,
        handleCancel
    }
}