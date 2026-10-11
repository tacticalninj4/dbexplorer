<script setup lang="ts">
import { computed } from 'vue';
import type { Classification } from '../types';
import BaseIcon from './BaseIcon.vue';
import ClassificationBadge from './ClassificationBadge.vue';
import ModalDialog from './ModalDialog.vue';

/**
 * The server's 409 `confirmation_required` (M2.2), for the SQL editor and the
 * script runner: what the server classified the SQL as, the consequence in
 * words, and a button that names the action. Cancel has focus. A destructive
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
    <ModalDialog :open="open" :title="text.title" wide @close="emit('cancel')">
        <div class="space-y-3">
            <div class="dbx-callout" :class="classification === 'destructive' ? 'dbx-callout-danger' : classification === 'read' ? 'dbx-callout-info' : 'dbx-callout-warning'">
                <BaseIcon :name="classification === 'destructive' ? 'alert' : 'info'" />
                <div>
                    <p class="flex flex-wrap items-center gap-2">Classified as <ClassificationBadge :value="classification" /></p>
                    <p class="mt-1">{{ text.text }}<template v-if="note"> {{ note }}</template></p>
                </div>
            </div>
            <pre class="dbx-code max-h-56 whitespace-pre-wrap">{{ sql }}</pre>
        </div>
        <div class="mt-4 flex items-center justify-end gap-2 border-t border-line pt-4">
            <button type="button" class="dbx-btn dbx-btn-ghost mr-auto" autofocus @click="emit('cancel')">Cancel</button>
            <button type="button" class="dbx-btn" :class="classification === 'destructive' ? 'dbx-btn-danger' : 'dbx-btn-primary'" @click="emit('confirm')">
                <BaseIcon :name="classification === 'destructive' ? 'alert' : 'play'" />{{ text.action }}
            </button>
        </div>
    </ModalDialog>
</template>
