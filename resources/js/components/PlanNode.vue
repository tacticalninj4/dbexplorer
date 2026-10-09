<script setup lang="ts">
import { computed } from 'vue';
import { formatNumber } from '../composables/format';

// One node of an EXPLAIN (FORMAT JSON) plan, with its children below it.
type PlanData = Record<string, unknown>;

const props = withDefaults(defineProps<{ node: PlanData; depth?: number; analyze?: boolean }>(), { depth: 0, analyze: false });

const SHOWN = ['Node Type', 'Plans', 'Relation Name', 'Alias', 'Schema', 'Index Name', 'Join Type', 'Startup Cost', 'Total Cost', 'Plan Rows', 'Plan Width', 'Actual Startup Time', 'Actual Total Time', 'Actual Rows', 'Actual Loops'];

// Conditions are what people look for first: shown without expanding.
const CONDITIONS = ['Index Cond', 'Recheck Cond', 'Hash Cond', 'Merge Cond', 'Join Filter', 'Filter'];

const children = computed(() => (Array.isArray(props.node.Plans) ? (props.node.Plans as PlanData[]) : []));
const title = computed(() => {
    const n = props.node;
    const parts = [String(n['Node Type'] ?? 'Node')];
    if (typeof n['Join Type'] === 'string' && !parts[0]?.includes(n['Join Type'])) {
        parts.unshift(n['Join Type']);
    }
    if (typeof n['Index Name'] === 'string') {
        parts.push(`using ${n['Index Name']}`);
    }
    if (typeof n['Relation Name'] === 'string') {
        parts.push(`on ${n['Schema'] ? `${String(n['Schema'])}.` : ''}${n['Relation Name']}${n.Alias && n.Alias !== n['Relation Name'] ? ` ${String(n.Alias)}` : ''}`);
    }
    return parts.join(' ');
});
const details = computed(() =>
    Object.entries(props.node)
        .filter(([key]) => !SHOWN.includes(key) && !CONDITIONS.includes(key))
        .map(([key, value]) => [key, typeof value === 'object' && value !== null ? JSON.stringify(value) : String(value)] as const),
);
const num = (key: string): number | null => (typeof props.node[key] === 'number' ? (props.node[key] as number) : null);
</script>

<template>
    <li class="relative">
        <details open class="group">
            <summary class="flex cursor-pointer flex-wrap items-baseline gap-x-3 rounded px-2 py-1 hover:bg-zinc-100 focus-visible:ring-2 focus-visible:ring-sky-500 focus-visible:outline-none dark:hover:bg-zinc-800">
                <span class="font-medium">{{ title }}</span>
                <span class="text-xs text-zinc-500 tabular-nums dark:text-zinc-400">
                    cost {{ num('Startup Cost') }}..{{ num('Total Cost') }} · rows {{ formatNumber(num('Plan Rows')) }} · width {{ num('Plan Width') }}
                </span>
                <span v-if="analyze && num('Actual Total Time') !== null" class="text-xs text-emerald-700 tabular-nums dark:text-emerald-400">
                    actual {{ num('Actual Total Time') }} ms · rows {{ formatNumber(num('Actual Rows')) }} · loops {{ num('Actual Loops') }}
                </span>
            </summary>
            <details v-if="details.length" class="ml-6 text-xs">
                <summary class="cursor-pointer text-zinc-500 focus-visible:ring-2 focus-visible:ring-sky-500 focus-visible:outline-none dark:text-zinc-400">{{ details.length }} more properties</summary>
                <dl class="grid grid-cols-[max-content_1fr] gap-x-3 py-1">
                    <template v-for="[key, value] in details" :key="key">
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ key }}</dt>
                        <dd class="font-mono break-all">{{ value }}</dd>
                    </template>
                </dl>
            </details>
            <p v-for="key in CONDITIONS.filter((k) => typeof node[k] === 'string')" :key="key" class="ml-6 font-mono text-xs text-zinc-600 dark:text-zinc-300">
                {{ key }}: {{ node[key] }}
            </p>
            <ul v-if="children.length" class="ml-4 border-l border-zinc-200 pl-2 dark:border-zinc-700">
                <PlanNode v-for="(child, index) in children" :key="index" :node="child" :depth="depth + 1" :analyze="analyze" />
            </ul>
        </details>
    </li>
</template>
