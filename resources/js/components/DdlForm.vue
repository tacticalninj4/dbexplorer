<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { api, describeError, segment } from '../api';
import type { DdlPreview } from '../types';
import CodeBlock from './CodeBlock.vue';
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

const emit = defineEmits<{ close: [changed: boolean] }>();

type Op = 'add_column' | 'drop_column' | 'rename_column' | 'create_index' | 'drop_index' | 'rename_table' | 'comment_table' | 'drop_table';

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
const queued = ref<number | null>(null);

const meta = computed(() => OPS.find((entry) => entry.op === op.value));

watch([() => props.open, op], () => {
    if (props.open) {
        error.value = null;
        preview.value = null;
        queued.value = null;
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
    queued.value = null;
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
        if (response.data.run) {
            queued.value = response.data.run.id;
            emit('close', true);
        } else {
            emit('close', true);
        }
    } catch (e) {
        error.value = describeError(e);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <ModalDialog :open="open" :title="`Alter ${schema}.${name}`" wide @close="emit('close', false)">
        <form class="space-y-3" @submit.prevent="build">
            <p v-if="error" role="alert" class="rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{{ error }}</p>
            <p v-if="queued" role="status" class="rounded border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-800 dark:border-sky-900 dark:bg-sky-950 dark:text-sky-200">
                Queued as background run #{{ queued }}: CONCURRENTLY can't run in a transaction.
            </p>

            <div class="flex flex-wrap gap-1" role="group" aria-label="Operation">
                <button
                    v-for="entry in OPS"
                    :key="entry.op"
                    type="button"
                    class="dbx-btn"
                    :class="entry.op === op ? 'dbx-btn-primary' : ''"
                    :aria-pressed="entry.op === op"
                    @click="op = entry.op"
                >
                    {{ entry.title }}
                </button>
            </div>

            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ meta?.note }}</p>

            <div v-if="op === 'add_column'" class="grid grid-cols-2 gap-2">
                <label class="text-sm">Column name <input v-model="columnName" class="dbx-input font-mono" required></label>
                <label class="text-sm">Type <input v-model="columnType" class="dbx-input font-mono" placeholder="e.g. varchar(80)" required></label>
                <label class="flex items-center gap-2 text-sm"><input v-model="columnNullable" type="checkbox"> nullable</label>
                <label class="text-sm">Default <input v-model="columnDefault" class="dbx-input font-mono" placeholder="optional, e.g. now()"></label>
            </div>

            <div v-else-if="op === 'drop_column' || op === 'rename_column'" class="grid grid-cols-2 gap-2">
                <label class="text-sm">Column <input v-model="columnName" class="dbx-input font-mono" required></label>
                <label v-if="op === 'rename_column'" class="text-sm">New name <input v-model="columnTo" class="dbx-input font-mono" required></label>
            </div>

            <div v-else-if="op === 'create_index' || op === 'drop_index'" class="grid grid-cols-2 gap-2">
                <label class="text-sm">Index name <input v-model="indexName" class="dbx-input font-mono" required></label>
                <label v-if="op === 'create_index'" class="text-sm">Columns <input v-model="indexColumns" class="dbx-input font-mono" placeholder="col1, col2" required></label>
                <label v-if="op === 'create_index'" class="flex items-center gap-2 text-sm"><input v-model="indexUnique" type="checkbox"> unique</label>
                <label class="flex items-center gap-2 text-sm" title="Builds or drops outside a transaction, as a background run"><input v-model="indexConcurrent" type="checkbox"> concurrently</label>
            </div>

            <div v-else-if="op === 'rename_table'" class="grid grid-cols-2 gap-2">
                <label class="text-sm">New name <input v-model="tableTo" class="dbx-input font-mono" required></label>
            </div>

            <div v-else-if="op === 'comment_table'" class="grid grid-cols-1 gap-2">
                <label class="text-sm">Comment <input v-model="tableComment" class="dbx-input"></label>
            </div>

            <div v-else class="grid grid-cols-2 gap-2">
                <label class="flex items-center gap-2 text-sm"><input v-model="cascade" type="checkbox"> cascade</label>
            </div>

            <CodeBlock v-if="preview" :code="preview.statements.map((statement) => statement.sql).join(';\n')" copy-label="Copy as SQL" />
            <CodeBlock v-if="preview?.migration" :code="preview.migration" copy-label="Copy as Laravel migration" />

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" class="dbx-btn" @click="emit('close', false)">Close</button>
                <button type="submit" class="dbx-btn" :disabled="busy">{{ busy ? 'Working…' : 'Preview SQL' }}</button>
                <button v-if="preview" type="button" class="dbx-btn" :class="meta?.destructive ? 'dbx-btn-danger' : 'dbx-btn-primary'" :disabled="busy" @click="execute">
                    Execute {{ meta?.destructive ? '(asks for password)' : '' }}
                </button>
            </div>
        </form>
    </ModalDialog>
</template>
