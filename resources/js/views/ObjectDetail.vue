<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, ApiError, describeError, segment } from '../api';
import CodeBlock from '../components/CodeBlock.vue';
import TableData from '../components/TableData.vue';
import { sendToEditor } from '../composables/editorInbox';
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
const loading = ref(false);

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
            error.value = e instanceof ApiError && e.status === 404 ? 'This object does not exist, or you cannot access it.' : describeError(e);
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

watch(() => [label.value, schema.value, name.value, isFunction.value], loadObject, { immediate: true });
watch(activeTab, () => void loadTab());
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <header class="flex flex-wrap items-baseline gap-x-3 gap-y-1 border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
            <h1 class="text-lg font-semibold break-all">
                <span class="text-zinc-400">{{ schema }}.</span>{{ name }}
            </h1>
            <span v-if="typeLabel" class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">{{ typeLabel }}</span>
            <span v-if="object?.parent" class="text-sm text-zinc-500">
                partition of
                <router-link :to="relationRoute({ schema: object.parent.schema, table: object.parent.name })" class="dbx-link">{{ object.parent.schema }}.{{ object.parent.name }}</router-link>
            </span>
            <button v-if="object && object.type !== 'enum' && object.type !== 'domain' && object.type !== 'composite_type'" type="button" class="dbx-btn ml-auto" @click="queryInEditor">
                Open in SQL editor
            </button>
            <p v-if="object?.comment || fn?.comment" class="w-full text-sm text-zinc-500 dark:text-zinc-400">{{ object?.comment ?? fn?.comment }}</p>
        </header>

        <p v-if="loading" class="p-4 text-sm text-zinc-500">Loading…</p>
        <div v-else-if="error" role="alert" class="m-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
            {{ error }}
        </div>

        <template v-else-if="activeTab">
            <div role="tablist" aria-label="Object details" class="flex gap-1 overflow-x-auto border-b border-zinc-200 px-3 dark:border-zinc-800" @keydown="onTabKeydown">
                <button
                    v-for="tab in tabs"
                    :id="`dbx-tab-${tab}`"
                    :key="tab"
                    type="button"
                    role="tab"
                    :aria-selected="tab === activeTab"
                    :aria-controls="`dbx-panel-${tab}`"
                    :tabindex="tab === activeTab ? 0 : -1"
                    class="-mb-px border-b-2 px-3 py-2 text-sm whitespace-nowrap focus-visible:ring-2 focus-visible:ring-sky-500 focus-visible:outline-none"
                    :class="tab === activeTab ? 'border-sky-600 font-medium text-sky-700 dark:text-sky-400' : 'border-transparent text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'"
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
                :class="activeTab === 'data' ? '' : 'overflow-auto p-4'"
            >
                <TableData v-if="activeTab === 'data'" :label="label" :schema="schema" :name="name" />

                <template v-else>
                    <p v-if="tabLoading" class="text-sm text-zinc-500">Loading…</p>
                    <p v-else-if="tabError" role="alert" class="text-sm text-red-700 dark:text-red-300">{{ tabError }}</p>

                    <table v-else-if="activeTab === 'structure' && columns" class="dbx-table">
                        <thead>
                            <tr><th>#</th><th>Column</th><th>Type</th><th>Nullable</th><th>Default</th><th>Comment</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="column in columns" :key="column.name">
                                <td class="text-zinc-400 tabular-nums">{{ column.position }}</td>
                                <td class="font-medium">{{ column.name }}</td>
                                <td class="font-mono text-xs">
                                    {{ column.type }}<span v-if="column.collation" class="text-zinc-400"> collate {{ column.collation }}</span>
                                </td>
                                <td>{{ column.nullable ? 'yes' : 'not null' }}</td>
                                <td class="font-mono text-xs">
                                    <template v-if="column.identity">identity ({{ column.identity.replace('_', ' ') }})</template>
                                    <template v-else-if="column.generated">generated: {{ column.generated }}</template>
                                    <template v-else>{{ column.default ?? '' }}</template>
                                </td>
                                <td class="text-zinc-500">{{ column.comment ?? '' }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <template v-else-if="activeTab === 'indexes' && indexes">
                        <p v-if="indexes.length === 0" class="text-sm text-zinc-500">No indexes.</p>
                        <table v-else class="dbx-table">
                            <thead><tr><th>Name</th><th>Method</th><th>Flags</th><th>Definition</th></tr></thead>
                            <tbody>
                                <tr v-for="index in indexes" :key="index.name">
                                    <td class="font-medium">{{ index.name }}</td>
                                    <td>{{ index.method }}</td>
                                    <td class="space-x-1 whitespace-nowrap">
                                        <span v-if="index.primary" class="dbx-badge">primary</span>
                                        <span v-else-if="index.unique" class="dbx-badge">unique</span>
                                        <span v-if="!index.valid" class="dbx-badge text-red-700 dark:text-red-300">invalid</span>
                                    </td>
                                    <td class="font-mono text-xs">{{ index.definition }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </template>

                    <template v-else-if="activeTab === 'constraints' && constraints">
                        <p v-if="constraints.length === 0" class="text-sm text-zinc-500">No constraints.</p>
                        <table v-else class="dbx-table">
                            <thead><tr><th>Name</th><th>Type</th><th>Columns</th><th>References</th><th>Definition</th></tr></thead>
                            <tbody>
                                <tr v-for="constraint in constraints" :key="constraint.name">
                                    <td class="font-medium">{{ constraint.name }}</td>
                                    <td class="whitespace-nowrap">{{ constraintLabel[constraint.type] }}</td>
                                    <td>{{ constraint.columns.join(', ') }}</td>
                                    <td>
                                        <router-link v-if="constraint.references" :to="relationRoute(constraint.references)" class="dbx-link">
                                            {{ constraint.references.schema }}.{{ constraint.references.table }} ({{ constraint.references.columns.join(', ') }})
                                        </router-link>
                                        <span v-else-if="constraint.type === 'foreign_key'" class="text-zinc-500" title="The referenced table is hidden">&lt;hidden&gt;</span>
                                    </td>
                                    <td class="font-mono text-xs">{{ constraint.definition }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </template>

                    <template v-else-if="activeTab === 'triggers' && triggers">
                        <p v-if="triggers.length === 0" class="text-sm text-zinc-500">No triggers.</p>
                        <table v-else class="dbx-table">
                            <thead><tr><th>Name</th><th>Function</th><th>Enabled</th><th>Definition</th></tr></thead>
                            <tbody>
                                <tr v-for="trigger in triggers" :key="trigger.name">
                                    <td class="font-medium">{{ trigger.name }}</td>
                                    <td class="font-mono text-xs">{{ trigger.function }}</td>
                                    <td>{{ trigger.enabled ? 'yes' : 'disabled' }}</td>
                                    <td class="font-mono text-xs">{{ trigger.definition }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </template>

                    <CodeBlock v-else-if="activeTab === 'ddl' && ddl !== undefined" :code="ddl" copy-label="Copy DDL" />

                    <dl v-else-if="activeTab === 'stats' && stats" class="grid max-w-2xl grid-cols-[max-content_1fr] gap-x-6 gap-y-1.5 text-sm">
                        <dt class="text-zinc-500">Total size</dt><dd>{{ formatBytes(stats.total_bytes) }}</dd>
                        <dt class="text-zinc-500">Table size</dt><dd>{{ formatBytes(stats.table_bytes) }}</dd>
                        <dt class="text-zinc-500">Index size</dt><dd>{{ formatBytes(stats.index_bytes) }}</dd>
                        <dt class="text-zinc-500">Estimated rows</dt><dd class="tabular-nums">{{ formatNumber(stats.estimated_rows) }}</dd>
                        <dt class="text-zinc-500">Live / dead rows</dt><dd class="tabular-nums">{{ formatNumber(stats.live_rows) }} / {{ formatNumber(stats.dead_rows) }}</dd>
                        <dt class="text-zinc-500">Sequential scans</dt><dd class="tabular-nums">{{ formatNumber(stats.seq_scans) }}</dd>
                        <dt class="text-zinc-500">Index scans</dt><dd class="tabular-nums">{{ formatNumber(stats.index_scans) }}</dd>
                        <dt class="text-zinc-500">Last vacuum</dt><dd>{{ formatDate(stats.last_vacuum) }} <span class="text-zinc-400">(auto: {{ formatDate(stats.last_autovacuum) }})</span></dd>
                        <dt class="text-zinc-500">Last analyze</dt><dd>{{ formatDate(stats.last_analyze) }} <span class="text-zinc-400">(auto: {{ formatDate(stats.last_autoanalyze) }})</span></dd>
                    </dl>

                    <template v-else-if="activeTab === 'definition' && fn">
                        <dl class="mb-4 grid max-w-2xl grid-cols-[max-content_1fr] gap-x-6 gap-y-1.5 text-sm">
                            <dt class="text-zinc-500">Signature</dt><dd class="font-mono text-xs">{{ fn.signature }}</dd>
                            <dt class="text-zinc-500">Arguments</dt><dd class="font-mono text-xs">{{ fn.arguments || '—' }}</dd>
                            <template v-if="fn.returns !== null"><dt class="text-zinc-500">Returns</dt><dd class="font-mono text-xs">{{ fn.returns }}</dd></template>
                            <dt class="text-zinc-500">Language</dt><dd>{{ fn.language }}</dd>
                            <dt class="text-zinc-500">Volatility</dt><dd>{{ fn.volatility }}</dd>
                            <dt class="text-zinc-500">Security</dt><dd>{{ fn.security_definer ? 'definer' : 'invoker' }}</dd>
                        </dl>
                        <CodeBlock :code="fn.definition" />
                    </template>

                    <CodeBlock v-else-if="activeTab === 'definition' && definition !== undefined" :code="definition" />

                    <dl v-else-if="activeTab === 'overview' && object" class="grid max-w-2xl grid-cols-[max-content_1fr] gap-x-6 gap-y-1.5 text-sm">
                        <dt class="text-zinc-500">Schema</dt><dd>{{ object.schema }}</dd>
                        <dt class="text-zinc-500">Name</dt><dd>{{ object.name }}</dd>
                        <dt class="text-zinc-500">Type</dt><dd>{{ typeLabel }}</dd>
                        <dt class="text-zinc-500">Comment</dt><dd>{{ object.comment ?? '—' }}</dd>
                    </dl>
                </template>
            </section>
        </template>
    </div>
</template>
