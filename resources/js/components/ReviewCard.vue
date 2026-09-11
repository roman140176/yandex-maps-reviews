<script setup lang="ts">
import RatingStars from '@/components/RatingStars.vue'
import { formatDate } from '@/composables/useFormat'
import type { Review } from '@/types'

defineProps<{ review: Review }>()
</script>

<template>
    <article class="rounded-xl border border-slate-200 bg-white p-5">
        <header class="flex items-start gap-3">
            <img
                v-if="review.author.avatar_url"
                :src="review.author.avatar_url"
                :alt="review.author.name"
                class="size-10 shrink-0 rounded-full bg-slate-100 object-cover"
                loading="lazy"
            >
            <div
                v-else
                class="flex size-10 shrink-0 items-center justify-center rounded-full bg-slate-100 text-sm font-medium text-slate-500"
                aria-hidden="true"
            >
                {{ review.author.name.charAt(0) }}
            </div>

            <div class="min-w-0 flex-1">
                <p class="truncate font-medium text-slate-900">{{ review.author.name }}</p>
                <p v-if="review.author.level" class="truncate text-xs text-slate-500">{{ review.author.level }}</p>
            </div>

            <div class="text-right">
                <RatingStars v-if="review.rating !== null" :rating="review.rating" />
                <span v-else class="text-xs text-slate-400">без оценки</span>
                <p class="mt-1 text-xs text-slate-500">{{ formatDate(review.published_at) }}</p>
            </div>
        </header>

        <p v-if="review.text" class="mt-3 whitespace-pre-line text-sm leading-relaxed text-slate-700">
            {{ review.text }}
        </p>
        <p v-else class="mt-3 text-sm italic text-slate-400">Оценка без текста</p>

        <!-- The organisation's own reply: the product this models is about
             answering reviews, so showing the answer matters. -->
        <div v-if="review.business_comment" class="mt-4 rounded-lg border-l-2 border-slate-300 bg-slate-50 px-4 py-3">
            <p class="text-xs font-medium text-slate-600">Ответ организации</p>
            <p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ review.business_comment.text }}</p>
        </div>

        <footer v-if="review.photos_count || review.likes_count || review.revisions_count" class="mt-3 flex gap-4 text-xs text-slate-500">
            <span v-if="review.photos_count">Фото: {{ review.photos_count }}</span>
            <span v-if="review.likes_count">Лайков: {{ review.likes_count }}</span>
            <span v-if="review.revisions_count" class="text-amber-700">Изменялся: {{ review.revisions_count }}</span>
        </footer>
    </article>
</template>
