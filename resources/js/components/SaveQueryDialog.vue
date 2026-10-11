<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { describeError } from '../api';
import { PARAM_TYPES, saveSavedQuery } from '../composables/savedQueries';
import type { ParamType, SavedQuery, SavedQueryParam } from '../composables/savedQueries';
import { namedParameters } from '../sql/parameters';
import BaseIcon from './BaseIcon.vue';
import ModalDialog from './ModalDialog.vue';
import SqlCodeEditor from './SqlCodeEditor.vue';

/**
 * Create or edit a saved query (PRD §7.8): name, description, tags,
 * visibility, the SQL, and its typed `:parameters`. Used by the saved
 * queries page and by "Save query" in the SQL editor.
 */
const props = withDefaults(defineProps<{ open: boolean; label: string; query?: SavedQuery | null; initialSql?: string }>(), { query: null, initialSql: '' });
const emit = defineEmits<{ close: []; saved: [query: SavedQuery] }>();

interface ParamDraft {
    key: number;
    name: string;
    type: ParamType;
    options: string;
    required: boolean;
}

const name = ref('');
const description = ref('');
const tags = ref('');
const visibility = ref<'private' | 'shared'>('private');
const sql = ref('');
const params = ref<ParamDraft[]>([]);
const error = ref<string | null>(null);
const busy = ref(false);
const submitted = ref(false);
let keys = 0;

const TYPE_LABELS: Record<ParamType, string> = { string: 'Text', int: 'Whole number', date: 'Date', enum: 'One of a list' };

function draftOf(param: SavedQueryParam): ParamDraft {
    return { key: ++keys, name: param.name, type: param.type ?? 'string', options: (param.options ?? []).join(', '), required: param.required ?? true };
}

function reset(): void {
    const query = props.query;
    name.value = query?.name ?? '';
    description.value = query?.description ?? '';
    tags.value = (query?.tags ?? []).join(', ');
    visibility.value = query?.visibility ?? 'private';
    sql.value = query?.sql ?? props.initialSql;
    params.value = (query?.params ?? []).map(draftOf);
    error.value = null;
    submitted.value = false;
    // A new query from the editor: define the placeholders it already uses.
    if (!query) {
        detected.value.forEach(addParam);
    }
}

const detected = computed(() => namedParameters(sql.value));
const defined = computed(() => params.value.map((param) => param.name.trim()));
const missing = computed(() => detected.value.filter((placeholder) => !defined.value.includes(placeholder)));
const unused = computed(() => defined.value.filter((param) => param !== '' && !detected.value.includes(param)));

function addParam(placeholder = ''): void {
    params.value.push({ key: ++keys, name: placeholder, type: 'string', options: '', required: true });
}

function splitList(value: string): string[] {
    return value
        .split(',')
        .map((item) => item.trim())
        .filter((item) => item !== '');
}

const problems = computed(() => {
    const list: string[] = [];
    if (name.value.trim() === '') {
        list.push('Give the query a name.');
    }
    if (sql.value.trim() === '') {
        list.push('Write the SQL to save.');
    }
    const seen = new Set<string>();
    for (const param of params.value) {
        const paramName = param.name.trim();
        if (!/^[A-Za-z_][A-Za-z0-9_$]*$/.test(paramName)) {
            list.push(`“${paramName || '(empty)'}” is not a valid parameter name: use letters, digits and _, without the colon.`);
        } else if (seen.has(paramName)) {
            list.push(`:${paramName} is defined twice.`);
        }
        seen.add(paramName);
        if (param.type === 'enum' && splitList(param.options).length === 0) {
            list.push(`:${paramName || '…'} is a list: give it at least one option.`);
        }
    }

    return list;
});

async function submit(): Promise<void> {
    submitted.value = true;
    if (problems.value.length > 0 || busy.value) {
        return;
    }

    busy.value = true;
    error.value = null;

    try {
        const saved = await saveSavedQuery(
            props.label,
            {
                name: name.value.trim(),
                description: description.value.trim() === '' ? null : description.value,
                sql: sql.value,
                visibility: visibility.value,
                tags: splitList(tags.value),
                params: params.value.map((param) => ({
                    name: param.name.trim(),
                    type: param.type,
                    required: param.required,
                    ...(param.type === 'enum' ? { options: splitList(param.options) } : {}),
                })),
            },
            props.query?.id,
        );
        emit('saved', saved);
    } catch (e) {
        error.value = describeError(e, 'The query could not be saved.');
    } finally {
        busy.value = false;
    }
}

watch(
    () => props.open,
    (open) => open && reset(),
    { immediate: true },
);
</script>

