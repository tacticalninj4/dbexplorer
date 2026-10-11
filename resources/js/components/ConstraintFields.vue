<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import * as catalog from '../composables/catalog';
import { CONSTRAINT_TYPES, FK_ACTIONS } from '../composables/ddl';
import type { ConstraintDraft } from '../composables/ddl';
import type { DbObject, Schema } from '../types';

/**
 * The fields of one table constraint (primary key, unique, check or foreign
 * key), for the create-table and add-constraint forms. Foreign key targets
 * are picked from the catalog: schema, then table, then its columns (the
 * primary key is preselected). `errors` are the server's field errors under
 * `prefix` (e.g. `constraint.`), shown on the matching control.
 */
const model = defineModel<ConstraintDraft>({ required: true });

const props = withDefaults(defineProps<{
    label: string;
    /** The columns the constraint can use. */
    columns: string[];
    /** Unique per form, for ids. */
    id: string;
    prefix?: string;
    errors?: Record<string, string>;
    /** Hide the type picker (the type is fixed by the caller). */
    fixedType?: boolean;
}>(), { prefix: '', errors: () => ({}), fixedType: false });

const schemas = ref<Schema[]>([]);
const tables = ref<DbObject[]>([]);
const targetColumns = ref<string[]>([]);
const loadError = ref<string | null>(null);

function error(field: string): string | undefined {
    return props.errors[`${props.prefix}${field}`];
}

/** Click order is key order, so composite keys come out as picked. */
function toggle(list: string[], name: string): string[] {
    return list.includes(name) ? list.filter((item) => item !== name) : [...list, name];
}

const usable = computed(() => props.columns.filter((name) => name.trim() !== ''));

watch(
    () => model.value.type,
    (type) => {
        if (type === 'foreign_key' && schemas.value.length === 0) {
            catalog.schemas(props.label).then((list) => {
                schemas.value = list;
                if (model.value.refSchema === '' && list[0]) {
                    model.value.refSchema = list.some((schema) => schema.name === 'public') ? 'public' : list[0].name;
                }
            }).catch(() => (loadError.value = 'Could not load schemas.'));
        }
    },
    { immediate: true },
);

watch(
    () => [model.value.type, model.value.refSchema] as const,
    ([type, schema]) => {
        tables.value = [];
        if (type !== 'foreign_key' || schema === '') {
            return;
        }

        catalog.objects(props.label, schema).then((objects) => {
            if (model.value.refSchema === schema) {
                tables.value = objects.filter((object) => object.type === 'table' || object.type === 'partitioned_table');
            }
        }).catch(() => (loadError.value = `Could not load the tables of ${schema}.`));
    },
    { immediate: true },
);

watch(
    () => [model.value.type, model.value.refSchema, model.value.refTable] as const,
    ([type, schema, table], previous) => {
        targetColumns.value = [];
        if (type !== 'foreign_key' || schema === '' || table === '') {
            return;
        }

        const changed = previous !== undefined && (previous[1] !== schema || previous[2] !== table);
        if (changed) {
            model.value.refColumns = [];
        }

        Promise.all([catalog.columns(props.label, schema, table), catalog.primaryKey(props.label, schema, table)]).then(([columns, key]) => {
            if (model.value.refSchema !== schema || model.value.refTable !== table) {
                return;
            }

            targetColumns.value = columns.map((column) => column.name);
            if (model.value.refColumns.length === 0 && key) {
                model.value.refColumns = [...key];
            }
        }).catch(() => (loadError.value = `Could not load the columns of ${schema}.${table}.`));
    },
    { immediate: true },
);
</script>

