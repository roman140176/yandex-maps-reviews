import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api, ApiError } from '@/api/client'
import type { User } from '@/types'

export const useAuthStore = defineStore('auth', () => {
    const user = ref<User | null>(null)
    const loading = ref(false)
    const checked = ref(false)

    /** Called by the router guard before the first protected navigation. */
    async function check(): Promise<void> {
        if (checked.value) {
            return
        }

        try {
            const { user: current } = await api.get<{ user: User }>('/api/auth/me')
            user.value = current
        } catch {
            user.value = null
        } finally {
            checked.value = true
        }
    }

    async function login(email: string, password: string): Promise<void> {
        loading.value = true

        try {
            const { user: current } = await api.post<{ user: User }>('/api/auth/login', { email, password })
            user.value = current
            checked.value = true
        } finally {
            loading.value = false
        }
    }

    async function logout(): Promise<void> {
        try {
            await api.post('/api/auth/logout')
        } catch (error) {
            // A session that is already gone is not a failure to log out.
            if (!(error instanceof ApiError) || error.status !== 401) {
                throw error
            }
        } finally {
            user.value = null
        }
    }

    return { user, loading, checked, check, login, logout }
})
