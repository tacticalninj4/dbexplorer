<script setup lang="ts">
import type { Classification } from '../types';
import BaseBadge from './BaseBadge.vue';
import type { Tone } from './BaseBadge.vue';

/** What the server's classifier decided a statement does. */
defineProps<{ value: Classification }>();

const TONES: Record<Classification, Tone> = {
    read: 'success',
    dml: 'warning',
    ddl: 'orange',
    destructive: 'danger',
    unknown: 'neutral',
};
const TITLES: Record<Classification, string> = {
    read: 'Reads data only',
    dml: 'Changes rows (DML)',
    ddl: 'Changes the schema (DDL)',
    destructive: 'Destructive: drops, truncates, or changes every row',
    unknown: 'Could not be classified',
};
</script>

<template>
    <BaseBadge :tone="TONES[value]" :icon="value === 'destructive' ? 'alert' : undefined" :title="TITLES[value]">{{ value }}</BaseBadge>
</template>
