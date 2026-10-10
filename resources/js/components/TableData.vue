<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { api, describeError, segment } from '../api';
import { primaryKey } from '../composables/catalog';
import { formatNumber } from '../composables/format';
import { findConnection } from '../composables/session';
import type { Cell, Filter, RowsPage } from '../types';
import DataGrid from './DataGrid.vue';
import BaseIcon from './BaseIcon.vue';
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
        <div v-if="error" role="alert" class="dbx-banner border-danger-line bg-danger-bg text-danger-fg">
            <BaseIcon name="alert-circle" />
            <span class="flex-1">{{ error }}</span>
            <button type="button" class="dbx-btn dbx-btn-sm -my-0.5" @click="load()"><BaseIcon name="refresh" />Retry</button>
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
            empty-text="This table has no rows"
            sortable
            filterable
            @sort="onSort"
            @filter="onFilter"
            @row-click="(index: number) => (selectedIndex = selectedIndex === index ? -1 : index)"
        >
            <template #toolbar>
                <template v-if="canWrite">
                    <span class="flex items-center gap-1.5" role="group" aria-label="Row actions">
                        <button type="button" class="dbx-btn" @click="selectedIndex = -1; editor = { mode: 'insert' }"><BaseIcon name="plus" />Insert row</button>
                        <button type="button" class="dbx-btn" :disabled="!selectedRow" :title="selectedRow ? '' : 'Select a row first'" @click="editor = { mode: 'update' }"><BaseIcon name="pencil" />Edit row</button>
                        <button type="button" class="dbx-btn dbx-btn-danger-ghost" :disabled="!selectedRow" :title="selectedRow ? '' : 'Select a row first'" @click="editor = { mode: 'delete' }"><BaseIcon name="trash" />Delete row</button>
                    </span>
                    <span class="text-xs text-fg-subtle">{{ selectedRow ? 'Row selected' : 'Click a row to select it' }}</span>
                </template>
                <span v-else-if="key !== null && key.length === 0" class="inline-flex items-center gap-1.5 text-xs text-fg-muted" title="Inline editing needs a primary key">
                    <BaseIcon name="lock" :size="12" />Read-only: no primary key
                </span>
                <span v-else-if="key !== null && (findConnection(label)?.role ?? 'viewer') === 'viewer'" class="inline-flex items-center gap-1.5 text-xs text-fg-muted">
                    <BaseIcon name="eye" :size="12" />Read-only: viewer role
                </span>
            </template>

            <template #footer>
                <div class="dbx-statusbar">
                    <div class="flex items-center gap-1" role="group" aria-label="Pages">
                        <button type="button" class="dbx-btn dbx-btn-ghost dbx-btn-icon dbx-btn-sm" :disabled="loading || stack.length <= 1" aria-label="Previous page" title="Previous page" @click="previous"><BaseIcon name="chevron-left" /></button>
                        <span class="min-w-28 text-center text-fg" aria-live="polite">
                            <template v-if="page && page.rows.length">Rows {{ formatNumber(rowOffset + 1) }}–{{ formatNumber(rowOffset + page.rows.length) }}</template>
                            <template v-else>Page {{ stack.length }}</template>
                        </span>
                        <button type="button" class="dbx-btn dbx-btn-ghost dbx-btn-icon dbx-btn-sm" :disabled="loading || !page?.truncated" aria-label="Next page" title="Next page" @click="next"><BaseIcon name="chevron-right" /></button>
                    </div>
                    <span class="dbx-separator" aria-hidden="true" />
                    <span class="flex items-center gap-1.5">
                        <template v-if="count.value === null">Row count unknown</template>
                        <template v-else-if="count.exact">{{ formatNumber(count.value) }} {{ count.value === 1 ? 'row' : 'rows' }}<template v-if="filters.length"> match</template></template>
                        <template v-else>≈ {{ formatNumber(count.value) }} rows <span class="text-fg-subtle">{{ filters.length ? '(table estimate)' : '(estimate)' }}</span></template>
                        <button v-if="!count.exact" type="button" class="dbx-link font-medium" :disabled="loading" @click="load(true)">
                            <span v-if="counting" class="inline-flex items-center gap-1"><span class="dbx-spinner size-3" aria-hidden="true" />Counting…</span>
                            <template v-else>Count exactly</template>
                        </button>
                    </span>
                    <span class="ml-auto flex items-center gap-3">
                        <label class="flex items-center gap-1.5">
                            Rows per page
                            <select v-model.number="limit" class="dbx-select h-6 text-xs" @change="restart(true)">
                                <option v-for="size in PAGE_SIZES" :key="size" :value="size">{{ size }}</option>
                            </select>
                        </label>
                        <span v-if="page" class="dbx-badge" :title="page.pagination === 'keyset' ? 'Paged by key: fast at any depth' : 'Paged by offset: no usable key, deep pages get slower'">{{ page.pagination }}</span>
                    </span>
                </div>
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
