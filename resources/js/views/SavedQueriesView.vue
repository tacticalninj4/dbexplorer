<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, ApiError, describeError, segment } from '../api';
import BaseBadge from '../components/BaseBadge.vue';
import BaseIcon from '../components/BaseIcon.vue';
import ClassificationBadge from '../components/ClassificationBadge.vue';
import CodeBlock from '../components/CodeBlock.vue';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import DataGrid from '../components/DataGrid.vue';
import EmptyState from '../components/EmptyState.vue';
import ResultBar from '../components/ResultBar.vue';
import SaveQueryDialog from '../components/SaveQueryDialog.vue';
import SkeletonRows from '../components/SkeletonRows.vue';
import { sendToEditor } from '../composables/editorInbox';
import { formatDate } from '../composables/format';
import { deleteSavedQuery, isRequired, listSavedQueries } from '../composables/savedQueries';
import type { SavedQuery, SavedQueryParam } from '../composables/savedQueries';
import { findConnection } from '../composables/session';
import type { Classification, QueryResponse } from '../types';

/**
 * /:label/queries (PRD §7.8): your saved queries and the shared ones. Pick
 * one (?id=) to see its SQL, fill in its typed parameters and run it here,
 * in the background, or in the SQL editor. The run gate is the server's:
 * 409 asks to confirm, 423 for the password (api()), 403 for DDL, and for
 * DML from a viewer (no confirmation dialog for those).
 */
const route = useRoute();
const router = useRouter();
const label = computed(() => String(route.params.label));
const isAdmin = computed(() => findConnection(label.value)?.role === 'admin');
const isViewer = computed(() => findConnection(label.value)?.role === 'viewer');

const queries = ref<SavedQuery[] | null>(null);
const loadError = ref<string | null>(null);
const search = ref('');
const scope = ref<'all' | 'mine' | 'shared'>('all');
const tag = ref<string | null>(null);

const selectedId = computed(() => (typeof route.query.id === 'string' ? Number(route.query.id) : null));
const selected = computed(() => queries.value?.find((query) => query.id === selectedId.value) ?? null);

const allTags = computed(() => [...new Set((queries.value ?? []).flatMap((query) => query.tags))].sort((a, b) => a.localeCompare(b)));
const filtered = computed(() => {
    const needle = search.value.trim().toLowerCase();

    return (queries.value ?? []).filter((query) => {
        if (scope.value === 'mine' && !query.owned) {
            return false;
        }
        if (scope.value === 'shared' && (query.owned || query.visibility !== 'shared')) {
            return false;
        }
        if (tag.value !== null && !query.tags.includes(tag.value)) {
            return false;
        }

        return needle === '' || [query.name, query.description ?? '', query.sql, ...query.tags].some((text) => text.toLowerCase().includes(needle));
    });
});

async function load(): Promise<void> {
    loadError.value = null;
    try {
        queries.value = await listSavedQueries(label.value);
    } catch (e) {
        loadError.value = describeError(e, 'The saved queries could not be loaded.');
    }
}

function select(id: number | null): void {
    void router.replace({ query: { ...route.query, id: id === null ? undefined : String(id) } });
}

// Create / edit / delete ------------------------------------------------
const editing = ref<{ query: SavedQuery | null } | null>(null);
const deleting = ref<SavedQuery | null>(null);
const deleteError = ref<string | null>(null);
const deleteBusy = ref(false);

async function onSaved(query: SavedQuery): Promise<void> {
    editing.value = null;
    await load();
    select(query.id);
}

async function remove(): Promise<void> {
    const query = deleting.value;
    if (!query) {
        return;
    }
    deleteBusy.value = true;
    deleteError.value = null;
    try {
        await deleteSavedQuery(label.value, query.id);
        deleting.value = null;
        select(null);
        await load();
    } catch (e) {
        deleteError.value = describeError(e);
    } finally {
        deleteBusy.value = false;
    }
}

