<script setup lang="ts">
import type { Role } from '../types';
import BaseBadge from './BaseBadge.vue';
import type { Tone } from './BaseBadge.vue';
import type { IconName } from './BaseIcon.vue';

/** The user's role on a connection: icon, word and tone, with what it allows. */
defineProps<{ role: Role }>();

const ROLES: Record<Role, { tone: Tone; icon: IconName; title: string }> = {
    viewer: { tone: 'neutral', icon: 'eye', title: 'Viewer: browse and run read-only SQL' },
    editor: { tone: 'teal', icon: 'pencil', title: 'Editor: also insert, update and delete rows, and run DML' },
    admin: { tone: 'violet', icon: 'shield', title: 'Admin: also change the schema (DDL), unmask columns and manage jobs' },
};
</script>

<template>
    <BaseBadge :tone="ROLES[role].tone" :icon="ROLES[role].icon" :title="ROLES[role].title">{{ role }}</BaseBadge>
</template>
