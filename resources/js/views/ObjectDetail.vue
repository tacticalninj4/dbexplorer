<script setup lang="ts">
import { computed, defineAsyncComponent, nextTick, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, ApiError, describeError, segment } from '../api';
import CodeBlock from '../components/CodeBlock.vue';
import BaseBadge from '../components/BaseBadge.vue';
import DdlForm from '../components/DdlForm.vue';
import type { DdlOutcome } from '../components/DdlForm.vue';
import EmptyState from '../components/EmptyState.vue';
import BaseIcon from '../components/BaseIcon.vue';
import type { IconName } from '../components/BaseIcon.vue';
import SkeletonRows from '../components/SkeletonRows.vue';
import TableData from '../components/TableData.vue';
import { invalidate } from '../composables/catalog';
import { takeFlash } from '../composables/ddl';
import { sendToEditor } from '../composables/editorInbox';
import { findConnection } from '../composables/session';
import { formatBytes, formatDate, formatNumber } from '../composables/format';
import { qualified } from '../sql/identifiers';
import type { Column, Constraint, DbObject, FunctionDefinition, Index, ObjectType, Stats, Trigger } from '../types';

/**
 * /:label/:schema/:name: one object, with tabs that depend on its type. A
 * function or procedure is addressed by its signature, with `?fn=1`.
 */
const route = useRoute();
const router = useRouter();

type Tab = 'data' | 'structure' | 'indexes' | 'constraints' | 'triggers' | 'ddl' | 'stats' | 'definition' | 'overview';

const TAB_TITLES: Record<Tab, string> = {
    data: 'Data',
    structure: 'Structure',
    indexes: 'Indexes',
    constraints: 'Constraints',
    triggers: 'Triggers',
    ddl: 'DDL',
    stats: 'Stats',
    definition: 'Definition',
    overview: 'Overview',
};

/** Which tabs the API serves for each object type (Introspector relkind checks). */
const TABS: Partial<Record<ObjectType, Tab[]>> = {
    table: ['data', 'structure', 'indexes', 'constraints', 'triggers', 'ddl', 'stats'],
    partitioned_table: ['data', 'structure', 'indexes', 'constraints', 'triggers', 'ddl', 'stats'],
    foreign_table: ['data', 'structure', 'constraints', 'triggers'],
    view: ['data', 'structure', 'triggers', 'definition'],
    materialized_view: ['data', 'structure', 'indexes', 'stats', 'definition'],
};

const label = computed(() => String(route.params.label));
const schema = computed(() => String(route.params.schema));
const name = computed(() => String(route.params.name));
const isFunction = computed(() => route.query.fn === '1');

const object = ref<DbObject | null>(null);
const fn = ref<FunctionDefinition | null>(null);
const error = ref<string | null>(null);
const notFound = ref(false);
const loading = ref(false);
/** What the last schema change did, shown after its dialog closes (F25a). */
const notice = ref<{ text: string; run?: number } | null>(null);

/** M2 schema editing (PRD §7.4): admins only, and only tables have forms. */
const ddlOpen = ref(false);
const alterable = computed(() => {
    const type = object.value?.type;

    return type === 'table' || type === 'partitioned_table';
});
const isAdmin = computed(() => findConnection(label.value)?.role === 'admin');
/** Constraint forms (add, or drop the picked one) and the matview refresh dialog, loaded on first use. */
const ConstraintForm = defineAsyncComponent(() => import('../components/ConstraintForm.vue'));
const RefreshMatviewForm = defineAsyncComponent(() => import('../components/RefreshMatviewForm.vue'));
const constraintFormUsed = ref(false);
const refreshUsed = ref(false);
const constraintForm = ref<{ mode: 'add' | 'drop'; constraint?: Constraint } | null>(null);
const refreshOpen = ref(false);
watch(() => constraintForm.value, (form) => (constraintFormUsed.value ||= form !== null));
watch(() => refreshOpen.value, (open) => (refreshUsed.value ||= open));

const tabs = computed<Tab[]>(() => {
    if (isFunction.value) {
        return ['definition'];
    }

    return object.value ? (TABS[object.value.type] ?? ['overview']) : [];
});
const activeTab = computed<Tab | undefined>(() => {
    const wanted = route.query.tab;

    return tabs.value.find((tab) => tab === wanted) ?? tabs.value[0];
});

