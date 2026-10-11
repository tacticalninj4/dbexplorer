<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { api, ApiError, describeError, segment } from '../api';
import BaseBadge from '../components/BaseBadge.vue';
import type { Tone } from '../components/BaseBadge.vue';
import BaseIcon from '../components/BaseIcon.vue';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import EmptyState from '../components/EmptyState.vue';
import SkeletonRows from '../components/SkeletonRows.vue';
import { formatDate } from '../composables/format';
import { findConnection } from '../composables/session';

/**
 * /:label/sessions (PRD §7.11), admins only: pg_stat_activity for this
 * label's database. Cancel stops a session's running statement; terminate
 * closes the session (password re-confirmation, 423). Both only reach
 * sessions of the same database login (docs/security.md §4).
 */
const route = useRoute();
const label = computed(() => String(route.params.label));
const isAdmin = computed(() => findConnection(label.value)?.role === 'admin');

interface Session {
    pid: number;
    usename: string | null;
    client: string | null;
    application_name: string | null;
    backend_type: string | null;
    state: string | null;
    wait_event_type: string | null;
    wait_event: string | null;
    backend_start: string | null;
    state_change: string | null;
    query_start: string | null;
    /** The first 500 characters. */
    query: string | null;
    /** A PostgreSQL interval, e.g. "00:00:01.5" or "2 days 03:04:05". */
    query_age: string | null;
}

const sessions = ref<Session[] | null>(null);
const error = ref<string | null>(null);
const loading = ref(false);
const autoRefresh = ref(true);
const showIdle = ref(true);
const showBackground = ref(false);
const expanded = ref(new Set<number>());
const notice = ref<string | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;

const visible = computed(() =>
    (sessions.value ?? []).filter(
        (session) => (showBackground.value || !isBackground(session)) && (showIdle.value || session.state !== 'idle'),
    ),
);
/** A server process, not a connection. Other logins' rows have no backend_type (pg_read_all_stats shows it). */
function isBackground(session: Session): boolean {
    return session.backend_type !== null && session.backend_type !== 'client backend';
}

/** Without pg_read_all_stats, another login's row hides everything but its pid and user. */
function isRestricted(session: Session): boolean {
    return session.backend_type === null;
}

const hiddenCount = computed(() => (sessions.value?.length ?? 0) - visible.value.length);

async function load(): Promise<void> {
    // Admins only: the API answers 403 to anyone else, so don't ask.
    if (!isAdmin.value) {
        return;
    }
    loading.value = true;
    try {
        sessions.value = (await api<{ data: Session[] }>(`${segment(label.value)}/sessions`)).data;
        error.value = null;
    } catch (e) {
        error.value = describeError(e, 'The sessions could not be loaded.');
    } finally {
        loading.value = false;
        schedule();
    }
}

/** Auto-refresh every 5 s, paused while a confirmation is open. */
function schedule(): void {
    clearTimeout(timer);
    if (autoRefresh.value) {
        timer = setTimeout(() => (action.value ? schedule() : void load()), 5000);
    }
}

watch(autoRefresh, schedule);

/** "00:01:02.5" → "1 min 2 s"; "2 days 03:04:05" → "2 d 3 h". */
function duration(interval: string | null): string {
    if (!interval) {
        return '—';
    }
    const match = /^(-)?(?:(\d+) days? )?(-)?(\d+):(\d+):(\d+(?:\.\d+)?)$/.exec(interval.trim());
    if (!match) {
        return interval;
    }
    if (match[1] || match[3]) {
        return '0 s';
    }
    const days = Number(match[2] ?? 0);
    const hours = Number(match[4]);
    const minutes = Number(match[5]);
    const seconds = Number(match[6]);
    if (days > 0) {
        return `${days} d ${hours} h`;
    }
    if (hours > 0) {
        return `${hours} h ${minutes} min`;
    }
    if (minutes > 0) {
        return `${minutes} min ${Math.floor(seconds)} s`;
    }

    return seconds < 10 ? `${seconds.toFixed(1)} s` : `${Math.floor(seconds)} s`;
}

function stateTone(state: string | null): Tone {
    if (state === 'active') {
        return 'accent';
    }
    if (state === 'idle in transaction') {
        return 'warning';
    }

    return state === 'idle in transaction (aborted)' ? 'danger' : 'neutral';
}

function toggleExpanded(pid: number): void {
    const next = new Set(expanded.value);
    if (!next.delete(pid)) {
        next.add(pid);
    }
    expanded.value = next;
}

