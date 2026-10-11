<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { ApiError, api, describeError, segment } from '../api';
import type { Cell, ResultColumn, RowConflictBody } from '../types';
import BaseBadge from './BaseBadge.vue';
import CodeBlock from './CodeBlock.vue';
import BaseIcon from './BaseIcon.vue';
import ModalDialog from './ModalDialog.vue';

/**
 * The M2 row writer modal (PRD §7.2): insert a row, edit a row or delete
 * one, with the generated SQL shown before it runs ("Review SQL") and a 409
 * conflict answered with the row as it is now. Masked columns are never
 * editable and never sent. The server owns every rule: destructive deletes
 * ask for the password through api()'s shared 423 flow.
 *
 * After a 409 on an update (F25c) the dialog shows each edited field as you
 * loaded it, as you set it and as it is now, then offers "Reload row" (start
 * over from the current row) or "Overwrite with mine": the same changes,
 * re-sent with the current row as `original` and `overwrite: true`. That is
 * still conflict-checked (another change means another 409) and is marked
 * in the audit log. An insert with no values is a 422 (F25d): the dialog
 * then offers an explicit "Insert a row with all defaults" (`defaults: true`).
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

/** `changed`: something was written, or a conflict showed the table is stale: reload it. */
const emit = defineEmits<{ close: [changed: boolean] }>();

const editable = computed(() => props.columns.filter((column) => !column.masked));

/** Input draft as strings; nulls are a per-field checkbox. */
const draft = ref<Record<string, string>>({});
const isNull = ref<Record<string, boolean>>({});
const error = ref<string | null>(null);
const busy = ref(false);
const preview = ref<string | null>(null);
/** A 409, with the changes that hit it (`mine`), so they can be re-sent unchanged. */
const conflict = ref<(RowConflictBody & { mine: Record<string, Cell>; loaded: Record<string, Cell> }) | null>(null);
/** The row the form edits: the selected row, or the current row after "Reload row". */
const base = ref<Record<string, Cell> | null>(null);
/** The server refused an insert with no values: offer the explicit all-defaults insert. */
const askDefaults = ref(false);
/** A conflict was seen: the grid behind the dialog is stale. */
let stale = false;

watch(
    () => [props.open, props.mode, props.row] as const,
    () => {
        if (!props.open) {
            return;
        }

        stale = false;
        load(props.row);
    },
    { immediate: true },
);

/** Start the form over from `row`. */
function load(row: Record<string, Cell> | null): void {
    base.value = row;
    draft.value = {};
    isNull.value = {};
    error.value = conflict.value = preview.value = null;
    askDefaults.value = false;

    if (row) {
        for (const column of editable.value) {
            const value = row[column.name] ?? null;
            draft.value[column.name] = value === null ? '' : String(value);
            isNull.value[column.name] = value === null;
        }
    }
}

function close(): void {
    emit('close', stale);
}

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
        key[column] = base.value?.[column] ?? null;
    }

    return key;
}

/** The row as the API returned it, only the editable columns. */
function original(): Record<string, Cell> {
    const values: Record<string, Cell> = {};

    for (const column of editable.value) {
        values[column.name] = base.value?.[column.name] ?? null;
    }

    return values;
}

/** The drafted changes: a column only when it differs from the original. */
function changes(): Record<string, Cell> {
    const values: Record<string, Cell> = {};

    for (const column of editable.value) {
        const was = base.value?.[column.name] ?? null;
        const now = isNull.value[column.name] ? null : (draft.value[column.name] ?? '');

        if (props.mode === 'insert' ? now !== null && now !== '' : String(was ?? '') !== String(now ?? '') || (was === null) !== (now === null)) {
            values[column.name] = now;
        }
    }

    return values;
}

