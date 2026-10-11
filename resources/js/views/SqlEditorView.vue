<script setup lang="ts">
import { PostgreSQL, sql as sqlLanguage } from '@codemirror/lang-sql';
import { Compartment, EditorSelection, EditorState, Prec, StateEffect, StateField } from '@codemirror/state';
import type { Extension } from '@codemirror/state';
import { Decoration, EditorView, keymap } from '@codemirror/view';
import type { DecorationSet } from '@codemirror/view';
import { basicSetup } from 'codemirror';
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, shallowRef } from 'vue';
import { useRoute } from 'vue-router';
import { api, ApiError, describeError, segment } from '../api';
import DataGrid from '../components/DataGrid.vue';
import EmptyState from '../components/EmptyState.vue';
import BaseIcon from '../components/BaseIcon.vue';
import ExportDialog from '../components/ExportDialog.vue';
import PlanTree from '../components/PlanTree.vue';
import ResultBar from '../components/ResultBar.vue';
import RunPanel from '../components/RunPanel.vue';
import SqlConfirmDialog from '../components/SqlConfirmDialog.vue';
import SaveQueryDialog from '../components/SaveQueryDialog.vue';
import { takeForEditor } from '../composables/editorInbox';
import { isActive, notifyRun } from '../composables/runs';
import type { BackgroundRun } from '../composables/runs';
import { findConnection } from '../composables/session';
import { readStorage, writeStorage } from '../composables/storage';
import { catalogCompletion } from '../sql/completion';
import { editorTheme } from '../sql/editorTheme';
import { locate, positionToIndex, statementAt } from '../sql/statements';
import type { Classification, QueryError, QueryResponse } from '../types';

/**
 * /:label/sql: CodeMirror 6 with PostgreSQL highlighting and catalog
 * completion, tabs kept in localStorage per connection, and results, plans
 * and errors below. Runs one statement: the selection, or the one at the cursor.
 *
 * Confirmations are server-driven (M2.2): the statement is sent as typed, and
 * the API's 409 (confirm), 423 (password, via api()) and 403 (permissions)
 * responses tell the UI what to ask for. The SPA classifies nothing itself.
 */
const route = useRoute();
const label = String(route.params.label);
const role = computed(() => findConnection(label)?.role);

type ExplainMode = 'plain' | 'analyze' | null;

interface Tab {
    id: string;
    title: string;
    sql: string;
}

interface Run {
    status: 'running' | 'confirm' | 'done' | 'failed' | 'background';
    sql: string;
    explain: ExplainMode;
    result?: QueryResponse;
    /** Run in background (batch 4A): the queued run. */
    job?: BackgroundRun;
    error?: QueryError & { status: number };
}

const STORAGE_KEY = `editor:${label}`;
const DEFAULT_SQL = '-- Ctrl/Cmd+Enter runs the statement at the cursor, or the selection.\nSELECT now();\n';

const saved = readStorage<{ tabs?: Tab[]; active?: string }>(STORAGE_KEY, {});
const tabs = ref<Tab[]>(
    Array.isArray(saved.tabs) && saved.tabs.length > 0
        ? saved.tabs.filter((tab) => typeof tab?.id === 'string' && typeof tab.sql === 'string').map((tab) => ({ id: tab.id, title: String(tab.title), sql: tab.sql }))
        : [{ id: newId(), title: 'Query 1', sql: DEFAULT_SQL }],
);
if (tabs.value.length === 0) {
    tabs.value.push({ id: newId(), title: 'Query 1', sql: DEFAULT_SQL });
}
const activeId = ref(tabs.value.some((tab) => tab.id === saved.active) ? (saved.active as string) : (tabs.value[0] as Tab).id);
const runs = reactive(new Map<string, Run>());
const unmask = ref(false);
const editorEl = ref<HTMLElement | null>(null);
const view = shallowRef<EditorView | null>(null);
const states = new Map<string, EditorState>();
const confirm = ref<{ sql: string; offset: number; explain: ExplainMode; classification: Classification } | null>(null);
const resultTab = ref<'results' | 'plan' | 'messages'>('results');
let saveTimer: ReturnType<typeof setTimeout> | undefined;

