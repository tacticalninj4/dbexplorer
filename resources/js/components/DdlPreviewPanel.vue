<script setup lang="ts">
import { computed } from 'vue';
import type { DdlPreview } from '../types';
import BaseBadge from './BaseBadge.vue';
import BaseIcon from './BaseIcon.vue';
import ClassificationBadge from './ClassificationBadge.vue';
import CodeBlock from './CodeBlock.vue';

/**
 * What a DDL operation will run, exactly as the server built it: one line
 * per statement with its classification, the full SQL to copy, the Laravel
 * migration when the server could express it, and warnings for raw SQL
 * expressions and background runs. Text interpolation only.
 */
const props = defineProps<{ preview: DdlPreview }>();

const sql = computed(() => props.preview.statements.map((statement) => `${statement.sql};`).join('\n\n'));
const raw = computed(() => props.preview.statements.some((statement) => statement.raw_expressions));
const background = computed(() => props.preview.statements.some((statement) => !statement.transactional));

function firstLine(text: string): string {
    const line = text.split('\n')[0] ?? '';

    return text.includes('\n') ? `${line} …` : line;
}
</script>

<template>
    <section aria-label="Generated SQL" class="space-y-3">
        <ol class="space-y-1" :aria-label="`${preview.statements.length} ${preview.statements.length === 1 ? 'statement' : 'statements'}`">
            <li v-for="(statement, index) in preview.statements" :key="index" class="flex min-w-0 items-center gap-2 text-sm">
                <span class="w-4 shrink-0 text-right text-xs text-fg-subtle tabular-nums">{{ index + 1 }}</span>
                <ClassificationBadge :value="statement.classification" />
                <BaseBadge v-if="!statement.transactional" tone="accent" icon="clock" title="Can't run in a transaction: it is queued as a background run">background run</BaseBadge>
                <BaseBadge v-if="statement.raw_expressions" tone="warning" icon="alert" title="Contains SQL you typed, passed to PostgreSQL as written">raw SQL</BaseBadge>
                <code class="min-w-0 truncate font-mono text-xs text-fg-muted">{{ firstLine(statement.sql) }}</code>
            </li>
        </ol>

        <CodeBlock :code="sql" copy-label="Copy SQL" />

        <div v-if="preview.migration">
            <p class="dbx-label">Laravel migration</p>
            <CodeBlock :code="preview.migration" copy-label="Copy as migration" />
        </div>

        <div v-if="raw" class="dbx-callout dbx-callout-warning">
            <BaseIcon name="alert" />
            <span>This SQL includes expressions you typed (defaults, checks or a query). DbExplorer passes them to PostgreSQL as written, so check them before you run it.</span>
        </div>
        <div v-if="background" class="dbx-callout dbx-callout-info">
            <BaseIcon name="clock" />
            <span>This can't run inside a transaction, so it will be queued as a background run. Follow it on the Runs page.</span>
        </div>
    </section>
</template>
