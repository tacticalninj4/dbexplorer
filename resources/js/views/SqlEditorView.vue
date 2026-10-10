<script setup lang="ts">
import { PostgreSQL, sql as sqlLanguage } from '@codemirror/lang-sql';
import { HighlightStyle, syntaxHighlighting } from '@codemirror/language';
import { Compartment, EditorSelection, EditorState, Prec, StateEffect, StateField } from '@codemirror/state';
import type { Extension } from '@codemirror/state';
import { Decoration, EditorView, keymap } from '@codemirror/view';
import type { DecorationSet } from '@codemirror/view';
import { tags } from '@lezer/highlight';
import { basicSetup } from 'codemirror';
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, shallowRef } from 'vue';
import { useRoute } from 'vue-router';
import { api, ApiError, describeError, segment } from '../api';
import DataGrid from '../components/DataGrid.vue';
import ModalDialog from '../components/ModalDialog.vue';
import PlanTree from '../components/PlanTree.vue';
import { takeForEditor } from '../composables/editorInbox';
import { formatDuration, formatNumber } from '../composables/format';
import { findConnection } from '../composables/session';
import { readStorage, writeStorage } from '../composables/storage';
import { catalogCompletion } from '../sql/completion';
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
    status: 'running' | 'confirm' | 'done' | 'failed';
    sql: string;
    explain: ExplainMode;
    result?: QueryResponse;
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

// The editor lives in a shadow root: CodeMirror injects its styles, and the
// strict CSP (style-src 'self', S15) refuses <style> elements in the document,
// but allows constructable stylesheets, which CodeMirror uses in a shadow root.
// So every editor style, token colours included, is a CodeMirror theme here.
const theme = new Compartment();
const darkQuery = window.matchMedia('(prefers-color-scheme: dark)');

function highlight(dark: boolean): Extension {
    const c = dark
        ? { keyword: '#c4b5fd', string: '#6ee7b7', number: '#7dd3fc', comment: '#a1a1aa', type: '#fcd34d', operator: '#d4d4d8', special: '#fda4af' }
        : { keyword: '#6d28d9', string: '#047857', number: '#0369a1', comment: '#71717a', type: '#b45309', operator: '#52525b', special: '#be123c' };

    return syntaxHighlighting(
        HighlightStyle.define(
            [
                { tag: tags.keyword, color: c.keyword, fontWeight: '500' },
                { tag: [tags.string, tags.special(tags.string)], color: c.string },
                { tag: [tags.number, tags.bool, tags.null, tags.atom], color: c.number },
                { tag: [tags.lineComment, tags.blockComment], color: c.comment, fontStyle: 'italic' },
                { tag: [tags.typeName, tags.standard(tags.name)], color: c.type },
                { tag: [tags.operator, tags.punctuation], color: c.operator },
                { tag: [tags.special(tags.name), tags.propertyName, tags.labelName], color: c.special },
            ],
            { themeType: dark ? 'dark' : 'light' },
        ),
    );
}