const tabData = ref<Partial<Record<Tab, unknown>>>({});
const tabError = ref<string | null>(null);
const tabLoading = ref(false);
let sequence = 0;
const inflight = new Set<string>();

const base = computed(() => `${segment(label.value)}/objects/${segment(schema.value)}/${segment(name.value)}`);
const columns = computed(() => tabData.value.structure as Column[] | undefined);
const indexes = computed(() => tabData.value.indexes as Index[] | undefined);
const constraints = computed(() => tabData.value.constraints as Constraint[] | undefined);
const triggers = computed(() => tabData.value.triggers as Trigger[] | undefined);
const ddl = computed(() => (tabData.value.ddl as { ddl: string } | undefined)?.ddl);
const stats = computed(() => tabData.value.stats as Stats | undefined);
const definition = computed(() => (tabData.value.definition as { definition: string } | undefined)?.definition);

async function loadObject(): Promise<void> {
    const id = ++sequence;
    object.value = null;
    fn.value = null;
    error.value = null;
    notFound.value = false;
    tabData.value = {};
    loading.value = true;

    try {
        if (isFunction.value) {
            const response = await api<{ data: FunctionDefinition }>(`${segment(label.value)}/functions/${segment(schema.value)}/${segment(name.value)}`);
            if (id === sequence) {
                fn.value = response.data;
            }
        } else {
            const response = await api<{ data: DbObject }>(base.value);
            if (id === sequence) {
                object.value = response.data;
            }
        }
    } catch (e) {
        if (id === sequence) {
            notFound.value = e instanceof ApiError && e.status === 404;
            error.value = notFound.value ? 'This object does not exist, or you cannot access it.' : describeError(e);
        }
    } finally {
        if (id === sequence) {
            loading.value = false;
        }
    }

    if (id === sequence) {
        void loadTab();
    }
}

async function loadTab(): Promise<void> {
    const tab = activeTab.value;
    tabError.value = null;

    const id = sequence;

    if (!tab || tab === 'data' || tab === 'overview' || isFunction.value || tabData.value[tab] !== undefined || inflight.has(`${id}:${tab}`)) {
        return;
    }

    const path = tab === 'structure' ? 'columns' : tab;
    inflight.add(`${id}:${tab}`);
    tabLoading.value = true;

    try {
        const response = await api<{ data: unknown }>(`${base.value}/${path}`);
        if (id === sequence) {
            tabData.value = { ...tabData.value, [tab]: response.data };
        }
    } catch (e) {
        if (id === sequence) {
            tabError.value = describeError(e);
        }
    } finally {
        inflight.delete(`${id}:${tab}`);
        if (id === sequence) {
            tabLoading.value = false;
        }
    }
}

function selectTab(tab: Tab, focus = false): void {
    void router.replace({ query: { ...route.query, tab } });

    if (focus) {
        void nextTick(() => document.getElementById(`dbx-tab-${tab}`)?.focus());
    }
}

function onTabKeydown(event: KeyboardEvent): void {
    const index = activeTab.value ? tabs.value.indexOf(activeTab.value) : 0;
    const moves: Record<string, number> = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: tabs.value.length - 1 };
    const target = moves[event.key];

    if (target === undefined) {
        return;
    }

    event.preventDefault();
    const tab = tabs.value[(target + tabs.value.length) % tabs.value.length];
    if (tab) {
        selectTab(tab, true);
    }
}

function queryInEditor(): void {
    const target = qualified(schema.value, name.value);
    const sql = object.value?.type === 'sequence' ? `SELECT * FROM ${target};` : `SELECT *\nFROM ${target}\nLIMIT 100;`;
    sendToEditor(label.value, sql);
    void router.push({ name: 'sql', params: { label: label.value } });
}

function relationRoute(target: { schema: string; table: string }) {
    return { name: 'object', params: { label: label.value, schema: target.schema, name: target.table }, query: { tab: 'data' } };
}

