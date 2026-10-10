<script setup lang="ts">
import { computed } from 'vue';
import BaseIcon from './BaseIcon.vue';
import PlanNode from './PlanNode.vue';

// The JSON plan of EXPLAIN (FORMAT JSON): [{Plan, Planning Time, Execution Time, …}].
const props = defineProps<{ plan: unknown[]; analyze: boolean }>();

type PlanData = Record<string, unknown>;

const entries = computed(() =>
    props.plan.filter((entry): entry is PlanData => typeof entry === 'object' && entry !== null && 'Plan' in entry),
);
const timing = (entry: PlanData, key: string): string | null => (typeof entry[key] === 'number' ? `${String(entry[key])} ms` : null);

/** What each node's share is measured against: root time (ANALYZE) or root cost. */
function rootTotal(entry: PlanData): number {
    const root = entry.Plan as PlanData;
    const time = root['Actual Total Time'];
    const loops = root['Actual Loops'];

    if (props.analyze && typeof time === 'number') {
        return time * (typeof loops === 'number' ? loops : 1);
    }

    return typeof root['Total Cost'] === 'number' ? root['Total Cost'] : 0;
}
</script>

<template>
    <div class="space-y-4 p-3 text-sm">
        <div v-for="(entry, index) in entries" :key="index">
            <p class="mb-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-fg-muted">
                <span v-if="timing(entry, 'Planning Time')" class="tabular-nums">Planning <span class="font-medium text-fg">{{ timing(entry, 'Planning Time') }}</span></span>
                <span v-if="timing(entry, 'Execution Time')" class="tabular-nums">Execution <span class="font-medium text-fg">{{ timing(entry, 'Execution Time') }}</span></span>
                <span class="inline-flex items-center gap-1">
                    <BaseIcon :name="analyze ? 'refresh' : 'info'" :size="12" />
                    {{ analyze ? 'EXPLAIN ANALYZE ran the statement and rolled it back.' : 'Estimated plan: nothing ran.' }}
                </span>
                <span class="ml-auto">Bars show each step's own share of the {{ analyze ? 'time' : 'cost' }}.</span>
            </p>
            <ul aria-label="Query plan" class="dbx-card p-1.5">
                <PlanNode :node="entry.Plan as PlanData" :analyze="analyze" :total="rootTotal(entry)" />
            </ul>
        </div>
        <p v-if="entries.length === 0" class="text-fg-muted">The plan is empty.</p>
    </div>
</template>
