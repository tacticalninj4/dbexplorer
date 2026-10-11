<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { api, describeError, segment } from '../api';
import { notifyRun } from '../composables/runs';
import type { BackgroundRun } from '../composables/runs';
import { qualified } from '../sql/identifiers';
import BaseIcon from './BaseIcon.vue';
import ModalDialog from './ModalDialog.vue';

/**
 * Export a table, view or query result as a background run (PRD §7.6,
 * POST export). Any role may export; masked columns stay masked in the file
 * (S14). A query is re-run by the job, so the file holds every row it
 * returns, not just the rows the grid shows. The queued run follows in a
 * toast that offers the download once the file is ready.
 */
const props = withDefaults(
    defineProps<{
        open: boolean;
        label: string;
        /** A table or view, or the SQL of a query result. */
        source: { table: { schema: string; name: string } } | { sql: string };
        /** The grid has filters or a sort the export can't carry. */
        filtered?: boolean;
    }>(),
    { filtered: false },
);
const emit = defineEmits<{ close: [] }>();

type Format = 'csv' | 'jsonl' | 'sql';

const FORMATS: { value: Format; title: string; text: string }[] = [
    { value: 'csv', title: 'CSV', text: 'Comma-separated, with a header row. Opens in spreadsheets.' },
    { value: 'jsonl', title: 'JSON Lines', text: 'One JSON array per row, values in column order.' },
    { value: 'sql', title: 'SQL INSERTs', text: 'INSERT statements for the table, to load the rows elsewhere.' },
];

const format = ref<Format>('csv');
const busy = ref(false);
const error = ref<string | null>(null);

const table = computed(() => ('table' in props.source ? props.source.table : null));
const sql = computed(() => ('sql' in props.source ? props.source.sql : null));

watch(
    () => props.open,
    (open) => {
        if (open) {
            error.value = null;
            busy.value = false;
            format.value = 'csv';
        }
    },
);

async function submit(): Promise<void> {
    if (busy.value) {
        return;
    }

    busy.value = true;
    error.value = null;

    try {
        const body = table.value ? { table: table.value, format: format.value } : { sql: sql.value, format: format.value };
        const response = await api<{ data: BackgroundRun }>(`${segment(props.label)}/export`, { method: 'POST', body });
        const name = FORMATS.find((item) => item.value === format.value)?.title ?? format.value;
        notifyRun(props.label, response.data, table.value ? `${name} export of ${qualified(table.value.schema, table.value.name)}` : `${name} export of a query`);
        emit('close');
    } catch (e) {
        error.value = describeError(e, 'The export could not be queued.');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <ModalDialog :open="open" title="Export" :description="table ? `Every row of ${qualified(table.schema, table.name)}, written to a file in the background.` : 'The query runs again in the background and every row it returns goes into the file.'" @close="emit('close')">
        <form class="space-y-4" @submit.prevent="submit">
            <fieldset>
                <legend class="dbx-label">Format</legend>
                <div class="space-y-1.5">
                    <label
                        v-for="item in FORMATS"
                        :key="item.value"
                        class="flex gap-2.5 rounded-md border px-3 py-2 transition-colors duration-100"
                        :class="[
                            format === item.value ? 'border-info-line bg-accent-soft' : 'border-line',
                            item.value === 'sql' && !table ? 'opacity-60' : 'hover:bg-hover',
                        ]"
                    >
                        <input v-model="format" type="radio" name="dbx-export-format" :value="item.value" :disabled="item.value === 'sql' && !table" class="mt-0.5">
                        <span>
                            <span class="block font-medium text-fg">{{ item.title }}</span>
                            <span class="block text-xs text-fg-muted">{{ item.value === 'sql' && !table ? 'Needs a table to write INSERTs for: export the table from its Data tab instead.' : item.text }}</span>
                        </span>
                    </label>
                </div>
            </fieldset>

            <pre v-if="sql" class="dbx-code max-h-32 whitespace-pre-wrap" aria-label="Query to export">{{ sql }}</pre>

            <div class="space-y-1.5">
                <p v-if="filtered" class="dbx-callout dbx-callout-warning">
                    <BaseIcon name="filter" />
                    <span>The grid's filters and sort aren't applied: the export has every row of the table. To export a subset, write the query in the SQL editor and export its result.</span>
                </p>
                <p class="dbx-callout dbx-callout-info">
                    <BaseIcon name="lock" />
                    <span>Masked columns stay masked in the file. A notice follows the export and offers the download once the file is ready; it's on the Runs page too.</span>
                </p>
            </div>

            <p v-if="error" role="alert" class="dbx-callout dbx-callout-danger"><BaseIcon name="alert-circle" /><span>{{ error }}</span></p>

            <div class="flex justify-end gap-2 border-t border-line pt-4">
                <button type="button" class="dbx-btn dbx-btn-ghost mr-auto" @click="emit('close')">Cancel</button>
                <button type="submit" class="dbx-btn dbx-btn-primary" :disabled="busy">
                    <span v-if="busy" class="dbx-spinner border-current/30 border-t-current" aria-hidden="true" />
                    <BaseIcon v-else name="download" />{{ busy ? 'Queuing…' : 'Start export' }}
                </button>
            </div>
        </form>
    </ModalDialog>
</template>
