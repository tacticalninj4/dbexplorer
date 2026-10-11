<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { api, describeError, segment } from '../api';
import BaseIcon from '../components/BaseIcon.vue';
import EmptyState from '../components/EmptyState.vue';
import RunDetails from '../components/RunDetails.vue';
import RunPanel from '../components/RunPanel.vue';
import * as catalog from '../composables/catalog';
import { formatBytes, formatNumber } from '../composables/format';
import type { BackgroundRun } from '../composables/runs';
import { hasRole } from '../composables/session';
import { qualified } from '../sql/identifiers';
import type { Column, DbObject, Schema } from '../types';

/**
 * /:label/import: the CSV import wizard (PRD §7.6), editors and admins only
 * (the API refuses viewers with a 403 either way).
 *
 * 1. Pick an existing table.
 * 2. Upload the file: the server sniffs it as CSV or plain text and caps its
 *    size (limits.import.max_upload_kb); its 422 says why it refused.
 * 3. Preview the first rows and map each CSV column to a table column, or
 *    skip it.
 * 4. Choose all-or-nothing or skip-bad-rows, then run it as a background
 *    run and follow it here: inserted, skipped, and the rejected rows' row
 *    numbers and messages, never their values (F7).
 */
const route = useRoute();
const label = String(route.params.label);
const allowed = computed(() => hasRole(label, 'editor'));

type Step = 'table' | 'file' | 'map' | 'options' | 'run';

const STEPS: { id: Step; title: string }[] = [
    { id: 'table', title: 'Table' },
    { id: 'file', title: 'File' },
    { id: 'map', title: 'Columns' },
    { id: 'options', title: 'Options' },
    { id: 'run', title: 'Import' },
];

const step = ref<Step>('table');
const stepIndex = computed(() => STEPS.findIndex((item) => item.id === step.value));
const heading = ref<HTMLElement | null>(null);

// 1. Table
const schemaList = ref<Schema[]>([]);
const tableList = ref<DbObject[]>([]);
const schema = ref(typeof route.query.schema === 'string' ? route.query.schema : '');
const table = ref(typeof route.query.name === 'string' ? route.query.name : '');
const targetColumns = ref<Column[]>([]);
const loadingTables = ref(false);

// 2. File
const fileInput = ref<HTMLInputElement | null>(null);
const file = ref<File | null>(null);
const header = ref(true);
const upload = ref<{ token: string; size: number } | null>(null);

// 3. Preview and map
const preview = ref<{ columns: string[]; rows: string[][] } | null>(null);
/** One entry per CSV column: the target column name, or '' to skip it. */
const map = ref<string[]>([]);

// 4. Options
const mode = ref<'transaction' | 'skip'>('transaction');
const emptyAsNull = ref(true);

// 5. Run
const run = ref<BackgroundRun | null>(null);

const busy = ref(false);
const error = ref<string | null>(null);

const targetName = computed(() => (schema.value && table.value ? qualified(schema.value, table.value) : ''));
const mapped = computed(() => map.value.filter((column) => column !== ''));
const duplicates = computed(() => new Set(mapped.value.filter((column, index) => mapped.value.indexOf(column) !== index)));
/** NOT NULL columns with no default, identity or generation: every row needs a value. */
const required = computed(() =>
    targetColumns.value.filter((column) => !column.nullable && column.default === null && column.identity === null && column.generated === null).map((column) => column.name),
);
const missingRequired = computed(() => required.value.filter((name) => !mapped.value.includes(name)));
const generated = computed(() => new Set(targetColumns.value.filter((column) => column.generated !== null || column.identity === 'always').map((column) => column.name)));

async function loadSchemas(): Promise<void> {
    try {
        schemaList.value = await catalog.schemas(label);

        if (!schema.value) {
            schema.value = schemaList.value.find((item) => item.name === 'public')?.name ?? schemaList.value[0]?.name ?? '';
        }
    } catch (e) {
        error.value = describeError(e, 'The schemas could not be loaded.');
    }
}

