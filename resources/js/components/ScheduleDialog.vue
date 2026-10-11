<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { api, ApiError, describeError, segment } from '../api';
import { describeCron, nextRuns } from '../composables/cron';
import { isRequired } from '../composables/savedQueries';
import type { SavedQuery } from '../composables/savedQueries';
import { plainPermissions } from '../composables/schedules';
import type { NotifyEvent, Schedule } from '../composables/schedules';
import BaseIcon from './BaseIcon.vue';
import ModalDialog from './ModalDialog.vue';

/**
 * Create a schedule from a saved query (its approval: the SQL is
 * snapshotted, docs/security.md §6), or edit an existing one's cron and
 * notifications. Writes and DDL are granted only here or on re-sync, and
 * granting either answers 423 until the password is confirmed (api()).
 */
const props = withDefaults(defineProps<{ open: boolean; label: string; schedule?: Schedule | null; queries: SavedQuery[]; initialQueryId?: number | null }>(), {
    schedule: null,
    initialQueryId: null,
});
const emit = defineEmits<{ close: []; saved: [schedule: Schedule] }>();

const PRESETS = [
    { cron: '0 * * * *', title: 'Every hour' },
    { cron: '0 6 * * *', title: 'Every day at 06:00' },
    { cron: '0 8 * * 1-5', title: 'Weekdays at 08:00' },
    { cron: '0 8 * * 1', title: 'Mondays at 08:00' },
    { cron: '0 3 1 * *', title: 'Monthly, the 1st at 03:00' },
];

const queryId = ref<number | null>(null);
const cron = ref('0 6 * * *');
const timezone = ref('');
const enabled = ref(true);
const allowWrites = ref(false);
const allowDdl = ref(false);
const notifyOn = ref<NotifyEvent[]>([]);
const thresholdRows = ref<number | null>(null);
const mail = ref('');
const error = ref<string | null>(null);
const busy = ref(false);

const browserZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
const zones = (() => {
    try {
        return Intl.supportedValuesOf('timeZone');
    } catch {
        return [];
    }
})();

const query = computed(() => props.queries.find((candidate) => candidate.id === queryId.value) ?? null);
const blockingParams = computed(() => (query.value?.params ?? []).filter(isRequired));
const description = computed(() => describeCron(cron.value));
const zone = computed(() => (props.schedule ? props.schedule.timezone : timezone.value.trim() || null) ?? 'UTC');
const upcoming = computed(() => nextRuns(cron.value, zone.value, 3));
const recipients = computed(() =>
    mail.value
        .split(/[\s,;]+/)
        .map((address) => address.trim())
        .filter((address) => address !== ''),
);
const formatter = computed(() => {
    try {
        return new Intl.DateTimeFormat(undefined, { timeZone: zone.value, weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', timeZoneName: 'short' });
    } catch {
        return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });
    }
});

function reset(): void {
    const schedule = props.schedule;
    queryId.value = schedule?.saved_query_id ?? props.initialQueryId ?? null;
    cron.value = schedule?.cron ?? '0 6 * * *';
    timezone.value = schedule?.timezone ?? '';
    enabled.value = true;
    allowWrites.value = false;
    allowDdl.value = false;
    notifyOn.value = [...(schedule?.notify.on ?? ['failure'])];
    thresholdRows.value = schedule?.notify.threshold_rows ?? null;
    mail.value = (schedule?.notify.mail ?? []).join(', ');
    error.value = null;
}

watch(
    () => props.open,
    (open) => open && reset(),
    { immediate: true },
);
watch(allowDdl, (ddl) => ddl && (allowWrites.value = true));

const canSubmit = computed(() => cron.value.trim() !== '' && (props.schedule !== null || (query.value !== null && blockingParams.value.length === 0)));

