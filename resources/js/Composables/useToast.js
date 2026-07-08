import { ref, reactive } from 'vue';

const toasts = ref([]);
let toastId = 0;

export function useToast() {
    const addToast = (message, type = 'success', duration = null) => {
        // Check for duplicate messages
        const existingToast = toasts.value.find(toast => toast.message === message && toast.type === type);
        if (existingToast) {
            return existingToast.id;
        }
        
        const id = ++toastId;
        // Set default duration based on type
        if (duration === null) {
            duration = type === 'error' ? 30000 : 4000; // 30 seconds for errors, 4 seconds for others
        }
        
        const toast = {
            id,
            message,
            type,
            duration,
            show: true
        };
        
        toasts.value.push(toast);
        
        if (duration > 0) {
            setTimeout(() => {
                removeToast(id);
            }, duration);
        }
        
        return id;
    };
    
    const removeToast = (id) => {
        const index = toasts.value.findIndex(toast => toast.id === id);
        if (index > -1) {
            toasts.value.splice(index, 1);
        }
    };
    
    const success = (message, duration) => addToast(message, 'success', duration);
    const error = (message, duration) => addToast(message, 'error', duration);
    const warning = (message, duration) => addToast(message, 'warning', duration);
    const info = (message, duration) => addToast(message, 'info', duration);
    
    return {
        toasts,
        addToast,
        removeToast,
        success,
        error,
        warning,
        info,
        showSuccess: success,
        showError: error,
        showWarning: warning,
        showInfo: info
    };
}