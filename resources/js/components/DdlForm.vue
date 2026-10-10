<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { api, describeError, segment } from '../api';
import type { DdlPreview } from '../types';
import CodeBlock from './CodeBlock.vue';
import BaseIcon from './BaseIcon.vue';
import ModalDialog from './ModalDialog.vue';

/**
 * The M2 schema editor (PRD §7.4), admins only: pick a structured operation
 * on the table, see the SQL it produces (with the migration snippet to copy)
 * before anything runs, then execute. CONCURRENTLY operations can't run in a
 * transaction, so the server queues them as a background run (202) instead.
 */
const props = defineProps<{
    open: boolean;
    label: string;
    schema: string;
    name: string;
}>();

/** What a successful execute did, for the page behind the dialog (F25a, F25b). */
export interface DdlOutcome {
    op: Op;
    title: string;
    /** Set when CONCURRENTLY queued it as a background run instead. */
    run?: number;
    /** The new name, for rename_table. */
    to?: string;
}

const emit = defineEmits<{ close: [outcome: DdlOutcome | null] }>();

export type Op = 'add_column' | 'drop_column' | 'rename_column' | 'create_index' | 'drop_index' | 'rename_table' | 'comment_table' | 'drop_table';

const OPS: { op: Op; title: string; destructive?: boolean; note: string }[] = [
    { op: 'add_column', title: 'Add column', note: 'Name and PostgreSQL type; the server validates the type against the database.' },
    { op: 'drop_column', title: 'Drop column', destructive: true, note: 'Removes the column and its data.' },
    { op: 'rename_column', title: 'Rename column', note: 'Column name and the new name.' },
    { op: 'create_index', title: 'Create index', note: 'Comma-separated columns; CONCURRENTLY builds it as a background run.' },
    { op: 'drop_index', title: 'Drop index', destructive: true, note: 'Index name.' },
    { op: 'rename_table', title: 'Rename table', note: 'The new name.' },
    { op: 'comment_table', title: 'Comment on table', note: 'Empty removes the comment.' },
    { op: 'drop_table', title: 'Drop table', destructive: true, note: 'The whole table. CASCADE also drops what depends on it.' },
];

const op = ref<Op>('add_column');
const columnName = ref('');
const columnType = ref('');
const columnNullable = ref(true);
const columnDefault = ref('');
const columnTo = ref('');
const indexName = ref('');
const indexColumns = ref('');
const indexUnique = ref(false);
const indexConcurrent = ref(false);
const tableTo = ref('');
const tableComment = ref('');
const cascade = ref(false);

const preview = ref<DdlPreview | null>(null);
const error = ref<string | null>(null);
const busy = ref(false);

const meta = computed(() => OPS.find((entry) => entry.op === op.value));

watch([() => props.open, op], () => {
    if (props.open) {
        error.value = null;
        preview.value = null;
    }
});

// Execute sends the form as it is now: an edit after Preview must be previewed again.
watch([columnName, columnType, columnNullable, columnDefault, columnTo, indexName, indexColumns, indexUnique, indexConcurrent, tableTo, tableComment, cascade], () => {
    preview.value = null;
});

/** Plain words for what a destructive operation loses, shown before Execute. */
const consequence = computed(() => {
    const target = `${props.schema}.${props.name}`;

    switch (op.value) {
        case 'drop_column':
            return `Dropping column ${columnName.value.trim() || '…'} deletes its values in every row of ${target}.`;
        case 'drop_index':
            return `Dropping index ${indexName.value.trim() || '…'} can make queries that relied on it slower.`;
        case 'drop_table':
            return `Dropping ${target} deletes the table and all of its rows${cascade.value ? ', and CASCADE also drops everything that depends on it (views, foreign keys)' : ''}.`;
        default:
            return null;
    }
});

