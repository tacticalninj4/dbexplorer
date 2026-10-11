<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import * as catalog from '../composables/catalog';
import { COMMON_TYPES, constraintOp, ddlFailure, emptyConstraint, executeDdl, fieldLabel, previewDdl } from '../composables/ddl';
import type { ConstraintDraft, DdlFailure, DdlOp } from '../composables/ddl';
import { qualified } from '../sql/identifiers';
import type { DdlPreview, Schema } from '../types';
import BaseIcon from './BaseIcon.vue';
import ConstraintFields from './ConstraintFields.vue';
import DdlPreviewPanel from './DdlPreviewPanel.vue';
import ModalDialog from './ModalDialog.vue';

/**
 * Create table (PRD §7.4), admins only: schema and name, a column builder,
 * table constraints and a comment, as a structured create_table operation.
 * Preview shows the server's SQL (and a Laravel migration when Blueprint can
 * express it); nothing runs until "Create table". Types are suggestions
 * only: the server validates every type against the database.
 */
const props = defineProps<{ open: boolean; label: string; schema: string }>();

export interface CreatedObject {
    schema: string;
    name: string;
    run?: number;
}

const emit = defineEmits<{ close: [created: CreatedObject | null] }>();

interface ColumnDraft {
    key: number;
    name: string;
    type: string;
    notNull: boolean;
    default: string;
    identity: '' | 'always' | 'by_default';
    primary: boolean;
    unique: boolean;
}

let nextKey = 0;
const schemas = ref<Schema[]>([]);
const userTypes = ref<string[]>([]);
const schemaName = ref('');
const name = ref('');
const comment = ref('');
const columns = ref<ColumnDraft[]>([]);
const constraints = ref<(ConstraintDraft & { key: number })[]>([]);
const preview = ref<DdlPreview | null>(null);
const failure = ref<DdlFailure | null>(null);
const busy = ref(false);
const columnsEl = ref<HTMLElement | null>(null);

function column(values: Partial<ColumnDraft> = {}): ColumnDraft {
    return { key: nextKey++, name: '', type: '', notNull: false, default: '', identity: '', primary: false, unique: false, ...values };
}

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        schemaName.value = props.schema;
        name.value = '';
        comment.value = '';
        columns.value = [column({ name: 'id', type: 'bigint', notNull: true, identity: 'by_default', primary: true })];
        constraints.value = [];
        preview.value = null;
        failure.value = null;
        void loadCatalog();
    },
    { immediate: true },
);

/** Schemas for the picker, and the database's enums, domains and composite types as type suggestions. */
async function loadCatalog(): Promise<void> {
    try {
        schemas.value = await catalog.schemas(props.label);
        const lists = await Promise.all(schemas.value.map((schema) => catalog.objects(props.label, schema.name).catch(() => [])));
        userTypes.value = lists
            .flat()
            .filter((object) => object.type === 'enum' || object.type === 'domain' || object.type === 'composite_type')
            .map((object) => qualified(object.schema, object.name));
    } catch {
        schemas.value = [{ name: props.schema, owner: '', comment: null }];
    }
}

// Execute sends the form as it is now: any edit after Preview needs a new preview.
watch([schemaName, name, comment, columns, constraints], () => (preview.value = null), { deep: true });

const columnNames = computed(() => columns.value.map((item) => item.name.trim()).filter((item) => item !== ''));
const primaryColumns = computed(() => columns.value.filter((item) => item.primary && item.name.trim() !== ''));
/** A composite primary key goes in as a table constraint, first. */
const compositeKey = computed(() => primaryColumns.value.length > 1);

function operation(): DdlOp {
    const tableConstraints: DdlOp[] = compositeKey.value
        ? [{ type: 'primary_key', columns: primaryColumns.value.map((item) => item.name.trim()) }]
        : [];

    return {
        op: 'create_table',
        schema: schemaName.value,
        name: name.value.trim(),
        columns: columns.value.map((item) => ({
            name: item.name.trim(),
            type: item.type.trim(),
            nullable: !item.notNull,
            ...(item.default.trim() === '' ? {} : { default: item.default.trim() }),
            ...(item.identity === '' ? {} : { identity: item.identity }),
            ...(item.primary && !compositeKey.value ? { primary: true } : {}),
            ...(item.unique ? { unique: true } : {}),
        })),
        ...(tableConstraints.length + constraints.value.length > 0 ? { constraints: [...tableConstraints, ...constraints.value.map(constraintOp)] } : {}),
        ...(comment.value.trim() === '' ? {} : { comment: comment.value.trim() }),
    };
}

