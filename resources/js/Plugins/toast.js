import { useToast } from '@/Composables/useToast.js';

export default {
    install(app) {
        const toast = useToast();
        
        app.config.globalProperties.$toast = toast;
        app.provide('toast', toast);
    }
};