const active = computed(() => tabs.value.find((tab) => tab.id === activeId.value) ?? (tabs.value[0] as Tab));
const run = computed(() => runs.get(activeId.value));
const running = computed(() => run.value?.status === 'running');
const errorCaret = computed(() => {
    const failure = run.value?.error;

    return failure?.position ? locate(run.value?.sql ?? '', failure.position) : null;
});

function newId(): string {
    return Math.random().toString(36).slice(2, 10);
}

function save(): void {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => writeStorage(STORAGE_KEY, { tabs: tabs.value, active: activeId.value }), 300);
}

// The error underline: set after a failed run, cleared by the next edit.
const setError = StateEffect.define<{ from: number; to: number } | null>();
const errorField = StateField.define<DecorationSet>({
    create: () => Decoration.none,
    update(decorations, transaction) {
        for (const effect of transaction.effects) {
            if (effect.is(setError)) {
                return effect.value ? Decoration.set([Decoration.mark({ class: 'cm-dbx-error' }).range(effect.value.from, effect.value.to)]) : Decoration.none;
            }
        }

        return transaction.docChanged ? Decoration.none : decorations.map(transaction.changes);
    },
    provide: (field) => EditorView.decorations.from(field),
});

// The editor lives in a shadow root, styled by CodeMirror themes only (see sql/editorTheme.ts).
const theme = new Compartment();
const darkQuery = window.matchMedia('(prefers-color-scheme: dark)');

function onSchemeChange(): void {
    view.value?.dispatch({ effects: theme.reconfigure(editorTheme(darkQuery.matches)) });
}

const extensions: Extension[] = [
    Prec.highest(
        keymap.of([
            { key: 'Mod-Enter', run: () => (start(null), true) },
            { key: 'Shift-Mod-Enter', run: () => (start('plain'), true) },
        ]),
    ),
    basicSetup,
    sqlLanguage({ dialect: PostgreSQL, upperCaseKeywords: true }),
    PostgreSQL.language.data.of({ autocomplete: catalogCompletion(label) }),
    errorField,
    theme.of(editorTheme(darkQuery.matches)),
    EditorView.contentAttributes.of({ 'aria-label': 'SQL editor' }),
    EditorView.updateListener.of((update) => {
        if (update.docChanged) {
            active.value.sql = update.state.doc.toString();
            save();
        }
    }),
];

function stateFor(tab: Tab): EditorState {
    let state = states.get(tab.id);

    if (!state) {
        state = EditorState.create({ doc: tab.sql, extensions });
        states.set(tab.id, state);
    }

    return state;
}

function switchTo(id: string): void {
    const editor = view.value;

    if (editor) {
        states.set(activeId.value, editor.state);
    }

    activeId.value = id;
    save();

    if (editor) {
        editor.setState(stateFor(active.value));
        editor.focus();
    }
}

function addTab(sql = ''): void {
    const used = new Set(tabs.value.map((tab) => tab.title));
    let n = tabs.value.length + 1;
    while (used.has(`Query ${n}`)) {
        n++;
    }

    const tab = { id: newId(), title: `Query ${n}`, sql };
    tabs.value.push(tab);
    switchTo(tab.id);
}

function closeTab(id: string): void {
    const index = tabs.value.findIndex((tab) => tab.id === id);
    tabs.value.splice(index, 1);
    states.delete(id);
    keepTrack(id);
    runs.delete(id);

    if (tabs.value.length === 0) {
        tabs.value.push({ id: newId(), title: 'Query 1', sql: '' });
    }

    if (id === activeId.value) {
        activeId.value = '';
        switchTo((tabs.value[Math.max(0, index - 1)] as Tab).id);
    } else {
        save();
    }
}

function onTabKeydown(event: KeyboardEvent): void {
    const index = tabs.value.findIndex((tab) => tab.id === activeId.value);
    const moves: Record<string, number> = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: tabs.value.length - 1 };
    const target = moves[event.key];

    if (target === undefined) {
        return;
    }

    event.preventDefault();
    const tab = tabs.value[(target + tabs.value.length) % tabs.value.length] as Tab;
    switchTo(tab.id);
    void nextTick(() => document.getElementById(`dbx-sql-tab-${tab.id}`)?.focus());
}

