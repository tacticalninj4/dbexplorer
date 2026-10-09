<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { api, describeError, segment } from '../api';
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

const STATUS_STYLES: Record<Run['status'], string> = {
    queued: 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300',
    running: 'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300',
    succeeded: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    failed: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
    cancelled: 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
};

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
    <section class="min-h-0 flex-1 overflow-y-auto p-4" aria-label="Background runs">
        <h1 class="mb-3 text-lg font-semibold">Runs</h1>

        <p v-if="error" role="alert" class="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">{{ error }}</p>

        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-zinc-200 text-left text-xs text-zinc-500 dark:border-zinc-800">
                    <th scope="col" class="px-2 py-1.5">#</th>
                    <th scope="col" class="px-2 py-1.5">Type</th>
                    <th scope="col" class="px-2 py-1.5">Status</th>
                    <th scope="col" class="px-2 py-1.5">Progress</th>
                    <th scope="col" class="px-2 py-1.5">Created</th>
                    <th scope="col" class="px-2 py-1.5">Error</th>
                    <th scope="col" class="px-2 py-1.5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="run in runs" :key="run.id" class="border-b border-zinc-100 dark:border-zinc-800/60">
                    <td class="px-2 py-1.5 tabular-nums text-zinc-400">{{ run.id }}</td>
                    <td class="px-2 py-1.5">{{ run.type }}</td>
                    <td class="px-2 py-1.5">
                        <span class="rounded-full px-2 py-0.5 text-xs" :class="STATUS_STYLES[run.status]">{{ run.status }}</span>
                    </td>
                    <td class="px-2 py-1.5 tabular-nums">{{ run.progress === null ? '—' : `${run.progress}%` }}</td>
                    <td class="px-2 py-1.5 whitespace-nowrap text-zinc-500 dark:text-zinc-400" :title="run.created_at">{{ formatDate(run.created_at) }}</td>
                    <td class="max-w-xs truncate px-2 py-1.5 text-red-700 dark:text-red-300" :title="run.error ?? ''">{{ run.error ?? '' }}</td>
                    <td class="px-2 py-1.5 text-right whitespace-nowrap">
                        <button v-if="run.status === 'queued' || run.status === 'running'" type="button" class="dbx-btn dbx-btn-danger" @click="cancel(run)">Cancel</button>
                        <button v-if="run.result_available" type="button" class="dbx-btn" @click="download(run)">Download</button>
                    </td>
                </tr>
            </tbody>
        </table>

        <p v-if="!loading && runs.length === 0" class="p-6 text-center text-sm text-zinc-500 dark:text-zinc-400">
            No runs yet. Exports, imports, scripts and backups land here.
        </p>

        <div class="mt-3 flex items-center justify-between text-sm">
            <button type="button" class="dbx-btn" :disabled="loading || page <= 1" @click="load(page - 1)">← Newer</button>
            <span class="text-zinc-500 dark:text-zinc-400">Page {{ page }}</span>
            <button type="button" class="dbx-btn" :disabled="loading || !hasMore" @click="load(page + 1)">Older →</button>
        </div>
    </section>
</template>