watch(
    schema,
    async (name, previous) => {
        tableList.value = [];

        if (previous !== undefined) {
            table.value = '';
        }

        if (!name) {
            return;
        }

        loadingTables.value = true;
        try {
            const objects = await catalog.objects(label, name);

            if (schema.value === name) {
                tableList.value = objects.filter((object) => object.type === 'table' || object.type === 'partitioned_table');

                if (table.value && !tableList.value.some((object) => object.name === table.value)) {
                    table.value = '';
                }
            }
        } catch (e) {
            error.value = describeError(e, 'The tables could not be loaded.');
        } finally {
            loadingTables.value = false;
        }
    },
    { immediate: true },
);

async function go(next: Step): Promise<void> {
    error.value = null;
    step.value = next;
    await nextTick();
    heading.value?.focus();
}

async function chooseTable(): Promise<void> {
    if (!schema.value || !table.value) {
        return;
    }

    busy.value = true;
    error.value = null;

    try {
        targetColumns.value = await catalog.columns(label, schema.value, table.value);
        void go('file');
    } catch (e) {
        error.value = describeError(e, 'The table’s columns could not be loaded.');
    } finally {
        busy.value = false;
    }
}

function onFile(event: Event): void {
    const picked = (event.target as HTMLInputElement).files?.[0] ?? null;
    file.value = picked;
    upload.value = null;
    preview.value = null;
    error.value = null;
}

async function loadPreview(): Promise<void> {
    if (!upload.value) {
        return;
    }

    const response = await api<{ data: { columns: string[]; rows: string[][] } }>(`${segment(label)}/imports/preview`, {
        method: 'POST',
        body: { token: upload.value.token, header: header.value },
    });

    preview.value = response.data;

    // Match CSV headers to table columns by name, ignoring case; the rest are skipped.
    const names = targetColumns.value.filter((column) => !generated.value.has(column.name)).map((column) => column.name);
    const used = new Set<string>();
    map.value = response.data.columns.map((csvColumn) => {
        const match = header.value ? names.find((name) => name.toLowerCase() === csvColumn.trim().toLowerCase() && !used.has(name)) : undefined;

        if (match) {
            used.add(match);
        }

        return match ?? '';
    });
}

async function sendFile(): Promise<void> {
    if (!file.value || busy.value) {
        return;
    }

    busy.value = true;
    error.value = null;

    try {
        if (!upload.value) {
            const form = new FormData();
            form.append('file', file.value);
            upload.value = (await api<{ data: { token: string; size: number } }>(`${segment(label)}/imports`, { method: 'POST', body: form })).data;
        }

        await loadPreview();
        void go('map');
    } catch (e) {
        error.value = describeError(e, 'The file could not be uploaded.');
    } finally {
        busy.value = false;
    }
}

async function toggleHeader(): Promise<void> {
    busy.value = true;
    error.value = null;

    try {
        await loadPreview();
    } catch (e) {
        error.value = describeError(e, 'The preview could not be loaded.');
    } finally {
        busy.value = false;
    }
}

async function start(): Promise<void> {
    if (busy.value || !upload.value) {
        return;
    }

    busy.value = true;
    error.value = null;

    try {
        const response = await api<{ data: BackgroundRun }>(`${segment(label)}/imports/run`, {
            method: 'POST',
            body: {
                token: upload.value.token,
                table: { schema: schema.value, name: table.value },
                map: map.value.map((column) => (column === '' ? null : column)),
                mode: mode.value,
                header: header.value,
                empty_as_null: emptyAsNull.value,
            },
        });
        run.value = response.data;
        catalog.invalidate(label, schema.value);
        void go('run');
    } catch (e) {
        error.value = describeError(e, 'The import could not be started.');
    } finally {
        busy.value = false;
    }
}

function restart(): void {
    file.value = null;
    upload.value = null;
    preview.value = null;
    map.value = [];
    run.value = null;
    mode.value = 'transaction';

    if (fileInput.value) {
        fileInput.value.value = '';
    }

    void go('file');
}

const mapReady = computed(() => mapped.value.length > 0 && duplicates.value.size === 0);