<template>
    <div class="space-y-3">
        <div class="grid grid-cols-2 gap-3">
            <label v-if="!fixedType">
                <span class="dbx-label">Type</span>
                <select v-model="model.type" class="dbx-select w-full">
                    <option v-for="entry in CONSTRAINT_TYPES" :key="entry.value" :value="entry.value">{{ entry.title }}</option>
                </select>
            </label>
            <label>
                <span class="dbx-label">Name <span class="font-normal text-fg-subtle">(optional)</span></span>
                <input v-model="model.name" class="dbx-input w-full font-mono" placeholder="PostgreSQL picks one" :aria-invalid="error('name') ? 'true' : undefined" spellcheck="false" autocomplete="off">
            </label>
        </div>

        <label v-if="model.type === 'check'" class="block">
            <span class="dbx-label">Check expression</span>
            <input
                v-model="model.expression"
                class="dbx-input w-full font-mono"
                placeholder="e.g. price >= 0"
                spellcheck="false"
                autocomplete="off"
                :aria-invalid="error('expression') ? 'true' : undefined"
                :aria-describedby="`${id}-check-hint`"
            >
            <span :id="`${id}-check-hint`" class="mt-1 block text-xs text-fg-subtle">Raw SQL, sent to PostgreSQL as written.</span>
        </label>

        <fieldset v-else :aria-invalid="error('columns') ? 'true' : undefined">
            <legend class="dbx-label">{{ model.type === 'foreign_key' ? 'Columns (this table)' : 'Columns' }}</legend>
            <p v-if="usable.length === 0" class="text-sm text-fg-subtle">Add columns first.</p>
            <div v-else class="flex flex-wrap gap-x-4 gap-y-1">
                <label v-for="name in usable" :key="name" class="dbx-check font-mono text-xs">
                    <input type="checkbox" :checked="model.columns.includes(name)" @change="model.columns = toggle(model.columns, name)">
                    {{ name }}
                </label>
            </div>
            <p v-if="model.columns.length > 1" class="mt-1 text-xs text-fg-subtle">Key order: <span class="font-mono">{{ model.columns.join(', ') }}</span></p>
        </fieldset>

        <fieldset v-if="model.type === 'foreign_key'" class="space-y-3 rounded-md border border-line p-3">
            <legend class="px-1 text-xs font-medium text-fg-muted">References</legend>
            <div class="grid grid-cols-2 gap-3">
                <label>
                    <span class="dbx-label">Schema</span>
                    <select v-model="model.refSchema" class="dbx-select w-full" :aria-invalid="error('references.schema') ? 'true' : undefined">
                        <option v-for="schema in schemas" :key="schema.name" :value="schema.name">{{ schema.name }}</option>
                    </select>
                </label>
                <label>
                    <span class="dbx-label">Table</span>
                    <select v-model="model.refTable" class="dbx-select w-full" :aria-invalid="error('references.table') ? 'true' : undefined">
                        <option value="" disabled>Choose a table…</option>
                        <option v-for="table in tables" :key="table.name" :value="table.name">{{ table.name }}</option>
                    </select>
                </label>
            </div>
            <div v-if="targetColumns.length" role="group" :aria-label="`Referenced columns of ${model.refTable}`">
                <p class="dbx-label">Referenced columns <span class="font-normal text-fg-subtle">(primary key preselected)</span></p>
                <div class="flex flex-wrap gap-x-4 gap-y-1">
                    <label v-for="name in targetColumns" :key="name" class="dbx-check font-mono text-xs">
                        <input type="checkbox" :checked="model.refColumns.includes(name)" @change="model.refColumns = toggle(model.refColumns, name)">
                        {{ name }}
                    </label>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <label>
                    <span class="dbx-label">On delete</span>
                    <select v-model="model.onDelete" class="dbx-select w-full">
                        <option value="">Default (no action)</option>
                        <option v-for="action in FK_ACTIONS" :key="action" :value="action">{{ action.toUpperCase() }}</option>
                    </select>
                </label>
                <label>
                    <span class="dbx-label">On update</span>
                    <select v-model="model.onUpdate" class="dbx-select w-full">
                        <option value="">Default (no action)</option>
                        <option v-for="action in FK_ACTIONS" :key="action" :value="action">{{ action.toUpperCase() }}</option>
                    </select>
                </label>
            </div>
            <p v-if="loadError" role="alert" class="text-xs text-danger-fg">{{ loadError }}</p>
        </fieldset>
    </div>
</template>
