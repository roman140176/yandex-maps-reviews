<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { ApiError } from '@/api/client'
import AppAlert from '@/components/AppAlert.vue'

const auth = useAuthStore()
const router = useRouter()

const email = ref('')
const password = ref('')
const error = ref<string | null>(null)

async function submit(): Promise<void> {
    error.value = null

    try {
        await auth.login(email.value, password.value)
        await router.push({ name: 'settings' })
    } catch (e) {
        error.value = e instanceof ApiError
            ? (e.firstError('email') ?? e.message)
            : 'Не удалось войти. Попробуйте ещё раз.'
    }
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center px-4">
        <div class="w-full max-w-sm">
            <h1 class="text-center text-2xl font-semibold text-slate-900">Отзывы с Яндекс.Карт</h1>
            <p class="mt-2 text-center text-sm text-slate-500">Войдите, чтобы подключить карточку организации</p>

            <form class="mt-8 space-y-4" @submit.prevent="submit">
                <div>
                    <label for="email" class="block text-sm font-medium text-slate-700">E-mail</label>
                    <input
                        id="email"
                        v-model="email"
                        type="email"
                        autocomplete="username"
                        required
                        class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900"
                    >
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-slate-700">Пароль</label>
                    <input
                        id="password"
                        v-model="password"
                        type="password"
                        autocomplete="current-password"
                        required
                        class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900"
                    >
                </div>

                <AppAlert v-if="error" variant="error">{{ error }}</AppAlert>

                <button
                    type="submit"
                    :disabled="auth.loading"
                    class="w-full rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-slate-800 disabled:opacity-60"
                >
                    {{ auth.loading ? 'Входим…' : 'Войти' }}
                </button>
            </form>
        </div>
    </div>
</template>
