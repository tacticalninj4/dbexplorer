<script setup lang="ts">
import { computed, ref } from 'vue';
import type { Cell, ResultColumn } from '../types';
import { byteaPreview, isArrayType, isJsonType, parseArray } from './cells';
import BaseIcon from './BaseIcon.vue';
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
    if (value === '') {
        return 'empty';
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
    <span v-if="kind === 'null'" class="inline-block rounded-sm border border-dashed border-fg-subtle/50 px-1 font-sans text-[10px] leading-4 font-medium tracking-wider text-fg-subtle" title="NULL">NULL</span>

    <span v-else-if="kind === 'empty'" class="text-fg-subtle" title="Empty string"><span aria-hidden="true">''</span><span class="sr-only">empty string</span></span>

    <span v-else-if="kind === 'masked'" class="inline-flex items-center gap-1 text-fg-subtle" title="Masked column">
        <BaseIcon name="lock" :size="12" />
        <span class="sr-only">Masked:</span>
        <span>{{ text }}</span>
    </span>

    <span v-else-if="kind === 'json'" class="block">
        <button
            type="button"
            class="inline-flex max-w-full cursor-default items-center gap-1 rounded-sm text-left text-fg-muted hover:text-fg"
            :aria-expanded="expanded"
            :title="expanded ? 'Collapse JSON' : 'Expand JSON'"
            @click.stop="expanded = !expanded"
        >
            <BaseIcon name="chevron-right" :size="10" class="text-fg-subtle transition-transform duration-150 ease-pop motion-reduce:transition-none" :class="expanded ? 'rotate-90' : ''" />
            <span class="truncate">{{ expanded ? 'JSON' : preview }}</span>
        </button>
        <span v-if="expanded" class="mt-1 mb-0.5 block max-h-80 max-w-xl min-w-72 overflow-auto rounded border border-line bg-sunken py-1.5 pr-2 text-xs whitespace-normal text-fg">
            <JsonView v-if="json.ok" :value="json.value" />
            <span v-else class="pl-3 whitespace-pre-wrap">{{ text }}</span>
        </span>
    </span>

    <span v-else-if="kind === 'array'" class="inline-flex items-center gap-1" :class="expanded ? 'flex-wrap whitespace-normal' : ''">
        <span v-if="items.length === 0" class="text-fg-subtle" title="Empty array">{}</span>
        <template v-for="(item, index) in (expanded ? items : items.slice(0, ARRAY_ITEMS))" :key="index">
            <span v-if="item === null" class="rounded-sm border border-dashed border-fg-subtle/50 px-1 font-sans text-[10px] leading-4 font-medium tracking-wider text-fg-subtle">NULL</span>
            <span v-else class="rounded-sm border border-line bg-sunken px-1 text-xs leading-4">{{ item }}</span>
        </template>
        <button
            v-if="items.length > ARRAY_ITEMS"
            type="button"
            class="dbx-link font-sans text-xs"
            @click.stop="expanded = !expanded"
        >{{ expanded ? 'less' : `+${items.length - ARRAY_ITEMS} more` }}</button>
    </span>

    <span v-else-if="kind === 'bytea'" :title="`${bytea.size} bytes`">
        <span>{{ bytea.hex }}{{ bytea.more ? ' …' : '' }}</span>
        <span class="ml-1.5 font-sans text-[11px] text-fg-subtle">{{ bytea.size }} B</span>
    </span>

    <span v-else-if="kind === 'long'" class="block">
        <span v-if="expanded" class="block max-h-80 max-w-2xl min-w-96 overflow-auto whitespace-pre-wrap break-words">{{ text }}</span>
        <span v-else class="inline-block max-w-80 truncate align-bottom">{{ preview }}</span>
        <button
            type="button"
            class="dbx-link ml-1 font-sans text-xs"
            :aria-expanded="expanded"
            @click.stop="expanded = !expanded"
        >{{ expanded ? 'Show less' : `${text.length.toLocaleString()} chars` }}</button>
    </span>

    <span v-else-if="typeof value === 'boolean'" :class="value ? 'text-success-fg' : 'text-fg-muted'">{{ text }}</span>

    <span v-else>{{ text }}</span>
</template>
