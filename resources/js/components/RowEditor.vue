<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { ApiError, api, describeError, segment } from '../api';
import type { Cell, ResultColumn, RowConflictBody } from '../types';
import CodeBlock from './CodeBlock.vue';
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
    <ModalDialog :open="open" :title="title" wide @close="emit('close', false)">
        <form class="space-y-3" @submit.prevent="save">
            <p v-if="error" role="alert" class="rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{{ error }}</p>

            <div v-if="conflict" role="alert" class="rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
                <p class="font-medium">{{ conflict.message }}</p>
                <p class="mt-1">Close this dialog and reload the table to see the row as it is now.</p>
            </div>

            <div v-if="mode === 'delete'" class="rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                This deletes the row for key <code>{{ JSON.stringify(key()) }}</code> from <strong>{{ schema }}.{{ name }}</strong> and asks for your password.
            </div>

            <fieldset v-else class="space-y-2">
                <div v-for="column in columns" :key="column.name" class="grid grid-cols-[10rem_1fr_4rem] items-center gap-2">
                    <label :for="`field-${column.name}`" class="truncate text-sm text-zinc-700 dark:text-zinc-200" :title="column.name">
                        {{ column.name }}
                        <span v-if="primaryKey.includes(column.name)" class="text-xs text-sky-600 dark:text-sky-400" title="Primary key">pk</span>
                        <span class="block text-xs font-normal text-zinc-400">{{ column.type }}</span>
                    </label>

                    <template v-if="column.masked">
                        <input class="dbx-input" :value="row?.[column.name] === null ? '' : '••••••'" disabled title="Masked columns are never editable">
                        <span class="text-xs text-zinc-400" title="Masked (S14)">masked</span>
                    </template>
                    <template v-else-if="mode === 'update' && primaryKey.includes(column.name)">
                        <input :id="`field-${column.name}`" class="dbx-input" :value="draft[column.name] ?? ''" disabled title="The primary key identifies the row and cannot change">
                        <span />
                    </template>
                    <template v-else>
                        <input :id="`field-${column.name}`" v-model="draft[column.name]" :disabled="isNull[column.name]" class="dbx-input font-mono" placeholder="A value, or tick null">
                        <label class="flex items-center gap-1 text-xs text-zinc-500 dark:text-zinc-400" title="Send NULL for this column">
                            <input v-model="isNull[column.name]" type="checkbox"> null
                        </label>
                    </template>
                </div>
            </fieldset>

            <CodeBlock v-if="preview" :code="preview" copy-label="Copy SQL" />

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" class="dbx-btn" :disabled="busy" @click="emit('close', false)">Cancel</button>
                <button type="button" class="dbx-btn" :disabled="busy" @click="review">Review SQL</button>
                <button type="submit" class="dbx-btn dbx-btn-danger" :disabled="busy">
                    {{ busy ? 'Working…' : mode === 'delete' ? 'Delete row' : mode === 'insert' ? 'Insert row' : 'Save changes' }}
                </button>
            </div>
        </form>
    </ModalDialog>
</template>
