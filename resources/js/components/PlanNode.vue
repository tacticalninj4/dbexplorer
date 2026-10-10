<script setup lang="ts">
import { computed } from 'vue';
import { formatNumber } from '../composables/format';
import BaseIcon from './BaseIcon.vue';

// One node of an EXPLAIN (FORMAT JSON) plan, with its children below it.
type PlanData = Record<string, unknown>;

const props = withDefaults(defineProps<{ node: PlanData; depth?: number; analyze?: boolean; total?: number }>(), { depth: 0, analyze: false, total: 0 });

const SHOWN = ['Node Type', 'Plans', 'Relation Name', 'Alias', 'Schema', 'Index Name', 'Join Type', 'Startup Cost', 'Total Cost', 'Plan Rows', 'Plan Width', 'Actual Startup Time', 'Actual Total Time', 'Actual Rows', 'Actual Loops'];

// Conditions are what people look for first: shown without expanding.
const CONDITIONS = ['Index Cond', 'Recheck Cond', 'Hash Cond', 'Merge Cond', 'Join Filter', 'Filter'];

const children = computed(() => (Array.isArray(props.node.Plans) ? (props.node.Plans as PlanData[]) : []));
const nodeType = computed(() => {
    const n = props.node;
    const type = String(n['Node Type'] ?? 'Node');

    return typeof n['Join Type'] === 'string' && !type.includes(n['Join Type']) ? `${n['Join Type']} ${type}` : type;
});
const target = computed(() => {
    const n = props.node;

    if (typeof n['Relation Name'] !== 'string') {
        return null;
    }

    return `${n['Schema'] ? `${String(n['Schema'])}.` : ''}${n['Relation Name']}${n.Alias && n.Alias !== n['Relation Name'] ? ` ${String(n.Alias)}` : ''}`;
});
const details = computed(() =>
    Object.entries(props.node)
        .filter(([key]) => !SHOWN.includes(key) && !CONDITIONS.includes(key))
        .map(([key, value]) => [key, typeof value === 'object' && value !== null ? JSON.stringify(value) : String(value)] as const),
);
const num = (key: string): number | null => (typeof props.node[key] === 'number' ? (props.node[key] as number) : null);

/** Inclusive time (all loops) under ANALYZE, else total cost. */
function weight(node: PlanData): number {
    const time = node['Actual Total Time'];

    if (props.analyze && typeof time === 'number') {
        const loops = node['Actual Loops'];

        return time * (typeof loops === 'number' ? loops : 1);
    }

    return typeof node['Total Cost'] === 'number' ? node['Total Cost'] : 0;
}

/** This step's own share: its weight minus its children's, over the root's. */
const share = computed(() => {
    if (props.total <= 0) {
        return 0;
    }

    const own = weight(props.node) - children.value.reduce((sum, child) => sum + weight(child), 0);

    return Math.min(1, Math.max(0, own / props.total));
});
const percent = computed(() => `${Math.round(share.value * 100)}%`);
const hot = computed(() => share.value >= 0.3);
</script>

<template>
    <li>
        <details open class="group/node">
            <summary class="flex cursor-default list-none flex-wrap items-center gap-x-3 gap-y-1 rounded-md px-2 py-1.5 outline-offset-[-2px] hover:bg-hover [&::-webkit-details-marker]:hidden">
                <BaseIcon v-if="children.length" name="chevron-right" :size="12" class="-ml-0.5 text-fg-subtle transition-transform duration-150 ease-pop group-open/node:rotate-90 motion-reduce:transition-none" />
                <span v-else class="-ml-0.5 w-3" aria-hidden="true" />
                <span class="font-semibold">{{ nodeType }}</span>
                <span v-if="typeof node['Index Name'] === 'string'" class="text-fg-muted">using <span class="font-mono text-xs text-fg">{{ node['Index Name'] }}</span></span>
                <span v-if="target" class="text-fg-muted">on <span class="font-mono text-xs text-accent">{{ target }}</span></span>
                <span v-if="hot" class="dbx-badge border-warning-line bg-warning-bg text-warning-fg"><BaseIcon name="clock" />hot spot</span>
                <span class="ml-auto flex items-center gap-3 text-xs text-fg-muted tabular-nums">
                    <span v-if="analyze && num('Actual Total Time') !== null" class="text-fg">
                        {{ num('Actual Total Time') }} ms · {{ formatNumber(num('Actual Rows')) }} rows<template v-if="(num('Actual Loops') ?? 1) > 1"> × {{ num('Actual Loops') }} loops</template>
                    </span>
                    <span :title="`Startup cost ${num('Startup Cost')}, total cost ${num('Total Cost')}, ${num('Plan Rows')} estimated rows of ${num('Plan Width')} bytes`">
                        cost {{ num('Startup Cost') }}..{{ num('Total Cost') }} · est. {{ formatNumber(num('Plan Rows')) }} rows
                    </span>
                    <span class="flex w-24 items-center gap-1.5" :title="`${percent} of the ${analyze ? 'time' : 'cost'} is spent in this step itself`">
                        <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-sunken">
                            <span class="block h-full rounded-full" :class="hot ? 'bg-warning-fg' : 'bg-accent'" :style="{ width: percent }" />
                        </span>
                        <span class="w-8 text-right">{{ percent }}</span>
                    </span>
                </span>
            </summary>
            <div class="ml-7 space-y-0.5 pb-1">
                <p v-for="key in CONDITIONS.filter((k) => typeof node[k] === 'string')" :key="key" class="font-mono text-xs text-fg-muted">
                    <span class="text-fg-subtle">{{ key }}:</span> {{ node[key] }}
                </p>
                <details v-if="details.length" class="text-xs">
                    <summary class="w-fit cursor-default rounded-sm text-fg-subtle hover:text-fg">{{ details.length }} more properties</summary>
                    <dl class="mt-1 grid grid-cols-[max-content_1fr] gap-x-4 gap-y-0.5 rounded-md border border-line bg-sunken p-2">
                        <template v-for="[key, value] in details" :key="key">
                            <dt class="text-fg-muted">{{ key }}</dt>
                            <dd class="font-mono break-all">{{ value }}</dd>
                        </template>
                    </dl>
                </details>
            </div>
            <ul v-if="children.length" class="ml-3.5 border-l border-line pl-2">
                <PlanNode v-for="(child, index) in children" :key="index" :node="child" :depth="depth + 1" :analyze="analyze" :total="total" />
            </ul>
        </details>
    </li>
</template>
