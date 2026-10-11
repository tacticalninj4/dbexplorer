<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, describeError, segment } from '../api';
import BaseIcon from '../components/BaseIcon.vue';
import EmptyState from '../components/EmptyState.vue';
import RunDetails from '../components/RunDetails.vue';
import RunStatusBadge from '../components/RunStatusBadge.vue';
import SkeletonRows from '../components/SkeletonRows.vue';
import { formatDate, formatNumber } from '../composables/format';
import { cancelRun, downloadRun, isActive, POLL_MS, RUN_STATUS, RUN_STATUSES, RUN_TYPES, runDuration, runType } from '../composables/runs';
import type { BackgroundRun, RunsPage } from '../composables/runs';
import { findConnection } from '../composables/session';

/**
 * The Runs page (PRD §7.5): background queries, exports, imports, scripts
 * and backups on this connection, with their progress. While any run on the
 * page is queued or running the list polls every 2 seconds, and stops once
 * none is. Cancel stops a run (the backend cancels its query); a finished
 * run's file downloads through a short-lived signed URL. An import shows
 * its inserted and skipped rows and the rejected rows (F7); a script, each
 * statement's outcome. Filters live in the URL (?status=, ?type=), and
 * ?run=N opens that run's details.
 */
const route = useRoute();
const router = useRouter();
const label = computed(() => String(route.params.label));
const isAdmin = computed(() => findConnection(label.value)?.role === 'admin');

const status = computed(() => RUN_STATUSES.find((value) => value === route.query.status) ?? null);
const type = computed(() => (typeof route.query.type === 'string' && route.query.type in RUN_TYPES ? route.query.type : ''));

const runs = ref<BackgroundRun[]>([]);
const hasMore = ref(false);
const page = ref(1);
const error = ref<string | null>(null);
const loading = ref(false);
const loaded = ref(false);
const expanded = ref(new Set<number>(typeof route.query.run === 'string' && /^\d+$/.test(route.query.run) ? [Number(route.query.run)] : []));
const busy = ref(new Set<number>());
let timer: ReturnType<typeof setTimeout> | undefined;
let sequence = 0;

const active = computed(() => runs.value.some(isActive));

async function load(pageNumber = page.value): Promise<void> {
    const id = ++sequence;
    clearTimeout(timer);
    loading.value = true;

    try {
        const response = await api<RunsPage>(`${segment(label.value)}/runs`, {
            query: { page: pageNumber, status: status.value ?? undefined, type: type.value || undefined },
        });

        if (id !== sequence) {
            return;
        }

        runs.value = response.data;
        loaded.value = true;
        error.value = null;
        page.value = response.meta.page;
        hasMore.value = response.meta.has_more;
    } catch (e) {
        if (id === sequence) {
            error.value = describeError(e, 'The runs could not be loaded.');
        }
    } finally {
        if (id === sequence) {
            loading.value = false;

            // Poll every 2 s while something on this page is still going, or
            // while the filter itself asks for queued or running runs.
            if (active.value || status.value === 'queued' || status.value === 'running') {
                timer = setTimeout(() => void load(), POLL_MS);
            }
        }
    }
}

function setFilter(name: 'status' | 'type', value: string): void {
    void router.replace({ query: { ...route.query, [name]: value || undefined, run: undefined } });
}

watch([status, type], () => {
    loaded.value = false;
    void load(1);
});

function toggle(run: BackgroundRun): void {
    const next = new Set(expanded.value);

    if (next.has(run.id)) {
        next.delete(run.id);
    } else {
        next.add(run.id);
    }

    expanded.value = next;
}

async function cancel(run: BackgroundRun): Promise<void> {
    busy.value = new Set(busy.value).add(run.id);

    try {
        await cancelRun(label.value, run.id);
        await load();
    } catch (e) {
        error.value = describeError(e);
    } finally {
        const next = new Set(busy.value);
        next.delete(run.id);
        busy.value = next;
    }
}

async function download(run: BackgroundRun): Promise<void> {
    try {
        await downloadRun(label.value, run.id);
    } catch (e) {
        error.value = describeError(e, 'The file could not be downloaded.');
    }
}

