<script setup lang="ts">
import type { ParseRun } from '@/types'
import { plural } from '@/composables/useFormat'

const props = defineProps<{ run: ParseRun }>()
</script>

<template>
    <div class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3">
        <div class="flex items-center justify-between text-sm text-sky-900">
            <span class="font-medium">
                {{ run.status === 'queued' ? 'Ожидает очереди' : 'Собираем отзывы' }}
            </span>
            <span class="tabular-nums">
                страница {{ run.progress.pages_done }} из {{ run.progress.pages_expected || '?' }}
            </span>
        </div>

        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-sky-200" role="progressbar" :aria-valuenow="run.progress.percent">
            <div
                class="h-full rounded-full bg-sky-600 transition-[width] duration-500"
                :style="{ width: `${Math.max(run.progress.percent, 4)}%` }"
            />
        </div>

        <p class="mt-2 text-xs text-sky-800">
            Собрано {{ run.progress.reviews_seen }}
            {{ plural(run.progress.reviews_seen, ['отзыв', 'отзыва', 'отзывов']) }}
        </p>
    </div>
</template>
