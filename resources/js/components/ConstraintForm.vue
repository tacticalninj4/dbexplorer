<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import * as catalog from '../composables/catalog';
import { constraintOp, ddlFailure, emptyConstraint, executeDdl, fieldLabel, previewDdl } from '../composables/ddl';
import type { ConstraintDraft, DdlFailure, DdlOp } from '../composables/ddl';
import type { Constraint, DdlPreview } from '../types';
import BaseIcon from './BaseIcon.vue';
import ConstraintFields from './ConstraintFields.vue';
import DdlPreviewPanel from './DdlPreviewPanel.vue';
import ModalDialog from './ModalDialog.vue';

/**
 * Add a primary key, unique, check or foreign key constraint to a table, or
 * drop one (PRD §7.4, admins only). Both show the server's SQL first. A drop
 * is destructive: it is styled as such, states the consequence, and the
 * server asks for the password (423) before it runs.
 */
const props = defineProps<{
    open: boolean;
    mode: 'add' | 'drop';
    label: string;
    schema: string;
    table: string;
    /** The constraint to drop. */
    constraint?: Constraint | null;
}>();

const emit = defineEmits<{ close: [done: { title: string; run?: number } | null] }>();

const draft = ref<ConstraintDraft>(emptyConstraint());
const columns = ref<string[]>([]);
const cascade = ref(false);
const preview = ref<DdlPreview | null>(null);
const failure = ref<DdlFailure | null>(null);
const busy = ref(false);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        draft.value = emptyConstraint('unique', props.schema);
        cascade.value = false;
        preview.value = null;
        failure.value = null;

        if (props.mode === 'add') {
            catalog.columns(props.label, props.schema, props.table)
                .then((list) => (columns.value = list.map((column) => column.name)))
                .catch(() => (columns.value = []));
        }
    },
    { immediate: true },
);

watch([draft, cascade], () => (preview.value = null), { deep: true });

const title = computed(() => (props.mode === 'add' ? `Add a constraint to ${props.schema}.${props.table}` : `Drop constraint ${props.constraint?.name ?? ''}`));

/** What a drop loses, in plain words. */
const consequence = computed(() => {
    const target = props.constraint;

    if (!target) {
        return '';
    }

    const what: Record<Constraint['type'], string> = {
        primary_key: 'The table loses its primary key: rows are no longer guaranteed unique, the data grid can no longer edit them, and foreign keys that reference it block the drop unless you cascade.',
        unique: 'Duplicate values become allowed in these columns, and foreign keys that reference this key block the drop unless you cascade.',
        check: 'The database stops checking this rule on new and changed rows.',
        foreign_key: 'The database stops checking that these values exist in the referenced table.',
        exclusion: 'The database stops enforcing this exclusion rule.',
        trigger: 'The constraint trigger stops firing.',
    };

    return `${what[target.type]}${cascade.value ? ' CASCADE also drops the objects that depend on it.' : ''}`;
});

function operation(): DdlOp {
    if (props.mode === 'drop') {
        return { op: 'drop_constraint', schema: props.schema, table: props.table, name: props.constraint?.name ?? '', ...(cascade.value ? { cascade: true } : {}) };
    }

    return { op: 'add_constraint', schema: props.schema, table: props.table, constraint: constraintOp(draft.value) };
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
        const result = await executeDdl(props.label, operation());
        emit('close', { title: props.mode === 'add' ? 'Add constraint' : `Drop constraint ${props.constraint?.name ?? ''}`, run: result.run });
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
        :title="title"
        :description="mode === 'add' ? 'Pick the kind of constraint and its columns, review the SQL, then add it. Adding fails if existing rows break the rule.' : `On ${schema}.${table}. Review the SQL before anything runs.`"
        :wide="mode === 'add'"
        @close="emit('close', null)"
    >
        <form class="space-y-4" novalidate @submit.prevent="build">
            <ConstraintFields
                v-if="mode === 'add'"
                id="dbx-add-constraint"
                v-model="draft"
                :label="label"
                :columns="columns"
                prefix="constraint."
                :errors="failure?.fields"
            />

            <template v-else-if="constraint">
                <dl class="dbx-kv">
                    <dt>Definition</dt><dd class="font-mono text-xs break-all">{{ constraint.definition }}</dd>
                </dl>
                <label class="dbx-check"><input v-model="cascade" type="checkbox"> Cascade <span class="text-fg-subtle">(also drop what depends on it)</span></label>
            </template>

            <div v-if="failure" role="alert" class="dbx-callout dbx-callout-danger">
                <BaseIcon name="alert-circle" />
                <div class="min-w-0">
                    <p>{{ failure.message }}</p>
                    <p v-if="Object.keys(failure.fields).length" class="mt-0.5 text-xs">{{ Object.keys(failure.fields).map(fieldLabel).join(' · ') }}</p>
                </div>
            </div>

            <template v-if="preview">
                <DdlPreviewPanel :preview="preview" />
                <div v-if="mode === 'drop'" class="dbx-callout dbx-callout-danger">
                    <BaseIcon name="alert" />
                    <span>{{ consequence }} This can't be undone from DbExplorer. You'll be asked for your password.</span>
                </div>
            </template>

            <div class="flex items-center justify-end gap-2 border-t border-line pt-4">
                <button type="button" class="dbx-btn dbx-btn-ghost mr-auto" :autofocus="mode === 'drop'" @click="emit('close', null)">Cancel</button>
                <button type="submit" class="dbx-btn" :class="preview ? '' : 'dbx-btn-primary'" :disabled="busy">
                    <span v-if="busy && !preview" class="dbx-spinner" aria-hidden="true" />{{ preview ? 'Refresh preview' : 'Preview SQL' }}
                </button>
                <button v-if="preview" type="button" class="dbx-btn" :class="mode === 'drop' ? 'dbx-btn-danger' : 'dbx-btn-primary'" :disabled="busy" @click="execute">
                    <span v-if="busy" class="dbx-spinner border-white/40 border-t-white" aria-hidden="true" />
                    <BaseIcon v-else :name="mode === 'drop' ? 'trash' : 'plus'" />{{ mode === 'drop' ? 'Drop constraint' : 'Add constraint' }}
                </button>
            </div>
        </form>
    </ModalDialog>
</template>
