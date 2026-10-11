<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, describeError, segment } from '../api';
import BaseBadge from '../components/BaseBadge.vue';
import type { Tone } from '../components/BaseBadge.vue';
import BaseIcon from '../components/BaseIcon.vue';
import ClassificationBadge from '../components/ClassificationBadge.vue';
import CodeBlock from '../components/CodeBlock.vue';
import DrawerPanel from '../components/DrawerPanel.vue';
import EmptyState from '../components/EmptyState.vue';
import JsonView from '../components/JsonView.vue';
import SkeletonRows from '../components/SkeletonRows.vue';
import { formatDate, formatDuration, formatNumber } from '../composables/format';
import { findConnection, useSession } from '../composables/session';
import { CLASSIFICATIONS } from '../types';
import type { Classification } from '../types';

/**
 * /:label/audit (PRD §7.12), admins only: the append-only audit log,
 * newest first, filtered on the server. Rows are limited to labels where
 * you're admin (F3). SQL shows as plain text: it may hold literals.
 */
const route = useRoute();
const router = useRouter();
const { connections } = useSession();
const label = computed(() => String(route.params.label));
const isAdmin = computed(() => findConnection(label.value)?.role === 'admin');
const adminLabels = computed(() => connections.value.filter((connection) => connection.role === 'admin').map((connection) => connection.label));

interface AuditEntry {
    id: number;
    action: string;
    user_id: string | null;
    label: string | null;
    role: string | null;
    classification: string | null;
    outcome: string;
    error: string | null;
    duration_ms: number | null;
    row_count: number | null;
    sql: string | null;
    meta: Record<string, unknown> | unknown[] | null;
    created_at: string;
}

interface AuditPage {
    data: AuditEntry[];
    meta: { page: number; per_page: number; has_more: boolean };
}

const FILTERS = ['user_id', 'label', 'action', 'classification', 'outcome', 'from', 'to'] as const;
type FilterKey = (typeof FILTERS)[number];

const ACTIONS = ['query', 'row.*', 'ddl*', 'run.*', 'export*', 'import*', 'script', 'schedule.*', 'session.*', 'password_confirm', 'backup*'];
const OUTCOMES = ['success', 'error', 'failure', 'denied', 'cancelled', 'conflict'];

/** The filters in the URL: shareable, and Back works. */
const applied = computed(() => {
    const filters: Partial<Record<FilterKey, string>> = {};
    for (const key of FILTERS) {
        const value = route.query[key];
        if (typeof value === 'string' && value !== '') {
            filters[key] = value;
        }
    }

    return filters;
});
const page = computed(() => Math.max(1, Number(route.query.page) || 1));
const draft = reactive<Record<FilterKey, string>>({ user_id: '', label: '', action: '', classification: '', outcome: '', from: '', to: '' });

const entries = ref<AuditPage | null>(null);
const error = ref<string | null>(null);
const loading = ref(false);
const selected = ref<AuditEntry | null>(null);

/** datetime-local is wall-clock time here; the API compares against UTC timestamps. */
function toApiDate(local: string): string {
    const date = new Date(local);

    return Number.isNaN(date.getTime()) ? local : date.toISOString();
}

function toLocalInput(iso: string): string {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return '';
    }
    const local = new Date(date.getTime() - date.getTimezoneOffset() * 60000);

    return local.toISOString().slice(0, 16);
}

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        entries.value = await api<AuditPage>(`${segment(label.value)}/audit`, { query: { ...applied.value, page: page.value > 1 ? page.value : undefined } });
    } catch (e) {
        error.value = describeError(e, 'The audit log could not be loaded.');
    } finally {
        loading.value = false;
    }
}

function syncDraft(): void {
    for (const key of FILTERS) {
        const value = applied.value[key] ?? '';
        draft[key] = key === 'from' || key === 'to' ? (value ? toLocalInput(value) : '') : value;
    }
}

function apply(): void {
    const query: Record<string, string | undefined> = {};
    for (const key of FILTERS) {
        const value = draft[key].trim();
        query[key] = value === '' ? undefined : key === 'from' || key === 'to' ? toApiDate(value) : value;
    }
    void router.push({ query });
}

function reset(): void {
    void router.push({ query: {} });
}

function goTo(next: number): void {
    void router.push({ query: { ...route.query, page: next > 1 ? String(next) : undefined } });
}

const activeFilters = computed(() => Object.keys(applied.value).length);

function asClassification(value: string | null): Classification | null {
    return CLASSIFICATIONS.find((candidate) => candidate === value) ?? null;
}

function outcomeTone(outcome: string): Tone {
    return outcome === 'success' ? 'success' : outcome === 'cancelled' || outcome === 'conflict' ? 'warning' : 'danger';
}

function hasMeta(meta: AuditEntry['meta']): boolean {
    return meta !== null && (Array.isArray(meta) ? meta.length > 0 : Object.keys(meta).length > 0);
}

