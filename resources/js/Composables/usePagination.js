import { ref, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'

export function usePagination(initialData = {}, routeName = '', extraParams = {}) {
    const search = ref('')
    const perPage = ref(10)
    const currentPage = ref(1)
    const data = ref(initialData.data || [])
    const total = ref(initialData.total || 0)
    const from = ref(initialData.from || 0)
    const to = ref(initialData.to || 0)
    const lastPage = ref(initialData.last_page || 1)
    const isLoading = ref(false)

    const showingText = computed(() => {
        if (total.value === 0) return 'No records found'
        return `Showing ${from.value} to ${to.value} of ${total.value} records`
    })

    const updateData = (newData) => {
        if (!newData) return

        // HANDLE BOTH LARAVEL PAGINATION OBJECT AND DIRECT DATA ARRAY
        const paginated = newData.data ? newData : { data: newData }
        
        data.value = paginated.data || []
        total.value = paginated.total || data.value.length
        from.value = paginated.from || (data.value.length > 0 ? 1 : 0)
        to.value = paginated.to || data.value.length
        currentPage.value = paginated.current_page || 1
        lastPage.value = paginated.last_page || 1
    }

    const performSearch = (url = null, additionalParams = {}) => {
        const searchUrl = url || route(routeName)
        const globalParams = typeof extraParams === 'function' ? extraParams() : extraParams
        const params = {
            search: search.value,
            per_page: perPage.value,
            page: currentPage.value,
            ...globalParams,
            ...additionalParams
        }

        isLoading.value = true
        router.get(searchUrl, params, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: (page) => {
                // TRY TO FIND DATA IN PROPS BASED ON ROUTE NAME OR 'data'
                const propKey = routeName.split('.')[0]
                const responseData = page.props[propKey] || page.props.data
                
                if (responseData) {
                    updateData(responseData)
                }
            },
            onFinish: () => {
                isLoading.value = false
            }
        })
    }

    const goToPage = (page, url = null, additionalParams = {}) => {
        if (page >= 1 && page <= lastPage.value) {
            currentPage.value = page
            performSearch(url, additionalParams)
        }
    }

    const changePerPage = (newPerPage, url = null, additionalParams = {}) => {
        perPage.value = newPerPage
        currentPage.value = 1
        performSearch(url, additionalParams)
    }

    // Auto-search with debounce
    let searchTimeout
    watch(() => search.value, (newSearch) => {
        clearTimeout(searchTimeout)
        searchTimeout = setTimeout(() => {
            currentPage.value = 1
            performSearch()
        }, 300)
    })

    return {
        search,
        perPage,
        currentPage,
        data,
        total,
        from,
        to,
        lastPage,
        isLoading,
        showingText,
        updateData,
        performSearch,
        goToPage,
        changePerPage
    }
}