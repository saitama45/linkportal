import { usePage } from '@inertiajs/vue3';

export function usePermission() {
    const hasPermission = (name) => {
        const permissions = usePage().props.auth.permissions || [];
        return permissions.includes(name);
    };

    const hasAnyPermission = (names) => {
        return names.some(name => hasPermission(name));
    };

    return { hasPermission, hasAnyPermission };
}