const typeLabel = computed(() => (fn.value ? fn.value.type : object.value?.type.replace(/_/g, ' ') ?? ''));
const constraintLabel: Record<Constraint['type'], string> = {
    primary_key: 'Primary key',
    unique: 'Unique',
    foreign_key: 'Foreign key',
    check: 'Check',
    exclusion: 'Exclusion',
    trigger: 'Trigger',
};

const TYPE_ICONS: Partial<Record<string, IconName>> = {
    table: 'table',
    partitioned_table: 'partition',
    foreign_table: 'table',
    view: 'view',
    materialized_view: 'matview',
    sequence: 'sequence',
    function: 'function',
    procedure: 'function',
    enum: 'type',
    domain: 'type',
    composite_type: 'type',
};
const typeIcon = computed<IconName>(() => (fn.value ? 'function' : TYPE_ICONS[object.value?.type ?? ''] ?? 'table'));

/**
 * After the DDL dialog: refresh the tree's catalog, and follow the object
 * when it was renamed or dropped (F25b). Background runs get a notice that
 * outlives the dialog, with a link to the Runs page (F25a).
 */
function onDdlClose(outcome: DdlOutcome | null): void {
    ddlOpen.value = false;

    if (!outcome) {
        return;
    }

    invalidate(label.value);

    if (outcome.op === 'drop_table' && outcome.run === undefined) {
        void router.push({ name: 'connection', params: { label: label.value }, query: { dropped: `${schema.value}.${name.value}` } });
        return;
    }

    if (outcome.op === 'rename_table' && outcome.to && outcome.run === undefined) {
        keepNotice = true;
        void router.replace({ name: 'object', params: { label: label.value, schema: schema.value, name: outcome.to }, query: route.query });
        notice.value = { text: `Renamed ${schema.value}.${name.value} to ${schema.value}.${outcome.to}.` };
        return;
    }

    notice.value = outcome.run !== undefined
        ? { text: `${outcome.title} was queued as background run #${outcome.run}: CONCURRENTLY can't run inside a transaction.`, run: outcome.run }
        : { text: `${outcome.title}: done.` };
    void loadObject();
}

/**
 * After a constraint form or a matview refresh: the cached constraints (and
 * the tab's data) are stale. A background run gets the queued-run notice.
 */
function onSchemaChange(done: { title: string; run?: number } | null): void {
    constraintForm.value = null;
    refreshOpen.value = false;

    if (!done) {
        return;
    }

    invalidate(label.value);
    notice.value = done.run !== undefined
        ? { text: `${done.title} was queued as background run #${done.run}: it can't run inside a transaction.`, run: done.run }
        : { text: `${done.title}: done.` };
    tabData.value = {};
    void loadTab();
}

/** A rename navigates to the new name: its notice survives that one move. */
let keepNotice = false;