// Cancel / terminate -------------------------------------------------------
const action = ref<{ kind: 'cancel' | 'terminate'; session: Session } | null>(null);
const actionBusy = ref(false);
const actionError = ref<string | null>(null);

function ask(kind: 'cancel' | 'terminate', session: Session): void {
    action.value = { kind, session };
    actionError.value = null;
}

async function signal(): Promise<void> {
    const current = action.value;
    if (!current || actionBusy.value) {
        return;
    }
    actionBusy.value = true;
    actionError.value = null;
    try {
        await api(`${segment(label.value)}/sessions/${current.session.pid}/${current.kind}`, { method: 'POST' });
        action.value = null;
        notice.value = current.kind === 'cancel' ? `Sent a cancel to session ${current.session.pid}.` : `Terminated session ${current.session.pid}.`;
        await load();
    } catch (e) {
        actionError.value = e instanceof ApiError && e.status === 404
            ? "That session doesn't belong to this connection's database login (or has already ended), so DbExplorer can't signal it."
            : e instanceof ApiError && e.status === 423
                ? 'Terminating a session needs your password. Nothing was sent.'
                : describeError(e);
    } finally {
        actionBusy.value = false;
    }
}

watch(label, () => {
    sessions.value = null;
    void load();
}, { immediate: true });
onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <header class="dbx-page-header">
            <BaseIcon name="users" class="text-fg-subtle" />
            <h1 class="text-lg font-semibold">Active sessions</h1>
            <span class="text-sm text-fg-muted">Connections to the {{ label }} database, from pg_stat_activity.</span>
        </header>

        <div v-if="isAdmin" class="dbx-toolbar">
            <button type="button" class="dbx-btn" :disabled="loading" @click="load"><BaseIcon name="refresh" />Refresh</button>
            <label class="dbx-check"><input v-model="autoRefresh" type="checkbox">Auto-refresh every 5 s</label>
            <span class="dbx-separator" aria-hidden="true" />
            <label class="dbx-check"><input v-model="showIdle" type="checkbox">Show idle</label>
            <label class="dbx-check"><input v-model="showBackground" type="checkbox">Show background processes</label>
            <span class="ml-auto text-xs text-fg-muted tabular-nums" role="status">
                <span v-if="loading" class="dbx-spinner mr-1.5 size-3 align-[-2px]" aria-hidden="true" />{{ visible.length }} shown<template v-if="hiddenCount"> · {{ hiddenCount }} hidden by filters</template>
            </span>
        </div>

        <div v-if="notice" role="status" class="dbx-banner border-success-line bg-success-bg text-success-fg">
            <BaseIcon name="check-circle" /><span class="flex-1">{{ notice }}</span>
            <button type="button" class="dbx-btn dbx-btn-ghost dbx-btn-icon dbx-btn-sm" aria-label="Dismiss" @click="notice = null"><BaseIcon name="x" /></button>
        </div>

        <div class="min-h-0 flex-1 overflow-auto p-4">
            <EmptyState v-if="!isAdmin" icon="shield" title="Admins only">Only admins of {{ label }} can see and signal its sessions.</EmptyState>
            <template v-else>
                <div class="dbx-callout dbx-callout-info mb-3 max-w-5xl">
                    <BaseIcon name="info" />
                    <span>
                        Cancel and terminate only reach sessions of this connection's own database login: a pid that belongs to another login (your app, other tools) is refused.
                        Other logins' query text shows only if that login has <code class="font-mono">pg_read_all_stats</code>. Both actions are audited.
                    </span>
                </div>
                <div v-if="error" role="alert" class="dbx-callout dbx-callout-danger mb-3"><BaseIcon name="alert-circle" /><span>{{ error }}</span></div>
                <div v-if="sessions === null && !error" class="dbx-card"><SkeletonRows :rows="4" :columns="7" /></div>
                <EmptyState v-else-if="sessions && visible.length === 0" icon="users" title="No sessions to show">
                    {{ hiddenCount ? 'The filters above hide every session.' : 'Nothing else is connected to this database.' }}
                </EmptyState>
                <div v-else-if="sessions" class="dbx-card overflow-x-auto">
                    <table class="dbx-table">
                        <thead>
                            <tr>
                                <th scope="col" class="text-right">PID</th>
                                <th scope="col">User · application</th>
                                <th scope="col">State</th>
                                <th scope="col" class="text-right">Running for</th>
                                <th scope="col">Waiting on</th>
                                <th scope="col" class="w-full">Query</th>
                                <th scope="col" class="text-right"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="session in visible" :key="session.pid">
                                <td class="text-right font-mono text-xs tabular-nums">{{ session.pid }}</td>
                                <td class="max-w-56">
                                    <span class="block truncate font-medium" :title="session.usename ?? undefined">{{ session.usename ?? '—' }}</span>
                                    <span class="block truncate text-xs text-fg-muted" :title="session.application_name ?? undefined">{{ isRestricted(session) ? 'details hidden: another login' : session.application_name || (isBackground(session) ? session.backend_type : 'no application name') }}</span>
                                    <span v-if="session.client" class="block font-mono text-xs text-fg-subtle">{{ session.client }}</span>
                                </td>
                                <td class="whitespace-nowrap">
                                    <BaseBadge v-if="session.state" :tone="stateTone(session.state)" :title="session.state_change ? `Since ${formatDate(session.state_change)}` : undefined">{{ session.state }}</BaseBadge>
                                    <span v-else class="text-fg-subtle">—</span>
                                </td>
                                <td class="text-right whitespace-nowrap tabular-nums" :title="session.query_start ? `Query started ${formatDate(session.query_start)}` : undefined">
                                    {{ session.state === 'active' ? duration(session.query_age) : '—' }}
                                </td>
                                <td class="text-xs whitespace-nowrap">
                                    <template v-if="session.wait_event">{{ session.wait_event_type }}: <span class="font-mono">{{ session.wait_event }}</span></template>
                                    <span v-else class="text-fg-subtle">—</span>
                                </td>
                                <td class="min-w-72">
                                    <template v-if="session.query">
                                        <pre class="font-mono text-xs whitespace-pre-wrap text-fg" :class="expanded.has(session.pid) ? '' : 'line-clamp-2'">{{ session.query }}</pre>
                                        <button v-if="session.query.length > 120 || session.query.includes('\n')" type="button" class="dbx-link mt-0.5 text-xs" :aria-expanded="expanded.has(session.pid)" @click="toggleExpanded(session.pid)">
                                            {{ expanded.has(session.pid) ? 'Show less' : 'Show all' }}<template v-if="session.query.length >= 500"> (first 500 characters)</template>
                                        </button>
                                    </template>
                                    <span v-else class="text-xs text-fg-subtle">{{ isRestricted(session) ? 'Not visible to this login' : '—' }}</span>
                                </td>
                                <td class="text-right whitespace-nowrap">
                                    <span v-if="isRestricted(session)" class="text-xs text-fg-subtle" title="Only this connection's own login can be cancelled or terminated">another login</span>
                                    <span v-else-if="!isBackground(session)" class="inline-flex gap-1">
                                        <button type="button" class="dbx-btn dbx-btn-sm" :disabled="session.state !== 'active'" :title="session.state === 'active' ? 'Stop the running statement; the session stays connected' : 'Nothing is running'" :aria-label="`Cancel the query of session ${session.pid}`" @click="ask('cancel', session)"><BaseIcon name="stop" />Cancel</button>
                                        <button type="button" class="dbx-btn dbx-btn-sm dbx-btn-danger-ghost" :aria-label="`Terminate session ${session.pid}`" @click="ask('terminate', session)"><BaseIcon name="x-circle" />Terminate</button>
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>

        <ConfirmDialog
            :open="action !== null"
            :title="action?.kind === 'terminate' ? `Terminate session ${action.session.pid}?` : `Cancel the query of session ${action?.session.pid ?? ''}?`"
            :message="action?.kind === 'terminate'
                ? 'Its connection closes and any open transaction rolls back. Whatever was using it sees a dropped connection. You will be asked for your password.'
                : 'Its running statement stops with an error; the session stays connected and its transaction, if any, is aborted.'"
            :action="action?.kind === 'terminate' ? 'Terminate session' : 'Cancel query'"
            :danger="action?.kind === 'terminate'"
            :dismiss="action?.kind === 'terminate' ? 'Keep session' : 'Keep running'"
            :busy="actionBusy"
            :error="actionError"
            @cancel="action = null"
            @confirm="signal"
        >
            <dl v-if="action" class="dbx-kv">
                <dt>User</dt><dd>{{ action.session.usename ?? '—' }}</dd>
                <dt>Application</dt><dd>{{ action.session.application_name || '—' }}</dd>
                <dt>State</dt><dd>{{ action.session.state ?? '—' }}</dd>
            </dl>
            <pre v-if="action?.session.query" class="dbx-code max-h-40 whitespace-pre-wrap">{{ action.session.query }}</pre>
        </ConfirmDialog>
    </div>
</template>
