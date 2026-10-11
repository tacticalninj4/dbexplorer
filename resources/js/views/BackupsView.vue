<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { api, describeError, segment } from '../api';
import BaseBadge from '../components/BaseBadge.vue';
import BaseIcon from '../components/BaseIcon.vue';
import CodeBlock from '../components/CodeBlock.vue';
import EmptyState from '../components/EmptyState.vue';
import SkeletonRows from '../components/SkeletonRows.vue';
import { formatBytes, formatDate } from '../composables/format';
import { findConnection } from '../composables/session';

/**
 * /:label/backups (PRD §7.10), admins only: the pg_dump preflight, a new
 * backup (custom format, whole database: what the API offers), and the
 * stored dumps, downloaded through the run's signed URL. Restoring is a CLI
 * job by design (docs/security.md §7): this page only shows the command.
 */
const route = useRoute();
const label = computed(() => String(route.params.label));
const isAdmin = computed(() => findConnection(label.value)?.role === 'admin');

interface BackupFile {
    name: string;
    bytes: number;
    created_at: string;
}

interface Backups {
    /** Null when pg_dump can dump this database, the problem otherwise. */
    preflight: string | null;
    keep: number;
    backups: BackupFile[];
}

interface RunState {
    id: number;
    status: 'queued' | 'running' | 'succeeded' | 'failed' | 'cancelled';
    error: string | null;
}

const data = ref<Backups | null>(null);
const error = ref<string | null>(null);
const creating = ref(false);
const createError = ref<string | null>(null);
const run = ref<RunState | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;

async function load(): Promise<void> {
    error.value = null;
    try {
        data.value = (await api<{ data: Backups }>(`${segment(label.value)}/backups`)).data;
    } catch (e) {
        error.value = describeError(e, 'The backups could not be loaded.');
    }
}

async function create(): Promise<void> {
    creating.value = true;
    createError.value = null;
    try {
        run.value = (await api<{ data: RunState }>(`${segment(label.value)}/backups`, { method: 'POST' })).data;
        poll();
    } catch (e) {
        createError.value = describeError(e, 'The backup could not be started.');
        // A 422 carries a fresh preflight verdict: show it where it belongs.
        await load();
    } finally {
        creating.value = false;
    }
}

/** Follow the queued backup until it finishes, then refresh the list. */
function poll(): void {
    clearTimeout(timer);
    timer = setTimeout(async () => {
        const current = run.value;
        if (!current) {
            return;
        }
        try {
            run.value = (await api<{ data: RunState }>(`${segment(label.value)}/runs/${current.id}`)).data;
        } catch {
            return;
        }
        if (run.value.status === 'queued' || run.value.status === 'running') {
            poll();
        } else {
            await load();
        }
    }, 2000);
}

/** Dumps are stored as run-{id}.dump: the run's signed URL downloads it. */
function runId(file: BackupFile): number | null {
    const match = /^run-(\d+)\.dump$/.exec(file.name);

    return match ? Number(match[1]) : null;
}

const downloadError = ref<string | null>(null);

async function download(file: BackupFile): Promise<void> {
    const id = runId(file);
    downloadError.value = null;
    if (id === null) {
        return;
    }
    try {
        const response = await api<{ data: { url: string } }>(`${segment(label.value)}/runs/${id}/download`);
        window.location.href = response.data.url;
    } catch (e) {
        downloadError.value = describeError(e, 'The download link could not be created.');
    }
}

const relative = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

function age(value: string): string {
    const minutes = Math.round((new Date(value).getTime() - Date.now()) / 60000);
    if (Number.isNaN(minutes)) {
        return '';
    }
    if (Math.abs(minutes) < 60) {
        return relative.format(minutes, 'minute');
    }
    if (Math.abs(minutes) < 60 * 48) {
        return relative.format(Math.round(minutes / 60), 'hour');
    }

    return relative.format(Math.round(minutes / 1440), 'day');
}

const restoreCommand = computed(() => {
    const newest = data.value?.backups[0]?.name;

    return `pg_restore --host <host> --username <login you control> --dbname <target database> --clean --if-exists dbx-${newest ?? 'run-<id>.dump'}`;
});

