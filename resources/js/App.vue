<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

async function logout(): Promise<void> {
    await auth.logout()
    await router.push({ name: 'login' })
}
</script>

<template>
    <!-- The login screen brings its own full-page layout. -->
    <RouterView v-if="!auth.user" />

    <div v-else class="min-h-screen">
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-5xl items-center justify-between px-4 py-4">
                <RouterLink :to="{ name: 'settings' }" class="font-semibold text-slate-900">
                    Отзывы с Яндекс.Карт
                </RouterLink>

                <div class="flex items-center gap-4 text-sm">
                    <span class="hidden text-slate-500 sm:inline">{{ auth.user.email }}</span>
                    <button type="button" class="text-slate-600 transition hover:text-slate-900" @click="logout">
                        Выйти
                    </button>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-5xl px-4 py-8">
            <RouterView />
        </main>
    </div>
</template>
