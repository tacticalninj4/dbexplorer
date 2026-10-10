<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { api, describeError, segment } from '../api';
import BaseBadge from '../components/BaseBadge.vue';
import type { Tone } from '../components/BaseBadge.vue';
import EmptyState from '../components/EmptyState.vue';
import BaseIcon from '../components/BaseIcon.vue';
import type { IconName } from '../components/BaseIcon.vue';
import SkeletonRows from '../components/SkeletonRows.vue';
import { formatDate } from '../composables/format';
import type { Role } from '../types';

/**
 * The Runs page (PRD §7.5): background queries, exports, imports, scripts
 * and backups on this connection, with their progress. While any run is
 * still going the list polls every 2 seconds; cancel stops the run (the
 * backend kills the query), and a finished run's result downloads through
 * a short-lived signed URL.
 */
const route = useRoute();
const label = computed(() => String(route.params.label));

interface Run {
    id: number;
    type: string;
    status: 'queued' | 'running' | 'succeeded' | 'failed' | 'cancelled';
    progress: number | null;
    label: string;
    role: Role;
    user_id: string | null;
    cancel_requested: boolean;
    error: string | null;
    result_available: boolean;
    created_at: string;
    started_at: string | null;
    finished_at: string | null;
}

interface RunsPage {
    data: Run[];
    meta: { page: number; per_page: number; has_more: boolean };
}

const runs = ref<Run[]>([]);
const hasMore = ref(false);
const page = ref(1);
const error = ref<string | null>(null);
const loading = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;
let sequence = 0;

const STATUS: Record<Run['status'], { tone: Tone; icon: IconName }> = {
    queued: { tone: 'neutral', icon: 'clock' },
    running: { tone: 'accent', icon: 'refresh' },
    succeeded: { tone: 'success', icon: 'check' },
    failed: { tone: 'danger', icon: 'x-circle' },
    cancelled: { tone: 'warning', icon: 'ban' },
};
const loaded = ref(false);

const active = computed(() => runs.value.some((run) => run.status === 'queued' || run.status === 'running'));

async function load(pageNumber = page.value): Promise<void> {
    const id = ++sequence;
    loading.value = true;
    error.value = null;

    try {
        const response = await api<RunsPage>(`${segment(label.value)}/runs`, { query: { page: pageNumber } });

        if (id !== sequence) {
            return;
        }

        runs.value = response.data;
        loaded.value = true;
        page.value = response.meta.page;
        hasMore.value = response.meta.has_more;
    } catch (e) {
        if (id === sequence) {
            error.value = describeError(e, 'The runs could not be loaded.');
        }
    } finally {
        if (id === sequence) {
            loading.value = false;
        }
    }
}

function schedule(): void {
    clearTimeout(timer);
    timer = setTimeout(() => void load(), 2000);
}

async function cancel(run: Run): Promise<void> {
    try {
        await api(`${segment(label.value)}/runs/${run.id}/cancel`, { method: 'POST' });
        await load();
    } catch (e) {
        error.value = describeError(e);
    }
}

async function download(run: Run): Promise<void> {
    try {
        const response = await api<{ data: { url: string } }>(`${segment(label.value)}/runs/${run.id}/download`);
        window.location.href = response.data.url;
    } catch (e) {
        error.value = describeError(e);
    }
}

void load();

// Poll every 2 s while something is queued or running, then stop.
watch(active, (isActive) => (isActive ? schedule() : clearTimeout(timer)));

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <header class="dbx-page-header">
            <BaseIcon name="activity" class="text-fg-subtle" />
            <h1 class="text-lg font-semibold">Runs</h1>
            <span class="text-sm text-fg-muted">Background queries, exports, imports, scripts and backups on {{ label }}.</span>
            <span v-if="active" class="ml-auto inline-flex items-center gap-1.5 text-xs text-fg-muted" role="status"><span class="dbx-spinner size-3" aria-hidden="true" />Updating every 2 seconds</span>
        </header>

        <section class="min-h-0 flex-1 overflow-y-auto p-4" aria-label="Background runs">
            <div v-if="error" role="alert" class="dbx-callout dbx-callout-danger mb-3"><BaseIcon name="alert-circle" />{{ error }}</div>

            <div v-if="!loaded && loading" class="dbx-card"><SkeletonRows :rows="4" :columns="6" /></div>
            <EmptyState v-else-if="loaded && runs.length === 0" icon="activity" title="No runs yet">
                Exports, imports, scripts, backups and CONCURRENTLY index builds run in the background and show up here with their progress.
            </EmptyState>
            <div v-else-if="runs.length" class="dbx-card overflow-x-auto">
                <table class="dbx-table">
                    <thead>
                        <tr>
                            <th scope="col" class="w-px text-right">#</th>
                            <th scope="col">Type</th>
                            <th scope="col">Status</th>
                            <th scope="col">Progress</th>
                            <th scope="col">Created</th>
                            <th scope="col">Error</th>
                            <th scope="col" class="text-right"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="run in runs" :key="run.id">
                            <td class="text-right text-xs text-fg-subtle tabular-nums">{{ run.id }}</td>
                            <td class="font-medium">{{ run.type }}</td>
                            <td><BaseBadge :tone="STATUS[run.status].tone" :icon="STATUS[run.status].icon">{{ run.status }}<template v-if="run.cancel_requested && (run.status === 'queued' || run.status === 'running')"> · cancelling</template></BaseBadge></td>
                            <td class="tabular-nums">
                                <span v-if="run.progress === null" class="text-fg-subtle">—</span>
                                <span v-else class="flex items-center gap-2">
                                    <span class="h-1.5 w-20 overflow-hidden rounded-full bg-sunken" role="progressbar" :aria-valuenow="run.progress" aria-valuemin="0" aria-valuemax="100" :aria-label="`Run ${run.id} progress`">
                                        <span class="block h-full rounded-full bg-accent-solid" :style="{ width: `${run.progress}%` }" />
                                    </span>
                                    <span class="text-xs text-fg-muted">{{ run.progress }}%</span>
                                </span>
                            </td>
                            <td class="whitespace-nowrap text-fg-muted" :title="run.created_at">{{ formatDate(run.created_at) }}</td>
                            <td class="max-w-xs truncate text-danger-fg" :title="run.error ?? ''">{{ run.error ?? '' }}</td>
                            <td class="text-right whitespace-nowrap">
                                <span class="inline-flex gap-1.5">
                                    <button v-if="run.status === 'queued' || run.status === 'running'" type="button" class="dbx-btn dbx-btn-sm dbx-btn-danger-ghost" :disabled="run.cancel_requested" @click="cancel(run)"><BaseIcon name="stop" />Cancel</button>
                                    <button v-if="run.result_available" type="button" class="dbx-btn dbx-btn-sm" @click="download(run)"><BaseIcon name="download" />Download</button>
                                </span>
                            </td>
                        </tr>
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