function editorTheme(dark: boolean): Extension {
    return [highlight(dark), EditorView.theme(
        {
            '&': { height: '100%', fontSize: '13px', backgroundColor: dark ? '#09090b' : '#ffffff', color: dark ? '#f4f4f5' : '#18181b' },
            '&.cm-focused': { outline: 'none' },
            '.cm-scroller': { fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace' },
            '.cm-dbx-error': {
                textDecoration: 'underline wavy #dc2626',
                textUnderlineOffset: '3px',
                backgroundColor: dark ? '#7f1d1d66' : '#fee2e2',
            },
            '.cm-gutters': { backgroundColor: dark ? '#18181b' : '#fafafa', color: '#a1a1aa', borderRight: `1px solid ${dark ? '#27272a' : '#e4e4e7'}` },
            '.cm-activeLine': { backgroundColor: dark ? '#18181b80' : '#f4f4f580' },
            '.cm-activeLineGutter': { backgroundColor: dark ? '#27272a' : '#f4f4f5' },
            '.cm-cursor': { borderLeftColor: dark ? '#f4f4f5' : '#18181b' },
            '&.cm-focused .cm-selectionBackground, .cm-selectionBackground': { backgroundColor: dark ? '#0c4a6e' : '#bae6fd' },
            '.cm-tooltip': { backgroundColor: dark ? '#18181b' : '#ffffff', border: `1px solid ${dark ? '#3f3f46' : '#d4d4d8'}` },
        },
        { dark },
    )];
}

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

async function execute(sql: string, offset: number, explain: ExplainMode, confirmedRequest: boolean): Promise<void> {
    const tabId = activeId.value;
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

const classificationClass: Record<Classification, string> = {
    read: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    dml: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-300',
    ddl: 'bg-orange-100 text-orange-900 dark:bg-orange-950 dark:text-orange-300',
    destructive: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    unknown: 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
};
const classificationText: Record<Classification, string> = {
    read: 'reads data',
    dml: 'changes rows (DML)',
    ddl: 'changes the schema (DDL)',
    destructive: 'is destructive: it drops, truncates, or changes every row',
    unknown: 'could not be classified',
};
const modKey = /Mac|iPhone|iPad/.test(navigator.userAgent) ? '⌘' : 'Ctrl';
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <div class="flex items-end gap-1 border-b border-zinc-200 px-2 pt-1.5 dark:border-zinc-800">
            <div role="tablist" aria-label="Query tabs" class="flex min-w-0 gap-1 overflow-x-auto" @keydown="onTabKeydown">
                <div
                    v-for="tab in tabs"
                    :key="tab.id"
                    class="group flex items-center rounded-t-md border border-b-0 text-sm transition-colors duration-150"
                    :class="tab.id === activeId ? 'border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900' : 'border-transparent text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-100'"
                >
                    <button
                        :id="`dbx-sql-tab-${tab.id}`"
                        type="button"
                        role="tab"
                        :aria-selected="tab.id === activeId"
                        :tabindex="tab.id === activeId ? 0 : -1"
                        class="rounded-tl-md px-3 py-1.5 whitespace-nowrap focus-visible:ring-2 focus-visible:ring-sky-500 focus-visible:outline-none"
                        @click="switchTo(tab.id)"
                    >
                        {{ tab.title }}<span v-if="runs.get(tab.id)?.status === 'running'" class="ml-1 text-sky-600 motion-safe:animate-pulse" aria-label="running">●</span>
                    </button>
                    <button
                        type="button"
                        class="mr-1 rounded px-1 text-zinc-400 hover:text-zinc-900 focus-visible:ring-2 focus-visible:ring-sky-500 focus-visible:outline-none dark:hover:text-zinc-100"
                        :aria-label="`Close ${tab.title}`"
                        @click="closeTab(tab.id)"
                    >
                        ×
                    </button>
                </div>
            </div>
            <button type="button" class="mb-1 rounded px-2 py-1 text-zinc-500 hover:bg-zinc-100 focus-visible:ring-2 focus-visible:ring-sky-500 focus-visible:outline-none dark:hover:bg-zinc-800" aria-label="New query tab" @click="addTab()">+</button>
        </div>

        <div class="flex flex-wrap items-center gap-2 border-b border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-800 dark:bg-zinc-900">
            <button type="button" class="dbx-btn dbx-btn-primary" :disabled="running" :title="`${modKey}+Enter`" @click="start(null)">
                <span v-if="running" class="dbx-spinner mr-1.5 border-white/50 border-t-white" aria-hidden="true" />{{ running ? 'Running…' : 'Run' }}<kbd class="ml-2 text-xs opacity-75">{{ modKey }}↵</kbd>
            </button>
            <button type="button" class="dbx-btn" :disabled="running" :title="`EXPLAIN: plans without running (${modKey}+Shift+Enter)`" @click="start('plain')">Explain</button>
            <button type="button" class="dbx-btn" :disabled="running" title="EXPLAIN ANALYZE: runs the statement, then rolls it back" @click="start('analyze')">Explain analyze</button>
            <label v-if="role === 'admin'" class="ml-2 flex items-center gap-1.5 text-zinc-600 dark:text-zinc-300" title="Show masked columns in clear. Every unmasked run is audited.">
                <input v-model="unmask" type="checkbox">
                Unmask columns
                <span v-if="unmask" class="text-xs text-amber-700 dark:text-amber-400">(audited)</span>
            </label>
            <router-link :to="{ name: 'history', params: { label } }" class="dbx-link ml-auto">History</router-link>
        </div>

        <div class="flex min-h-0 flex-1 flex-col">
            <div ref="editorEl" class="h-[38vh] min-h-40 shrink-0 resize-y overflow-hidden border-b border-zinc-200 dark:border-zinc-800" />

            <section class="flex min-h-0 flex-1 flex-col" aria-label="Result" aria-live="polite">
                <p v-if="!run" class="p-4 text-sm text-zinc-500">Run a statement to see its result here.</p>
                <p v-else-if="run.status === 'running'" class="flex items-center gap-2 p-4 text-sm text-zinc-500"><span class="dbx-spinner" aria-hidden="true" />Running…</p>
                <p v-else-if="run.status === 'confirm'" class="p-4 text-sm text-zinc-500">Waiting for your confirmation…</p>

                <div v-else-if="run.status === 'failed' && run.error" role="alert" class="m-3 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-900 dark:border-red-900 dark:bg-red-950 dark:text-red-100">
                    <p class="flex flex-wrap items-baseline gap-2">
                        <span class="font-semibold">Error</span>
                        <span v-if="run.error.sqlstate" class="rounded bg-red-100 px-1.5 font-mono text-xs dark:bg-red-900">SQLSTATE {{ run.error.sqlstate }}</span>
                        <span v-else-if="run.error.status === 403" class="text-xs">(not allowed)</span>
                        <span v-else-if="run.error.status === 429" class="text-xs">(rate limited)</span>
                    </p>
                    <pre class="mt-1 font-sans whitespace-pre-wrap">{{ run.error.message }}</pre>
                    <pre v-if="errorCaret" class="mt-2 overflow-x-auto rounded bg-white/70 p-2 font-mono text-xs text-zinc-800 dark:bg-zinc-900/70 dark:text-zinc-200"><span class="text-zinc-400">LINE {{ errorCaret.line }}: </span>{{ errorCaret.text }}
<span class="text-zinc-400">{{ ' '.repeat(`LINE ${errorCaret.line}: `.length) }}</span><span class="font-bold text-red-600">{{ ' '.repeat(errorCaret.column) }}^</span></pre>
                </div>

                <template v-else-if="run.result">
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-zinc-200 px-3 py-1.5 text-sm dark:border-zinc-800">
                        <span class="rounded px-1.5 py-0.5 text-xs font-medium" :class="classificationClass[run.result.classification]">{{ run.result.classification }}</span>
                        <span v-if="run.result.explain" class="text-zinc-600 dark:text-zinc-300">{{ run.result.explain === 'analyze' ? 'EXPLAIN ANALYZE' : 'EXPLAIN' }}</span>
                        <template v-else>
                            <span class="tabular-nums">{{ formatNumber(run.result.row_count) }} {{ run.result.row_count === 1 ? 'row' : 'rows' }}</span>
                            <span v-if="run.result.affected_rows !== null" class="tabular-nums">{{ formatNumber(run.result.affected_rows) }} affected</span>
                        </template>
                        <span class="text-zinc-500 tabular-nums">{{ formatDuration(run.result.duration_ms) }}</span>
                        <span v-if="run.result.truncated" class="text-amber-700 dark:text-amber-400">Showing the first {{ formatNumber(run.result.row_count) }} rows: your row limit cut the rest.</span>
                        <div role="tablist" aria-label="Result views" class="ml-auto flex gap-1">
                            <button v-if="run.result.plan" type="button" role="tab" :aria-selected="resultTab === 'plan'" class="rounded px-2 py-0.5 transition-colors duration-150" :class="resultTab === 'plan' ? 'bg-zinc-200 dark:bg-zinc-700' : 'hover:bg-zinc-100 dark:hover:bg-zinc-800'" @click="resultTab = 'plan'">Plan</button>
                            <button v-else type="button" role="tab" :aria-selected="resultTab === 'results'" class="rounded px-2 py-0.5 transition-colors duration-150" :class="resultTab === 'results' ? 'bg-zinc-200 dark:bg-zinc-700' : 'hover:bg-zinc-100 dark:hover:bg-zinc-800'" @click="resultTab = 'results'">Results</button>
                            <button type="button" role="tab" :aria-selected="resultTab === 'messages'" class="rounded px-2 py-0.5 transition-colors duration-150" :class="resultTab === 'messages' ? 'bg-zinc-200 dark:bg-zinc-700' : 'hover:bg-zinc-100 dark:hover:bg-zinc-800'" @click="resultTab = 'messages'">
                                Messages<span v-if="run.result.notices.length" class="ml-1 rounded-full bg-sky-600 px-1.5 text-xs text-white">{{ run.result.notices.length }}</span>
                            </button>
                        </div>
                    </div>

                    <div v-if="resultTab === 'messages'" class="overflow-auto p-3 text-sm">
                        <p v-if="!run.result.notices_supported" class="text-zinc-500">Notices unavailable: this PHP database driver can't capture NOTICE messages.</p>
                        <p v-else-if="run.result.notices.length === 0" class="text-zinc-500">No notices.</p>
                        <ul v-else class="space-y-1 font-mono text-xs">
                            <li v-for="(notice, index) in run.result.notices" :key="index" class="whitespace-pre-wrap">{{ notice }}</li>
                        </ul>
                    </div>
                    <div v-else-if="run.result.plan" class="min-h-0 flex-1 overflow-auto">
                        <PlanTree :plan="run.result.plan" :analyze="run.result.explain === 'analyze'" />
                    </div>
                    <DataGrid v-else-if="run.result.columns.length" :columns="run.result.columns" :rows="run.result.rows" />
                    <p v-else class="p-4 text-sm text-zinc-500">The statement returned no result set.</p>
                </template>
            </section>
        </div>

        <ModalDialog :open="confirm !== null" title="Run this statement?" @close="cancelConfirm">
            <template v-if="confirm">
                <p class="mt-2 text-sm">
                    This statement
                    <span class="rounded px-1.5 py-0.5 text-xs font-medium" :class="classificationClass[confirm.classification]">{{ confirm.classification }}</span>
                    {{ classificationText[confirm.classification] }}.
                    <template v-if="confirm.explain === 'analyze'"> EXPLAIN ANALYZE runs it, then rolls it back.</template>
                </p>
                <p v-if="confirm.classification === 'destructive'" class="mt-2 text-sm text-amber-800 dark:text-amber-300">
                    It is destructive, so you will also be asked for your password.
                </p>
                <pre class="mt-3 max-h-48 overflow-auto rounded bg-zinc-100 p-2 font-mono text-xs whitespace-pre-wrap dark:bg-zinc-800">{{ confirm.sql }}</pre>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" class="dbx-btn" @click="cancelConfirm">Cancel</button>
                    <button type="button" class="dbx-btn" :class="confirm.classification === 'destructive' ? 'dbx-btn-danger' : 'dbx-btn-primary'" @click="confirmed">Run it</button>
                </div>
            </template>
        </ModalDialog>
    </div>
</template>
