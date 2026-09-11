<script setup lang="ts">
import { computed } from 'vue'
import type { ParseStatus } from '@/types'

const props = defineProps<{ status: ParseStatus | null; label?: string | null }>()

const styles = computed<string>(() => {
    switch (props.status) {
        case 'success':
            return 'bg-emerald-50 text-emerald-700 ring-emerald-600/20'
        case 'partial':
            return 'bg-amber-50 text-amber-700 ring-amber-600/20'
        case 'running':
        case 'queued':
            return 'bg-sky-50 text-sky-700 ring-sky-600/20'
        case 'blocked':
            return 'bg-orange-50 text-orange-700 ring-orange-600/20'
        case 'failed':
            return 'bg-rose-50 text-rose-700 ring-rose-600/20'
        default:
            return 'bg-slate-100 text-slate-600 ring-slate-500/20'
    }
})
</script>

<template>
    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset" :class="styles">
        <span
            v-if="status === 'running' || status === 'queued'"
            class="size-1.5 animate-pulse rounded-full bg-current"
        />
        {{ label ?? 'Не обновлялась' }}
    </span>
</template>
