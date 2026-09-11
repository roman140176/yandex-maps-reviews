import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
    history: createWebHistory(),
    routes: [
        {
            path: '/login',
            name: 'login',
            component: () => import('@/pages/LoginPage.vue'),
            meta: { guest: true },
        },
        {
            path: '/',
            name: 'settings',
            component: () => import('@/pages/SettingsPage.vue'),
        },
        {
            path: '/organizations/:id(\\d+)',
            name: 'organization',
            component: () => import('@/pages/OrganizationPage.vue'),
        },
        {
            path: '/:pathMatch(.*)*',
            name: 'not-found',
            component: () => import('@/pages/NotFoundPage.vue'),
        },
    ],
})

router.beforeEach(async (to) => {
    const auth = useAuthStore()

    // One /api/auth/me on first navigation tells us whether the session cookie
    // from a previous visit is still good.
    await auth.check()

    if (!auth.user && !to.meta.guest) {
        return { name: 'login' }
    }

    if (auth.user && to.meta.guest) {
        return { name: 'settings' }
    }

    return true
})

export default router
