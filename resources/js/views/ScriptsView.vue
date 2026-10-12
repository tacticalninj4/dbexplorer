<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { api, ApiError, describeError, segment } from '../api';
import BaseIcon from '../components/BaseIcon.vue';
import EmptyState from '../components/EmptyState.vue';
import RunDetails from '../components/RunDetails.vue';
import RunPanel from '../components/RunPanel.vue';
import SqlCodeEditor from '../components/SqlCodeEditor.vue';
import SqlConfirmDialog from '../components/SqlConfirmDialog.vue';
import { invalidate } from '../composables/catalog';
import { fetchRun, isActive } from '../composables/runs';
import type { BackgroundRun } from '../composables/runs';
import { findConnection, hasRole } from '../composables/session';
import { readStorage, writeStorage } from '../composables/storage';
import { statements } from '../sql/statements';
import type { Classification } from '../types';

/**
 * /:label/scripts: the script runner (PRD §7.7), editors and admins. A
 * multi-statement script runs as a background run, in one transaction or
 * statement by statement, and the result shows each statement's status,
 * duration and rows affected, with the one it stopped at highlighted.
 *
 * Gating is the server's, as in the SQL editor: 403 for DDL from an editor,
 * 409 to confirm anything that isn't a read, 423 (via api()) for the
 * password on destructive SQL, 422 when the script can't be split safely.
 * The runs API never returns a script's SQL, so the statement previews come
 * from the copy of the script this page sent.
 */
const route = useRoute();
const label = String(route.params.label);
const allowed = computed(() => hasRole(label, 'editor'));
const isAdmin = computed(() => findConnection(label)?.role === 'admin');

type Mode = 'transaction' | 'step';

const MODES: { value: Mode; title: string; text: string }[] = [
    { value: 'transaction', title: 'One transaction', text: 'All statements run in one transaction. If any fails, everything the script did is rolled back.' },
    { value: 'step', title: 'Step by step', text: 'Each statement commits on its own, in order. The run stops at the first failure and keeps what already committed. Use it for CREATE INDEX CONCURRENTLY.' },
];

const STORAGE_KEY = `script:${label}`;
const DEFAULT_SQL = '-- One statement per semicolon. Ctrl/Cmd+Enter runs the whole script.\n';

interface Saved {
    sql?: string;
    mode?: Mode;
    /** The last run, and the script it ran, so a reload can pick it up again. */
    last?: { id: number; sql: string };
}

const saved = readStorage<Saved>(STORAGE_KEY, {});
const sql = ref(typeof saved.sql === 'string' ? saved.sql : DEFAULT_SQL);
const mode = ref<Mode>(saved.mode === 'step' ? 'step' : 'transaction');
const run = ref<BackgroundRun | null>(null);
/** The script as sent with the run on screen: line numbers and previews refer to it. */
const sent = ref(saved.last?.sql ?? '');
const submitting = ref(false);
const error = ref<{ message: string; status: number } | null>(null);
const confirm = ref<{ classification: Classification; sql: string; mode: Mode } | null>(null);
const selected = ref<number | null>(null);
let saveTimer: ReturnType<typeof setTimeout> | undefined;

function save(): void {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(
        () => writeStorage(STORAGE_KEY, { sql: sql.value, mode: mode.value, last: run.value ? { id: run.value.id, sql: sent.value } : undefined } satisfies Saved),
        300,
    );
}

watch([sql, mode], save);

const busy = computed(() => submitting.value || (run.value !== null && isActive(run.value)));

/** Client-side statement spans of the sent script, to match the server's lines. */
const spans = computed(() => {
    const text = sent.value;

    return statements(text).map((span) => ({
        text: text.slice(span.from, span.to),
        from: lineOf(text, span.from),
        to: lineOf(text, span.to),
    }));
});

function lineOf(text: string, index: number): number {
    let line = 1;

    for (let i = 0; i < index; i++) {
        if (text.charCodeAt(i) === 10) {
            line++;
        }
    }

    return line;
}

/**
 * The sent statement the server's statement refers to. The server lists every
 * statement in script order, so the one at the same position is it, as long as
 * it fits the server's start line; several statements can share a line, so the
 * line alone would name the first of them each time.
 */
function spanFor(line: number, index: number): { text: string; from: number; to: number } | undefined {
    const own = spans.value[index];
    const previous = index > 0 ? spans.value[index - 1] : undefined;

    if (own && own.to >= line && (!previous || previous.to <= line)) {
        return own;
    }

    return spans.value.find((span) => span.to >= line);
}

