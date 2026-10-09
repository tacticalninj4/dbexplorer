<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { api, describeError, segment } from '../api';
import { primaryKey } from '../composables/catalog';
import { formatNumber } from '../composables/format';
import { findConnection } from '../composables/session';
import type { Cell, Filter, RowsPage } from '../types';
import DataGrid from './DataGrid.vue';
import RowEditor from './RowEditor.vue';

/**
 * The Data tab: a server-side paged view of a table, view or materialized
 * view (GET rows/{schema}/{name}). Keyset cursors only page forward, so the
 * cursors (or offsets) of the pages already seen are kept for "Previous".
 */
const props = defineProps<{ label: string; schema: string; name: string }>();

const PAGE_SIZES = [50, 100, 250, 500, 1000];

const page = ref<RowsPage | null>(null);
const error = ref<string | null>(null);
const loading = ref(false);
const limit = ref(100);
const sort = ref<{ column: string; direction: 'asc' | 'desc' } | null>(null);
const filters = ref<Filter[]>([]);
/** Cursor or offset of each page seen so far; the last one is the current page. */
const stack = ref<{ cursor?: string; offset?: number }[]>([{}]);
/** An exact count, kept while the filters stay the same. */
const exactCount = ref<number | null>(null);
const counting = ref(false);
let sequence = 0;

/** M2 writes: the primary key (writes need one) and the picked row. */
const key = ref<string[] | null>(null);
const selectedIndex = ref(-1);
const editor = ref<{ mode: 'insert' | 'update' | 'delete' } | null>(null);

const canWrite = computed(() => key.value !== null && key.value.length > 0 && (findConnection(props.label)?.role ?? 'viewer') !== 'viewer');

const selectedRow = computed<Record<string, Cell> | null>(() => {
    const data = page.value;
    const index = selectedIndex.value;

    if (!data || index < 0 || index >= data.rows.length) {
        return null;
    }

    const row: Record<string, Cell> = {};
    data.columns.forEach((column, position) => {
        row[column.name] = data.rows[index]?.[position] ?? null;
    });

    return row;
});

const rowOffset = computed(() => {
    const current = stack.value[stack.value.length - 1];

    return current?.offset ?? (stack.value.length - 1) * limit.value;
});

const count = computed(() => {
    if (exactCount.value !== null) {
        return { value: exactCount.value, exact: true };
    }

    return page.value?.count ?? { value: null, exact: false };
});

function apiFilters(): Record<string, unknown>[] {
    return filters.value.map(({ column, op, value }) => {
        if (op === 'is_null' || op === 'not_null') {
            return { column, op };
        }

        if (op === 'in') {
            return { column, op, value: value.split(',').map((item) => item.trim()).filter((item) => item !== '') };
        }

        return { column, op, value };
    });
}

async function load(exact = false): Promise<void> {
    const id = ++sequence;
    const current = stack.value[stack.value.length - 1] ?? {};
    loading.value = true;
    counting.value = exact;
    error.value = null;

    try {
        const response = await api<{ data: RowsPage }>(`${segment(props.label)}/rows/${segment(props.schema)}/${segment(props.name)}`, {
            query: {
                limit: limit.value,
                sort: sort.value?.column,
                direction: sort.value?.direction,
                filters: apiFilters(),
                cursor: current.cursor,
                offset: current.offset,
                exact: exact ? 1 : undefined,
            },
        });

        if (id !== sequence) {
            return;
        }

        page.value = response.data;
        if (exact && response.data.count.exact) {
            exactCount.value = response.data.count.value;
        }
    } catch (e) {
        if (id === sequence) {
            error.value = describeError(e);
        }
    } finally {
        if (id === sequence) {
            loading.value = false;
            counting.value = false;
        }
    }
}

/** Back to page one, e.g. after the sort, filters or page size changed. */
function restart(keepCount = false): void {
    stack.value = [{}];
    if (!keepCount) {
        exactCount.value = null;
    }
    void load();
}

function next(): void {
    const data = page.value;
    if (!data?.truncated) {
        return;
    }

    if (data.next_cursor !== null) {
        stack.value.push({ cursor: data.next_cursor });
    } else if (data.next_offset !== null) {
        stack.value.push({ offset: data.next_offset });
    } else {
        return;
    }

    void load();
}

function previous(): void {
    if (stack.value.length > 1) {
        stack.value.pop();
        void load();
    }
}

