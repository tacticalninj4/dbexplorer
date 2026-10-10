<script setup lang="ts">
import { computed } from 'vue';
import { formatDuration, formatNumber } from '../composables/format';
import type { QueryResponse } from '../types';
import ClassificationBadge from './ClassificationBadge.vue';
import BaseIcon from './BaseIcon.vue';

/**
 * The summary line above a SQL result: what ran, how much it touched, how
 * long it took, and the Results / Plan / Messages switch. A statement with
 * no result set reports rows affected, never rows returned (F25e).
 */
const props = defineProps<{ result: QueryResponse }>();
const view = defineModel<'results' | 'plan' | 'messages'>('view', { required: true });

const hasResultSet = computed(() => props.result.columns.length > 0);
const plural = (n: number, word: string) => `${formatNumber(n)} ${word}${n === 1 ? '' : 's'}`;
</script>

<template>
    <div class="flex min-w-0 flex-1 flex-wrap items-center gap-x-3 gap-y-1 text-sm">
        <ClassificationBadge :value="result.classification" />
        <span v-if="result.explain" class="font-medium">{{ result.explain === 'analyze' ? 'EXPLAIN ANALYZE' : 'EXPLAIN' }}</span>
        <template v-else>
            <span v-if="hasResultSet" class="tabular-nums">{{ plural(result.row_count, 'row') }}</span>
            <span v-if="result.affected_rows !== null" class="tabular-nums">{{ hasResultSet ? `${formatNumber(result.affected_rows)} affected` : `${plural(result.affected_rows, 'row')} affected` }}</span>
        </template>
        <span class="inline-flex items-center gap-1 text-fg-muted tabular-nums"><BaseIcon name="clock" :size="12" />{{ formatDuration(result.duration_ms) }}</span>
        <span v-if="result.truncated" class="inline-flex items-center gap-1 text-warning-fg"><BaseIcon name="alert" :size="12" />Showing the first {{ formatNumber(result.row_count) }} rows: your row limit cut the rest.</span>
        <div role="tablist" aria-label="Result views" class="dbx-segmented ml-auto">
            <button v-if="result.plan" type="button" role="tab" :aria-selected="view === 'plan'" @click="view = 'plan'"><BaseIcon name="plan" :size="12" />Plan</button>
            <button v-else type="button" role="tab" :aria-selected="view === 'results'" @click="view = 'results'"><BaseIcon name="table" :size="12" />Results</button>
            <button type="button" role="tab" :aria-selected="view === 'messages'" @click="view = 'messages'">
                <BaseIcon name="message" :size="12" />Messages<span v-if="result.notices.length" class="rounded-full bg-accent-solid px-1.5 text-[10px] leading-4 text-on-accent tabular-nums">{{ result.notices.length }}</span>
            </button>
        </div>
    </div>
</template>