void loadSchemas();
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <header class="dbx-page-header">
            <BaseIcon name="upload" class="text-fg-subtle" />
            <h1 class="text-lg font-semibold">Import CSV</h1>
            <span class="text-sm text-fg-muted">Load rows from a CSV file into an existing table on {{ label }}, as a background run.</span>
        </header>

        <div v-if="!allowed" class="flex flex-1 items-center justify-center">
            <EmptyState icon="lock" title="Importing needs the editor role">
                Your role on {{ label }} can browse and export, but not write rows. Ask an admin for the editor role.
            </EmptyState>
        </div>

        <div v-else class="min-h-0 flex-1 overflow-y-auto p-4">
            <div class="mx-auto max-w-4xl space-y-4">
                <nav aria-label="Import steps">
                    <ol class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                        <li v-for="(item, index) in STEPS" :key="item.id" class="flex items-center gap-2" :aria-current="item.id === step ? 'step' : undefined">
                            <span
                                class="grid size-5 place-items-center rounded-full border text-[11px] font-semibold tabular-nums"
                                :class="index < stepIndex ? 'border-transparent bg-accent-solid text-on-accent' : index === stepIndex ? 'border-accent text-accent' : 'border-line text-fg-subtle'"
                            >
                                <BaseIcon v-if="index < stepIndex" name="check" :size="12" /><template v-else>{{ index + 1 }}</template>
                            </span>
                            <span :class="index === stepIndex ? 'font-medium text-fg' : 'text-fg-muted'">{{ item.title }}</span>
                            <span v-if="index < stepIndex" class="sr-only">(done)</span>
                            <BaseIcon v-if="index < STEPS.length - 1" name="chevron-right" :size="12" class="text-fg-subtle" />
                        </li>
                    </ol>
                </nav>

                <p v-if="error" role="alert" class="dbx-callout dbx-callout-danger"><BaseIcon name="alert-circle" /><span>{{ error }}</span></p>

                <!-- 1. Table -->
                <form v-if="step === 'table'" class="dbx-card space-y-4 p-4" @submit.prevent="chooseTable">
                    <h2 ref="heading" tabindex="-1" class="text-base font-semibold">Which table should the rows go into?</h2>
                    <div class="flex flex-wrap gap-3">
                        <div>
                            <label for="dbx-import-schema" class="dbx-label">Schema</label>
                            <select id="dbx-import-schema" v-model="schema" class="dbx-select w-56">
                                <option v-for="item in schemaList" :key="item.name" :value="item.name">{{ item.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label for="dbx-import-table" class="dbx-label">Table</label>
                            <select id="dbx-import-table" v-model="table" class="dbx-select w-72" :disabled="loadingTables || tableList.length === 0">
                                <option value="" disabled>{{ loadingTables ? 'Loading…' : tableList.length ? 'Choose a table…' : 'No tables in this schema' }}</option>
                                <option v-for="item in tableList" :key="item.name" :value="item.name">{{ item.name }}</option>
                            </select>
                        </div>
                    </div>
                    <p class="text-xs text-fg-muted">The table must already exist. Rows are added to it; nothing is replaced or deleted.</p>
                    <div class="flex justify-end border-t border-line pt-4">
                        <button type="submit" class="dbx-btn dbx-btn-primary" :disabled="!table || busy">Next<BaseIcon name="arrow-right" /></button>
                    </div>
                </form>

                <!-- 2. File -->
                <form v-else-if="step === 'file'" class="dbx-card space-y-4 p-4" @submit.prevent="sendFile">
                    <h2 ref="heading" tabindex="-1" class="text-base font-semibold">Upload a CSV file for {{ targetName }}</h2>
                    <div>
                        <label for="dbx-import-file" class="dbx-label">CSV file</label>
                        <input
                            id="dbx-import-file"
                            ref="fileInput"
                            type="file"
                            accept=".csv,.txt,text/csv,text/plain"
                            class="block w-full text-sm text-fg-muted file:mr-3 file:h-7 file:rounded-md file:border file:border-line file:bg-panel file:px-2.5 file:text-sm file:font-medium file:text-fg hover:file:bg-hover"
                            aria-describedby="dbx-import-file-hint"
                            @change="onFile"
                        >
                        <p id="dbx-import-file-hint" class="mt-1.5 text-xs text-fg-muted">
                            Comma-separated, double-quoted where needed, UTF-8. The server checks the content is CSV or plain text and caps the upload size.
                        </p>
                        <p v-if="file" class="mt-1.5 text-xs text-fg tabular-nums">{{ file.name }} · {{ formatBytes(file.size) }}</p>
                    </div>
                    <label class="dbx-check"><input v-model="header" type="checkbox">The first row holds column names</label>
                    <div class="flex justify-between gap-2 border-t border-line pt-4">
                        <button type="button" class="dbx-btn dbx-btn-ghost" @click="go('table')"><BaseIcon name="chevron-left" />Back</button>
                        <button type="submit" class="dbx-btn dbx-btn-primary" :disabled="!file || busy">
                            <span v-if="busy" class="dbx-spinner border-current/30 border-t-current" aria-hidden="true" />
                            <BaseIcon v-else name="upload" />{{ busy ? 'Uploading…' : 'Upload and preview' }}
                        </button>
                    </div>
                </form>

                <!-- 3. Map -->
                <form v-else-if="step === 'map' && preview" class="dbx-card space-y-4 p-4" @submit.prevent="mapReady && go('options')">
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <h2 ref="heading" tabindex="-1" class="text-base font-semibold">Match the file’s columns to {{ targetName }}</h2>
                        <span class="text-xs text-fg-muted">{{ preview.columns.length }} columns detected · first {{ formatNumber(preview.rows.length) }} rows shown</span>
                    </div>
                    <label class="dbx-check"><input v-model="header" type="checkbox" :disabled="busy" @change="toggleHeader">The first row holds column names</label>

                    <div class="dbx-card overflow-x-auto">
                        <table class="dbx-table">
                            <thead>
                                <tr>
                                    <th scope="col">CSV column</th>
                                    <th scope="col">Sample</th>
                                    <th scope="col">Import into</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(csvColumn, index) in preview.columns" :key="index">
                                    <td class="font-mono text-xs whitespace-nowrap">{{ csvColumn || `Column ${index + 1}` }}</td>
                                    <td class="max-w-64 truncate font-mono text-xs text-fg-muted" :title="preview.rows.map((row) => row[index] ?? '').slice(0, 5).join(', ')">
                                        {{ preview.rows.map((row) => row[index] ?? '').slice(0, 3).join(', ') }}
                                    </td>
                                    <td>
                                        <label :for="`dbx-import-map-${index}`" class="sr-only">Target column for {{ csvColumn || `column ${index + 1}` }}</label>
                                        <select
                                            :id="`dbx-import-map-${index}`"
                                            v-model="map[index]"
                                            class="dbx-select w-56 font-mono text-xs"
                                            :class="map[index] === '' ? 'text-fg-subtle' : ''"
                                            :aria-invalid="map[index] !== '' && duplicates.has(map[index] as string)"
                                        >
                                            <option value="">Skip this column</option>
                                            <option v-for="column in targetColumns" :key="column.name" :value="column.name" :disabled="generated.has(column.name)">
                                                {{ column.name }} ({{ column.type }}){{ generated.has(column.name) ? ' · generated' : '' }}
                                            </option>
                                        </select>
                                        <span v-if="map[index] !== '' && duplicates.has(map[index] as string)" class="ml-2 text-xs text-danger-fg">Used twice</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p v-if="mapped.length === 0" class="dbx-callout dbx-callout-info"><BaseIcon name="info" /><span>Choose a table column for at least one CSV column.</span></p>
                    <p v-if="duplicates.size" class="dbx-callout dbx-callout-danger" role="alert"><BaseIcon name="alert-circle" /><span>Each table column can only be filled from one CSV column: {{ [...duplicates].join(', ') }}.</span></p>
                    <p v-if="mapped.length && missingRequired.length" class="dbx-callout dbx-callout-warning">
                        <BaseIcon name="alert" />
                        <span>{{ missingRequired.join(', ') }} {{ missingRequired.length === 1 ? 'is' : 'are' }} NOT NULL with no default but not mapped, so every row will be rejected.</span>
                    </p>

                    <details class="text-sm">
                        <summary class="cursor-default text-fg-muted">Preview the first rows as the server read them</summary>
                        <div class="mt-2 max-h-72 overflow-auto rounded-md border border-line">
                            <table class="dbx-table font-mono text-xs">
                                <thead class="sticky top-0 bg-sunken">
                                    <tr>
                                        <th scope="col" class="w-px text-right">Row</th>
                                        <th v-for="(csvColumn, index) in preview.columns" :key="index" scope="col">{{ csvColumn || `Column ${index + 1}` }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(row, r) in preview.rows" :key="r">
                                        <td class="text-right text-fg-subtle tabular-nums">{{ r + 1 }}</td>
                                        <td v-for="(_, index) in preview.columns" :key="index" class="max-w-56 truncate whitespace-nowrap">{{ row[index] ?? '' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </details>

                    <div class="flex justify-between gap-2 border-t border-line pt-4">
                        <button type="button" class="dbx-btn dbx-btn-ghost" @click="go('file')"><BaseIcon name="chevron-left" />Back</button>
                        <button type="submit" class="dbx-btn dbx-btn-primary" :disabled="!mapReady || busy">Next<BaseIcon name="arrow-right" /></button>
                    </div>
                </form>

                <!-- 4. Options -->
                <form v-else-if="step === 'options'" class="dbx-card space-y-4 p-4" @submit.prevent="start">
                    <h2 ref="heading" tabindex="-1" class="text-base font-semibold">What should happen when a row is rejected?</h2>
                    <fieldset class="space-y-2">
                        <legend class="sr-only">Import mode</legend>
                        <label class="flex gap-2.5 rounded-md border px-3 py-2.5" :class="mode === 'transaction' ? 'border-info-line bg-accent-soft' : 'border-line hover:bg-hover'">
                            <input v-model="mode" type="radio" name="dbx-import-mode" value="transaction" class="mt-0.5">
                            <span>
                                <span class="block font-medium text-fg">All or nothing</span>
                                <span class="block text-xs text-fg-muted">The whole file loads in one transaction. If any row is rejected, nothing is imported and the run fails, naming that row. Safe to retry after fixing the file.</span>
                            </span>
                        </label>
                        <label class="flex gap-2.5 rounded-md border px-3 py-2.5" :class="mode === 'skip' ? 'border-info-line bg-accent-soft' : 'border-line hover:bg-hover'">
                            <input v-model="mode" type="radio" name="dbx-import-mode" value="skip" class="mt-0.5">
                            <span>
                                <span class="block font-medium text-fg">Skip bad rows</span>
                                <span class="block text-xs text-fg-muted">Good rows are imported and stay imported. Rejected rows are left out and listed with their row number and the database’s reason, so you can fix them and import just those rows again.</span>
                            </span>
                        </label>
                    </fieldset>
                    <label class="dbx-check"><input v-model="emptyAsNull" type="checkbox">Treat empty cells as NULL</label>

                    <div class="dbx-callout dbx-callout-info">
                        <BaseIcon name="info" />
                        <span>
                            Imports {{ mapped.length }} {{ mapped.length === 1 ? 'column' : 'columns' }} from {{ file?.name ?? 'the file' }}
                            ({{ formatBytes(upload?.size) }}) into {{ targetName }}<template v-if="map.length > mapped.length">, skipping {{ map.length - mapped.length }} {{ map.length - mapped.length === 1 ? 'column' : 'columns' }}</template>.
                        </span>
                    </div>

                    <div class="flex justify-between gap-2 border-t border-line pt-4">
                        <button type="button" class="dbx-btn dbx-btn-ghost" @click="go('map')"><BaseIcon name="chevron-left" />Back</button>
                        <button type="submit" class="dbx-btn dbx-btn-primary" :disabled="busy">
                            <span v-if="busy" class="dbx-spinner border-current/30 border-t-current" aria-hidden="true" />
                            <BaseIcon v-else name="play" />{{ busy ? 'Starting…' : 'Start import' }}
                        </button>
                    </div>
                </form>

                <!-- 5. Run -->
                <section v-else-if="step === 'run' && run" class="space-y-3" aria-live="polite">
                    <h2 ref="heading" tabindex="-1" class="text-base font-semibold">Import into {{ targetName }}</h2>
                    <RunPanel :label="label" :run="run" :title="`Import into ${targetName}`" @update="(fresh: BackgroundRun) => (run = fresh)">
                        <RunDetails :run="run" />
                    </RunPanel>
                    <div class="flex flex-wrap gap-2">
                        <button v-if="run.status === 'failed' || run.status === 'cancelled'" type="button" class="dbx-btn" @click="go('map')"><BaseIcon name="sliders" />Change columns or options</button>
                        <button type="button" class="dbx-btn" @click="restart"><BaseIcon name="upload" />Import another file</button>
                        <router-link :to="{ name: 'object', params: { label, schema, name: table }, query: { tab: 'data' } }" class="dbx-btn dbx-btn-ghost"><BaseIcon name="table" />Open {{ table }}</router-link>
                    </div>
                </section>
            </div>
        </div>
    </div>
</template>
