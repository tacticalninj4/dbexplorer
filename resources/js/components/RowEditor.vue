<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { ApiError, api, describeError, segment } from '../api';
import type { Cell, ResultColumn, RowConflictBody } from '../types';
import CodeBlock from './CodeBlock.vue';
import BaseIcon from './BaseIcon.vue';
import ModalDialog from './ModalDialog.vue';

/**
 * The M2 row writer modal (PRD §7.2): insert a row, edit a row or delete
 * one, with the generated SQL shown before it runs ("Review SQL") and a 409
 * conflict answered with the row as it is now. Masked columns are never
 * editable and never sent. The server owns every rule: destructive deletes
 * ask for the password through api()'s shared 423 flow.
 */
const props = defineProps<{
    open: boolean;
    mode: 'insert' | 'update' | 'delete';
    label: string;
    schema: string;
    name: string;
    columns: ResultColumn[];
    primaryKey: string[];
    /** The selected row, as the API returned it (null for insert). */
    row: Record<string, Cell> | null;
}>();

const emit = defineEmits<{ close: [saved: boolean] }>();

const editable = computed(() => props.columns.filter((column) => !column.masked));

/** Input draft as strings; nulls are a per-field checkbox. */
const draft = ref<Record<string, string>>({});
const isNull = ref<Record<string, boolean>>({});
const error = ref<string | null>(null);
const busy = ref(false);
const preview = ref<string | null>(null);
const conflict = ref<RowConflictBody | null>(null);

watch(
    () => [props.open, props.mode, props.row] as const,
    () => {
        if (!props.open) {
            return;
        }

        draft.value = {};
        isNull.value = {};
        error.value = conflict.value = preview.value = null;

        if (props.row) {
            for (const column of editable.value) {
                const value = props.row[column.name];
                draft.value[column.name] = value === null ? '' : String(value);
                isNull.value[column.name] = value === null;
            }
        }
    },
    { immediate: true },
);

/** The row's key in words, e.g. `id = 2`, for the delete confirmation. */
const keyText = computed(() =>
    props.primaryKey.map((column) => `${column} = ${String(props.row?.[column] ?? 'NULL')}`).join(', '),
);
const description = computed(() =>
    props.mode === 'delete'
        ? undefined
        : 'Only the fields you change are sent. Review SQL shows the exact statement before anything runs.',
);

const title = computed(() => (props.mode === 'insert' ? `Insert a row into ${props.schema}.${props.name}` : props.mode === 'update' ? `Edit a row of ${props.schema}.${props.name}` : `Delete a row from ${props.schema}.${props.name}`));

/** The key the server addresses the row by. */
function key(): Record<string, Cell> {
    const key: Record<string, Cell> = {};

    for (const column of props.primaryKey) {
        key[column] = props.row?.[column] ?? null;
    }

    return key;
}

/** The row as the API returned it, only the editable columns. */
function original(): Record<string, Cell> {
    const values: Record<string, Cell> = {};

    for (const column of editable.value) {
        values[column.name] = props.row?.[column.name] ?? null;
    }

    return values;
}

/** The drafted changes: a column only when it differs from the original. */
function changes(): Record<string, Cell> {
    const values: Record<string, Cell> = {};

    for (const column of editable.value) {
        const was = props.row?.[column.name] ?? null;
        const now = isNull.value[column.name] ? null : (draft.value[column.name] ?? '');

        if (props.mode === 'insert' ? now !== null && now !== '' : String(was ?? '') !== String(now ?? '') || (was === null) !== (now === null)) {
            values[column.name] = now;
        }
    }

    return values;
}

function endpointBody(previewOnly: boolean): { method: string; body: Record<string, unknown> } {
    const preview = previewOnly || undefined;

    if (props.mode === 'insert') {
        return { method: 'POST', body: { values: changes(), preview } };
    }

    if (props.mode === 'update') {
        return { method: 'PATCH', body: { key: key(), original: original(), changes: changes(), preview } };
    }

    return { method: 'DELETE', body: { key: key(), original: original(), preview } };
}

function conflictBody(e: unknown): RowConflictBody | null {
    if (!(e instanceof ApiError) || e.status !== 409) {
        return null;
    }

    const body = e.body;
    const errorPart = body && typeof body === 'object' && 'error' in body ? (body as { error?: { message?: unknown } }).error : null;
    const current = body && typeof body === 'object' && 'current' in body ? (body as { current: unknown }).current : null;
    const message = typeof errorPart?.message === 'string' ? errorPart.message : e.message;

    if (current !== null && typeof current !== 'object') {
        return null;
    }

    return { message, current: (current as Record<string, Cell> | null) ?? null };
}

async function review(): Promise<void> {
    error.value = conflict.value = null;
    preview.value = null;
    busy.value = true;

    try {
        const { method, body } = endpointBody(true);
        const response = await api<{ data: { sql: string } }>(path(), { method, body });

        preview.value = response.data.sql;
    } catch (e) {
        error.value = describeError(e);
    } finally {
        busy.value = false;
    }
}

