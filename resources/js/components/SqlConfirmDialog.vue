<script setup lang="ts">
import { computed } from 'vue';
import type { Classification } from '../types';
import ClassificationBadge from './ClassificationBadge.vue';
import ConfirmDialog from './ConfirmDialog.vue';

/**
 * The server's 409 `confirmation_required` (M2.2), for the SQL editor and the
 * script runner, on the shared ConfirmDialog: what the server classified the
 * SQL as, the consequence in words, and a button that names the action.
 * Cancel has focus. A destructive
 * verdict continues into the password modal through api()'s 423 handling.
 */
const props = withDefaults(
    defineProps<{
        open: boolean;
        classification: Classification;
        sql: string;
        /** What is being run: "statement" (SQL editor) or "script". */
        noun?: 'statement' | 'script';
        /** An extra sentence, e.g. that EXPLAIN ANALYZE rolls back. */
        note?: string;
    }>(),
    { noun: 'statement', note: undefined },
);
const emit = defineEmits<{ confirm: []; cancel: [] }>();

const text = computed(() => {
    const it = `This ${props.noun}`;
    const effect = props.noun === 'script' ? 'Each statement takes effect as the script commits.' : 'It takes effect as soon as it succeeds.';

    return {
        read: { title: `Run this ${props.noun}?`, text: 'The server asked for confirmation before running it.', action: `Run ${props.noun}` },
        dml: { title: 'Change rows?', text: `${it} inserts, updates or deletes rows. ${effect}`, action: props.noun === 'script' ? 'Run script' : 'Run DML' },
        ddl: { title: 'Change the schema?', text: `${it} creates, alters or drops database objects. ${effect}`, action: props.noun === 'script' ? 'Run script' : 'Run DDL' },
        destructive: {
            title: `Run a destructive ${props.noun}?`,
            text: `${it} drops, truncates, or changes every row of a table. It can't be undone from DbExplorer, and you'll be asked for your password.`,
            action: `Run destructive ${props.noun}`,
        },
        unknown: { title: `Run an unclassified ${props.noun}?`, text: `DbExplorer could not tell what this ${props.noun} does. Check it before running it.`, action: `Run ${props.noun}` },
    }[props.classification];
});
</script>

<template>
    <ConfirmDialog
        :open="open"
        :title="text.title"
        :message="note ? `${text.text} ${note}` : text.text"
        :action="text.action"
        :danger="classification === 'destructive'"
        :warning="classification !== 'destructive' && classification !== 'read'"
        @confirm="emit('confirm')"
        @cancel="emit('cancel')"
    >
        <p class="flex flex-wrap items-center gap-2 text-sm text-fg-muted">Classified by the server as <ClassificationBadge :value="classification" /></p>
        <pre class="dbx-code max-h-56 whitespace-pre-wrap">{{ sql }}</pre>
    </ConfirmDialog>
</template>