watch(
    () => [label.value, route.query],
    () => {
        syncDraft();
        if (isAdmin.value) {
            void load();
        }
    },
    { immediate: true, deep: true },
);
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <header class="dbx-page-header">
            <BaseIcon name="file-text" class="text-fg-subtle" />
            <h1 class="text-lg font-semibold">Audit log</h1>
            <span class="text-sm text-fg-muted">Every action on the connections you administer, newest first. Read-only.</span>
        </header>

        <EmptyState v-if="!isAdmin" icon="shield" title="Admins only">Only admins of {{ label }} can read its audit log.</EmptyState>
        <template v-else>
            <form class="shrink-0 border-b border-line bg-panel px-4 py-3" aria-label="Audit filters" @submit.prevent="apply">
                <div class="grid grid-cols-[repeat(auto-fill,minmax(10.5rem,1fr))] gap-x-3 gap-y-2">
                    <div>
                        <label for="dbx-audit-user" class="dbx-label">User id</label>
                        <input id="dbx-audit-user" v-model="draft.user_id" class="dbx-input w-full font-mono" autocomplete="off">
                    </div>
                    <div>
                        <label for="dbx-audit-label" class="dbx-label">Connection</label>
                        <select id="dbx-audit-label" v-model="draft.label" class="dbx-select w-full">
                            <option value="">All I administer</option>
                            <option v-for="name in adminLabels" :key="name" :value="name">{{ name }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="dbx-audit-action" class="dbx-label">Action <span class="font-normal text-fg-subtle">(* matches any)</span></label>
                        <input id="dbx-audit-action" v-model="draft.action" class="dbx-input w-full font-mono" list="dbx-audit-actions" autocomplete="off" placeholder="schedule.*">
                        <datalist id="dbx-audit-actions"><option v-for="action in ACTIONS" :key="action" :value="action" /></datalist>
                    </div>
                    <div>
                        <label for="dbx-audit-class" class="dbx-label">Classification</label>
                        <select id="dbx-audit-class" v-model="draft.classification" class="dbx-select w-full">
                            <option value="">Any</option>
                            <option v-for="value in CLASSIFICATIONS" :key="value" :value="value">{{ value }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="dbx-audit-outcome" class="dbx-label">Outcome</label>
                        <select id="dbx-audit-outcome" v-model="draft.outcome" class="dbx-select w-full">
                            <option value="">Any</option>
                            <option v-for="value in OUTCOMES" :key="value" :value="value">{{ value }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="dbx-audit-from" class="dbx-label">From</label>
                        <input id="dbx-audit-from" v-model="draft.from" type="datetime-local" class="dbx-input w-full">
                    </div>
                    <div>
                        <label for="dbx-audit-to" class="dbx-label">To</label>
                        <input id="dbx-audit-to" v-model="draft.to" type="datetime-local" class="dbx-input w-full">
                    </div>
                    <div class="flex items-end gap-1.5">
                        <button type="submit" class="dbx-btn dbx-btn-primary"><BaseIcon name="filter" />Apply</button>
                        <button v-if="activeFilters" type="button" class="dbx-btn dbx-btn-ghost" @click="reset">Clear</button>
                    </div>
                </div>
            </form>

            <div class="min-h-0 flex-1 overflow-auto p-4">
                <div class="dbx-callout dbx-callout-info mb-3 max-w-5xl">
                    <BaseIcon name="shield" />
                    <span class="flex flex-wrap items-center gap-x-1.5">
                        Each entry is HMAC-chained to the one before it. To check that nothing was edited, removed or reordered, run
                        <code class="rounded border border-info-line px-1 font-mono text-xs">php artisan dbx:audit:verify</code>
                        on the server (daily, with an alert on failure).
                    </span>
                </div>

                <div v-if="error" role="alert" class="dbx-callout dbx-callout-danger mb-3">
                    <BaseIcon name="alert-circle" /><span class="flex-1">{{ error }}</span>
                    <button type="button" class="dbx-btn dbx-btn-sm" @click="load"><BaseIcon name="refresh" />Retry</button>
                </div>
                <div v-if="entries === null && !error" class="dbx-card"><SkeletonRows :rows="8" :columns="6" /></div>
                <EmptyState v-else-if="entries && entries.data.length === 0" icon="file-text" :title="activeFilters ? 'No entries match these filters' : 'No audit entries yet'">
                    <template v-if="activeFilters">Widen the date range or clear a filter. A connection you don't administer always shows no entries.</template>
                    <template v-if="activeFilters" #actions><button type="button" class="dbx-btn" @click="reset"><BaseIcon name="x" />Clear filters</button></template>
                </EmptyState>
                <div v-else-if="entries" class="dbx-card overflow-x-auto" :aria-busy="loading">
                    <table class="dbx-table">
                        <thead>
                            <tr>
                                <th scope="col">When</th>
                                <th scope="col">Action</th>
                                <th scope="col">User</th>
                                <th scope="col">Connection</th>
                                <th scope="col">Class</th>
                                <th scope="col">Outcome</th>
                                <th scope="col" class="w-full">SQL</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="entry in entries.data" :key="entry.id" class="cursor-default" @click="selected = entry">
                                <td class="whitespace-nowrap">
                                    <button type="button" class="dbx-link text-left tabular-nums" :aria-label="`Details of audit entry ${entry.id}`" @click.stop="selected = entry">{{ formatDate(entry.created_at) }}</button>
                                </td>
                                <td class="font-mono text-xs whitespace-nowrap">{{ entry.action }}</td>
                                <td class="text-xs whitespace-nowrap">
                                    <span class="font-mono">{{ entry.user_id ?? '—' }}</span><span v-if="entry.role" class="text-fg-subtle"> · {{ entry.role }}</span>
                                </td>
                                <td class="text-xs">{{ entry.label ?? '—' }}</td>
                                <td><ClassificationBadge v-if="asClassification(entry.classification)" :value="asClassification(entry.classification)!" /><span v-else class="text-fg-subtle">—</span></td>
                                <td><BaseBadge :tone="outcomeTone(entry.outcome)">{{ entry.outcome }}</BaseBadge></td>
                                <td class="max-w-md"><span class="line-clamp-1 font-mono text-xs break-all text-fg-muted">{{ entry.sql ?? entry.error ?? '' }}</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <footer v-if="entries && (page > 1 || entries.meta.has_more)" class="dbx-statusbar">
                <button type="button" class="dbx-btn dbx-btn-ghost dbx-btn-sm" :disabled="page <= 1 || loading" @click="goTo(page - 1)"><BaseIcon name="chevron-left" />Newer</button>
                <span>Page {{ page }} · {{ entries.meta.per_page }} per page</span>
                <button type="button" class="dbx-btn dbx-btn-ghost dbx-btn-sm" :disabled="!entries.meta.has_more || loading" @click="goTo(page + 1)">Older<BaseIcon name="chevron-right" /></button>
            </footer>
        </template>

        <DrawerPanel :open="selected !== null" :title="selected ? `Audit entry #${selected.id}` : ''" :description="selected ? formatDate(selected.created_at) : undefined" @close="selected = null">
            <div v-if="selected" class="space-y-5">
                <dl class="dbx-kv">
                    <dt>Action</dt><dd class="font-mono">{{ selected.action }}</dd>
                    <dt>Outcome</dt><dd><BaseBadge :tone="outcomeTone(selected.outcome)">{{ selected.outcome }}</BaseBadge></dd>
                    <dt>User id</dt><dd class="font-mono">{{ selected.user_id ?? '—' }}</dd>
                    <dt>Role</dt><dd>{{ selected.role ?? '—' }}</dd>
                    <dt>Connection</dt><dd>{{ selected.label ?? '— (not tied to one)' }}</dd>
                    <dt>Classification</dt><dd><ClassificationBadge v-if="asClassification(selected.classification)" :value="asClassification(selected.classification)!" /><span v-else>—</span></dd>
                    <dt>Duration</dt><dd>{{ formatDuration(selected.duration_ms) }}</dd>
                    <dt>Rows</dt><dd>{{ formatNumber(selected.row_count) }}</dd>
                    <dt>Recorded</dt><dd class="tabular-nums">{{ selected.created_at }}</dd>
                </dl>
                <div v-if="selected.error">
                    <h3 class="dbx-label">Error</h3>
                    <div class="dbx-callout dbx-callout-danger"><BaseIcon name="alert-circle" /><pre class="font-sans whitespace-pre-wrap">{{ selected.error }}</pre></div>
                </div>
                <div v-if="selected.sql">
                    <h3 class="dbx-label">SQL <span class="font-normal text-fg-subtle">(as logged: masked, may contain literals)</span></h3>
                    <CodeBlock :code="selected.sql" copy-label="Copy SQL" />
                </div>
                <div v-if="hasMeta(selected.meta)">
                    <h3 class="dbx-label">Meta</h3>
                    <div class="dbx-code font-mono text-xs whitespace-normal"><JsonView :value="selected.meta" /></div>
                </div>
                <div>
                    <h3 class="dbx-label">Chain</h3>
                    <p class="text-sm text-fg-muted">The audit API doesn't return the entry's <code class="font-mono">hash</code> and <code class="font-mono">prev_hash</code>. To check the chain this entry belongs to, run on the server:</p>
                    <CodeBlock class="mt-2" code="php artisan dbx:audit:verify" copy-label="Copy" />
                </div>
            </div>
        </DrawerPanel>
    </div>
</template>