async function submit(): Promise<void> {
    if (!canSubmit.value || busy.value) {
        return;
    }
    busy.value = true;
    error.value = null;
    const notify = {
        on: notifyOn.value,
        threshold_rows: notifyOn.value.includes('threshold') ? thresholdRows.value : null,
        mail: recipients.value,
    };

    try {
        const response = props.schedule
            ? await api<{ data: Schedule }>(`${segment(props.label)}/schedules/${props.schedule.id}`, { method: 'PATCH', body: { cron: cron.value.trim(), notify } })
            : await api<{ data: Schedule }>(`${segment(props.label)}/schedules`, {
                method: 'POST',
                body: {
                    saved_query_id: queryId.value,
                    cron: cron.value.trim(),
                    timezone: timezone.value.trim() || null,
                    enabled: enabled.value,
                    allow_writes: allowWrites.value,
                    allow_ddl: allowDdl.value,
                    notify,
                },
            });
        emit('saved', response.data);
    } catch (e) {
        error.value = e instanceof ApiError && e.status === 423
            ? 'Allowing writes or DDL needs your password. Nothing was saved: confirm your password to approve it, or untick the permission.'
            : plainPermissions(describeError(e, 'The schedule could not be saved.'));
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <ModalDialog
        :open="open"
        :title="schedule ? `Edit schedule #${schedule.id}` : 'New schedule'"
        :description="schedule ? 'Change when it runs and who hears about it. The approved SQL and its permissions only change through Re-sync.' : 'Creating a schedule approves a copy of the saved query’s SQL as it is now. It runs that copy, as you, until an admin re-syncs it.'"
        wide
        @close="emit('close')"
    >
        <form v-if="open" class="space-y-4" novalidate @submit.prevent="submit">
            <div v-if="!schedule">
                <label for="dbx-sch-query" class="dbx-label">Saved query</label>
                <select id="dbx-sch-query" v-model="queryId" class="dbx-select w-full" required>
                    <option :value="null" disabled>Choose a saved query…</option>
                    <option v-for="candidate in queries" :key="candidate.id" :value="candidate.id">{{ candidate.name }}{{ candidate.owned ? '' : ' (shared)' }}</option>
                </select>
                <p v-if="queries.length === 0" class="mt-1 text-xs text-fg-muted">There are no saved queries on this connection yet. Save one first, from Saved queries or the SQL editor.</p>
            </div>

            <div v-if="!schedule && query">
                <p class="dbx-label">SQL to approve <span class="font-normal text-fg-subtle">(snapshot, read-only)</span></p>
                <pre class="dbx-code max-h-40 whitespace-pre-wrap">{{ query.sql }}</pre>
                <div v-if="blockingParams.length" role="alert" class="dbx-callout dbx-callout-warning mt-2">
                    <BaseIcon name="alert" />
                    <span>
                        This query needs a value for {{ blockingParams.map((param) => `:${param.name}`).join(', ') }} each time it runs, and nobody is there to type one at 3 a.m.
                        Make those parameters optional (an empty one binds NULL) to schedule it.
                    </span>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 max-sm:grid-cols-1">
                <div>
                    <label for="dbx-sch-cron" class="dbx-label">When (cron: minute hour day month weekday)</label>
                    <input id="dbx-sch-cron" v-model="cron" class="dbx-input w-full font-mono" spellcheck="false" autocomplete="off" required aria-describedby="dbx-sch-cron-preview">
                </div>
                <div>
                    <label for="dbx-sch-preset" class="dbx-label">Common schedules</label>
                    <select id="dbx-sch-preset" class="dbx-select w-full" :value="PRESETS.some((preset) => preset.cron === cron) ? cron : ''" @change="cron = ($event.target as HTMLSelectElement).value || cron">
                        <option value="">Custom</option>
                        <option v-for="preset in PRESETS" :key="preset.cron" :value="preset.cron">{{ preset.title }}</option>
                    </select>
                </div>
            </div>
            <div id="dbx-sch-cron-preview" class="-mt-2 rounded-md border border-line bg-sunken px-3 py-2 text-sm" aria-live="polite">
                <p class="flex items-center gap-1.5 font-medium">
                    <BaseIcon name="clock" :size="14" class="text-fg-subtle" />
                    <template v-if="description">{{ description }}</template>
                    <template v-else-if="upcoming.length">Custom: <code class="font-mono">{{ cron }}</code></template>
                    <template v-else>Can't read <code class="font-mono">{{ cron || '(empty)' }}</code>; the server checks it when you save.</template>
                </p>
                <p v-if="upcoming.length" class="mt-0.5 text-xs text-fg-muted">
                    Next{{ (schedule ? schedule.timezone : timezone.trim()) ? '' : ' (if the app timezone is UTC)' }}: {{ upcoming.map((date) => formatter.format(date)).join(' · ') }}
                </p>
            </div>

            <div v-if="!schedule">
                <label for="dbx-sch-tz" class="dbx-label">Timezone <span class="font-normal text-fg-subtle">(empty: the app's timezone)</span></label>
                <div class="flex gap-2">
                    <input id="dbx-sch-tz" v-model="timezone" class="dbx-input flex-1" list="dbx-sch-zones" placeholder="App timezone" autocomplete="off">
                    <button v-if="timezone !== browserZone" type="button" class="dbx-btn" @click="timezone = browserZone">Use {{ browserZone }}</button>
                </div>
                <datalist id="dbx-sch-zones"><option v-for="name in zones" :key="name" :value="name" /></datalist>
            </div>
            <p v-else class="text-sm text-fg-muted">Timezone: {{ schedule.timezone ?? 'the app’s timezone' }} (set when the schedule was created).</p>

            <fieldset v-if="!schedule" class="space-y-2">
                <legend class="dbx-label">What it may do</legend>
                <p class="text-sm text-fg-muted">Without these, the schedule runs in a read-only transaction: anything that writes fails at the database.</p>
                <label class="flex items-start gap-2.5 rounded-md border p-2.5" :class="allowWrites ? 'border-warning-line bg-warning-bg' : 'border-line'">
                    <input v-model="allowWrites" type="checkbox" class="mt-0.5" :disabled="allowDdl">
                    <span>
                        <span class="font-medium" :class="allowWrites ? 'text-warning-fg' : ''">Allow writes (INSERT, UPDATE, DELETE)</span>
                        <span class="block text-xs text-fg-muted">It runs unattended, as you, on every tick: a mistake repeats each time. Needs your password.</span>
                    </span>
                </label>
                <label class="flex items-start gap-2.5 rounded-md border p-2.5" :class="allowDdl ? 'border-danger-line bg-danger-bg' : 'border-line'">
                    <input v-model="allowDdl" type="checkbox" class="mt-0.5">
                    <span>
                        <span class="font-medium" :class="allowDdl ? 'text-danger-fg' : ''">Allow schema changes (DDL: CREATE, ALTER, DROP)</span>
                        <span class="block text-xs text-fg-muted">Includes writes. A scheduled DROP or TRUNCATE can't be undone from DbExplorer. Needs your password.</span>
                    </span>
                </label>
            </fieldset>

            <fieldset class="space-y-2">
                <legend class="dbx-label">Send mail when</legend>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                    <label class="dbx-check"><input v-model="notifyOn" type="checkbox" value="failure">a run fails</label>
                    <label class="dbx-check"><input v-model="notifyOn" type="checkbox" value="success">a run succeeds</label>
                    <label class="dbx-check"><input v-model="notifyOn" type="checkbox" value="threshold">a run returns at least</label>
                    <label class="sr-only" for="dbx-sch-threshold">Row threshold</label>
                    <input id="dbx-sch-threshold" v-model.number="thresholdRows" type="number" min="1" class="dbx-input w-24 tabular-nums" :disabled="!notifyOn.includes('threshold')" placeholder="rows">
                    <span class="text-sm text-fg-muted">rows</span>
                </div>
                <div>
                    <label for="dbx-sch-mail" class="dbx-label">Recipients <span class="font-normal text-fg-subtle">(comma-separated; none: no mail)</span></label>
                    <input id="dbx-sch-mail" v-model="mail" class="dbx-input w-full" type="text" inputmode="email" autocomplete="off" placeholder="ops@example.com">
                </div>
            </fieldset>

            <label v-if="!schedule" class="dbx-check"><input v-model="enabled" type="checkbox">Enable it right away</label>

            <div v-if="error" role="alert" class="dbx-callout dbx-callout-danger"><BaseIcon name="alert-circle" /><span>{{ error }}</span></div>

            <div class="flex items-center justify-end gap-2 border-t border-line pt-4">
                <button type="button" class="dbx-btn dbx-btn-ghost mr-auto" @click="emit('close')">Cancel</button>
                <button type="submit" class="dbx-btn dbx-btn-primary" :disabled="busy || !canSubmit">
                    <span v-if="busy" class="dbx-spinner border-current/30 border-t-current" aria-hidden="true" />
                    <BaseIcon v-else :name="allowWrites ? 'lock' : 'calendar'" />{{ schedule ? 'Save changes' : 'Approve and schedule' }}
                </button>
            </div>
        </form>
    </ModalDialog>
</template>
