<script setup lang="ts">
import BaseIcon from './BaseIcon.vue';
import type { IconName } from './BaseIcon.vue';

/** A small inline label. The tone adds meaning; the words always carry it. */
export type Tone = 'neutral' | 'accent' | 'success' | 'warning' | 'danger' | 'orange' | 'violet' | 'teal';

withDefaults(defineProps<{ tone?: Tone; icon?: IconName }>(), { tone: 'neutral', icon: undefined });

const TONES: Record<Tone, string> = {
    neutral: '',
    accent: 'border-info-line bg-info-bg text-info-fg',
    success: 'border-success-line bg-success-bg text-success-fg',
    warning: 'border-warning-line bg-warning-bg text-warning-fg',
    danger: 'border-danger-line bg-danger-bg text-danger-fg',
    orange: 'border-orange-line bg-orange-bg text-orange-fg',
    violet: 'border-violet-line bg-violet-bg text-violet-fg',
    teal: 'border-teal-line bg-teal-bg text-teal-fg',
};
</script>

<template>
    <span class="dbx-badge" :class="TONES[tone]"><BaseIcon v-if="icon" :name="icon" :size="12" /><slot /></span>
</template>
