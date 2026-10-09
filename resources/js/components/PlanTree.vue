<script setup lang="ts">
import { computed } from 'vue';
import PlanNode from './PlanNode.vue';

// The JSON plan of EXPLAIN (FORMAT JSON): [{Plan, Planning Time, Execution Time, …}].
const props = defineProps<{ plan: unknown[]; analyze: boolean }>();

type PlanData = Record<string, unknown>;

const entries = computed(() =>
    props.plan.filter((entry): entry is PlanData => typeof entry === 'object' && entry !== null && 'Plan' in entry),
);
const timing = (entry: PlanData, key: string): string | null => (typeof entry[key] === 'number' ? `${String(entry[key])} ms` : null);
</script>

<template>
    <div class="space-y-3 p-3 text-sm">
        <div v-for="(entry, index) in entries" :key="index">
            <p class="mb-2 flex gap-4 text-xs text-zinc-500 dark:text-zinc-400">
                <span v-if="timing(entry, 'Planning Time')">Planning: {{ timing(entry, 'Planning Time') }}</span>
                <span v-if="timing(entry, 'Execution Time')">Execution: {{ timing(entry, 'Execution Time') }}</span>
                <span v-if="!analyze">Estimated plan: nothing ran.</span>
                <span v-else>EXPLAIN ANALYZE ran the statement and rolled it back.</span>
            </p>
            <ul aria-label="Query plan">
                <PlanNode :node="entry.Plan as PlanData" :analyze="analyze" />
            </ul>
        </div>
        <p v-if="entries.length === 0" class="text-zinc-500">The plan is empty.</p>
    </div>
</template>