/** The structured operation the DdlBuilder turns into SQL. */
function operation(): Record<string, unknown> {
    const base = { op: op.value, schema: props.schema };

    switch (op.value) {
        case 'add_column':
            return { ...base, table: props.name, column: { name: columnName.value.trim(), type: columnType.value.trim(), nullable: columnNullable.value, ...(columnDefault.value.trim() === '' ? {} : { default: columnDefault.value.trim() }) } };
        case 'drop_column':
            return { ...base, table: props.name, name: columnName.value.trim() };
        case 'rename_column':
            return { ...base, table: props.name, name: columnName.value.trim(), to: columnTo.value.trim() };
        case 'create_index':
            return { ...base, table: props.name, name: indexName.value.trim(), columns: indexColumns.value.split(',').map((item) => item.trim()).filter((item) => item !== ''), unique: indexUnique.value || undefined, concurrently: indexConcurrent.value || undefined };
        case 'drop_index':
            return { ...base, name: indexName.value.trim(), concurrently: indexConcurrent.value || undefined };
        case 'rename_table':
            return { ...base, name: props.name, to: tableTo.value.trim() };
        case 'comment_table':
            return { ...base, name: props.name, comment: tableComment.value === '' ? null : tableComment.value };
        default:
            return { ...base, name: props.name, cascade: cascade.value || undefined };
    }
}

async function build(): Promise<void> {
    error.value = null;
    preview.value = null;
    busy.value = true;

    try {
        const response = await api<{ data: DdlPreview }>(`${segment(props.label)}/ddl/preview`, { method: 'POST', body: { op: operation() } });
        preview.value = response.data;
    } catch (e) {
        error.value = describeError(e);
    } finally {
        busy.value = false;
    }
}