/**
 * Run the statement at the cursor (or the selection). No client-side gating:
 * reads run directly, and the server answers anything else with 409 (confirm
 * first), 423 (password confirmation, handled by the shared modal in api()) or
 * 403 (DDL from a non-admin), each of which the UI just follows.
 */
function start(explain: ExplainMode): void {
    const editor = view.value;
    if (!editor || running.value) {
        return;
    }

    const selection = editor.state.selection.main;
    const { text, offset } = statementAt(editor.state.doc.toString(), selection.from, selection.to);

    if (text.trim() === '') {
        return;
    }

    void execute(text, offset, explain, false);
}

/** "Run it" in the confirmation dialog: re-send the same statement, confirmed. */
function confirmed(): void {
    const pending = confirm.value;
    confirm.value = null;

    if (pending) {
        void execute(pending.sql, pending.offset, pending.explain, true);
    }
}

/** Cancelled confirmation: drop the waiting run too. */
function cancelConfirm(): void {
    confirm.value = null;

    if (run.value?.status === 'confirm') {
        runs.delete(activeId.value);
    }
}

// batch 4A: run in background, and export a result.
const exporting = ref<string | null>(null);

/**
 * Queue the statement at the cursor as a background query (PRD §7.3): no
 * row cap, the result goes to a JSON Lines file. The server only takes reads
 * (422 otherwise); the panel below follows the run, with cancel and download.
 */
async function startBackground(): Promise<void> {
    const editor = view.value;
    if (!editor || running.value) {
        return;
    }

    const selection = editor.state.selection.main;
    const { text } = statementAt(editor.state.doc.toString(), selection.from, selection.to);

    if (text.trim() === '') {
        return;
    }

    const tabId = activeId.value;
    keepTrack(tabId);
    runs.set(tabId, { status: 'running', sql: text, explain: null });
    view.value?.dispatch({ effects: setError.of(null) });

    try {
        const response = await api<{ data: BackgroundRun }>(`${segment(label)}/query`, { method: 'POST', body: { sql: text, background: true } });
        runs.set(tabId, { status: 'background', sql: text, explain: null, job: response.data });
    } catch (e) {
        const status = e instanceof ApiError ? e.status : 0;
        runs.set(tabId, { status: 'failed', sql: text, explain: null, error: { message: describeError(e), sqlstate: null, position: null, status } });
    }
}

/** A tab's still-running background query moves to a toast before the tab shows something else. */
function keepTrack(tabId: string): void {
    const job = runs.get(tabId)?.job;

    if (job && isActive(job)) {
        notifyRun(label, job, 'Background query');
    }
}
// end batch 4A

async function execute(sql: string, offset: number, explain: ExplainMode, confirmedRequest: boolean): Promise<void> {
    const tabId = activeId.value;
    keepTrack(tabId);
    runs.set(tabId, { status: 'running', sql, explain });
    view.value?.dispatch({ effects: setError.of(null) });

    try {
        const response = await api<{ data: QueryResponse }>(`${segment(label)}/query`, {
            method: 'POST',
            body: { sql, explain, unmask: unmask.value ? true : undefined, confirm: confirmedRequest ? true : undefined },
        });
        runs.set(tabId, { status: 'done', sql, explain, result: response.data });
        resultTab.value = response.data.plan ? 'plan' : 'results';
    } catch (e) {
        const required = e instanceof ApiError && !confirmedRequest ? e.confirmationRequired : null;

        if (required) {
            // The server refuses to run it until the user confirms: show what it
            // classified, then re-send with confirm: true. A destructive verdict
            // continues into the password modal through api()'s 423 handling.
            runs.set(tabId, { status: 'confirm', sql, explain });
            confirm.value = { sql, offset, explain, classification: required.classification };
            return;
        }

        const queryError = e instanceof ApiError ? e.queryError : null;
        const status = e instanceof ApiError ? e.status : 0;
        runs.set(tabId, {
            status: 'failed',
            sql,
            explain,
            error: queryError ? { ...queryError, status } : { message: describeError(e), sqlstate: null, position: null, status },
        });

        if (queryError?.position && tabId === activeId.value) {
            markError(offset + positionToIndex(sql, queryError.position));
        }
    }
}

