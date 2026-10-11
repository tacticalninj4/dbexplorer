<script setup lang="ts">
import { defineAsyncComponent, ref, watch } from 'vue';
import * as catalog from '../composables/catalog';
import { ddlFailure, executeDdl, previewDdl } from '../composables/ddl';
import type { DdlFailure, DdlOp } from '../composables/ddl';
import type { DdlPreview, Schema } from '../types';
import BaseIcon from './BaseIcon.vue';
import type { CreatedObject } from './CreateTableForm.vue';
import DdlPreviewPanel from './DdlPreviewPanel.vue';
import ModalDialog from './ModalDialog.vue';

/**
 * Create a view or a materialized view (PRD §7.4), admins only: schema,
 * name and the query in the shared CodeMirror field. Views are always
 * CREATE OR REPLACE (that's what the API builds); a materialized view can
 * be created WITH NO DATA and refreshed later. The query is raw SQL by
 * design, so the preview flags it.
 */
const props = defineProps<{ open: boolean; label: string; schema: string; initialKind: 'view' | 'materialized_view' }>();
const emit = defineEmits<{ close: [created: CreatedObject | null] }>();

// CodeMirror stays in its own chunk, loaded when the form first opens.
const SqlCodeEditor = defineAsyncComponent(() => import('./SqlCodeEditor.vue'));

const kind = ref<'view' | 'materialized_view'>('view');
const schemas = ref<Schema[]>([]);
const schemaName = ref('');
const name = ref('');
const body = ref('');
const withData = ref(true);
const preview = ref<DdlPreview | null>(null);
const failure = ref<DdlFailure | null>(null);
const busy = ref(false);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        kind.value = props.initialKind;
        schemaName.value = props.schema;
        name.value = '';
        body.value = 'SELECT\n    \nFROM ';
        withData.value = true;
        preview.value = null;
        failure.value = null;
        catalog.schemas(props.label)
            .then((list) => (schemas.value = list))
            .catch(() => (schemas.value = [{ name: props.schema, owner: '', comment: null }]));
    },
    { immediate: true },
);

watch([kind, schemaName, name, body, withData], () => (preview.value = null));

function operation(): DdlOp {
    const base = { schema: schemaName.value, name: name.value.trim(), body: body.value };

    return kind.value === 'view'
        ? { op: 'create_view', ...base }
        : { op: 'create_materialized_view', ...base, with_data: withData.value };
}

async function build(): Promise<void> {
    failure.value = null;
    preview.value = null;
    busy.value = true;

    try {
        preview.value = await previewDdl(props.label, operation());
    } catch (e) {
        failure.value = ddlFailure(e);
    } finally {
        busy.value = false;
    }
}

async function execute(): Promise<void> {
    failure.value = null;
    busy.value = true;

    try {
        const op = operation();
        const result = await executeDdl(props.label, op);
        emit('close', { schema: String(op.schema), name: String(op.name), run: result.run });
    } catch (e) {
        failure.value = ddlFailure(e);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <ModalDialog
        :open="open"
        :title="kind === 'view' ? 'Create view' : 'Create materialized view'"
        description="Write the query, review the SQL, then create it. Nothing runs until the last step."
        wide
        @close="emit('close', null)"
    >
        <form class="space-y-4" novalidate @submit.prevent="build">
            <div class="dbx-segmented" role="group" aria-label="Kind">
                <button type="button" :aria-pressed="kind === 'view'" @click="kind = 'view'"><BaseIcon name="view" />View</button>
                <button type="button" :aria-pressed="kind === 'materialized_view'" @click="kind = 'materialized_view'"><BaseIcon name="matview" />Materialized view</button>
            </div>

            <div class="grid grid-cols-[minmax(0,12rem)_1fr] gap-3">
                <label>
                    <span class="dbx-label">Schema</span>
                    <select v-model="schemaName" class="dbx-select w-full" :aria-invalid="failure?.fields.schema ? 'true' : undefined">
                        <option v-for="item in schemas" :key="item.name" :value="item.name">{{ item.name }}</option>
                    </select>
                </label>
                <label>
                    <span class="dbx-label">Name</span>
                    <input v-model="name" class="dbx-input w-full font-mono" required spellcheck="false" autocomplete="off" :aria-invalid="failure?.fields.name ? 'true' : undefined">
                </label>
            </div>

            <div>
                <p id="dbx-create-view-query" class="dbx-label">Query</p>
                <SqlCodeEditor v-model="body" :label="label" aria-label="View query" :invalid="Boolean(failure?.fields.body)" class="h-56" />
                <p class="mt-1 text-xs text-fg-subtle">A single SELECT (or VALUES, or a WITH query). It is sent to PostgreSQL as written.</p>
            </div>

            <p v-if="kind === 'view'" class="dbx-callout dbx-callout-info">
                <BaseIcon name="info" />
                <span>Views are created with <code class="font-mono text-xs">CREATE OR REPLACE</code>: an existing view with this name is replaced, as long as its columns stay compatible.</span>
            </p>
            <label v-else class="dbx-check items-start">
                <input v-model="withData" type="checkbox" class="mt-0.5">
                <span>Populate now (WITH DATA) <span class="block text-xs text-fg-subtle">Unchecked creates it empty (WITH NO DATA): it can't be queried until it is refreshed.</span></span>
            </label>

            <div v-if="failure" role="alert" class="dbx-callout dbx-callout-danger"><BaseIcon name="alert-circle" /><span>{{ failure.message }}</span></div>

            <DdlPreviewPanel v-if="preview" :preview="preview" />

            <div class="flex items-center justify-end gap-2 border-t border-line pt-4">
                <button type="button" class="dbx-btn dbx-btn-ghost mr-auto" @click="emit('close', null)">Cancel</button>
                <button type="submit" class="dbx-btn" :class="preview ? '' : 'dbx-btn-primary'" :disabled="busy">
                    <span v-if="busy && !preview" class="dbx-spinner" aria-hidden="true" />{{ preview ? 'Refresh preview' : 'Preview SQL' }}
                </button>
                <button v-if="preview" type="button" class="dbx-btn dbx-btn-primary" :disabled="busy" @click="execute">
                    <span v-if="busy" class="dbx-spinner border-white/40 border-t-white" aria-hidden="true" />
                    <BaseIcon v-else name="play" />{{ kind === 'view' ? 'Create view' : 'Create materialized view' }}
                </button>
            </div>
        </form>
    </ModalDialog>
</template>
