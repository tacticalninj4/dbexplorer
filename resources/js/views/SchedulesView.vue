<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, ApiError, describeError, segment } from '../api';
import BaseBadge from '../components/BaseBadge.vue';
import type { Tone } from '../components/BaseBadge.vue';
import BaseIcon from '../components/BaseIcon.vue';
import type { IconName } from '../components/BaseIcon.vue';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import EmptyState from '../components/EmptyState.vue';
import ModalDialog from '../components/ModalDialog.vue';
import ScheduleDialog from '../components/ScheduleDialog.vue';
import SkeletonRows from '../components/SkeletonRows.vue';
import { describeCron, nextRuns } from '../composables/cron';
import { lineDiff } from '../composables/diff';
import { formatDate } from '../composables/format';
import { listSavedQueries } from '../composables/savedQueries';
import type { SavedQuery } from '../composables/savedQueries';
import { cronTimeZone, plainPermissions } from '../composables/schedules';
import type { Schedule } from '../composables/schedules';
import { findConnection } from '../composables/session';

/**
 * /:label/schedules (PRD §7.9), admins only. A schedule runs its own
 * approved snapshot of a saved query's SQL, as the approving admin,
 * read-only unless the approval allowed writes or DDL. Re-sync shows what
 * changed in the saved query before approving the new SQL.
 */
const route = useRoute();
const router = useRouter();
const label = computed(() => String(route.params.label));
const isAdmin = computed(() => findConnection(label.value)?.role === 'admin');

interface RunSummary {
    id: number;
    status: 'queued' | 'running' | 'succeeded' | 'failed' | 'cancelled';
    error: string | null;
}

const schedules = ref<Schedule[] | null>(null);
const queries = ref<SavedQuery[]>([]);
const runs = ref<Map<number, RunSummary>>(new Map());
const error = ref<string | null>(null);
const notice = ref<string | null>(null);

const RUN_STATUS: Record<RunSummary['status'], { tone: Tone; icon: IconName }> = {
    queued: { tone: 'neutral', icon: 'clock' },
    running: { tone: 'accent', icon: 'refresh' },
    succeeded: { tone: 'success', icon: 'check' },
    failed: { tone: 'danger', icon: 'x-circle' },
    cancelled: { tone: 'warning', icon: 'ban' },
};

async function load(): Promise<void> {
    error.value = null;
    try {
        const [list, saved, recent] = await Promise.all([
            api<{ data: Schedule[] }>(`${segment(label.value)}/schedules`),
            listSavedQueries(label.value),
            api<{ data: RunSummary[] }>(`${segment(label.value)}/runs`, { query: { type: 'scheduled_query' } }).catch(() => ({ data: [] })),
        ]);
        schedules.value = list.data;
        queries.value = saved;
        runs.value = new Map(recent.data.map((run) => [run.id, run]));
    } catch (e) {
        error.value = describeError(e, 'The schedules could not be loaded.');
    }
}