watch(
    () => [label.value, schema.value, name.value, isFunction.value],
    () => {
        if (!keepNotice) {
            notice.value = null;
        }
        keepNotice = false;
        // Set by a create form that navigated here.
        notice.value = takeFlash() ?? notice.value;
        void loadObject();
    },
    { immediate: true },
);
watch(activeTab, () => void loadTab());
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <header class="dbx-page-header">
            <BaseIcon :name="typeIcon" class="text-fg-subtle" />
            <h1 class="min-w-0 text-lg font-semibold break-all">
                <span class="font-normal text-fg-subtle">{{ schema }}.</span>{{ name }}
            </h1>
            <BaseBadge v-if="typeLabel">{{ typeLabel }}</BaseBadge>
            <span v-if="object?.parent" class="text-sm text-fg-muted">
                partition of
                <router-link :to="relationRoute({ schema: object.parent.schema, table: object.parent.name })" class="dbx-link font-mono text-xs">{{ object.parent.schema }}.{{ object.parent.name }}</router-link>
            </span>
            <div class="ml-auto flex items-center gap-1.5">
                <button v-if="object && object.type !== 'enum' && object.type !== 'domain' && object.type !== 'composite_type'" type="button" class="dbx-btn" @click="queryInEditor">
                    <BaseIcon name="terminal" />Open in SQL editor
                </button>
                <button v-if="isAdmin && alterable" type="button" class="dbx-btn" title="Add, rename or drop columns and indexes, or drop the table" @click="ddlOpen = true">
                    <BaseIcon name="sliders" />Alter table
                </button>
                <button v-if="isAdmin && object?.type === 'materialized_view'" type="button" class="dbx-btn" title="Re-run the view's query and store the new rows" @click="refreshOpen = true">
                    <BaseIcon name="refresh" />Refresh
                </button>
            </div>
            <p v-if="object?.comment || fn?.comment" class="w-full text-sm text-fg-muted">{{ object?.comment ?? fn?.comment }}</p>
        </header>

        <Transition name="dbx-reveal">
            <div v-if="notice" role="status" class="dbx-banner border-info-line bg-info-bg text-info-fg">
                <BaseIcon :name="notice.run !== undefined ? 'clock' : 'check-circle'" />
                <span class="flex-1">
                    {{ notice.text }}
                    <router-link v-if="notice.run !== undefined" :to="{ name: 'runs', params: { label } }" class="ml-1 font-medium underline underline-offset-2">View runs</router-link>
                </span>
                <button type="button" class="dbx-btn dbx-btn-ghost dbx-btn-icon dbx-btn-sm -my-0.5 text-current" aria-label="Dismiss" @click="notice = null"><BaseIcon name="x" /></button>
            </div>
        </Transition>

        <div v-if="loading" class="p-4"><SkeletonRows :rows="5" :columns="4" /></div>
        <EmptyState
            v-else-if="error"
            :icon="notFound ? 'search' : 'alert-circle'"
            :tone="notFound ? 'neutral' : 'danger'"
            :title="notFound ? 'Object not found' : 'Could not load this object'"
            role="alert"
        >
            {{ notFound ? `${schema}.${name} does not exist, or you can't access it. It may have been dropped or renamed.` : error }}
            <template #actions>
                <router-link :to="{ name: 'connection', params: { label } }" class="dbx-btn"><BaseIcon name="chevron-left" />Back to overview</router-link>
                <button v-if="!notFound" type="button" class="dbx-btn" @click="loadObject"><BaseIcon name="refresh" />Try again</button>
            </template>
        </EmptyState>

        <template v-else-if="activeTab">
            <div role="tablist" aria-label="Object details" class="dbx-tabs" @keydown="onTabKeydown">
                <button
                    v-for="tab in tabs"
                    :id="`dbx-tab-${tab}`"
                    :key="tab"
                    type="button"
                    role="tab"
                    class="dbx-tab"
                    :aria-selected="tab === activeTab"
                    :aria-controls="`dbx-panel-${tab}`"
                    :tabindex="tab === activeTab ? 0 : -1"
                    @click="selectTab(tab)"
                >
                    {{ TAB_TITLES[tab] }}
                </button>
            </div>

            <section
                :id="`dbx-panel-${activeTab}`"
                role="tabpanel"
                :aria-labelledby="`dbx-tab-${activeTab}`"
                class="flex min-h-0 flex-1 flex-col"
                :class="activeTab === 'data' ? '' : 'overflow-auto'"
            >
                <TableData v-if="activeTab === 'data'" :label="label" :schema="schema" :name="name" />

                <div v-else class="p-4">
                    <SkeletonRows v-if="tabLoading" :rows="6" :columns="activeTab === 'stats' ? 2 : 4" />
                    <div v-else-if="tabError" role="alert" class="dbx-callout dbx-callout-danger"><BaseIcon name="alert-circle" />{{ tabError }}</div>

                    <div v-else-if="activeTab === 'structure' && columns" class="dbx-card overflow-x-auto">
                        <table class="dbx-table">
                            <thead>
                                <tr><th class="w-px text-right">#</th><th>Column</th><th>Type</th><th>Null</th><th>Default</th><th>Comment</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="column in columns" :key="column.name">
                                    <td class="text-right text-xs text-fg-subtle tabular-nums">{{ column.position }}</td>
                                    <td class="font-medium">{{ column.name }}</td>
                                    <td class="font-mono text-xs whitespace-nowrap text-fg-muted">
                                        {{ column.type }}<span v-if="column.collation" class="text-fg-subtle"> collate {{ column.collation }}</span>
                                    </td>
                                    <td>
                                        <span v-if="column.nullable" class="text-xs text-fg-subtle">nullable</span>
                                        <BaseBadge v-else>not null</BaseBadge>
                                    </td>
                                    <td class="font-mono text-xs text-fg-muted">
                                        <template v-if="column.identity">identity ({{ column.identity.replace('_', ' ') }})</template>
                                        <template v-else-if="column.generated">generated: {{ column.generated }}</template>
                                        <template v-else>{{ column.default ?? '' }}</template>
                                    </td>
                                    <td class="text-fg-muted">{{ column.comment ?? '' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <template v-else-if="activeTab === 'indexes' && indexes">
                        <EmptyState v-if="indexes.length === 0" icon="key" title="No indexes">Every query on this table scans it sequentially.</EmptyState>
                        <div v-else class="dbx-card overflow-x-auto">
                            <table class="dbx-table">
                                <thead><tr><th>Name</th><th>Method</th><th>Flags</th><th>Definition</th></tr></thead>
                                <tbody>
                                    <tr v-for="index in indexes" :key="index.name">
                                        <td class="font-medium">{{ index.name }}</td>
                                        <td class="text-fg-muted">{{ index.method }}</td>
                                        <td class="whitespace-nowrap">
                                            <span class="inline-flex gap-1">
                                                <BaseBadge v-if="index.primary" tone="accent" icon="key">primary</BaseBadge>
                                                <BaseBadge v-else-if="index.unique">unique</BaseBadge>
                                                <BaseBadge v-if="!index.valid" tone="danger" icon="alert">invalid</BaseBadge>
                                            </span>
                                        </td>
                                        <td class="font-mono text-xs text-fg-muted">{{ index.definition }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>

                    <template v-else-if="activeTab === 'constraints' && constraints">
                        <div v-if="isAdmin && alterable" class="mb-3 flex items-center gap-2">
                            <button type="button" class="dbx-btn" @click="constraintForm = { mode: 'add' }"><BaseIcon name="plus" />Add constraint</button>
                            <span class="text-xs text-fg-subtle">Primary key, unique, check or foreign key.</span>
                        </div>
                        <EmptyState v-if="constraints.length === 0" icon="key" title="No constraints" />
                        <div v-else class="dbx-card overflow-x-auto">
                            <table class="dbx-table">
                                <thead><tr><th>Name</th><th>Type</th><th>Columns</th><th>References</th><th>Definition</th><th v-if="isAdmin && alterable"><span class="sr-only">Actions</span></th></tr></thead>
                                <tbody>
                                    <tr v-for="constraint in constraints" :key="constraint.name">
                                        <td class="font-medium">{{ constraint.name }}</td>
                                        <td><BaseBadge :tone="constraint.type === 'primary_key' ? 'accent' : 'neutral'" :icon="constraint.type === 'primary_key' ? 'key' : undefined">{{ constraintLabel[constraint.type] }}</BaseBadge></td>
                                        <td class="font-mono text-xs">{{ constraint.columns.join(', ') }}</td>
                                        <td>
                                            <router-link v-if="constraint.references" :to="relationRoute(constraint.references)" class="dbx-link inline-flex items-center gap-1 font-mono text-xs whitespace-nowrap">
                                                {{ constraint.references.schema }}.{{ constraint.references.table }} ({{ constraint.references.columns.join(', ') }})<BaseIcon name="arrow-right" :size="12" />
                                            </router-link>
                                            <span v-else-if="constraint.type === 'foreign_key'" class="inline-flex items-center gap-1 text-xs text-fg-subtle" title="The referenced table is hidden"><BaseIcon name="lock" :size="12" />hidden table</span>
                                        </td>
                                        <td class="font-mono text-xs text-fg-muted">{{ constraint.definition }}</td>
                                        <td v-if="isAdmin && alterable" class="w-px text-right whitespace-nowrap">
                                            <button
                                                type="button"
                                                class="dbx-btn dbx-btn-danger-ghost dbx-btn-sm"
                                                :aria-label="`Drop constraint ${constraint.name}`"
                                                @click="constraintForm = { mode: 'drop', constraint }"
                                            >
                                                <BaseIcon name="trash" />Drop
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>

                    <template v-else-if="activeTab === 'triggers' && triggers">
                        <EmptyState v-if="triggers.length === 0" icon="function" title="No triggers" />
                        <div v-else class="dbx-card overflow-x-auto">
                            <table class="dbx-table">
                                <thead><tr><th>Name</th><th>Function</th><th>State</th><th>Definition</th></tr></thead>
                                <tbody>
                                    <tr v-for="trigger in triggers" :key="trigger.name">
                                        <td class="font-medium">{{ trigger.name }}</td>
                                        <td class="font-mono text-xs">{{ trigger.function }}</td>
                                        <td><BaseBadge :tone="trigger.enabled ? 'success' : 'neutral'" :icon="trigger.enabled ? 'check' : 'ban'">{{ trigger.enabled ? 'enabled' : 'disabled' }}</BaseBadge></td>
                                        <td class="font-mono text-xs text-fg-muted">{{ trigger.definition }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>

                    <CodeBlock v-else-if="activeTab === 'ddl' && ddl !== undefined" :code="ddl" copy-label="Copy DDL" />

                    <dl v-else-if="activeTab === 'stats' && stats" class="dbx-kv">
                        <dt>Total size</dt><dd>{{ formatBytes(stats.total_bytes) }}</dd>
                        <dt>Table size</dt><dd>{{ formatBytes(stats.table_bytes) }}</dd>
                        <dt>Index size</dt><dd>{{ formatBytes(stats.index_bytes) }}</dd>
                        <dt>Estimated rows</dt><dd>{{ formatNumber(stats.estimated_rows) }}</dd>
                        <dt>Live / dead rows</dt><dd>{{ formatNumber(stats.live_rows) }} / {{ formatNumber(stats.dead_rows) }}</dd>
                        <dt>Sequential scans</dt><dd>{{ formatNumber(stats.seq_scans) }}</dd>
                        <dt>Index scans</dt><dd>{{ formatNumber(stats.index_scans) }}</dd>
                        <dt>Last vacuum</dt><dd>{{ formatDate(stats.last_vacuum) }} <span class="text-fg-subtle">· auto {{ formatDate(stats.last_autovacuum) }}</span></dd>
                        <dt>Last analyze</dt><dd>{{ formatDate(stats.last_analyze) }} <span class="text-fg-subtle">· auto {{ formatDate(stats.last_autoanalyze) }}</span></dd>
                    </dl>

                    <template v-else-if="activeTab === 'definition' && fn">
                        <dl class="dbx-kv mb-4">
                            <dt>Signature</dt><dd class="font-mono text-data">{{ fn.signature }}</dd>
                            <dt>Arguments</dt><dd class="font-mono text-data">{{ fn.arguments || '—' }}</dd>
                            <template v-if="fn.returns !== null"><dt>Returns</dt><dd class="font-mono text-data">{{ fn.returns }}</dd></template>
                            <dt>Language</dt><dd>{{ fn.language }}</dd>
                            <dt>Volatility</dt><dd>{{ fn.volatility }}</dd>
                            <dt>Security</dt><dd>{{ fn.security_definer ? 'definer' : 'invoker' }}</dd>
                        </dl>
                        <CodeBlock :code="fn.definition" />
                    </template>

                    <CodeBlock v-else-if="activeTab === 'definition' && definition !== undefined" :code="definition" />

                    <dl v-else-if="activeTab === 'overview' && object" class="dbx-kv">
                        <dt>Schema</dt><dd>{{ object.schema }}</dd>
                        <dt>Name</dt><dd>{{ object.name }}</dd>
                        <dt>Type</dt><dd>{{ typeLabel }}</dd>
                        <dt>Comment</dt><dd>{{ object.comment ?? '—' }}</dd>
                    </dl>
                </div>
            </section>
        </template>

        <ConstraintForm
            v-if="constraintFormUsed"
            :open="constraintForm !== null"
            :mode="constraintForm?.mode ?? 'add'"
            :label="label"
            :schema="schema"
            :table="name"
            :constraint="constraintForm?.constraint ?? null"
            @close="onSchemaChange"
        />
        <RefreshMatviewForm v-if="refreshUsed" :open="refreshOpen" :label="label" :schema="schema" :name="name" @close="onSchemaChange" />

        <DdlForm
            :open="ddlOpen"
            :label="label"
            :schema="schema"
            :name="name"
            @close="onDdlClose"
        />
    </div>
</template>
