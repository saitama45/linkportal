import { router } from '@inertiajs/vue3'

export function useErrorHandler() {
    return {
        post: (url, data = {}, options = {}) => {
            return router.post(url, data, options)
        },
        put: (url, data = {}, options = {}) => {
            return router.put(url, data, options)
        },
        patch: (url, data = {}, options = {}) => {
            return router.patch(url, data, options)
        },
        destroy: (url, options = {}) => {
            return router.delete(url, options)
        }
    }
}