// Run --------------------------------------------------------------------
const values = reactive<Record<string, string>>({});
const result = ref<QueryResponse | null>(null);
const runError = ref<string | null>(null);
/** The callout's title for the last failed run: a refusal or the run cap, else none. */
const runErrorTitle = ref<string | null>(null);
const running = ref(false);
const queued = ref<number | null>(null);
const resultView = ref<'results' | 'plan' | 'messages'>('results');
const confirm = ref<Classification | null>(null);

watch(selected, (query, previous) => {
    if (query?.id === previous?.id) {
        return;
    }
    Object.keys(values).forEach((key) => delete values[key]);
    query?.params.forEach((param) => (values[param.name] = ''));
    result.value = null;
    runError.value = null;
    queued.value = null;
});

/** Only filled values are sent: an empty optional parameter binds NULL, an empty required one is the server's 422. */
function filledValues(): Record<string, string> {
    return Object.fromEntries(Object.entries(values).filter(([, value]) => value !== ''));
}

async function run(confirmed = false, background = false): Promise<void> {
    const query = selected.value;
    if (!query || running.value) {
        return;
    }
    running.value = true;
    runError.value = null;
    queued.value = null;
    try {
        const response = await api<{ data: Omit<QueryResponse, 'explain' | 'plan'> & { id?: number } }>(`${segment(label.value)}/queries/${query.id}/run`, {
            method: 'POST',
            body: { values: filledValues(), confirm: confirmed ? true : undefined, background: background ? true : undefined },
        });
        if (background) {
            queued.value = response.data.id ?? null;
        } else {
            result.value = { ...response.data, explain: null, plan: null };
            resultView.value = 'results';
        }
    } catch (e) {
        const required = e instanceof ApiError && !confirmed ? e.confirmationRequired : null;
        if (required) {
            confirm.value = required.classification;
            return;
        }
        result.value = null;
        const status = e instanceof ApiError ? e.status : 0;
        runErrorTitle.value = status === 403 ? 'Not allowed' : status === 429 ? 'Limit reached' : null;
        runError.value = e instanceof ApiError && e.status === 423 ? 'This query is destructive and needs your password. Run it again to confirm.' : describeError(e);
    } finally {
        running.value = false;
    }
}

function confirmed(): void {
    confirm.value = null;
    void run(true);
}

function openInEditor(query: SavedQuery): void {
    sendToEditor(label.value, query.sql);
    void router.push({ name: 'sql', params: { label: label.value } });
}

const TYPE_HINTS: Record<string, string> = { string: 'text', int: 'whole number', date: 'date', enum: 'one of a list' };

function paramHint(param: SavedQueryParam): string {
    return `${TYPE_HINTS[param.type ?? 'string']}${isRequired(param) ? '' : ', optional'}`;
}

const CONFIRM_TEXT: Record<Classification, string> = {
    read: 'The server asked for confirmation before running it.',
    dml: 'This query inserts, updates or deletes rows. It takes effect as soon as it succeeds.',
    ddl: 'This query creates, alters or drops database objects. It takes effect as soon as it succeeds.',
    destructive: "This query drops, truncates, or changes every row of a table. It can't be undone from DbExplorer, and you'll be asked for your password.",
    unknown: 'DbExplorer could not tell what this query does. Check it before running it.',
};

