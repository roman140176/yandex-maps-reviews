<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useOrganizationsStore } from '@/stores/organizations'
import { useParseProgress } from '@/composables/useParseProgress'
import { formatDateTime, formatNumber, formatRating, plural } from '@/composables/useFormat'
import type { Organization, Paginated, Review, ReviewRevision } from '@/types'
import AppAlert from '@/components/AppAlert.vue'
import AppPagination from '@/components/AppPagination.vue'
import ParseProgress from '@/components/ParseProgress.vue'
import ReviewCard from '@/components/ReviewCard.vue'
import StatusBadge from '@/components/StatusBadge.vue'

const route = useRoute()
const store = useOrganizationsStore()

const id = computed(() => Number(route.params.id))

const organization = ref<Organization | null>(null)
const reviews = ref<Paginated<Review> | null>(null)
const changes = ref<Paginated<ReviewRevision> | null>(null)

const loadingOrganization = ref(true)
const loadingReviews = ref(false)
const refreshing = ref(false)
const error = ref<string | null>(null)
const tab = ref<'reviews' | 'changes'>('reviews')
const sort = ref('published_desc')
const page = ref(1)

const latestRun = computed(() => organization.value?.latest_run ?? null)

/** While a parse runs the card is polled, so the progress bar actually moves. */
const { start: watchProgress } = useParseProgress(async () => {
    const fresh = await store.fetchOne(id.value)
    organization.value = fresh

    // Reviews only appear once the run is done — reload them then, once.
    if (!fresh.is_parsing) {
        await loadReviews(1)
    }

    return fresh
})

onMounted(load)
watch(id, load)

async function load(): Promise<void> {
    loadingOrganization.value = true
    error.value = null

    try {
        organization.value = await store.fetchOne(id.value)
        await loadReviews(1)
        watchProgress(organization.value)
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Не удалось загрузить карточку.'
    } finally {
        loadingOrganization.value = false
    }
}

async function loadReviews(nextPage: number): Promise<void> {
    loadingReviews.value = true
    page.value = nextPage

    try {
        reviews.value = await store.fetchReviews(id.value, nextPage, sort.value)
    } finally {
        loadingReviews.value = false
    }
}

async function loadChanges(): Promise<void> {
    changes.value = await store.fetchChanges(id.value)
}

async function refresh(): Promise<void> {
    refreshing.value = true

    try {
        organization.value = await store.refresh(id.value)
        watchProgress(organization.value)
    } finally {
        refreshing.value = false
    }
}

function switchTab(next: 'reviews' | 'changes'): void {
    tab.value = next

    if (next === 'changes' && changes.value === null) {
        loadChanges()
    }
}

watch(sort, () => loadReviews(1))

function changedFields(revision: ReviewRevision): string[] {
    const titles: Record<string, string> = {
        text: 'текст отзыва',
        rating: 'оценка',
        author_name: 'имя автора',
        business_comment_text: 'ответ организации',
        business_comment_at: 'дата ответа',
        published_at: 'дата публикации',
    }

    return Object.keys(revision.changes).map((field) => titles[field] ?? field)
}
</script>