const unapproved = computed(() => (schedules.value ?? []).filter((schedule) => !schedule.approved).length);
const dateTime = new Intl.DateTimeFormat(undefined, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

function nextRun(schedule: Schedule): string {
    if (!schedule.enabled || !schedule.approved) {
        return '—';
    }
    const [next] = nextRuns(schedule.cron, cronTimeZone(schedule), 1);

    return next ? dateTime.format(next) : '—';
}

/** The schedule's last run, as a 0- or 1-item list for v-for: it may be off the first page of runs. */
function lastRun(schedule: Schedule): RunSummary[] {
    const run = schedule.last_run_id === null ? undefined : runs.value.get(schedule.last_run_id);

    return run ? [run] : [];
}

function replace(updated: Schedule): void {
    schedules.value = (schedules.value ?? []).map((schedule) => (schedule.id === updated.id ? updated : schedule));
}

// Create and edit ----------------------------------------------------------
const dialog = ref<{ schedule: Schedule | null; queryId: number | null } | null>(null);

function onSaved(schedule: Schedule): void {
    const created = dialog.value?.schedule === null;
    dialog.value = null;
    notice.value = created ? `Schedule #${schedule.id} created and approved${schedule.enabled ? '' : ' (paused)'}.` : `Schedule #${schedule.id} saved.`;
    void load();
}

// ?new=<saved query id> from the saved queries page opens the form.
watch(
    () => route.query.new,
    (value) => {
        if (typeof value === 'string' && isAdmin.value) {
            dialog.value = { schedule: null, queryId: Number(value) || null };
            void router.replace({ query: { ...route.query, new: undefined } });
        }
    },
    { immediate: true },
);

// Enable / disable -----------------------------------------------------------
const toggling = ref<number | null>(null);

async function toggle(schedule: Schedule): Promise<void> {
    toggling.value = schedule.id;
    error.value = null;
    try {
        replace((await api<{ data: Schedule }>(`${segment(label.value)}/schedules/${schedule.id}`, { method: 'PATCH', body: { enabled: !schedule.enabled } })).data);
    } catch (e) {
        error.value = describeError(e);
    } finally {
        toggling.value = null;
    }
}

// Re-sync ------------------------------------------------------------------
const resyncing = ref<Schedule | null>(null);
const resyncWrites = ref(false);
const resyncDdl = ref(false);
const resyncError = ref<string | null>(null);
const resyncBusy = ref(false);

const resyncSource = computed(() => queries.value.find((query) => query.id === resyncing.value?.saved_query_id) ?? null);
const resyncDiff = computed(() => (resyncing.value && resyncSource.value ? lineDiff(resyncing.value.sql ?? '', resyncSource.value.sql) : []));
const resyncChanged = computed(() => resyncDiff.value.some((line) => line.kind !== 'same'));

function openResync(schedule: Schedule): void {
    resyncing.value = schedule;
    resyncWrites.value = schedule.allow_writes;
    resyncDdl.value = schedule.allow_ddl;
    resyncError.value = null;
}

watch(resyncDdl, (ddl) => ddl && (resyncWrites.value = true));

async function resync(): Promise<void> {
    const schedule = resyncing.value;
    if (!schedule || resyncBusy.value) {
        return;
    }
    resyncBusy.value = true;
    resyncError.value = null;
    try {
        const updated = (await api<{ data: Schedule }>(`${segment(label.value)}/schedules/${schedule.id}/resync`, {
            method: 'POST',
            body: { allow_writes: resyncWrites.value, allow_ddl: resyncDdl.value },
        })).data;
        replace(updated);
        resyncing.value = null;
        notice.value = `Schedule #${updated.id} re-approved with the saved query's current SQL.${updated.enabled ? '' : ' It is still paused: enable it when you are ready.'}`;
    } catch (e) {
        resyncError.value = e instanceof ApiError && e.status === 423
            ? 'Allowing writes or DDL needs your password. Nothing changed: confirm your password, or untick the permission.'
            : plainPermissions(describeError(e, 'The schedule could not be re-synced.'));
    } finally {
        resyncBusy.value = false;
    }
}

// Delete -------------------------------------------------------------------
const deleting = ref<Schedule | null>(null);
const deleteBusy = ref(false);
const deleteError = ref<string | null>(null);

async function remove(): Promise<void> {
    const schedule = deleting.value;
    if (!schedule) {
        return;
    }
    deleteBusy.value = true;
    deleteError.value = null;
    try {
        await api(`${segment(label.value)}/schedules/${schedule.id}`, { method: 'DELETE' });
        deleting.value = null;
        notice.value = `Schedule #${schedule.id} deleted.`;
        await load();
    } catch (e) {
        deleteError.value = describeError(e);
    } finally {
        deleteBusy.value = false;
    }
}

watch(label, load, { immediate: true });
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <header class="dbx-page-header">
            <BaseIcon name="calendar" class="text-fg-subtle" />
            <h1 class="text-lg font-semibold">Schedules</h1>
            <span class="text-sm text-fg-muted">Saved queries that run on a cron on {{ label }}, as the admin who approved them.</span>
            <button v-if="isAdmin" type="button" class="dbx-btn dbx-btn-primary ml-auto" @click="dialog = { schedule: null, queryId: null }"><BaseIcon name="plus" />New schedule</button>
        </header>

        <div v-if="notice" role="status" class="dbx-banner border-success-line bg-success-bg text-success-fg">
            <BaseIcon name="check-circle" /><span class="flex-1">{{ notice }}</span>
            <button type="button" class="dbx-btn dbx-btn-ghost dbx-btn-icon dbx-btn-sm" aria-label="Dismiss" @click="notice = null"><BaseIcon name="x" /></button>
        </div>

        <div class="min-h-0 flex-1 overflow-auto p-4">
            <EmptyState v-if="!isAdmin" icon="shield" title="Admins only">Schedules run unattended with an admin's approval, so only admins of {{ label }} can see and manage them.</EmptyState>
            <template v-else>
                <div v-if="error" role="alert" class="dbx-callout dbx-callout-danger mb-3">
                    <BaseIcon name="alert-circle" /><span class="flex-1">{{ error }}</span>
                    <button type="button" class="dbx-btn dbx-btn-sm" @click="load"><BaseIcon name="refresh" />Retry</button>
                </div>

                <div class="dbx-callout dbx-callout-info mb-3 max-w-5xl">
                    <BaseIcon name="info" />
                    <span>
                        Each schedule runs the <strong>snapshot</strong> of SQL approved when it was created or last re-synced, never the saved query's live text: editing the saved query changes nothing until you re-sync.
                        Runs are read-only unless the approval allowed writes or DDL, and appear on the <router-link :to="{ name: 'runs', params: { label } }" class="dbx-link font-medium">Runs</router-link> page.
                    </span>
                </div>

                <div v-if="unapproved" role="status" class="dbx-callout dbx-callout-warning mb-3 max-w-5xl">
                    <BaseIcon name="alert" />
                    <span>
                        {{ unapproved === 1 ? 'One schedule has' : `${unapproved} schedules have` }} no approved SQL and can't run. They predate approval snapshots, so nobody signed off on their SQL.
                        Check each one with <strong>Review and approve</strong>, then enable it.
                    </span>
                </div>

                <div v-if="schedules === null && !error" class="dbx-card"><SkeletonRows :rows="3" :columns="6" /></div>
                <EmptyState v-else-if="schedules && schedules.length === 0" icon="calendar" title="No schedules yet">
                    Schedule a saved query to run it every hour, day or week, and get mail when it fails or finds rows.
                    <template #actions>
                        <button type="button" class="dbx-btn dbx-btn-primary" @click="dialog = { schedule: null, queryId: null }"><BaseIcon name="plus" />New schedule</button>
                        <router-link :to="{ name: 'queries', params: { label } }" class="dbx-btn"><BaseIcon name="bookmark" />Saved queries</router-link>
                    </template>
                </EmptyState>
                <div v-else-if="schedules" class="dbx-card overflow-x-auto">
                    <table class="dbx-table">
                        <thead>
                            <tr>
                                <th scope="col">Schedule</th>
                                <th scope="col">When</th>
                                <th scope="col">Next run</th>
                                <th scope="col">Last run</th>
                                <th scope="col">May</th>
                                <th scope="col">Enabled</th>
                                <th scope="col" class="text-right"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="schedule in schedules" :key="schedule.id">
                                <td class="min-w-48">
                                    <span class="flex items-center gap-1.5">
                                        <router-link :to="{ name: 'queries', params: { label }, query: { id: String(schedule.saved_query_id) } }" class="dbx-link font-medium">{{ schedule.name ?? `Saved query #${schedule.saved_query_id}` }}</router-link>
                                        <span class="text-xs text-fg-subtle tabular-nums">#{{ schedule.id }}</span>
                                    </span>
                                    <span class="mt-1 flex flex-wrap gap-1">
                                        <BaseBadge v-if="!schedule.approved" tone="warning" icon="lock" title="No approved SQL: re-sync to approve it">Not approved</BaseBadge>
                                        <BaseBadge v-if="!schedule.in_sync" tone="accent" icon="refresh" title="The saved query's SQL differs from the approved snapshot">Saved query changed</BaseBadge>
                                    </span>
                                    <span v-if="schedule.approved" class="mt-1 block text-xs text-fg-muted">Approved by user {{ schedule.approved_by }}, {{ formatDate(schedule.approved_at) }}</span>
                                    <span v-else class="mt-1 block max-w-64 text-xs text-warning-fg">Paused until approved: nobody has signed off on its SQL yet. Review it with “Review and approve”.</span>
                                </td>
                                <td class="min-w-44">
                                    <span class="block">{{ describeCron(schedule.cron) ?? 'Custom' }}</span>
                                    <span class="block text-xs whitespace-nowrap text-fg-subtle"><code class="font-mono">{{ schedule.cron }}</code> · {{ schedule.timezone ?? 'app timezone' }}</span>
                                </td>
                                <td class="whitespace-nowrap tabular-nums" :title="schedule.timezone ? undefined : 'Estimated in your browser, assuming the app timezone is UTC'">
                                    {{ nextRun(schedule) }}
                                    <span v-if="schedule.enabled && schedule.approved" class="block text-xs text-fg-subtle">estimated</span>
                                </td>
                                <td class="whitespace-nowrap">
                                    <template v-if="schedule.last_run_id">
                                        <span class="block tabular-nums">{{ formatDate(schedule.last_run_at) }}</span>
                                        <span class="mt-0.5 flex items-center gap-1.5">
                                            <BaseBadge v-for="run in lastRun(schedule)" :key="run.id" :tone="RUN_STATUS[run.status].tone" :icon="RUN_STATUS[run.status].icon" :title="run.error ?? undefined">{{ run.status }}</BaseBadge>
                                            <router-link :to="{ name: 'runs', params: { label } }" class="dbx-link text-xs">run #{{ schedule.last_run_id }}</router-link>
                                        </span>
                                    </template>
                                    <span v-else class="text-fg-subtle">Never</span>
                                </td>
                                <td>
                                    <span class="flex flex-wrap gap-1">
                                        <BaseBadge v-if="schedule.allow_ddl" tone="orange" icon="alert" title="May change the schema (DDL), and write rows">DDL</BaseBadge>
                                        <BaseBadge v-if="schedule.allow_writes" tone="warning" icon="pencil" title="May insert, update and delete rows">Writes</BaseBadge>
                                        <BaseBadge v-if="!schedule.allow_writes" tone="success" icon="eye" title="Runs in a read-only transaction">Read-only</BaseBadge>
                                    </span>
                                </td>
                                <td>
                                    <button
                                        type="button"
                                        role="switch"
                                        :aria-checked="schedule.enabled"
                                        :aria-label="`${schedule.enabled ? 'Disable' : 'Enable'} schedule #${schedule.id}`"
                                        :disabled="toggling === schedule.id || (!schedule.enabled && !schedule.approved)"
                                        :title="!schedule.approved && !schedule.enabled ? 'Approve it with Re-sync before enabling it' : schedule.enabled ? 'Enabled: runs when due' : 'Paused'"
                                        class="inline-flex cursor-default items-center gap-2 rounded-full text-xs font-medium disabled:opacity-50"
                                        @click="toggle(schedule)"
                                    >
                                        <span class="relative inline-flex h-4 w-7 shrink-0 items-center rounded-full border transition-colors duration-150" :class="schedule.enabled ? 'border-transparent bg-accent-solid' : 'border-control bg-sunken'">
                                            <span class="absolute size-3 rounded-full transition-transform duration-150 motion-reduce:transition-none" :class="schedule.enabled ? 'translate-x-3.5 bg-on-accent' : 'translate-x-0.5 bg-fg-subtle'" />
                                        </span>
                                        {{ schedule.enabled ? 'On' : 'Paused' }}
                                    </button>
                                </td>
                                <td class="text-right whitespace-nowrap">
                                    <span class="inline-flex gap-1">
                                        <button type="button" class="dbx-btn dbx-btn-sm" :class="!schedule.approved || !schedule.in_sync ? 'dbx-btn-primary' : ''" @click="openResync(schedule)"><BaseIcon name="refresh" />{{ schedule.approved ? 'Re-sync…' : 'Review and approve' }}</button>
                                        <button type="button" class="dbx-btn dbx-btn-sm dbx-btn-ghost dbx-btn-icon" :aria-label="`Edit schedule #${schedule.id}`" title="Edit cron and notifications" @click="dialog = { schedule, queryId: null }"><BaseIcon name="pencil" /></button>
                                        <button type="button" class="dbx-btn dbx-btn-sm dbx-btn-ghost dbx-btn-danger-ghost dbx-btn-icon" :aria-label="`Delete schedule #${schedule.id}`" title="Delete" @click="deleting = schedule; deleteError = null"><BaseIcon name="trash" /></button>
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>

        <ScheduleDialog
            :open="dialog !== null"
            :label="label"
            :schedule="dialog?.schedule ?? null"
            :queries="queries"
            :initial-query-id="dialog?.queryId ?? null"
            @close="dialog = null"
            @saved="onSaved"
        />

        <ModalDialog
            :open="resyncing !== null"
            :title="resyncing?.approved ? `Re-sync schedule #${resyncing?.id}` : `Review and approve schedule #${resyncing?.id}`"
            description="Re-syncing copies the saved query's current SQL into the schedule and records you as its approver. From then on it runs as you."
            wide
            @close="resyncing = null"
        >
            <div v-if="resyncing" class="space-y-4">
                <div v-if="!resyncSource" role="alert" class="dbx-callout dbx-callout-warning">
                    <BaseIcon name="alert" />
                    <span>Its saved query isn't visible to you any more (deleted, or made private by its owner), so there is no SQL to re-sync from. The schedule keeps running its approved snapshot; delete it if that's no longer wanted.</span>
                </div>
                <template v-else>
                    <div>
                        <p class="dbx-label flex flex-wrap items-center gap-3">
                            <span>{{ resyncChanged ? 'What changes' : 'No changes' }}</span>
                            <span v-if="resyncChanged" class="inline-flex items-center gap-1 font-normal text-danger-fg"><span aria-hidden="true">−</span> approved snapshot</span>
                            <span v-if="resyncChanged" class="inline-flex items-center gap-1 font-normal text-success-fg"><span aria-hidden="true">+</span> saved query now</span>
                        </p>
                        <p v-if="!resyncChanged && resyncing.sql !== null" class="mb-2 text-sm text-fg-muted">The saved query's SQL matches the approved snapshot. Re-syncing re-approves the same SQL as you, with the permissions below.</p>
                        <p v-if="resyncing.sql === null" class="mb-2 text-sm text-fg-muted">The schedule has no snapshot yet. This is the SQL you would approve:</p>
                        <ol class="dbx-code max-h-72 list-none p-0 py-1" aria-label="Line diff between the approved snapshot and the saved query">
                            <li
                                v-for="(line, index) in resyncDiff"
                                :key="index"
                                class="flex px-3"
                                :class="line.kind === 'removed' ? 'bg-danger-bg text-danger-fg' : line.kind === 'added' ? 'bg-success-bg text-success-fg' : ''"
                            >
                                <span class="w-4 shrink-0 select-none" aria-hidden="true">{{ line.kind === 'removed' ? '−' : line.kind === 'added' ? '+' : ' ' }}</span>
                                <span class="sr-only">{{ line.kind === 'removed' ? 'removed: ' : line.kind === 'added' ? 'added: ' : '' }}</span>
                                <span class="whitespace-pre-wrap">{{ line.text || ' ' }}</span>
                            </li>
                        </ol>
                    </div>

                    <fieldset class="space-y-2">
                        <legend class="dbx-label">What it may do</legend>
                        <label class="flex items-start gap-2.5 rounded-md border p-2.5" :class="resyncWrites ? 'border-warning-line bg-warning-bg' : 'border-line'">
                            <input v-model="resyncWrites" type="checkbox" class="mt-0.5" :disabled="resyncDdl">
                            <span>
                                <span class="font-medium" :class="resyncWrites ? 'text-warning-fg' : ''">Allow writes (INSERT, UPDATE, DELETE)</span>
                                <span class="block text-xs text-fg-muted">Runs unattended, as you: a mistake repeats on every run. Needs your password.</span>
                            </span>
                        </label>
                        <label class="flex items-start gap-2.5 rounded-md border p-2.5" :class="resyncDdl ? 'border-danger-line bg-danger-bg' : 'border-line'">
                            <input v-model="resyncDdl" type="checkbox" class="mt-0.5">
                            <span>
                                <span class="font-medium" :class="resyncDdl ? 'text-danger-fg' : ''">Allow schema changes (DDL)</span>
                                <span class="block text-xs text-fg-muted">Includes writes. Needs your password.</span>
                            </span>
                        </label>
                        <p class="text-xs text-fg-muted">Leave both off and it runs read-only. Permissions are set fresh on every approval.</p>
                    </fieldset>
                </template>

                <div v-if="resyncError" role="alert" class="dbx-callout dbx-callout-danger"><BaseIcon name="alert-circle" /><span>{{ resyncError }}</span></div>

                <div class="flex items-center justify-end gap-2 border-t border-line pt-4">
                    <button type="button" class="dbx-btn dbx-btn-ghost mr-auto" autofocus @click="resyncing = null">Cancel</button>
                    <button type="button" class="dbx-btn dbx-btn-primary" :disabled="!resyncSource || resyncBusy" @click="resync">
                        <span v-if="resyncBusy" class="dbx-spinner border-current/30 border-t-current" aria-hidden="true" />
                        <BaseIcon v-else :name="resyncWrites ? 'lock' : 'check'" />Approve this SQL
                    </button>
                </div>
            </div>
        </ModalDialog>

        <ConfirmDialog
            :open="deleting !== null"
            :title="`Delete schedule #${deleting?.id ?? ''}?`"
            :message="`“${deleting?.name ?? 'This schedule'}” stops running and its approval is gone. The saved query and past runs stay.`"
            action="Delete schedule"
            danger
            :busy="deleteBusy"
            :error="deleteError"
            @cancel="deleting = null"
            @confirm="remove"
        />
    </div>
</template>