<template>
    <ModalDialog :open="open" :title="query ? `Edit “${query.name}”` : 'Save query'" :description="query ? undefined : 'Keep this SQL with a name, typed parameters and tags, for you or for everyone on this connection.'" wide @close="emit('close')">
        <form v-if="open" class="space-y-4" novalidate @submit.prevent="submit">
            <div class="grid grid-cols-[1fr_auto] gap-3 max-sm:grid-cols-1">
                <div>
                    <label for="dbx-sq-name" class="dbx-label">Name</label>
                    <input id="dbx-sq-name" v-model="name" class="dbx-input w-full" maxlength="191" required :aria-invalid="submitted && name.trim() === ''">
                </div>
                <fieldset>
                    <legend class="dbx-label">Visibility</legend>
                    <div class="dbx-segmented" role="group" aria-label="Visibility">
                        <button type="button" :aria-pressed="visibility === 'private'" @click="visibility = 'private'"><BaseIcon name="lock" :size="12" />Private</button>
                        <button type="button" :aria-pressed="visibility === 'shared'" @click="visibility = 'shared'"><BaseIcon name="users" :size="12" />Shared</button>
                    </div>
                </fieldset>
            </div>
            <p class="-mt-2 text-xs text-fg-muted">
                {{ visibility === 'private' ? 'Only you can see and run it.' : 'Everyone who can use this connection can see and run it, within what their role allows. Only you can edit or delete it.' }}
            </p>

            <div>
                <label for="dbx-sq-description" class="dbx-label">Description <span class="font-normal text-fg-subtle">(optional)</span></label>
                <textarea id="dbx-sq-description" v-model="description" rows="2" class="dbx-input h-auto w-full py-1" />
            </div>

            <div>
                <label for="dbx-sq-tags" class="dbx-label">Tags <span class="font-normal text-fg-subtle">(comma-separated)</span></label>
                <input id="dbx-sq-tags" v-model="tags" class="dbx-input w-full" placeholder="reports, billing">
            </div>

            <div>
                <p class="dbx-label">SQL</p>
                <SqlCodeEditor v-model="sql" :label="label" aria-label="Saved query SQL" :invalid="submitted && sql.trim() === ''" />
                <p class="mt-1 text-xs text-fg-muted">Use <code class="font-mono">:name</code> for values asked for when it runs, e.g. <code class="font-mono">WHERE created_at &gt;= :since</code>.</p>
            </div>

            <fieldset class="space-y-2">
                <legend class="dbx-label">Parameters</legend>
                <div v-if="missing.length" class="dbx-callout dbx-callout-warning">
                    <BaseIcon name="alert" />
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span>The SQL uses placeholders with no definition, so it can't run yet:</span>
                        <button v-for="placeholder in missing" :key="placeholder" type="button" class="dbx-btn dbx-btn-sm font-mono" @click="addParam(placeholder)"><BaseIcon name="plus" />:{{ placeholder }}</button>
                    </div>
                </div>
                <p v-if="params.length === 0 && missing.length === 0" class="text-sm text-fg-muted">No parameters. Add one for each <code class="font-mono">:name</code> in the SQL.</p>
                <div v-for="(param, index) in params" :key="param.key" class="flex flex-wrap items-center gap-2 rounded-md border border-line bg-sunken p-2">
                    <label class="sr-only" :for="`dbx-sq-param-${param.key}`">Parameter {{ index + 1 }} name</label>
                    <span class="flex items-center font-mono text-sm text-fg-subtle" aria-hidden="true">:</span>
                    <input :id="`dbx-sq-param-${param.key}`" v-model="param.name" class="dbx-input w-36 font-mono" placeholder="name" maxlength="63">
                    <label class="sr-only" :for="`dbx-sq-type-${param.key}`">Type of :{{ param.name }}</label>
                    <select :id="`dbx-sq-type-${param.key}`" v-model="param.type" class="dbx-select">
                        <option v-for="type in PARAM_TYPES" :key="type" :value="type">{{ TYPE_LABELS[type] }}</option>
                    </select>
                    <template v-if="param.type === 'enum'">
                        <label class="sr-only" :for="`dbx-sq-options-${param.key}`">Options of :{{ param.name }}, comma-separated</label>
                        <input :id="`dbx-sq-options-${param.key}`" v-model="param.options" class="dbx-input min-w-40 flex-1" placeholder="open, closed, pending">
                    </template>
                    <label class="dbx-check" title="An optional parameter left empty binds NULL. Only queries whose parameters are all optional can be scheduled.">
                        <input v-model="param.required" type="checkbox">Required
                    </label>
                    <span v-if="unused.includes(param.name.trim())" class="text-xs text-warning-fg">not in the SQL</span>
                    <button type="button" class="dbx-btn dbx-btn-ghost dbx-btn-icon ml-auto" :aria-label="`Remove parameter ${param.name || index + 1}`" title="Remove parameter" @click="params.splice(index, 1)"><BaseIcon name="x" /></button>
                </div>
                <button type="button" class="dbx-btn dbx-btn-sm" :disabled="params.length >= 20" @click="addParam()"><BaseIcon name="plus" />Add parameter</button>
            </fieldset>

            <ul v-if="submitted && problems.length" role="alert" class="dbx-callout dbx-callout-danger list-none flex-col gap-0.5">
                <li v-for="problem in problems" :key="problem">{{ problem }}</li>
            </ul>
            <div v-if="error" role="alert" class="dbx-callout dbx-callout-danger"><BaseIcon name="alert-circle" /><span>{{ error }}</span></div>

            <div class="flex items-center justify-end gap-2 border-t border-line pt-4">
                <button type="button" class="dbx-btn dbx-btn-ghost mr-auto" @click="emit('close')">Cancel</button>
                <button type="submit" class="dbx-btn dbx-btn-primary" :disabled="busy">
                    <span v-if="busy" class="dbx-spinner border-current/30 border-t-current" aria-hidden="true" />
                    <BaseIcon v-else name="bookmark" />{{ query ? 'Save changes' : 'Save query' }}
                </button>
            </div>
        </form>
    </ModalDialog>
</template>