<template>
    <div v-if="loadingOrganization" class="py-16 text-center text-sm text-slate-500">Загружаем карточку…</div>

    <AppAlert v-else-if="error" variant="error">{{ error }}</AppAlert>

    <div v-else-if="organization" class="space-y-6">
        <header class="rounded-xl border border-slate-200 bg-white p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <h1 class="text-xl font-semibold text-slate-900">
                        {{ organization.name ?? 'Карточка ' + organization.external_id }}
                    </h1>
                    <p v-if="organization.address" class="mt-1 text-sm text-slate-500">{{ organization.address }}</p>
                    <a
                        :href="organization.url"
                        target="_blank"
                        rel="noopener"
                        class="mt-2 inline-block text-sm text-sky-700 hover:underline"
                    >Открыть на Яндекс.Картах ↗</a>
                </div>

                <div class="flex items-center gap-3">
                    <StatusBadge :status="organization.parse_status" :label="organization.parse_status_label" />
                    <button
                        type="button"
                        :disabled="refreshing || organization.is_parsing"
                        class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:opacity-50"
                        @click="refresh"
                    >
                        Обновить
                    </button>
                </div>
            </div>

            <dl class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div class="rounded-lg bg-slate-50 px-4 py-3">
                    <dt class="text-xs text-slate-500">Средний рейтинг</dt>
                    <dd class="mt-0.5 text-2xl font-semibold text-slate-900">{{ formatRating(organization.rating.value) }}</dd>
                </div>
                <div class="rounded-lg bg-slate-50 px-4 py-3">
                    <dt class="text-xs text-slate-500">Оценок</dt>
                    <dd class="mt-0.5 text-2xl font-semibold text-slate-900">{{ formatNumber(organization.rating.ratings_count) }}</dd>
                </div>
                <div class="rounded-lg bg-slate-50 px-4 py-3">
                    <dt class="text-xs text-slate-500">Отзывов</dt>
                    <dd class="mt-0.5 text-2xl font-semibold text-slate-900">{{ formatNumber(organization.rating.reviews_count) }}</dd>
                </div>
                <div class="rounded-lg bg-slate-50 px-4 py-3">
                    <dt class="text-xs text-slate-500">Собрано у нас</dt>
                    <dd class="mt-0.5 text-2xl font-semibold text-slate-900">{{ formatNumber(organization.reviews_stored) }}</dd>
                </div>
            </dl>

            <p class="mt-3 text-xs text-slate-500">
                Обновлено: {{ formatDateTime(organization.last_parsed_at) }}
            </p>
        </header>

        <ParseProgress v-if="latestRun && !latestRun.is_finished" :run="latestRun" />

        <AppAlert v-if="latestRun?.error" variant="error" title="Сбор отзывов не завершился">
            {{ latestRun.error.message }}
            <span class="mt-1 block font-mono text-xs opacity-70">код: {{ latestRun.error.code }}</span>
        </AppAlert>

        <AppAlert v-if="latestRun?.warnings?.length" variant="warning" title="Замечания последнего сбора">
            <ul class="list-inside list-disc space-y-1">
                <li v-for="(warning, index) in latestRun.warnings" :key="index">{{ warning }}</li>
            </ul>
        </AppAlert>

        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200">
            <nav class="flex gap-1" aria-label="Разделы карточки">
                <button
                    v-for="item in [
                        { key: 'reviews' as const, label: 'Отзывы' },
                        { key: 'changes' as const, label: 'История изменений' },
                    ]"
                    :key="item.key"
                    type="button"
                    class="border-b-2 px-4 py-2.5 text-sm font-medium transition"
                    :class="tab === item.key
                        ? 'border-slate-900 text-slate-900'
                        : 'border-transparent text-slate-500 hover:text-slate-700'"
                    @click="switchTab(item.key)"
                >
                    {{ item.label }}
                </button>
            </nav>

            <select
                v-if="tab === 'reviews'"
                v-model="sort"
                class="mb-2 rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
                aria-label="Сортировка отзывов"
            >
                <option value="published_desc">Сначала новые</option>
                <option value="rating_asc">Сначала низкие оценки</option>
            </select>
        </div>

        <section v-if="tab === 'reviews'">
            <p v-if="loadingReviews" class="py-10 text-center text-sm text-slate-500">Загружаем отзывы…</p>

            <template v-else-if="reviews && reviews.data.length">
                <p class="mb-4 text-sm text-slate-500">
                    Показаны {{ reviews.meta.from }}–{{ reviews.meta.to }} из {{ formatNumber(reviews.meta.total) }}
                    {{ plural(reviews.meta.total, ['отзыва', 'отзывов', 'отзывов']) }}
                </p>

                <div class="space-y-3">
                    <ReviewCard v-for="review in reviews.data" :key="review.id" :review="review" />
                </div>

                <div class="mt-6">
                    <AppPagination
                        :current="reviews.meta.current_page"
                        :last="reviews.meta.last_page"
                        @change="loadReviews"
                    />
                </div>
            </template>

            <p v-else class="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-500">
                {{ organization.is_parsing ? 'Отзывы собираются — подождите немного.' : 'Отзывов пока нет.' }}
            </p>
        </section>

        <section v-else>
            <template v-if="changes && changes.data.length">
                <ul class="space-y-3">
                    <li
                        v-for="revision in changes.data"
                        :key="revision.id"
                        class="rounded-xl border border-slate-200 bg-white p-5"
                    >
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <p class="font-medium text-slate-900">{{ revision.review.author_name ?? 'Отзыв' }}</p>
                            <p class="text-xs text-slate-500">{{ formatDateTime(revision.recorded_at) }}</p>
                        </div>

                        <p class="mt-1 text-sm text-slate-500">Изменилось: {{ changedFields(revision).join(', ') }}</p>

                        <dl class="mt-3 space-y-3">
                            <div v-for="(change, field) in revision.changes" :key="field" class="text-sm">
                                <dt class="text-xs uppercase tracking-wide text-slate-400">{{ field }}</dt>
                                <dd class="mt-1 grid gap-2 sm:grid-cols-2">
                                    <div class="rounded-lg bg-rose-50 px-3 py-2 text-rose-900">
                                        <span class="block text-xs opacity-70">было</span>
                                        {{ change.old ?? '—' }}
                                    </div>
                                    <div class="rounded-lg bg-emerald-50 px-3 py-2 text-emerald-900">
                                        <span class="block text-xs opacity-70">стало</span>
                                        {{ change.new ?? '—' }}
                                    </div>
                                </dd>
                            </div>
                        </dl>
                    </li>
                </ul>
            </template>

            <p v-else class="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-500">
                Изменений пока не зафиксировано. Они появятся, когда отзыв отредактируют,
                изменят оценку или организация ответит на отзыв.
            </p>
        </section>
    </div>
</template>