watch(label, load, { immediate: true });
onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <header class="dbx-page-header">
            <BaseIcon name="archive" class="text-fg-subtle" />
            <h1 class="text-lg font-semibold">Backups</h1>
            <span class="text-sm text-fg-muted">On-demand pg_dump of the {{ label }} database, kept on the server's private backup disk.</span>
        </header>

        <div class="min-h-0 flex-1 overflow-auto p-4">
            <EmptyState v-if="!isAdmin" icon="shield" title="Admins only">Only admins of {{ label }} can back it up or download backups.</EmptyState>
            <div v-else class="max-w-4xl space-y-5">
                <div v-if="error" role="alert" class="dbx-callout dbx-callout-danger">
                    <BaseIcon name="alert-circle" /><span class="flex-1">{{ error }}</span>
                    <button type="button" class="dbx-btn dbx-btn-sm" @click="load"><BaseIcon name="refresh" />Retry</button>
                </div>
                <div v-else-if="data === null" class="dbx-card"><SkeletonRows :rows="2" :columns="2" /></div>

                <template v-else>
                    <section class="dbx-card p-4" aria-labelledby="dbx-backup-new">
                        <h2 id="dbx-backup-new" class="mb-3 text-base font-semibold">New backup</h2>
                        <div v-if="data.preflight" id="dbx-preflight" role="alert" class="dbx-callout dbx-callout-danger mb-3">
                            <BaseIcon name="alert-circle" />
                            <div>
                                <p class="font-medium">Backups can't run on this server right now</p>
                                <p class="mt-0.5">{{ data.preflight }}</p>
                                <p class="mt-1 text-xs">An operator fixes this on the server: install a pg_dump at least as new as the database, and point <code class="font-mono">dbexplorer.backup.pg_dump_path</code> at it.</p>
                            </div>
                        </div>
                        <div v-else class="dbx-callout dbx-callout-success mb-3">
                            <BaseIcon name="check-circle" /><span>pg_dump is ready: its version can dump this server.</span>
                        </div>

                        <dl class="dbx-kv mb-4">
                            <dt>Format</dt>
                            <dd>Custom (a <code class="font-mono">pg_restore</code> archive, compressed)</dd>
                            <dt>Contents</dt>
                            <dd>The whole database, read through the admin connection's database login</dd>
                            <dt>Retention</dt>
                            <dd>The newest {{ data.keep }} per connection; older ones are deleted after each new backup</dd>
                        </dl>

                        <div class="flex flex-wrap items-center gap-3">
                            <button type="button" class="dbx-btn dbx-btn-primary" :disabled="data.preflight !== null || creating || (run !== null && (run.status === 'queued' || run.status === 'running'))" :aria-describedby="data.preflight ? 'dbx-preflight' : undefined" @click="create">
                                <span v-if="creating" class="dbx-spinner border-current/30 border-t-current" aria-hidden="true" />
                                <BaseIcon v-else name="archive" />Create backup
                            </button>
                            <span v-if="data.preflight" class="text-sm text-fg-muted">Disabled until the preflight above passes.</span>
                            <span v-else-if="run" role="status" class="inline-flex items-center gap-2 text-sm">
                                <template v-if="run.status === 'queued' || run.status === 'running'"><span class="dbx-spinner" aria-hidden="true" />Backup run #{{ run.id }} is {{ run.status }}…</template>
                                <template v-else-if="run.status === 'succeeded'"><BaseIcon name="check-circle" class="text-success-fg" />Backup run #{{ run.id }} finished.</template>
                                <template v-else><BaseIcon name="x-circle" class="text-danger-fg" />Backup run #{{ run.id }} {{ run.status }}{{ run.error ? `: ${run.error}` : '.' }}</template>
                                <router-link :to="{ name: 'runs', params: { label } }" class="dbx-link">Runs</router-link>
                            </span>
                        </div>
                        <div v-if="createError" role="alert" class="dbx-callout dbx-callout-danger mt-3"><BaseIcon name="alert-circle" /><span>{{ createError }}</span></div>
                    </section>

                    <section aria-labelledby="dbx-backup-list">
                        <h2 id="dbx-backup-list" class="mb-2 flex items-center gap-2 text-sm font-semibold">
                            Stored backups <span class="text-xs font-normal text-fg-subtle tabular-nums">{{ data.backups.length }} of {{ data.keep }} kept</span>
                        </h2>
                        <div v-if="downloadError" role="alert" class="dbx-callout dbx-callout-danger mb-2"><BaseIcon name="alert-circle" /><span>{{ downloadError }}</span></div>
                        <EmptyState v-if="data.backups.length === 0" icon="archive" title="No backups yet">Backups you create show up here, newest first.</EmptyState>
                        <div v-else class="dbx-card overflow-x-auto">
                            <table class="dbx-table">
                                <thead><tr><th scope="col">File</th><th scope="col" class="text-right">Size</th><th scope="col">Created</th><th scope="col" class="text-right"><span class="sr-only">Actions</span></th></tr></thead>
                                <tbody>
                                    <tr v-for="(file, index) in data.backups" :key="file.name">
                                        <td class="font-mono text-xs">
                                            {{ file.name }}
                                            <BaseBadge v-if="index === data.backups.length - 1 && data.backups.length >= data.keep" tone="warning" class="ml-2 font-sans" title="The next backup deletes this one">removed next</BaseBadge>
                                        </td>
                                        <td class="text-right tabular-nums">{{ formatBytes(file.bytes) }}</td>
                                        <td class="whitespace-nowrap"><span class="tabular-nums">{{ formatDate(file.created_at) }}</span> <span class="text-xs text-fg-subtle">{{ age(file.created_at) }}</span></td>
                                        <td class="text-right">
                                            <button type="button" class="dbx-btn dbx-btn-sm" :disabled="runId(file) === null" :title="runId(file) === null ? 'Not made by DbExplorer: fetch it from the backup disk' : 'Download through a link valid for 10 minutes'" @click="download(file)"><BaseIcon name="download" />Download</button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="space-y-2" aria-labelledby="dbx-backup-restore">
                        <h2 id="dbx-backup-restore" class="text-sm font-semibold">Restoring</h2>
                        <p class="text-sm text-fg-muted">
                            Restoring is deliberately not available here: a button that can overwrite the database you browse would undo every other safeguard.
                            Restore from a terminal, into a database you choose (a copy first), with a login you control. <code class="font-mono">pg_restore --list</code> shows what a dump contains first.
                        </p>
                        <CodeBlock :code="restoreCommand" copy-label="Copy command" />
                    </section>
                </template>
            </div>
        </div>
    </div>
</template>