const sources = computed(() => (run.value?.statements ?? []).map((statement, index) => (spanFor(statement.line, index)?.text ?? '').replace(/\s+/g, ' ').slice(0, 160)));
const stoppedAt = computed(() => (run.value?.statements ?? []).findIndex((statement) => statement.status === 'failed' || statement.status === 'cancelled'));
const shown = computed(() => {
    const index = selected.value ?? (stoppedAt.value >= 0 ? stoppedAt.value : null);
    const statement = index === null ? undefined : run.value?.statements?.[index];
    const span = statement && index !== null ? spanFor(statement.line, index) : undefined;

    if (index === null || !statement || !span) {
        return null;
    }

    const lines = span.text.split('\n');

    return { index, statement, lines: lines.map((text, offset) => ({ number: span.from + offset, text })) };
});

async function submit(confirmed = false, pending?: { sql: string; mode: Mode }): Promise<void> {
    const script = pending?.sql ?? sql.value;
    const runMode = pending?.mode ?? mode.value;

    if (busy.value || script.trim() === '') {
        return;
    }

    submitting.value = true;
    error.value = null;

    try {
        const response = await api<{ data: BackgroundRun }>(`${segment(label)}/script`, {
            method: 'POST',
            body: { sql: script, mode: runMode, confirm: confirmed ? true : undefined },
        });
        sent.value = script;
        selected.value = null;
        run.value = response.data;
        save();
    } catch (e) {
        const required = e instanceof ApiError && !confirmed ? e.confirmationRequired : null;

        if (required) {
            confirm.value = { classification: required.classification, sql: script, mode: runMode };
            return;
        }

        error.value = { message: describeError(e), status: e instanceof ApiError ? e.status : 0 };
    } finally {
        submitting.value = false;
    }
}

function confirmed(): void {
    const pending = confirm.value;
    confirm.value = null;

    if (pending) {
        void submit(true, pending);
    }
}

function onUpdate(fresh: BackgroundRun): void {
    const was = run.value;
    run.value = fresh;

    // A script that finished may have changed the schema: drop the cached catalog.
    if (was && isActive(was) && !isActive(fresh)) {
        invalidate(label);
    }
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
        // Capture phase, before CodeMirror's own Enter handling inserts a line.
        event.preventDefault();
        event.stopPropagation();
        void submit();
    }
}

// Pick up the last run after a reload (it may still be going).
if (saved.last && typeof saved.last.id === 'number') {
    fetchRun(label, saved.last.id)
        .then((found) => {
            run.value ??= found;
        })
        .catch(() => {
            // Gone (pruned, or another database): forget it.
            sent.value = '';
            save();
        });
}

onBeforeUnmount(() => {
    clearTimeout(saveTimer);
    writeStorage(STORAGE_KEY, { sql: sql.value, mode: mode.value, last: run.value ? { id: run.value.id, sql: sent.value } : undefined } satisfies Saved);
});

