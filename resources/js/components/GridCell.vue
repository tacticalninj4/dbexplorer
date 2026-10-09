<script setup lang="ts">
import { computed, ref } from 'vue';
import type { Cell, ResultColumn } from '../types';
import { byteaPreview, isArrayType, isJsonType, parseArray } from './cells';
import JsonView from './JsonView.vue';

// One grid value. Text interpolation only, never v-html (S15).
const props = defineProps<{ value: Cell; column: ResultColumn }>();

const TRUNCATE = 120;
const ARRAY_ITEMS = 8;

const expanded = ref(false);

const kind = computed(() => {
    const { value, column } = props;

    if (value === null) {
        return 'null';
    }
    if (column.masked) {
        return 'masked';
    }
    if (typeof value !== 'string') {
        return 'scalar';
    }
    if (isJsonType(column.type)) {
        return 'json';
    }
    if (column.type === 'bytea' && value.startsWith('\\x')) {
        return 'bytea';
    }
    if (isArrayType(column.type) && parseArray(value) !== null) {
        return 'array';
    }

    return value.length > TRUNCATE ? 'long' : 'scalar';
});

const text = computed(() => String(props.value));

const json = computed<{ ok: boolean; value: unknown }>(() => {
    try {
        return { ok: true, value: JSON.parse(text.value) as unknown };
    } catch {
        return { ok: false, value: null };
    }
});

const items = computed(() => parseArray(text.value) ?? []);
const bytea = computed(() => byteaPreview(text.value));
const preview = computed(() => (text.value.length > TRUNCATE ? `${text.value.slice(0, TRUNCATE)}…` : text.value));
</script>

<template>
    <span v-if="kind === 'null'" class="rounded bg-zinc-100 px-1 text-xs font-medium tracking-wide text-zinc-400 italic dark:bg-zinc-800 dark:text-zinc-500">NULL</span>

    <span v-else-if="kind === 'masked'" class="inline-flex items-center gap-1 text-zinc-500 dark:text-zinc-400" title="Masked column">
        <svg aria-hidden="true" viewBox="0 0 16 16" class="size-3.5 shrink-0 fill-current"><path d="M8 1a3.5 3.5 0 0 0-3.5 3.5V6H4a1.5 1.5 0 0 0-1.5 1.5v6A1.5 1.5 0 0 0 4 15h8a1.5 1.5 0 0 0 1.5-1.5v-6A1.5 1.5 0 0 0 12 6h-.5V4.5A3.5 3.5 0 0 0 8 1Zm2 5H6V4.5a2 2 0 1 1 4 0V6Z" /></svg>
        <span class="sr-only">Masked:</span>
        <span>{{ text }}</span>
    </span>

    <span v-else-if="kind === 'json'" class="block">
        <button
            type="button"
            class="max-w-full truncate text-left font-mono text-xs text-zinc-700 hover:underline focus-visible:ring-2 focus-visible:ring-sky-500 focus-visible:outline-none dark:text-zinc-300"
            :aria-expanded="expanded"
            :title="expanded ? 'Collapse JSON' : 'Expand JSON'"
            @click="expanded = !expanded"
        >{{ expanded ? '▾ JSON' : preview }}</button>
        <span v-if="expanded" class="mt-1 block max-h-80 overflow-auto rounded border border-zinc-200 bg-zinc-50 p-2 font-mono text-xs whitespace-normal dark:border-zinc-700 dark:bg-zinc-900">
            <JsonView v-if="json.ok" :value="json.value" />
            <span v-else class="whitespace-pre-wrap">{{ text }}</span>
        </span>
    </span>

    <span v-else-if="kind === 'array'" class="inline-flex items-center gap-1" :class="expanded ? 'flex-wrap' : ''">
        <span v-if="items.length === 0" class="text-zinc-400">{}</span>
        <template v-for="(item, index) in (expanded ? items : items.slice(0, ARRAY_ITEMS))" :key="index">
            <span v-if="item === null" class="rounded bg-zinc-100 px-1 text-xs text-zinc-400 italic dark:bg-zinc-800">NULL</span>
            <span v-else class="rounded bg-sky-50 px-1 text-xs text-sky-900 ring-1 ring-sky-200 dark:bg-sky-950 dark:text-sky-200 dark:ring-sky-900">{{ item }}</span>
        </template>
        <button
            v-if="items.length > ARRAY_ITEMS"
            type="button"
            class="text-xs text-sky-700 hover:underline dark:text-sky-400"
            @click="expanded = !expanded"
        >{{ expanded ? 'less' : `+${items.length - ARRAY_ITEMS} more` }}</button>
    </span>

    <span v-else-if="kind === 'bytea'" class="font-mono text-xs" :title="`${bytea.size} bytes`">
        <span>{{ bytea.hex }}{{ bytea.more ? ' …' : '' }}</span>
        <span class="ml-1 text-zinc-400">({{ bytea.size }} B)</span>
    </span>

    <span v-else-if="kind === 'long'" class="block">
        <span :class="expanded ? 'block max-h-80 overflow-auto whitespace-pre-wrap' : 'inline-block max-w-md truncate align-bottom'">{{ expanded ? text : preview }}</span>
        <button
            type="button"
            class="ml-1 text-xs text-sky-700 hover:underline focus-visible:ring-2 focus-visible:ring-sky-500 focus-visible:outline-none dark:text-sky-400"
            :aria-expanded="expanded"
            @click="expanded = !expanded"
        >{{ expanded ? 'less' : `more (${text.length.toLocaleString()} chars)` }}</button>
    </span>

    <span v-else-if="typeof value === 'boolean'" :class="value ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400'">{{ text }}</span>

    <span v-else>{{ text }}</span>
</template>
