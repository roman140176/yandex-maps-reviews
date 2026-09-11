<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useOrganizationsStore } from '@/stores/organizations'
import { ApiError } from '@/api/client'
import { formatDateTime, formatNumber, formatRating } from '@/composables/useFormat'
import AppAlert from '@/components/AppAlert.vue'
import StatusBadge from '@/components/StatusBadge.vue'

const organizations = useOrganizationsStore()
const router = useRouter()

const url = ref('')
const submitting = ref(false)
const error = ref<string | null>(null)
const notice = ref<string | null>(null)

onMounted(() => organizations.fetchAll())

async function submit(): Promise<void> {
    error.value = null
    notice.value = null
    submitting.value = true

    try {
        const organization = await organizations.connect(url.value)
        url.value = ''
        notice.value = 'Карточка подключена, отзывы собираются в фоне.'
        await router.push({ name: 'organization', params: { id: organization.id } })
    } catch (e) {
        error.value = e instanceof ApiError
            ? (e.firstError('url') ?? e.message)
            : 'Не удалось подключить карточку.'
    } finally {
        submitting.value = false
    }
}

async function remove(id: number, name: string | null): Promise<void> {
    if (!window.confirm(`Отключить «${name ?? 'карточку'}»? Собранные отзывы будут удалены.`)) {
        return
    }

    await organizations.remove(id)
}
</script>

<template>
    <div class="space-y-8">
        <section class="rounded-xl border border-slate-200 bg-white p-6">
            <h2 class="text-lg font-semibold text-slate-900">Подключить карточку организации</h2>
            <p class="mt-1 text-sm text-slate-500">
                Вставьте ссылку на компанию в Яндекс.Картах — например,
                <code class="rounded bg-slate-100 px-1 py-0.5 text-xs">https://yandex.ru/maps/org/yandeks/1124715036/</code>
            </p>

            <form class="mt-4 flex flex-col gap-3 sm:flex-row" @submit.prevent="submit">
                <input
                    v-model="url"
                    type="text"
                    inputmode="url"
                    placeholder="https://yandex.ru/maps/org/…"
                    required
                    class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900"
                >
                <button
                    type="submit"
                    :disabled="submitting"
                    class="rounded-lg bg-slate-900 px-5 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:opacity-60"
                >
                    {{ submitting ? 'Проверяем…' : 'Подключить' }}
                </button>
            </form>

            <AppAlert v-if="error" variant="error" class="mt-4">{{ error }}</AppAlert>
            <AppAlert v-else-if="notice" variant="success" class="mt-4">{{ notice }}</AppAlert>
        </section>

        <section>
            <h2 class="text-lg font-semibold text-slate-900">Подключённые карточки</h2>

            <p v-if="organizations.loading" class="mt-4 text-sm text-slate-500">Загружаем…</p>

            <AppAlert v-else-if="organizations.error" variant="error" class="mt-4">
                {{ organizations.error }}
            </AppAlert>

            <p v-else-if="!organizations.hasAny" class="mt-4 rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500">
                Пока ничего не подключено. Вставьте ссылку выше — отзывы соберутся автоматически.
            </p>

            <ul v-else class="mt-4 space-y-3">
                <li
                    v-for="organization in organizations.items"
                    :key="organization.id"
                    class="rounded-xl border border-slate-200 bg-white p-5"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <RouterLink
                                :to="{ name: 'organization', params: { id: organization.id } }"
                                class="font-medium text-slate-900 hover:underline"
                            >
                                {{ organization.name ?? 'Карточка ' + organization.external_id }}
                            </RouterLink>
                            <p v-if="organization.address" class="mt-0.5 truncate text-sm text-slate-500">
                                {{ organization.address }}
                            </p>
                        </div>

                        <div class="flex items-center gap-3">
                            <StatusBadge :status="organization.parse_status" :label="organization.parse_status_label" />
                            <button
                                type="button"
                                class="text-sm text-slate-500 transition hover:text-rose-600"
                                @click="remove(organization.id, organization.name)"
                            >
                                Отключить
                            </button>
                        </div>
                    </div>

                    <dl class="mt-4 grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                        <div>
                            <dt class="text-xs text-slate-500">Рейтинг</dt>
                            <dd class="font-medium text-slate-900">{{ formatRating(organization.rating.value) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">Оценок</dt>
                            <dd class="font-medium text-slate-900">{{ formatNumber(organization.rating.ratings_count) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">Отзывов</dt>
                            <dd class="font-medium text-slate-900">{{ formatNumber(organization.rating.reviews_count) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">Обновлено</dt>
                            <dd class="font-medium text-slate-900">{{ formatDateTime(organization.last_parsed_at) }}</dd>
                        </div>
                    </dl>
                </li>
            </ul>
        </section>
    </div>
</template>