const isMac = /Mac|iPhone|iPad/.test(navigator.userAgent);
const modKey = isMac ? '⌘' : 'Ctrl';
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <header class="dbx-page-header">
            <BaseIcon name="file" class="text-fg-subtle" />
            <h1 class="text-lg font-semibold">Scripts</h1>
            <span class="text-sm text-fg-muted">Run several statements on {{ label }} as one background run.</span>
        </header>

        <div v-if="!allowed" class="flex flex-1 items-center justify-center">
            <EmptyState icon="lock" title="Scripts need the editor role">
                Your role on {{ label }} runs read-only SQL in the SQL editor. Ask an admin for the editor role to run scripts.
                <template #actions>
                    <router-link :to="{ name: 'sql', params: { label } }" class="dbx-btn"><BaseIcon name="terminal" />Open SQL editor</router-link>
                </template>
            </EmptyState>
        </div>

        <template v-else>
            <div class="dbx-toolbar">
                <button type="button" class="dbx-btn dbx-btn-primary" :disabled="busy || sql.trim() === ''" :title="`Run the whole script (${modKey}+Enter)`" @click="submit()">
                    <span v-if="submitting" class="dbx-spinner border-current/30 border-t-current" aria-hidden="true" />
                    <BaseIcon v-else name="play" />
                    {{ submitting ? 'Queuing…' : busy ? 'Running…' : 'Run script' }}<kbd class="dbx-kbd ml-0.5 opacity-80">{{ modKey }}↵</kbd>
                </button>
                <span class="dbx-separator" aria-hidden="true" />
                <div class="dbx-segmented" role="group" aria-label="Mode">
                    <button
                        v-for="item in MODES"
                        :key="item.value"
                        type="button"
                        :aria-pressed="mode === item.value"
                        :title="item.text"
                        @click="mode = item.value"
                    >
                        {{ item.title }}
                    </button>
                </div>
                <span class="min-w-0 flex-1 text-xs text-fg-muted" aria-live="polite">{{ MODES.find((item) => item.value === mode)?.text }}</span>
                <router-link :to="{ name: 'runs', params: { label }, query: { type: 'script' } }" class="dbx-btn dbx-btn-ghost"><BaseIcon name="activity" />Script runs</router-link>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto">
                <div class="space-y-3 p-3">
                    <!-- Mod+Enter: key events from the editor's shadow root reach this wrapper. -->
                    <div @keydown.capture="onKeydown">
                        <SqlCodeEditor v-model="sql" :label="label" aria-label="Script" class="h-[34vh] min-h-32" />
                    </div>
                    <p v-if="!isAdmin" class="flex items-center gap-1.5 text-xs text-fg-muted">
                        <BaseIcon name="info" :size="12" />Scripts with DDL (CREATE, ALTER, DROP, TRUNCATE, GRANT…) need the admin role. Writes ask you to confirm first.
                    </p>
                    <p v-else class="flex items-center gap-1.5 text-xs text-fg-muted">
                        <BaseIcon name="info" :size="12" />Writes and schema changes ask you to confirm first; destructive statements also ask for your password.
                    </p>

                    <div v-if="error" role="alert" class="dbx-callout dbx-callout-danger">
                        <BaseIcon name="alert-circle" />
                        <div class="min-w-0">
                            <p class="font-semibold">{{ error.status === 403 ? 'Not allowed' : error.status === 422 ? 'The script can’t run' : error.status === 429 ? 'Limit reached' : 'The script could not be queued' }}</p>
                            <pre class="mt-1 font-sans break-words whitespace-pre-wrap">{{ error.message }}</pre>
                        </div>
                    </div>

                    <section v-if="run" class="space-y-3" aria-label="Script result" aria-live="polite">
                        <RunPanel :key="run.id" :label="label" :run="run" :title="`Script of ${spans.length || run.statements?.length || 0} ${(spans.length || run.statements?.length) === 1 ? 'statement' : 'statements'}`" @update="onUpdate">
                            <RunDetails :run="run" :sources="sources" selectable @select="(index: number) => (selected = index)" />
                            <div v-if="shown" class="dbx-card overflow-hidden" :class="shown.index === stoppedAt ? 'border-danger-line' : ''">
                                <p class="flex items-center gap-1.5 border-b px-3 py-1.5 text-xs font-medium" :class="shown.index === stoppedAt ? 'border-danger-line bg-danger-bg text-danger-fg' : 'border-line bg-sunken text-fg-muted'">
                                    <BaseIcon :name="shown.index === stoppedAt ? 'x-circle' : 'file'" :size="12" />
                                    {{ shown.index === stoppedAt ? (shown.statement.status === 'cancelled' ? 'Cancelled at' : 'Failed at') : 'Statement' }} {{ shown.index + 1 }}, line {{ shown.statement.line }}
                                    <button v-if="selected !== null" type="button" class="dbx-link ml-auto font-normal" @click="selected = null">{{ stoppedAt >= 0 ? 'Back to the failure' : 'Hide' }}</button>
                                </p>
                                <pre class="max-h-56 overflow-auto bg-panel py-2 font-mono text-xs leading-relaxed"><span v-for="line in shown.lines" :key="line.number" class="block px-3" :class="shown.index === stoppedAt ? 'bg-danger-bg/60' : ''"><span class="mr-3 inline-block w-8 text-right text-fg-subtle select-none">{{ line.number }}</span>{{ line.text }}</span></pre>
                            </div>
                        </RunPanel>
                    </section>
                    <EmptyState v-else icon="file" title="Write a script, then run it">
                        Statements run in order on a queue worker. Each one’s status, duration and rows affected show up here, and on the Runs page.
                    </EmptyState>
                </div>
            </div>
        </template>

        <SqlConfirmDialog :open="confirm !== null" noun="script" :classification="confirm?.classification ?? 'read'" :sql="confirm?.sql ?? ''" @confirm="confirmed" @cancel="confirm = null" />
    </div>
</template>