async function execute(): Promise<void> {
    if (!preview.value) {
        return;
    }

    error.value = null;
    busy.value = true;

    try {
        const response = await api<{ data: { run?: { id: number } } }>(`${segment(props.label)}/ddl/execute`, { method: 'POST', body: { op: operation() } });

        // CONCURRENTLY: queued as a background run instead of executed.
        emit('close', {
            op: op.value,
            title: meta.value?.title ?? op.value,
            run: response.data.run?.id,
            to: op.value === 'rename_table' ? tableTo.value.trim() : undefined,
        });
    } catch (e) {
        error.value = describeError(e);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <ModalDialog
        :open="open"
        :title="`Alter ${schema}.${name}`"
        description="Pick a change, review the SQL it generates, then run it. Nothing runs until you press the final button."
        wide
        @close="emit('close', null)"
    >
        <form class="space-y-4" @submit.prevent="build">
            <div class="flex flex-wrap items-center gap-1" role="group" aria-label="Operation">
                <button
                    v-for="entry in OPS.filter((item) => !item.destructive)"
                    :key="entry.op"
                    type="button"
                    class="dbx-btn dbx-btn-sm"
                    :class="entry.op === op ? 'dbx-btn-pressed' : ''"
                    :aria-pressed="entry.op === op"
                    @click="op = entry.op"
                >
                    {{ entry.title }}
                </button>
                <span class="dbx-separator mx-1" aria-hidden="true" />
                <button
                    v-for="entry in OPS.filter((item) => item.destructive)"
                    :key="entry.op"
                    type="button"
                    class="dbx-btn dbx-btn-sm"
                    :class="entry.op === op ? 'border-danger-line bg-danger-bg text-danger-fg' : 'dbx-btn-danger-ghost'"
                    :aria-pressed="entry.op === op"
                    @click="op = entry.op"
                >
                    <BaseIcon name="trash" />{{ entry.title }}
                </button>
            </div>

            <p class="text-sm text-fg-muted">{{ meta?.note }}</p>

            <div v-if="op === 'add_column'" class="grid grid-cols-2 gap-3">
                <label><span class="dbx-label">Column name</span><input v-model="columnName" class="dbx-input w-full font-mono" required></label>
                <label><span class="dbx-label">Type</span><input v-model="columnType" class="dbx-input w-full font-mono" placeholder="e.g. varchar(80)" required></label>
                <label><span class="dbx-label">Default</span><input v-model="columnDefault" class="dbx-input w-full font-mono" placeholder="optional, e.g. now()"></label>
                <label class="dbx-check self-end pb-1"><input v-model="columnNullable" type="checkbox"> Nullable</label>
            </div>

            <div v-else-if="op === 'drop_column' || op === 'rename_column'" class="grid grid-cols-2 gap-3">
                <label><span class="dbx-label">Column</span><input v-model="columnName" class="dbx-input w-full font-mono" required></label>
                <label v-if="op === 'rename_column'"><span class="dbx-label">New name</span><input v-model="columnTo" class="dbx-input w-full font-mono" required></label>
            </div>

            <div v-else-if="op === 'create_index' || op === 'drop_index'" class="grid grid-cols-2 gap-3">
                <label><span class="dbx-label">Index name</span><input v-model="indexName" class="dbx-input w-full font-mono" required></label>
                <label v-if="op === 'create_index'"><span class="dbx-label">Columns</span><input v-model="indexColumns" class="dbx-input w-full font-mono" placeholder="col1, col2" required></label>
                <div class="col-span-2 flex flex-wrap gap-4">
                    <label v-if="op === 'create_index'" class="dbx-check"><input v-model="indexUnique" type="checkbox"> Unique</label>
                    <label class="dbx-check" title="Builds or drops outside a transaction, as a background run"><input v-model="indexConcurrent" type="checkbox"> Concurrently <span class="text-fg-subtle">(runs in the background)</span></label>
                </div>
            </div>

            <div v-else-if="op === 'rename_table'" class="grid grid-cols-2 gap-3">
                <label><span class="dbx-label">New name</span><input v-model="tableTo" class="dbx-input w-full font-mono" required></label>
            </div>

            <div v-else-if="op === 'comment_table'">
                <label><span class="dbx-label">Comment</span><input v-model="tableComment" class="dbx-input w-full"></label>
            </div>

            <div v-else>
                <label class="dbx-check"><input v-model="cascade" type="checkbox"> Cascade <span class="text-fg-subtle">(also drop dependent objects)</span></label>
            </div>

            <div v-if="error" role="alert" class="dbx-callout dbx-callout-danger"><BaseIcon name="alert-circle" /><span>{{ error }}</span></div>

            <template v-if="preview">
                <div>
                    <p class="dbx-label">SQL</p>
                    <CodeBlock :code="preview.statements.map((statement) => statement.sql).join(';\n')" copy-label="Copy SQL" />
                </div>
                <div v-if="preview.migration">
                    <p class="dbx-label">Laravel migration</p>
                    <CodeBlock :code="preview.migration" copy-label="Copy migration" />
                </div>
                <div v-if="meta?.destructive && consequence" class="dbx-callout dbx-callout-danger">
                    <BaseIcon name="alert" />
                    <span>{{ consequence }} This can't be undone from DbExplorer. You'll be asked for your password.</span>
                </div>
            </template>

            <div class="flex items-center justify-end gap-2 border-t border-line pt-4">
                <button type="button" class="dbx-btn dbx-btn-ghost mr-auto" @click="emit('close', null)">Cancel</button>
                <button type="submit" class="dbx-btn" :class="preview ? '' : 'dbx-btn-primary'" :disabled="busy">
                    <span v-if="busy && !preview" class="dbx-spinner" aria-hidden="true" />{{ preview ? 'Refresh preview' : 'Preview SQL' }}
                </button>
                <button v-if="preview" type="button" class="dbx-btn" :class="meta?.destructive ? 'dbx-btn-danger' : 'dbx-btn-primary'" :disabled="busy" @click="execute">
                    <span v-if="busy" class="dbx-spinner border-white/40 border-t-white" aria-hidden="true" />
                    <BaseIcon v-else :name="meta?.destructive ? 'trash' : 'play'" />{{ meta?.destructive ? meta.title : 'Run' }}
                </button>
            </div>
        </form>
    </ModalDialog>
</template>