/** Underline the token at the error and put the cursor there. */
function markError(index: number): void {
    const editor = view.value;
    if (!editor) {
        return;
    }

    const doc = editor.state.doc;
    const from = Math.min(index, doc.length);
    const word = /^[\w$"]+/.exec(doc.sliceString(from, Math.min(doc.length, from + 64)));
    const to = Math.min(doc.length, from + Math.max(1, word?.[0].length ?? 1));

    editor.dispatch({
        effects: [setError.of(from < to ? { from, to } : null), EditorView.scrollIntoView(from, { y: 'center' })],
        selection: EditorSelection.cursor(from),
    });
}

onMounted(() => {
    const incoming = takeForEditor(label);

    const shadow = (editorEl.value as HTMLElement).attachShadow({ mode: 'open' });
    view.value = new EditorView({ parent: shadow, root: shadow, state: stateFor(active.value) });

    if (incoming !== null) {
        addTab(incoming);
    }

    darkQuery.addEventListener('change', onSchemeChange);
    view.value.focus();
});

onBeforeUnmount(() => {
    darkQuery.removeEventListener('change', onSchemeChange);
    clearTimeout(saveTimer);
    writeStorage(STORAGE_KEY, { tabs: tabs.value, active: activeId.value });
    view.value?.destroy();
});

// batch 4B: "Save query" keeps the statement at the cursor (or the selection) as a saved query.
const saveSql = ref<string | null>(null);
const savedNotice = ref<string | null>(null);
let savedTimer: ReturnType<typeof setTimeout> | undefined;

function onQuerySaved(name: string): void {
    saveSql.value = null;
    savedNotice.value = name;
    clearTimeout(savedTimer);
    savedTimer = setTimeout(() => (savedNotice.value = null), 4000);
}

function openSave(): void {
    const editor = view.value;
    const selection = editor?.state.selection.main;
    const text = editor && selection ? statementAt(editor.state.doc.toString(), selection.from, selection.to).text : active.value.sql;
    saveSql.value = text.trim() === '' ? active.value.sql : text;
}
// end batch 4B

const isMac = /Mac|iPhone|iPad/.test(navigator.userAgent);
const modKey = isMac ? '⌘' : 'Ctrl';
const shiftKey = isMac ? '⇧' : 'Shift';
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <div class="flex shrink-0 items-end gap-1 border-b border-line bg-sunken px-2 pt-1.5">
            <div role="tablist" aria-label="Query tabs" class="-mb-px flex min-w-0 gap-0.5 overflow-x-auto" @keydown="onTabKeydown">
                <div
                    v-for="tab in tabs"
                    :key="tab.id"
                    class="group flex h-8 items-center rounded-t-md border text-sm transition-colors duration-100"
                    :class="tab.id === activeId ? 'border-line border-b-panel bg-panel text-fg' : 'border-transparent text-fg-muted hover:bg-hover hover:text-fg'"
                >
                    <button
                        :id="`dbx-sql-tab-${tab.id}`"
                        type="button"
                        role="tab"
                        :aria-selected="tab.id === activeId"
                        :tabindex="tab.id === activeId ? 0 : -1"
                        class="flex h-full cursor-default items-center gap-1.5 rounded-tl-md pr-1 pl-3 whitespace-nowrap outline-offset-[-2px]"
                        @click="switchTo(tab.id)"
                    >
                        <span v-if="runs.get(tab.id)?.status === 'running'" class="dbx-spinner size-3" aria-hidden="true" />
                        <BaseIcon v-else name="terminal" :size="12" class="text-fg-subtle" />
                        {{ tab.title }}<span v-if="runs.get(tab.id)?.status === 'running'" class="sr-only">(running)</span>
                    </button>
                    <button
                        type="button"
                        class="mr-1 grid size-5 cursor-default place-items-center rounded text-fg-subtle outline-offset-[-2px] hover:bg-hover hover:text-fg"
                        :aria-label="`Close ${tab.title}`"
                        :title="`Close ${tab.title}`"
                        @click="closeTab(tab.id)"
                    >
                        <BaseIcon name="x" :size="12" />
                    </button>
                </div>
            </div>
            <button type="button" class="dbx-btn dbx-btn-ghost dbx-btn-icon dbx-btn-sm mb-1" aria-label="New query tab" title="New query tab" @click="addTab()"><BaseIcon name="plus" /></button>
        </div>

        <div class="dbx-toolbar">
            <button type="button" class="dbx-btn dbx-btn-primary" :disabled="running" :title="`Run the statement at the cursor, or the selection (${modKey}+Enter)`" @click="start(null)">
                <span v-if="running" class="dbx-spinner border-current/30 border-t-current" aria-hidden="true" />
                <BaseIcon v-else name="play" />
                {{ running ? 'Running…' : 'Run' }}<kbd class="dbx-kbd ml-0.5 opacity-80">{{ modKey }}↵</kbd>
            </button>
            <button type="button" class="dbx-btn" :disabled="running" :title="`EXPLAIN: plan without running (${modKey}+${shiftKey}+Enter)`" @click="start('plain')">
                <BaseIcon name="plan" />Explain<kbd class="dbx-kbd ml-0.5 text-fg-subtle">{{ shiftKey }}{{ modKey }}↵</kbd>
            </button>
            <button type="button" class="dbx-btn" :disabled="running" title="EXPLAIN ANALYZE: runs the statement, then rolls it back" @click="start('analyze')">Explain analyze</button>
            <button type="button" class="dbx-btn" :disabled="running" title="Run a read query as a background run: no row limit, the result goes to a downloadable JSON Lines file" @click="startBackground"><BaseIcon name="activity" />Run in background</button>
            <template v-if="role === 'admin'">
                <span class="dbx-separator" aria-hidden="true" />
                <label class="dbx-check" :class="unmask ? 'text-warning-fg' : ''" title="Show masked columns in clear. Every unmasked run is audited.">
                    <input v-model="unmask" type="checkbox">
                    <BaseIcon :name="unmask ? 'unlock' : 'lock'" :size="14" />
                    Unmask columns
                    <span v-if="unmask" class="text-xs">(audited)</span>
                </label>
            </template>
            <span class="ml-auto hidden text-xs text-fg-subtle xl:inline">Runs the statement at the cursor, or the selection</span>
            <button type="button" class="dbx-btn dbx-btn-ghost" title="Save the statement at the cursor, or the selection, as a saved query" @click="openSave"><BaseIcon :name="savedNotice ? 'check' : 'bookmark'" />{{ savedNotice ? 'Saved' : 'Save query' }}</button>
            <span class="sr-only" role="status">{{ savedNotice ? `Saved as “${savedNotice}”` : '' }}</span>
            <router-link :to="{ name: 'history', params: { label } }" class="dbx-btn dbx-btn-ghost"><BaseIcon name="history" />History</router-link>
        </div>

        <div class="flex min-h-0 flex-1 flex-col">
            <div ref="editorEl" class="h-[38vh] min-h-32 shrink-0 resize-y overflow-hidden border-b border-line bg-panel" />

            <section class="flex min-h-0 flex-1 flex-col bg-canvas" aria-label="Result" aria-live="polite">
                <EmptyState v-if="!run" icon="terminal" title="Run a statement to see its result">
                    Press <kbd class="dbx-kbd">{{ modKey }}↵</kbd> to run the statement under the cursor, or <kbd class="dbx-kbd">{{ shiftKey }}{{ modKey }}↵</kbd> to see its plan.
                    Writes and schema changes ask you to confirm first.
                </EmptyState>
                <div v-else-if="run.status === 'running'" class="flex items-center gap-2 p-4 text-sm text-fg-muted" role="status"><span class="dbx-spinner" aria-hidden="true" />Running…</div>
                <div v-else-if="run.status === 'background' && run.job" class="overflow-auto p-3">
                    <RunPanel :key="run.job.id" :label="label" :run="run.job" title="Background query" @update="(fresh: BackgroundRun) => run && (run.job = fresh)">
                        <p class="text-sm text-fg-muted">The query runs on a queue worker with no row limit. When it succeeds, download the result as a JSON Lines file (one array of values per row, in column order). Masked columns stay masked.</p>
                    </RunPanel>
                </div>
                <div v-else-if="run.status === 'confirm'" class="flex items-center gap-2 p-4 text-sm text-fg-muted" role="status"><BaseIcon name="clock" />Waiting for your confirmation…</div>

                <div v-else-if="run.status === 'failed' && run.error" class="overflow-auto p-3">
                    <div role="alert" class="dbx-callout dbx-callout-danger">
                        <BaseIcon name="alert-circle" />
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold">{{ run.error.status === 403 ? 'Not allowed' : run.error.status === 429 ? 'Rate limited' : 'Query failed' }}</span>
                                <span v-if="run.error.sqlstate" class="rounded-sm border border-danger-line px-1.5 font-mono text-xs">SQLSTATE {{ run.error.sqlstate }}</span>
                            </p>
                            <pre class="mt-1 font-sans whitespace-pre-wrap">{{ run.error.message }}</pre>
                            <pre v-if="errorCaret" class="mt-2 overflow-x-auto rounded-md border border-line bg-panel p-2 font-mono text-xs text-fg"><span class="text-fg-subtle">LINE {{ errorCaret.line }}: </span>{{ errorCaret.text }}
<span class="text-fg-subtle">{{ ' '.repeat(`LINE ${errorCaret.line}: `.length) }}</span><span class="font-bold text-danger-fg">{{ ' '.repeat(errorCaret.column) }}^</span></pre>
                        </div>
                    </div>
                </div>

                <template v-else-if="run.result">
                    <DataGrid v-if="resultTab === 'results' && !run.result.plan && run.result.columns.length" :columns="run.result.columns" :rows="run.result.rows" empty-text="The query returned no rows">
                        <template #toolbar>
                            <ResultBar v-model:view="resultTab" :result="run.result" />
                            <button type="button" class="dbx-btn" title="Run this query again in the background and save every row to a file" @click="exporting = run.sql"><BaseIcon name="download" />Export</button>
                        </template>
                    </DataGrid>
                    <template v-else>
                        <div class="dbx-toolbar"><ResultBar v-model:view="resultTab" :result="run.result" /></div>
                        <div v-if="resultTab === 'messages'" class="min-h-0 flex-1 overflow-auto p-3 text-sm">
                            <p v-if="!run.result.notices_supported" class="text-fg-muted">Notices unavailable: this PHP database driver can't capture NOTICE messages.</p>
                            <p v-else-if="run.result.notices.length === 0" class="text-fg-muted">No notices.</p>
                            <ul v-else class="dbx-card divide-y divide-line-subtle font-mono text-xs">
                                <li v-for="(notice, index) in run.result.notices" :key="index" class="px-3 py-1.5 whitespace-pre-wrap">{{ notice }}</li>
                            </ul>
                        </div>
                        <div v-else-if="run.result.plan" class="min-h-0 flex-1 overflow-auto">
                            <PlanTree :plan="run.result.plan" :analyze="run.result.explain === 'analyze'" />
                        </div>
                        <EmptyState v-else icon="check-circle" :title="run.result.affected_rows !== null ? 'Statement completed' : 'Statement completed with no result set'">
                            <template v-if="run.result.affected_rows !== null">{{ run.result.affected_rows === 1 ? '1 row' : `${run.result.affected_rows.toLocaleString()} rows` }} affected.</template>
                        </EmptyState>
                    </template>
                </template>
            </section>
        </div>

        <SaveQueryDialog :open="saveSql !== null" :label="label" :initial-sql="saveSql ?? ''" @close="saveSql = null" @saved="(query) => onQuerySaved(query.name)" />

        <SqlConfirmDialog
            :open="confirm !== null"
            :classification="confirm?.classification ?? 'read'"
            :sql="confirm?.sql ?? ''"
            :note="confirm?.explain === 'analyze' ? 'EXPLAIN ANALYZE runs it, then rolls it back.' : undefined"
            @confirm="confirmed"
            @cancel="cancelConfirm"
        />
        <ExportDialog :open="exporting !== null" :label="label" :source="{ sql: exporting ?? '' }" @close="exporting = null" />
    </div>
</template>