function path(): string {
    return `${segment(props.label)}/rows/${segment(props.schema)}/${segment(props.name)}`;
}

async function save(): Promise<void> {
    error.value = conflict.value = null;
    preview.value = null;
    busy.value = true;

    try {
        const { method, body } = endpointBody(false);
        await api(path(), { method, body });
        emit('close', true);
    } catch (e) {
        const body409 = conflictBody(e);

        if (body409) {
            conflict.value = body409;
        } else {
            error.value = describeError(e);
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <ModalDialog :open="open" :title="title" :description="description" :wide="mode !== 'delete'" @close="emit('close', false)">
        <form class="space-y-4" @submit.prevent="save">
            <div v-if="error" role="alert" class="dbx-callout dbx-callout-danger"><BaseIcon name="alert-circle" /><span>{{ error }}</span></div>

            <div v-if="conflict" role="alert" class="dbx-callout dbx-callout-warning">
                <BaseIcon name="alert" />
                <div>
                    <p class="font-medium">{{ conflict.message }}</p>
                    <p class="mt-0.5">Someone changed or deleted this row after you loaded it, so nothing was saved. Close this dialog and reload the table to see the row as it is now.</p>
                </div>
            </div>

            <div v-if="mode === 'delete'" class="dbx-callout dbx-callout-danger">
                <BaseIcon name="alert" />
                <div>
                    <p>
                        Deletes the row where <code class="rounded-sm bg-danger-line/40 px-1 font-mono text-xs">{{ keyText }}</code>
                        from <strong class="font-semibold">{{ schema }}.{{ name }}</strong>.
                    </p>
                    <p class="mt-0.5">This can't be undone from DbExplorer. You'll be asked for your password.</p>
                </div>
            </div>

            <fieldset v-else class="divide-y divide-line-subtle rounded-md border border-line">
                <legend class="sr-only">Values</legend>
                <div v-for="column in columns" :key="column.name" class="grid grid-cols-[11rem_1fr_4.5rem] items-center gap-3 px-3 py-1.5">
                    <label :for="`field-${column.name}`" class="min-w-0" :title="column.name">
                        <span class="flex items-center gap-1 text-sm font-medium">
                            <span class="truncate">{{ column.name }}</span>
                            <BaseIcon v-if="primaryKey.includes(column.name)" name="key" :size="12" class="text-accent" />
                            <span v-if="primaryKey.includes(column.name)" class="sr-only">(primary key)</span>
                        </span>
                        <span class="block truncate font-mono text-[11px] text-fg-subtle">{{ column.type }}</span>
                    </label>

                    <template v-if="column.masked">
                        <input class="dbx-input font-mono" :value="row?.[column.name] === null ? '' : '••••••'" disabled title="Masked columns are never editable">
                        <span class="inline-flex items-center gap-1 text-xs text-fg-subtle" title="Masked columns are never sent"><BaseIcon name="lock" :size="12" />masked</span>
                    </template>
                    <template v-else-if="mode === 'update' && primaryKey.includes(column.name)">
                        <input :id="`field-${column.name}`" class="dbx-input font-mono" :value="draft[column.name] ?? ''" disabled title="The primary key identifies the row and cannot change">
                        <span class="text-xs text-fg-subtle">key</span>
                    </template>
                    <template v-else>
                        <input
                            :id="`field-${column.name}`"
                            v-model="draft[column.name]"
                            :disabled="isNull[column.name]"
                            class="dbx-input font-mono"
                            :placeholder="isNull[column.name] ? 'NULL' : mode === 'insert' ? 'default' : ''"
                        >
                        <label class="dbx-check text-xs" title="Send NULL for this column">
                            <input v-model="isNull[column.name]" type="checkbox"> NULL
                        </label>
                    </template>
                </div>
            </fieldset>

            <div v-if="preview">
                <p class="dbx-label">SQL that will run</p>
                <CodeBlock :code="preview" copy-label="Copy SQL" />
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-line pt-4">
                <button type="button" class="dbx-btn dbx-btn-ghost mr-auto" :disabled="busy" :autofocus="mode === 'delete'" @click="emit('close', false)">Cancel</button>
                <button type="button" class="dbx-btn" :disabled="busy" @click="review"><BaseIcon name="search" />Review SQL</button>
                <button type="submit" class="dbx-btn" :class="mode === 'delete' ? 'dbx-btn-danger' : 'dbx-btn-primary'" :disabled="busy">
                    <span v-if="busy" class="dbx-spinner border-current/30 border-t-current" aria-hidden="true" />
                    <BaseIcon v-else :name="mode === 'delete' ? 'trash' : mode === 'insert' ? 'plus' : 'check'" />
                    {{ busy ? 'Working…' : mode === 'delete' ? 'Delete row' : mode === 'insert' ? 'Insert row' : 'Save changes' }}
                </button>
            </div>
        </form>
    </ModalDialog>
</template>
