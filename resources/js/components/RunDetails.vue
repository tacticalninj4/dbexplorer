<script setup lang="ts">
import { computed } from 'vue';
import { formatDuration, formatNumber } from '../composables/format';
import type { BackgroundRun, RunStatement } from '../composables/runs';
import BaseBadge from './BaseBadge.vue';
import type { Tone } from './BaseBadge.vue';
import BaseIcon from './BaseIcon.vue';
import type { IconName } from './BaseIcon.vue';

/**
 * What a finished (or running) import or script did, from the runs API:
 * an import's inserted and skipped counts with its rejected rows (row number
 * and the server's redacted message, never cell values: F7), or a script's
 * per-statement outcomes. The API never returns a script's SQL; the script
 * screen passes `sources` (its own copy of what it sent) for the snippets.
 */
const props = withDefaults(
    defineProps<{
        run: BackgroundRun;
        /** One short preview per statement, from the submitted script. */
        sources?: string[];
        /** Statements are buttons that emit `select` (jump to it in the editor). */
        selectable?: boolean;
    }>(),
    { sources: () => [], selectable: false },
);

const emit = defineEmits<{ select: [index: number] }>();

const STATEMENT: Record<RunStatement['status'], { tone: Tone; icon: IconName; text: string }> = {
    succeeded: { tone: 'success', icon: 'check', text: 'Succeeded' },
    failed: { tone: 'danger', icon: 'x-circle', text: 'Failed' },
    skipped: { tone: 'neutral', icon: 'clock', text: 'Not run' },
    rolled_back: { tone: 'warning', icon: 'history', text: 'Rolled back' },
    cancelled: { tone: 'warning', icon: 'ban', text: 'Cancelled' },
};

const isImport = computed(() => props.run.type === 'import');
const statements = computed(() => props.run.statements ?? []);
const failedIndex = computed(() => statements.value.findIndex((statement) => statement.status === 'failed' || statement.status === 'cancelled'));
const errors = computed(() => props.run.errors ?? []);
const plural = (n: number, word: string) => `${formatNumber(n)} ${word}${n === 1 ? '' : 's'}`;
const statementStatus = (statement: RunStatement) => STATEMENT[statement.status] ?? STATEMENT.skipped;
</script>

<template>
    <div class="space-y-3">
        <template v-if="isImport">
            <p v-if="run.status === 'failed' || run.status === 'cancelled'" class="text-sm text-fg-muted">Nothing was imported: the import runs in one transaction, which was rolled back.</p>
            <dl v-else class="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                <div class="flex items-center gap-1.5">
                    <dt class="text-fg-muted">Inserted</dt>
                    <dd class="font-semibold tabular-nums">{{ run.inserted === undefined ? '—' : formatNumber(run.inserted) }}</dd>
                </div>
                <div class="flex items-center gap-1.5">
                    <dt class="text-fg-muted">Skipped</dt>
                    <dd class="font-semibold tabular-nums" :class="run.skipped ? 'text-warning-fg' : ''">{{ run.skipped === undefined ? '—' : formatNumber(run.skipped) }}</dd>
                </div>
            </dl>
            <div v-if="errors.length" class="dbx-card overflow-hidden">
                <p class="flex items-center gap-1.5 border-b border-line bg-sunken px-3 py-1.5 text-xs font-medium text-fg-muted">
                    <BaseIcon name="alert" :size="12" class="text-warning-fg" />
                    Rejected rows
                    <span v-if="run.skipped !== undefined && run.skipped > errors.length">(the first {{ formatNumber(errors.length) }} of {{ formatNumber(run.skipped) }})</span>
                    <span class="ml-auto font-normal">Row numbers count data rows, after the header.</span>
                </p>
                <div class="max-h-64 overflow-y-auto">
                    <table class="dbx-table">
                        <thead class="sr-only">
                            <tr><th scope="col">Row</th><th scope="col">Error</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="(error, index) in errors" :key="index">
                                <td class="w-px text-right whitespace-nowrap text-fg-muted tabular-nums">Row {{ formatNumber(error.row) }}</td>
                                <td class="font-mono text-xs break-all text-danger-fg">{{ error.error }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>

        <div v-else-if="statements.length" class="dbx-card overflow-x-auto">
            <table class="dbx-table">
                <caption class="sr-only">Statements</caption>
                <thead>
                    <tr>
                        <th scope="col" class="w-px text-right">#</th>
                        <th scope="col" class="w-px">Line</th>
                        <th scope="col">Status</th>
                        <th v-if="sources.length" scope="col">Statement</th>
                        <th scope="col" class="text-right">Duration</th>
                        <th scope="col" class="text-right">Rows affected</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(statement, index) in statements"
                        :key="index"
                        :class="index === failedIndex ? 'bg-danger-bg' : ''"
                        :aria-current="index === failedIndex ? 'true' : undefined"
                    >
                        <td class="text-right text-xs text-fg-subtle tabular-nums">{{ index + 1 }}</td>
                        <td class="tabular-nums">
                            <button v-if="selectable" type="button" class="dbx-link" :title="`Go to line ${statement.line}`" @click="emit('select', index)">{{ statement.line }}</button>
                            <template v-else>{{ statement.line }}</template>
                        </td>
                        <td><BaseBadge :tone="statementStatus(statement).tone" :icon="statementStatus(statement).icon">{{ statementStatus(statement).text }}</BaseBadge></td>
                        <td v-if="sources.length" class="max-w-md truncate font-mono text-xs" :title="sources[index] ?? ''">{{ sources[index] ?? '' }}</td>
                        <td class="text-right whitespace-nowrap text-fg-muted tabular-nums">{{ formatDuration(statement.duration_ms) }}</td>
                        <td class="text-right tabular-nums">{{ statement.affected === null ? '—' : formatNumber(statement.affected) }}</td>
                    </tr>
                </tbody>
            </table>
            <p class="border-t border-line-subtle px-3 py-1.5 text-xs text-fg-muted">
                {{ plural(statements.filter((s) => s.status === 'succeeded').length, 'statement') }} of {{ formatNumber(statements.length) }} succeeded
                <template v-if="statements.some((s) => s.status === 'rolled_back')"> · earlier statements were rolled back with the failure</template>
            </p>
        </div>
    </div>
</template>
