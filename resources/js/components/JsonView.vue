<script setup lang="ts">
import { computed } from 'vue';

// A collapsible JSON tree. Every value renders through text interpolation (S15).
const props = withDefaults(defineProps<{ value: unknown; name?: string | number | null; depth?: number }>(), {
    name: null,
    depth: 0,
});

const isArray = computed(() => Array.isArray(props.value));
const isContainer = computed(() => typeof props.value === 'object' && props.value !== null);
const entries = computed<[string | number, unknown][]>(() => {
    if (Array.isArray(props.value)) {
        return props.value.map((item, index) => [index, item]);
    }

    return isContainer.value ? Object.entries(props.value as Record<string, unknown>) : [];
});
const summary = computed(() => (isArray.value ? `[${entries.value.length}]` : `{${entries.value.length}}`));
const scalarClass = computed(() => {
    switch (typeof props.value) {
        case 'string':
            return 'text-emerald-700 dark:text-emerald-400';
        case 'number':
            return 'text-sky-700 dark:text-sky-400';
        case 'boolean':
            return 'text-violet-700 dark:text-violet-400';
        default:
            return 'italic text-zinc-400';
    }
});
const scalar = computed(() => (typeof props.value === 'string' ? JSON.stringify(props.value) : String(props.value)));
</script>

<template>
    <details v-if="isContainer" :open="depth < 2" class="pl-3">
        <summary class="-ml-3 cursor-pointer rounded select-none focus-visible:ring-2 focus-visible:ring-sky-500 focus-visible:outline-none">
            <span v-if="name !== null" class="text-zinc-500 dark:text-zinc-400">{{ name }}: </span>
            <span class="text-zinc-400">{{ summary }}</span>
        </summary>
        <JsonView v-for="[key, item] in entries" :key="key" :value="item" :name="key" :depth="depth + 1" />
    </details>
    <div v-else class="pl-3">
        <span v-if="name !== null" class="text-zinc-500 dark:text-zinc-400">{{ name }}: </span>
        <span :class="scalarClass" class="break-all">{{ scalar }}</span>
    </div>
</template>
