<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(
    defineProps<{ variant?: 'error' | 'warning' | 'info' | 'success'; title?: string }>(),
    { variant: 'info' },
)

const styles = computed(() => ({
    error: 'border-rose-200 bg-rose-50 text-rose-800',
    warning: 'border-amber-200 bg-amber-50 text-amber-900',
    info: 'border-sky-200 bg-sky-50 text-sky-900',
    success: 'border-emerald-200 bg-emerald-50 text-emerald-900',
}[props.variant]))
</script>

<template>
    <div class="rounded-lg border px-4 py-3 text-sm" :class="styles" role="alert">
        <p v-if="title" class="mb-1 font-semibold">{{ title }}</p>
        <slot />
    </div>
</template>