function onSort(column: string): void {
    if (sort.value?.column !== column) {
        sort.value = { column, direction: 'asc' };
    } else if (sort.value.direction === 'asc') {
        sort.value = { column, direction: 'desc' };
    } else {
        sort.value = null;
    }

    // Sorting doesn't change which rows match: an exact count stays valid.
    restart(true);
}

function onFilter(next: Filter[]): void {
    filters.value = next;
    restart();
}

watch(
    () => [props.label, props.schema, props.name],
    () => {
        sort.value = null;
        filters.value = [];
        page.value = null;
        selectedIndex.value = -1;
        editor.value = null;
        restart();
        primaryKey(props.label, props.schema, props.name).then((columns) => {
            key.value = columns;
        });
    },
    { immediate: true },
);
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <div v-if="error" role="alert" class="border-b border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
            {{ error }}
        </div>
        <DataGrid
            :columns="page?.columns ?? []"
            :rows="page?.rows ?? []"
            :loading="loading"
            :row-offset="rowOffset"
            :sort="sort"
            :filters="filters"
            :selectable="canWrite"
            :selected="selectedIndex"
            sortable
            filterable
            @sort="onSort"
            @filter="onFilter"
            @row-click="(index: number) => (selectedIndex = selectedIndex === index ? -1 : index)"
        >
            <template #toolbar>
                <div class="flex items-center gap-1" role="group" aria-label="Pages">
                    <button type="button" class="dbx-btn" :disabled="loading || stack.length <= 1" @click="previous">← Previous</button>
                    <span class="px-2 text-zinc-600 tabular-nums dark:text-zinc-300" aria-live="polite">
                        Page {{ stack.length }}<template v-if="page && page.rows.length">
                            · rows {{ formatNumber(rowOffset + 1) }}–{{ formatNumber(rowOffset + page.rows.length) }}</template>
                    </span>
                    <button type="button" class="dbx-btn" :disabled="loading || !page?.truncated" @click="next">Next →</button>
                </div>
                <label class="ml-2 flex items-center gap-1 text-zinc-500 dark:text-zinc-400">
                    Page size
                    <select v-model.number="limit" class="dbx-input" @change="restart(true)">
                        <option v-for="size in PAGE_SIZES" :key="size" :value="size">{{ size }}</option>
                    </select>
                </label>
                <span class="ml-2 text-zinc-500 tabular-nums dark:text-zinc-400">
                    <template v-if="count.value === null">Row count unknown</template>
                    <template v-else-if="count.exact">{{ formatNumber(count.value) }} rows</template>
                    <template v-else>≈ {{ formatNumber(count.value) }} rows {{ filters.length ? 'in the table' : '' }} (estimate)</template>
                </span>
                <button v-if="!count.exact" type="button" class="dbx-btn" :disabled="loading" @click="load(true)">
                    {{ counting ? 'Counting…' : 'Exact count' }}
                </button>
                <span v-if="page" class="text-xs text-zinc-400" :title="page.pagination === 'keyset' ? 'Paged by key: fast at any depth' : 'Paged by offset: no usable key'">
                    {{ page.pagination }}
                </span>
                <span v-if="canWrite" class="ml-auto flex items-center gap-1" role="group" aria-label="Row actions">
                    <button type="button" class="dbx-btn dbx-btn-primary" @click="selectedIndex = -1; editor = { mode: 'insert' }">Insert row</button>
                    <button type="button" class="dbx-btn" :disabled="!selectedRow" @click="editor = { mode: 'update' }">Edit row</button>
                    <button type="button" class="dbx-btn dbx-btn-danger" :disabled="!selectedRow" @click="editor = { mode: 'delete' }">Delete row</button>
                </span>
                <span v-else-if="key !== null && key.length === 0" class="ml-auto text-xs text-zinc-400" title="Inline editing needs a primary key">
                    read-only (no primary key)
                </span>
            </template>
        </DataGrid>

        <RowEditor
            :open="editor !== null"
            :mode="editor?.mode ?? 'insert'"
            :label="label"
            :schema="schema"
            :name="name"
            :columns="page?.columns ?? []"
            :primary-key="key ?? []"
            :row="selectedRow"
            @close="(saved: boolean) => {
                editor = null;
                if (saved) {
                    selectedIndex = -1;
                    void load();
                }
            }"
        />
    </div>
</template>
