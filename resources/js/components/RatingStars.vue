<script setup lang="ts">
defineProps<{ rating: number }>()
</script>

<template>
    <span class="inline-flex items-center gap-0.5" :aria-label="`Оценка ${rating} из 5`">
        <svg
            v-for="star in 5"
            :key="star"
            class="size-4"
            :class="star <= rating ? 'text-amber-400' : 'text-slate-200'"
            viewBox="0 0 20 20"
            fill="currentColor"
            aria-hidden="true"
        >
            <path d="M10 1.5l2.6 5.3 5.9.9-4.2 4.1 1 5.8-5.3-2.8-5.3 2.8 1-5.8L1.5 7.7l5.9-.9L10 1.5z" />
        </svg>
    </span>
</template>
