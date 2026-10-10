<script setup lang="ts">
import BaseIcon from './BaseIcon.vue';
import type { IconName } from './BaseIcon.vue';

/** A centred empty, missing or idle state: what happened and what to do next. */
withDefaults(defineProps<{ icon?: IconName; title: string; tone?: 'neutral' | 'danger' }>(), { icon: 'inbox', tone: 'neutral' });
</script>

<template>
    <div class="flex flex-col items-center justify-center px-6 py-10 text-center">
        <span
            class="mb-3 grid size-9 place-items-center rounded-lg border"
            :class="tone === 'danger' ? 'border-danger-line bg-danger-bg text-danger-fg' : 'border-line bg-panel text-fg-subtle'"
        >
            <BaseIcon :name="icon" :size="18" />
        </span>
        <p class="text-base font-medium text-fg">{{ title }}</p>
        <div class="mt-1 max-w-sm text-sm text-fg-muted"><slot /></div>
        <div v-if="$slots.actions" class="mt-4 flex items-center gap-2"><slot name="actions" /></div>
    </div>
</template>