function endpointBody(previewOnly: boolean, defaults = false): { method: string; body: Record<string, unknown> } {
    const preview = previewOnly || undefined;

    if (props.mode === 'insert') {
        return { method: 'POST', body: { values: changes(), defaults: defaults || undefined, preview } };
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

/** True for the 422 an insert with no values gets (F25d). */
function isEmptyInsert(e: unknown): boolean {
    const errors = e instanceof ApiError && e.status === 422 && e.body && typeof e.body === 'object' ? (e.body as { errors?: unknown }).errors : null;

    return props.mode === 'insert' && typeof errors === 'object' && errors !== null && 'values' in errors && Object.keys(changes()).length === 0;
}

function same(a: Cell | undefined, b: Cell | undefined): boolean {
    return (a ?? null) === null ? (b ?? null) === null : (b ?? null) !== null && String(a) === String(b);
}

/** The conflict, field by field: every column you edited, plus any other column that changed meanwhile. */
const diff = computed(() => {
    const found = conflict.value;

    if (!found?.current) {
        return [];
    }

    return editable.value
        .filter((column) => column.name in found.mine || !same(found.loaded[column.name], found.current?.[column.name]))
        .map((column) => ({
            name: column.name,
            edited: column.name in found.mine,
            loaded: found.loaded[column.name] ?? null,
            mine: column.name in found.mine ? (found.mine[column.name] ?? null) : null,
            current: found.current?.[column.name] ?? null,
            moved: !same(found.loaded[column.name], found.current?.[column.name]),
        }));
});

function display(value: Cell): string {
    return value === null ? 'NULL' : value === '' ? "''" : String(value);
}

async function review(): Promise<void> {
    error.value = conflict.value = null;
    preview.value = null;
    busy.value = true;

    try {
        const { method, body } = endpointBody(true, askDefaults.value);
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

async function save(defaults = false): Promise<void> {
    const mine = changes();
    const loaded = original();
    await send(endpointBody(false, defaults), mine, loaded);
}

/**
 * "Overwrite with mine": the edits that hit the conflict, unchanged, with
 * the conflict's current row (those columns only) as the new original.
 */
async function overwrite(): Promise<void> {
    const found = conflict.value;

    if (!found?.current) {
        return;
    }

    const original: Record<string, Cell> = {};
    for (const column of Object.keys(found.mine)) {
        original[column] = found.current[column] ?? null;
    }

    await send({ method: 'PATCH', body: { key: key(), original, changes: found.mine, overwrite: true } }, found.mine, found.loaded);
}

/** "Reload row": start over from the row as it is now; your edits are dropped. */
function reloadRow(): void {
    const current = conflict.value?.current ?? null;

    if (current) {
        load(current);
    }
}

async function send(request: { method: string; body: Record<string, unknown> }, mine: Record<string, Cell>, loaded: Record<string, Cell>): Promise<void> {
    error.value = conflict.value = null;
    preview.value = null;
    askDefaults.value = false;
    busy.value = true;

    try {
        await api(path(), request);
        emit('close', true);
    } catch (e) {
        const body409 = conflictBody(e);

        if (body409) {
            stale = true;
            conflict.value = { ...body409, mine, loaded };
        } else if (isEmptyInsert(e)) {
            askDefaults.value = true;
        } else {
            error.value = describeError(e);
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <ModalDialog :open="open" :title="title" :description="description" :wide="mode !== 'delete'" @close="close">
        <form class="space-y-4" @submit.prevent="save()">
            <div v-if="error" role="alert" class="dbx-callout dbx-callout-danger"><BaseIcon name="alert-circle" /><span>{{ error }}</span></div>

            <div v-if="conflict" role="alert" class="dbx-callout dbx-callout-warning">
                <BaseIcon name="alert" />
                <div class="min-w-0 flex-1">
                    <p class="font-medium">{{ conflict.message }}</p>
                    <p v-if="!conflict.current" class="mt-0.5">Someone deleted this row after you loaded it, so nothing was saved. Close this dialog to reload the table.</p>
                    <p v-else-if="mode === 'update'" class="mt-0.5">Someone changed this row after you loaded it, so nothing was saved. Compare the values, then reload the row or overwrite it with yours.</p>
                    <p v-else class="mt-0.5">Someone changed this row after you loaded it, so nothing was deleted. Reload the row to see it as it is now.</p>
                </div>
            </div>

            <div v-if="conflict?.current && diff.length" class="dbx-card overflow-x-auto">
                <table class="dbx-table">
                    <caption class="sr-only">Your values against the row as it is now</caption>
                    <thead>
                        <tr><th scope="col">Column</th><th scope="col">When you loaded it</th><th v-if="mode === 'update'" scope="col">Yours</th><th scope="col">Now</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="field in diff" :key="field.name">
                            <th scope="row" class="font-medium">{{ field.name }}</th>
                            <td class="font-mono text-xs text-fg-muted">{{ display(field.loaded) }}</td>
                            <td v-if="mode === 'update'" class="font-mono text-xs">
                                <template v-if="field.edited">{{ display(field.mine) }}</template>
                                <span v-else class="font-sans text-fg-subtle">not edited</span>
                            </td>
                            <td class="font-mono text-xs">
                                <span :class="field.moved ? 'font-semibold text-warning-fg' : 'text-fg-muted'">{{ display(field.current) }}</span>
                                <BaseBadge v-if="field.moved" tone="warning" class="ml-1.5 font-sans">changed</BaseBadge>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="conflict?.current" class="flex flex-wrap items-center justify-end gap-2">
                <p v-if="mode === 'update'" class="mr-auto text-xs text-fg-muted">Overwrite sends only the fields you edited, checked against the row as it is now. It is recorded in the audit log.</p>
                <button type="button" class="dbx-btn" :disabled="busy" @click="reloadRow"><BaseIcon name="refresh" />Reload row</button>
                <button v-if="mode === 'update'" type="button" class="dbx-btn dbx-btn-primary" :disabled="busy" @click="overwrite"><BaseIcon name="check" />Overwrite with mine</button>
            </div>

            <div v-if="askDefaults" role="alert" class="dbx-callout dbx-callout-info">
                <BaseIcon name="info" />
                <div class="min-w-0 flex-1">
                    <p class="font-medium">You haven't entered any values.</p>
                    <p class="mt-0.5">Enter at least one, or insert a row where every column takes its default value.</p>
                    <button type="button" class="dbx-btn dbx-btn-sm mt-2" :disabled="busy" @click="save(true)"><BaseIcon name="plus" />Insert a row with all defaults</button>
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
                <button type="button" class="dbx-btn dbx-btn-ghost mr-auto" :disabled="busy" :autofocus="mode === 'delete'" @click="close">Cancel</button>
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
