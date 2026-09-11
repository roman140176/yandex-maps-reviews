<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{ current: number; last: number }>()
const emit = defineEmits<{ change: [page: number] }>()

/** A compact window around the current page, with the ends always reachable. */
const pages = computed<(number | '…')[]>(() => {
    if (props.last <= 7) {
        return Array.from({ length: props.last }, (_, i) => i + 1)
    }

    const result: (number | '…')[] = [1]
    const from = Math.max(2, props.current - 1)
    const to = Math.min(props.last - 1, props.current + 1)

    if (from > 2) result.push('…')
    for (let page = from; page <= to; page++) result.push(page)
    if (to < props.last - 1) result.push('…')

    result.push(props.last)

    return result
})
</script>

<template>
    <nav v-if="last > 1" class="flex flex-wrap items-center justify-center gap-1" aria-label="Постраничная навигация">
        <button
            type="button"
            class="rounded-md px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-100 disabled:opacity-40 disabled:hover:bg-transparent"
            :disabled="current === 1"
            @click="emit('change', current - 1)"
        >
            Назад
        </button>

        <template v-for="(page, index) in pages" :key="`${page}-${index}`">
            <span v-if="page === '…'" class="px-2 text-sm text-slate-400">…</span>
            <button
                v-else
                type="button"
                class="min-w-9 rounded-md px-3 py-1.5 text-sm"
                :class="page === current
                    ? 'bg-slate-900 font-medium text-white'
                    : 'text-slate-600 hover:bg-slate-100'"
                :aria-current="page === current ? 'page' : undefined"
                @click="emit('change', page as number)"
            >
                {{ page }}
            </button>
        </template>

        <button
            type="button"
            class="rounded-md px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-100 disabled:opacity-40 disabled:hover:bg-transparent"
            :disabled="current === last"
            @click="emit('change', current + 1)"
        >
            Вперёд
        </button>
    </nav>
</template>