watch(label, load, { immediate: true });
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <header class="dbx-page-header">
            <BaseIcon name="bookmark" class="text-fg-subtle" />
            <h1 class="text-lg font-semibold">Saved queries</h1>
            <span class="text-sm text-fg-muted">Named SQL with typed parameters on {{ label }}: yours, and the ones shared with everyone.</span>
            <button type="button" class="dbx-btn dbx-btn-primary ml-auto" @click="editing = { query: null }"><BaseIcon name="plus" />New saved query</button>
        </header>

        <div v-if="loadError" class="p-4">
            <div role="alert" class="dbx-callout dbx-callout-danger max-w-3xl">
                <BaseIcon name="alert-circle" /><span class="flex-1">{{ loadError }}</span>
                <button type="button" class="dbx-btn dbx-btn-sm" @click="load"><BaseIcon name="refresh" />Retry</button>
            </div>
        </div>

        <div v-else class="flex min-h-0 flex-1 max-md:flex-col">
            <section class="flex w-80 shrink-0 flex-col border-r border-line bg-panel max-lg:w-64 max-md:max-h-72 max-md:w-full max-md:border-r-0 max-md:border-b" aria-label="Saved query list">
                <div class="space-y-2 border-b border-line p-2">
                    <label for="dbx-sq-search" class="sr-only">Search saved queries</label>
                    <div class="relative">
                        <BaseIcon name="search" :size="14" class="pointer-events-none absolute top-1/2 left-2 -translate-y-1/2 text-fg-subtle" />
                        <input id="dbx-sq-search" v-model="search" type="search" class="dbx-input w-full pl-7" placeholder="Search name, SQL, tags">
                    </div>
                    <div class="dbx-segmented w-full" role="group" aria-label="Show">
                        <button v-for="option in (['all', 'mine', 'shared'] as const)" :key="option" type="button" class="flex-1 justify-center" :aria-pressed="scope === option" :title="option === 'shared' ? 'Shared by others' : undefined" @click="scope = option">
                            {{ option === 'all' ? 'All' : option === 'mine' ? 'Mine' : 'Shared' }}
                        </button>
                    </div>
                    <div v-if="allTags.length" class="flex flex-wrap gap-1" role="group" aria-label="Filter by tag">
                        <button
                            v-for="name in allTags"
                            :key="name"
                            type="button"
                            class="dbx-badge cursor-default hover:text-fg"
                            :class="tag === name ? 'border-info-line bg-accent-soft text-accent-soft-fg' : ''"
                            :aria-pressed="tag === name"
                            @click="tag = tag === name ? null : name"
                        >
                            # {{ name }}
                        </button>
                    </div>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto">
                    <SkeletonRows v-if="queries === null" :rows="5" :columns="1" />
                    <EmptyState v-else-if="queries.length === 0" icon="bookmark" title="No saved queries yet">
                        Save SQL you run often, from here or with “Save query” in the SQL editor.
                    </EmptyState>
                    <EmptyState v-else-if="filtered.length === 0" icon="search" title="Nothing matches">
                        Change the search, the tag or the Mine / Shared filter.
                    </EmptyState>
                    <ul v-else class="divide-y divide-line-subtle">
                        <li v-for="query in filtered" :key="query.id">
                            <button
                                type="button"
                                class="block w-full cursor-default px-3 py-2 text-left outline-offset-[-2px] transition-colors duration-100"
                                :class="query.id === selectedId ? 'bg-accent-soft text-accent-soft-fg' : 'hover:bg-hover'"
                                :aria-current="query.id === selectedId ? 'true' : undefined"
                                @click="select(query.id)"
                            >
                                <span class="flex items-center gap-1.5">
                                    <span class="truncate font-medium">{{ query.name }}</span>
                                    <BaseIcon v-if="query.visibility === 'shared'" name="users" :size="12" class="shrink-0 opacity-70" />
                                    <span v-if="query.visibility === 'shared'" class="sr-only">(shared)</span>
                                    <span v-if="!query.owned" class="ml-auto shrink-0 text-xs opacity-80">shared with you</span>
                                </span>
                                <span v-if="query.description" class="mt-0.5 block truncate text-xs opacity-80">{{ query.description }}</span>
                                <span v-else class="mt-0.5 block truncate font-mono text-xs opacity-70">{{ query.sql }}</span>
                            </button>
                        </li>
                    </ul>
                </div>
            </section>

            <section class="flex min-h-0 min-w-0 flex-1 flex-col" aria-label="Saved query">
                <EmptyState v-if="!selected" icon="bookmark" title="Pick a saved query">
                    Its SQL, parameters and a Run button show here.
                </EmptyState>
                <template v-else>
                    <div class="max-h-[55%] shrink-0 space-y-3 overflow-y-auto border-b border-line bg-panel p-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-base font-semibold break-all">{{ selected.name }}</h2>
                            <BaseBadge :icon="selected.visibility === 'shared' ? 'users' : 'lock'">{{ selected.visibility }}</BaseBadge>
                            <BaseBadge v-for="name in selected.tags" :key="name"># {{ name }}</BaseBadge>
                            <span class="text-xs text-fg-subtle">{{ selected.owned ? 'Yours' : 'Shared with you' }} · updated {{ formatDate(selected.updated_at) }}</span>
                            <span class="ml-auto flex gap-1.5">
                                <router-link v-if="isAdmin" :to="{ name: 'schedules', params: { label }, query: { new: String(selected.id) } }" class="dbx-btn dbx-btn-sm"><BaseIcon name="calendar" />Schedule…</router-link>
                                <button v-if="selected.owned" type="button" class="dbx-btn dbx-btn-sm" @click="editing = { query: selected }"><BaseIcon name="pencil" />Edit</button>
                                <button v-if="selected.owned" type="button" class="dbx-btn dbx-btn-sm dbx-btn-danger-ghost" @click="deleting = selected; deleteError = null"><BaseIcon name="trash" />Delete</button>
                            </span>
                        </div>
                        <p v-if="selected.description" class="text-sm whitespace-pre-wrap text-fg-muted">{{ selected.description }}</p>
                        <CodeBlock :code="selected.sql" copy-label="Copy SQL" wrap />

                        <form class="space-y-3" @submit.prevent="run()">
                            <fieldset v-if="selected.params.length" class="grid grid-cols-[repeat(auto-fill,minmax(14rem,1fr))] gap-3">
                                <legend class="dbx-label mb-2">Parameters</legend>
                                <div v-for="param in selected.params" :key="param.name">
                                    <label :for="`dbx-run-${param.name}`" class="dbx-label">
                                        <span class="font-mono text-fg">:{{ param.name }}</span>
                                        <span class="font-normal text-fg-subtle"> · {{ paramHint(param) }}</span>
                                    </label>
                                    <select v-if="param.type === 'enum'" :id="`dbx-run-${param.name}`" v-model="values[param.name]" class="dbx-select w-full" :required="isRequired(param)">
                                        <option value="">{{ isRequired(param) ? 'Choose…' : '(none: NULL)' }}</option>
                                        <option v-for="option in param.options ?? []" :key="option" :value="option">{{ option }}</option>
                                    </select>
                                    <input
                                        v-else
                                        :id="`dbx-run-${param.name}`"
                                        v-model="values[param.name]"
                                        class="dbx-input w-full"
                                        :class="param.type === 'int' || param.type === 'date' ? 'font-mono' : ''"
                                        :type="param.type === 'date' ? 'date' : 'text'"
                                        :inputmode="param.type === 'int' ? 'numeric' : undefined"
                                        :pattern="param.type === 'int' ? '-?[0-9]+' : undefined"
                                        :required="isRequired(param)"
                                        :placeholder="isRequired(param) ? '' : 'empty: NULL'"
                                    >
                                </div>
                            </fieldset>
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="submit" class="dbx-btn dbx-btn-primary" :disabled="running">
                                    <span v-if="running" class="dbx-spinner border-current/30 border-t-current" aria-hidden="true" />
                                    <BaseIcon v-else name="play" />{{ running ? 'Running…' : 'Run' }}
                                </button>
                                <button type="button" class="dbx-btn" :disabled="running" title="Read-only queries can run as a background job; the result lands on the Runs page" @click="run(false, true)"><BaseIcon name="activity" />Run in background</button>
                                <button type="button" class="dbx-btn dbx-btn-ghost" title="Opens the SQL as written, :parameters included, in a new editor tab" @click="openInEditor(selected)"><BaseIcon name="terminal" />Open in SQL editor</button>
                            </div>
                        </form>
                        <div v-if="queued !== null" role="status" class="dbx-callout dbx-callout-success">
                            <BaseIcon name="check-circle" />
                            <span>Queued as run #{{ queued }}. <router-link :to="{ name: 'runs', params: { label } }" class="dbx-link font-medium">See it on the Runs page</router-link></span>
                        </div>
                    </div>

                    <div class="flex min-h-0 flex-1 flex-col bg-canvas" aria-live="polite">
                        <div v-if="runError" class="p-3">
                            <div role="alert" class="dbx-callout dbx-callout-danger">
                                <BaseIcon name="alert-circle" />
                                <div class="min-w-0 flex-1">
                                    <p v-if="runErrorTitle" class="font-semibold">{{ runErrorTitle }}</p>
                                    <pre class="font-sans whitespace-pre-wrap">{{ runError }}</pre>
                                </div>
                            </div>
                        </div>
                        <template v-else-if="result">
                            <DataGrid v-if="resultView === 'results' && result.columns.length" :columns="result.columns" :rows="result.rows" empty-text="The query returned no rows">
                                <template #toolbar><ResultBar v-model:view="resultView" :result="result" /></template>
                            </DataGrid>
                            <template v-else>
                                <div class="dbx-toolbar"><ResultBar v-model:view="resultView" :result="result" /></div>
                                <div v-if="resultView === 'messages'" class="min-h-0 flex-1 overflow-auto p-3 text-sm">
                                    <p v-if="!result.notices_supported" class="text-fg-muted">Notices unavailable: this PHP database driver can't capture NOTICE messages.</p>
                                    <p v-else-if="result.notices.length === 0" class="text-fg-muted">No notices.</p>
                                    <ul v-else class="dbx-card divide-y divide-line-subtle font-mono text-xs">
                                        <li v-for="(notice, index) in result.notices" :key="index" class="px-3 py-1.5 whitespace-pre-wrap">{{ notice }}</li>
                                    </ul>
                                </div>
                                <EmptyState v-else icon="check-circle" title="Query completed">
                                    <template v-if="result.affected_rows !== null">{{ result.affected_rows === 1 ? '1 row' : `${result.affected_rows.toLocaleString()} rows` }} affected.</template>
                                </EmptyState>
                            </template>
                        </template>
                        <EmptyState v-else icon="play" title="Run it to see the result">
                            <template v-if="isViewer">Viewers can run read queries only: a query that changes data is refused.</template>
                            <template v-else>Writes ask you to confirm first, and destructive SQL asks for your password.</template>
                        </EmptyState>
                    </div>
                </template>
            </section>
        </div>

        <SaveQueryDialog :open="editing !== null" :label="label" :query="editing?.query ?? null" @close="editing = null" @saved="onSaved" />

        <ConfirmDialog
            :open="deleting !== null"
            :title="`Delete “${deleting?.name ?? ''}”?`"
            :message="`${deleting?.visibility === 'shared' ? 'It disappears for everyone it is shared with.' : 'The saved query is removed.'} Every schedule made from it is deleted too, and this can't be undone.`"
            action="Delete saved query"
            danger
            :busy="deleteBusy"
            :error="deleteError"
            @cancel="deleting = null"
            @confirm="remove"
        />

        <ConfirmDialog
            :open="confirm !== null"
            title="Run this saved query?"
            :message="confirm ? CONFIRM_TEXT[confirm] : ''"
            :action="confirm === 'destructive' ? 'Run destructive query' : confirm === 'dml' ? 'Run DML' : confirm === 'ddl' ? 'Run DDL' : 'Run query'"
            :danger="confirm === 'destructive'"
            :warning="confirm === 'dml' || confirm === 'ddl' || confirm === 'unknown'"
            @cancel="confirm = null"
            @confirm="confirmed"
        >
            <p v-if="confirm" class="flex items-center gap-2 text-sm">Classified as <ClassificationBadge :value="confirm" /></p>
            <pre v-if="selected" class="dbx-code max-h-48 whitespace-pre-wrap">{{ selected.sql }}</pre>
        </ConfirmDialog>
    </div>
</template>