/** The server's field error for a column field, if any. */
function columnError(index: number, field: string): string | undefined {
    return failure.value?.fields[`columns.${index}.${field}`];
}

/** Server paths count the composite key constraint first: map them back to the form's list. */
const constraintErrors = computed(() => {
    const fields = failure.value?.fields ?? {};
    const offset = compositeKey.value ? 1 : 0;
    const mapped: Record<string, string> = {};

    for (const [path, message] of Object.entries(fields)) {
        const match = /^constraints\.(\d+)\.(.*)$/.exec(path);
        if (match) {
            mapped[`constraints.${Number(match[1]) - offset}.${match[2]}`] = message;
        }
    }

    return mapped;
});

function addColumn(): void {
    columns.value.push(column());
    void nextTick(() => columnsEl.value?.querySelector<HTMLInputElement>('[data-row]:last-child input')?.focus());
}

function removeColumn(index: number): void {
    columns.value.splice(index, 1);
}

function onIdentity(item: ColumnDraft): void {
    if (item.identity !== '') {
        item.default = '';
        item.notNull = true;
    }
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
        title="Create table"
        description="Define the columns and constraints, review the SQL, then create the table. Nothing runs until the last step."
        wide
        @close="emit('close', null)"
    >
        <form class="space-y-5" novalidate @submit.prevent="build">
            <div class="grid grid-cols-[minmax(0,12rem)_1fr] gap-3">
                <label>
                    <span class="dbx-label">Schema</span>
                    <select v-model="schemaName" class="dbx-select w-full" :aria-invalid="failure?.fields.schema ? 'true' : undefined">
                        <option v-for="item in schemas" :key="item.name" :value="item.name">{{ item.name }}</option>
                    </select>
                </label>
                <label>
                    <span class="dbx-label">Table name</span>
                    <input v-model="name" class="dbx-input w-full font-mono" required spellcheck="false" autocomplete="off" :aria-invalid="failure?.fields.name ? 'true' : undefined">
                </label>
            </div>

            <fieldset>
                <legend class="mb-1.5 flex w-full items-center text-sm font-semibold">Columns</legend>
                <datalist id="dbx-create-table-types">
                    <option v-for="type in [...COMMON_TYPES, ...userTypes]" :key="type" :value="type" />
                </datalist>
                <ol ref="columnsEl" class="divide-y divide-line-subtle rounded-md border border-line">
                    <li v-for="(item, index) in columns" :key="item.key" data-row class="space-y-2 px-3 py-2.5">
                        <div class="grid grid-cols-[1fr_1fr_auto] items-center gap-2">
                            <input
                                v-model="item.name"
                                class="dbx-input w-full font-mono"
                                :aria-label="`Column ${index + 1} name`"
                                placeholder="name"
                                spellcheck="false"
                                autocomplete="off"
                                :aria-invalid="columnError(index, 'name') ? 'true' : undefined"
                            >
                            <input
                                v-model="item.type"
                                class="dbx-input w-full font-mono"
                                list="dbx-create-table-types"
                                :aria-label="`Column ${index + 1} type`"
                                placeholder="type, e.g. text"
                                spellcheck="false"
                                autocomplete="off"
                                :aria-invalid="columnError(index, 'type') ? 'true' : undefined"
                            >
                            <button
                                type="button"
                                class="dbx-btn dbx-btn-ghost dbx-btn-icon"
                                :disabled="columns.length === 1"
                                :aria-label="`Remove column ${index + 1}${item.name ? ` (${item.name})` : ''}`"
                                title="Remove column"
                                @click="removeColumn(index)"
                            >
                                <BaseIcon name="trash" />
                            </button>
                        </div>
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                            <input
                                v-model="item.default"
                                class="dbx-input w-48 font-mono"
                                :aria-label="`Column ${index + 1} default`"
                                :placeholder="item.identity ? 'identity: no default' : 'default, e.g. now()'"
                                :disabled="item.identity !== ''"
                                spellcheck="false"
                                autocomplete="off"
                                :aria-invalid="columnError(index, 'default') ? 'true' : undefined"
                            >
                            <select v-model="item.identity" class="dbx-select" :aria-label="`Column ${index + 1} identity`" @change="onIdentity(item)">
                                <option value="">No identity</option>
                                <option value="by_default">Identity (by default)</option>
                                <option value="always">Identity (always)</option>
                            </select>
                            <label class="dbx-check"><input v-model="item.notNull" type="checkbox"> Not null</label>
                            <label class="dbx-check"><input v-model="item.primary" type="checkbox"> Primary key</label>
                            <label class="dbx-check"><input v-model="item.unique" type="checkbox"> Unique</label>
                        </div>
                    </li>
                </ol>
                <div class="mt-2 flex items-center gap-3">
                    <button type="button" class="dbx-btn dbx-btn-sm" @click="addColumn"><BaseIcon name="plus" />Add column</button>
                    <span v-if="compositeKey" class="text-xs text-fg-muted">{{ primaryColumns.length }} primary key columns: added as one composite key.</span>
                    <span v-else class="text-xs text-fg-subtle">Defaults are raw SQL. Suggestions include this database's enums and domains.</span>
                </div>
            </fieldset>

            <fieldset>
                <legend class="mb-1.5 text-sm font-semibold">Table constraints <span class="font-normal text-fg-subtle">(optional)</span></legend>
                <ol v-if="constraints.length" class="space-y-2">
                    <li v-for="(item, index) in constraints" :key="item.key" class="rounded-md border border-line p-3">
                        <div class="mb-2 flex items-center">
                            <span class="text-xs font-medium text-fg-muted">Constraint {{ index + 1 }}</span>
                            <button type="button" class="dbx-btn dbx-btn-ghost dbx-btn-sm ml-auto" :aria-label="`Remove constraint ${index + 1}`" @click="constraints.splice(index, 1)"><BaseIcon name="x" />Remove</button>
                        </div>
                        <ConstraintFields
                            :id="`dbx-ct-c${item.key}`"
                            :model-value="item"
                            :label="label"
                            :columns="columnNames"
                            :prefix="`constraints.${index}.`"
                            :errors="constraintErrors"
                        />
                    </li>
                </ol>
                <button type="button" class="dbx-btn dbx-btn-sm" :class="constraints.length ? 'mt-2' : ''" @click="constraints.push({ ...emptyConstraint('foreign_key', schemaName), key: nextKey++ })">
                    <BaseIcon name="plus" />Add constraint
                </button>
            </fieldset>

            <label class="block">
                <span class="dbx-label">Comment <span class="font-normal text-fg-subtle">(optional)</span></span>
                <input v-model="comment" class="dbx-input w-full">
            </label>

            <div v-if="failure" role="alert" class="dbx-callout dbx-callout-danger">
                <BaseIcon name="alert-circle" />
                <div class="min-w-0">
                    <p>{{ failure.message }}</p>
                    <ul v-if="Object.keys(failure.fields).length > 1" class="mt-1 list-disc pl-4 text-xs">
                        <li v-for="(message, field) in failure.fields" :key="field">{{ fieldLabel(String(field)) }}: {{ message }}</li>
                    </ul>
                    <p v-else-if="Object.keys(failure.fields).length === 1" class="mt-0.5 text-xs">{{ fieldLabel(Object.keys(failure.fields)[0] ?? '') }}</p>
                </div>
            </div>

            <DdlPreviewPanel v-if="preview" :preview="preview" />

            <div class="flex items-center justify-end gap-2 border-t border-line pt-4">
                <button type="button" class="dbx-btn dbx-btn-ghost mr-auto" @click="emit('close', null)">Cancel</button>
                <button type="submit" class="dbx-btn" :class="preview ? '' : 'dbx-btn-primary'" :disabled="busy">
                    <span v-if="busy && !preview" class="dbx-spinner" aria-hidden="true" />{{ preview ? 'Refresh preview' : 'Preview SQL' }}
                </button>
                <button v-if="preview" type="button" class="dbx-btn dbx-btn-primary" :disabled="busy" @click="execute">
                    <span v-if="busy" class="dbx-spinner border-white/40 border-t-white" aria-hidden="true" />
                    <BaseIcon v-else name="play" />Create table
                </button>
            </div>
        </form>
    </ModalDialog>
</template>