/** A one-line outcome for the table: rows imported, statements run. */
function summary(run: BackgroundRun): string {
    if (run.type === 'import' && run.inserted !== undefined) {
        return `${formatNumber(run.inserted)} inserted${run.skipped ? ` · ${formatNumber(run.skipped)} skipped` : ''}`;
    }

    if (run.statements?.length) {
        const done = run.statements.filter((statement) => statement.status === 'succeeded').length;

        return `${formatNumber(done)}/${formatNumber(run.statements.length)} statements`;
    }

    return '';
}

function duration(run: BackgroundRun): string {
    return runDuration(run) ?? '';
}

const hasDetails = (run: BackgroundRun) => run.type === 'import' || (run.statements?.length ?? 0) > 0 || run.error !== null;

const emptyFilterText = computed(() => {
    const parts = [status.value ? RUN_STATUS[status.value].text.toLowerCase() : '', type.value ? runType(type.value).title.toLowerCase() : ''].filter((part) => part !== '');

    return `No ${parts.join(' ')} runs${page.value > 1 ? ' on this page' : ''}.`;
});

void load();

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <header class="dbx-page-header">
            <BaseIcon name="activity" class="text-fg-subtle" />
            <h1 class="text-lg font-semibold">Runs</h1>
            <span class="text-sm text-fg-muted">{{ isAdmin ? 'Everyone’s' : 'Your' }} background queries, exports, imports, scripts and backups on {{ label }}.</span>
            <span v-if="active || status === 'queued' || status === 'running'" class="ml-auto inline-flex items-center gap-1.5 text-xs text-fg-muted" role="status"><span class="dbx-spinner size-3" aria-hidden="true" />Updating every 2 seconds</span>
        </header>

        <div class="dbx-toolbar">
            <div class="dbx-segmented" role="group" aria-label="Filter by status">
                <button type="button" :aria-pressed="status === null" @click="setFilter('status', '')">All</button>
                <button v-for="value in RUN_STATUSES" :key="value" type="button" :aria-pressed="status === value" @click="setFilter('status', value)">{{ RUN_STATUS[value].text }}</button>
            </div>
            <label class="ml-2 flex items-center gap-1.5 text-sm text-fg-muted">
                Type
                <select class="dbx-select" :value="type" @change="setFilter('type', ($event.target as HTMLSelectElement).value)">
                    <option value="">All types</option>
                    <option v-for="(item, key) in RUN_TYPES" :key="key" :value="key">{{ item.title }}</option>
                </select>
            </label>
            <button type="button" class="dbx-btn dbx-btn-ghost ml-auto" :disabled="loading" @click="load()"><BaseIcon name="refresh" />Refresh</button>
        </div>

        <section class="min-h-0 flex-1 overflow-y-auto p-4" aria-label="Background runs">
            <div v-if="error" role="alert" class="dbx-callout dbx-callout-danger mb-3"><BaseIcon name="alert-circle" />{{ error }}</div>

            <div v-if="!loaded && loading" class="dbx-card"><SkeletonRows :rows="4" :columns="6" /></div>
            <EmptyState v-else-if="loaded && runs.length === 0 && (status || type)" icon="filter" title="No runs match these filters">
                {{ emptyFilterText }}
                <template #actions>
                    <button type="button" class="dbx-btn" @click="router.replace({ query: {} })"><BaseIcon name="x" />Clear filters</button>
                </template>
            </EmptyState>
            <EmptyState v-else-if="loaded && runs.length === 0" icon="activity" title="No runs yet">
                Exports, imports, scripts, background queries, backups and CONCURRENTLY index builds run in the background and show up here with their progress.
                <template #actions>
                    <router-link :to="{ name: 'sql', params: { label } }" class="dbx-btn"><BaseIcon name="terminal" />Open SQL editor</router-link>
                </template>
            </EmptyState>
            <div v-else-if="runs.length" class="dbx-card overflow-x-auto" :aria-busy="loading">
                <table class="dbx-table">
                    <thead>
                        <tr>
                            <th scope="col" class="w-px"><span class="sr-only">Details</span></th>
                            <th scope="col" class="w-px text-right">#</th>
                            <th scope="col">Type</th>
                            <th scope="col">Status</th>
                            <th scope="col">Progress</th>
                            <th scope="col">Result</th>
                            <th scope="col">Created</th>
                            <th v-if="isAdmin" scope="col">User</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                            <th scope="col">Error</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="run in runs" :key="run.id">
                            <tr :class="expanded.has(run.id) ? 'bg-sunken' : ''">
                                <td>
                                    <button
                                        v-if="hasDetails(run)"
                                        type="button"
                                        class="dbx-btn dbx-btn-ghost dbx-btn-icon dbx-btn-sm"
                                        :aria-expanded="expanded.has(run.id)"
                                        :aria-controls="`dbx-run-${run.id}`"
                                        :aria-label="`${expanded.has(run.id) ? 'Hide' : 'Show'} details of run ${run.id}`"
                                        @click="toggle(run)"
                                    >
                                        <BaseIcon name="chevron-right" class="transition-transform duration-150 motion-reduce:transition-none" :class="expanded.has(run.id) ? 'rotate-90' : ''" />
                                    </button>
                                </td>
                                <td class="text-right text-xs text-fg-subtle tabular-nums">{{ run.id }}</td>
                                <td class="whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1.5 font-medium"><BaseIcon :name="runType(run.type).icon" :size="14" class="text-fg-subtle" />{{ runType(run.type).title }}</span>
                                </td>
                                <td><RunStatusBadge :run="run" /></td>
                                <td class="tabular-nums">
                                    <span v-if="run.progress === null || (isActive(run) && run.progress === 0)" class="text-fg-subtle">{{ isActive(run) ? 'Waiting…' : '—' }}</span>
                                    <span v-else class="flex items-center gap-2">
                                        <span class="h-1.5 w-20 overflow-hidden rounded-full bg-sunken" role="progressbar" :aria-valuenow="run.progress" aria-valuemin="0" aria-valuemax="100" :aria-label="`Run ${run.id} progress`">
                                            <span class="block h-full rounded-full bg-accent-solid" :style="{ width: `${run.progress}%` }" />
                                        </span>
                                        <span class="text-xs text-fg-muted">{{ run.progress }}%</span>
                                    </span>
                                </td>
                                <td class="whitespace-nowrap text-fg-muted tabular-nums">
                                    {{ summary(run) }}<span v-if="duration(run)" class="text-fg-subtle">{{ summary(run) ? ' · ' : '' }}{{ duration(run) }}</span>
                                </td>
                                <td class="whitespace-nowrap text-fg-muted" :title="run.created_at">{{ formatDate(run.created_at) }}</td>
                                <td v-if="isAdmin" class="text-fg-muted tabular-nums">{{ run.user_id ?? '—' }}</td>
                                <td class="whitespace-nowrap">
                                    <span class="inline-flex gap-1.5">
                                        <button v-if="isActive(run)" type="button" class="dbx-btn dbx-btn-sm dbx-btn-danger-ghost" :disabled="run.cancel_requested || busy.has(run.id)" @click="cancel(run)"><BaseIcon name="stop" />{{ run.cancel_requested ? 'Cancelling…' : 'Cancel' }}</button>
                                        <button v-if="run.result_available" type="button" class="dbx-btn dbx-btn-sm" @click="download(run)"><BaseIcon name="download" />Download</button>
                                    </span>
                                </td>
                                <td class="max-w-64 truncate text-danger-fg" :title="run.error ?? ''">{{ run.error ?? '' }}</td>
                            </tr>
                            <tr v-if="expanded.has(run.id) && hasDetails(run)" :id="`dbx-run-${run.id}`" class="bg-sunken">
                                <td />
                                <td :colspan="isAdmin ? 9 : 8" class="pb-3">
                                    <div class="max-w-4xl space-y-2">
                                        <p v-if="run.error" class="dbx-callout dbx-callout-danger"><BaseIcon name="alert-circle" /><span class="font-mono text-xs break-words whitespace-pre-wrap">{{ run.error }}</span></p>
                                        <RunDetails :run="run" />
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </section>

        <footer v-if="page > 1 || hasMore" class="dbx-statusbar">
            <button type="button" class="dbx-btn dbx-btn-ghost dbx-btn-sm" :disabled="loading || page <= 1" @click="load(page - 1)"><BaseIcon name="chevron-left" />Newer</button>
            <span>Page {{ page }}</span>
            <button type="button" class="dbx-btn dbx-btn-ghost dbx-btn-sm" :disabled="loading || !hasMore" @click="load(page + 1)">Older<BaseIcon name="chevron-right" /></button>
        </footer>
    </div>
</template>
