<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { Cell, Filter, FilterOperator, ResultColumn } from '../types';
import { FILTER_OPERATORS } from '../types';
import { isNumericType } from './cells';
import GridCell from './GridCell.vue';

/**
 * The grid behind both a table's Data tab and SQL results. It renders exactly
 * the rows it is given (one page, at most the role's row cap), so a page stays
 * cheap without virtual scrolling. Sorting and filtering are server-side: the
 * grid only emits what the user picked.
 */
const props = withDefaults(
    defineProps<{
        columns: ResultColumn[];
        rows: Cell[][];
        loading?: boolean;
        /** Row number of the first row, for the # column. */
        rowOffset?: number;
        sort?: { column: string; direction: 'asc' | 'desc' } | null;
        sortable?: boolean;
        filterable?: boolean;
        filters?: Filter[];
    }>(),
    { loading: false, rowOffset: 0, sort: null, sortable: false, filterable: false, filters: () => [] },
);

const emit = defineEmits<{
    sort: [column: string];
    filter: [filters: Filter[]];
}>();

const OPERATOR_LABELS: Record<FilterOperator, string> = {
    '=': '=',
    '!=': '≠',
    '<': '<',
    '<=': '≤',
    '>': '>',
    '>=': '≥',
    like: 'like',
    ilike: 'ilike (case-insensitive)',
    in: 'in (comma list)',
    is_null: 'is null',
    not_null: 'is not null',
};

const hidden = ref(new Set<string>());
const showColumns = ref(false);
const showFilters = ref(false);
const draft = ref<Filter[]>([]);

const visible = computed(() =>
    props.columns.map((column, index) => ({ column, index })).filter(({ column }) => !hidden.value.has(column.name)),
);
const filterColumns = computed(() => props.columns.filter((column) => !column.masked));

watch(
    () => props.filters,
    (filters) => {
        draft.value = filters.map((filter) => ({ ...filter }));
    },
    { immediate: true },
);

function toggleColumn(name: string): void {
    const next = new Set(hidden.value);
    if (next.has(name)) {
        next.delete(name);
    } else {
        next.add(name);
    }
    hidden.value = next;
}

function addFilter(): void {
    const column = filterColumns.value[0]?.name;
    if (column !== undefined) {
        draft.value.push({ column, op: '=', value: '' });
    }
}

function applyFilters(): void {
    emit('filter', draft.value.map((filter) => ({ ...filter })));
}

function clearFilters(): void {
    draft.value = [];
    emit('filter', []);
}

function ariaSort(column: ResultColumn): 'ascending' | 'descending' | 'none' {
    if (props.sort?.column !== column.name) {
        return 'none';
    }

    return props.sort.direction === 'asc' ? 'ascending' : 'descending';
}

function needsValue(op: FilterOperator): boolean {
    return op !== 'is_null' && op !== 'not_null';
}
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <div class="flex flex-wrap items-center gap-2 border-b border-zinc-200 px-3 py-2 text-sm dark:border-zinc-800">
            <slot name="toolbar" />
            <div class="ml-auto flex items-center gap-2">
                <button
                    v-if="filterable"
                    type="button"
                    class="dbx-btn"
                    :aria-expanded="showFilters"
                    aria-controls="dbx-grid-filters"
                    @click="showFilters = !showFilters"
                >
                    Filters<span v-if="filters.length" class="ml-1 rounded-full bg-sky-600 px-1.5 text-xs text-white">{{ filters.length }}</span>
                </button>
                <div class="relative">
                    <button type="button" class="dbx-btn" :aria-expanded="showColumns" @click="showColumns = !showColumns">
                        Columns<span v-if="hidden.size" class="ml-1 text-xs text-zinc-500">({{ columns.length - hidden.size }}/{{ columns.length }})</span>
                    </button>
                    <div
                        v-if="showColumns"
                        class="absolute right-0 z-20 mt-1 max-h-80 w-64 overflow-auto rounded-md border border-zinc-200 bg-white p-2 shadow-lg dark:border-zinc-700 dark:bg-zinc-900"
                        @keydown.esc="showColumns = false"
                    >
                        <fieldset>
                            <legend class="sr-only">Visible columns</legend>
                            <label v-for="column in columns" :key="column.name" class="flex items-center gap-2 rounded px-1 py-0.5 hover:bg-zinc-100 dark:hover:bg-zinc-800">
                                <input type="checkbox" :checked="!hidden.has(column.name)" @change="toggleColumn(column.name)">
                                <span class="truncate">{{ column.name }}</span>
                                <span class="ml-auto text-xs text-zinc-400">{{ column.type }}</span>
                            </label>
                        </fieldset>
                    </div>
                </div>
            </div>
        </div>

        <form
            v-if="filterable && showFilters"
            id="dbx-grid-filters"
            class="space-y-2 border-b border-zinc-200 bg-zinc-50 px-3 py-2 text-sm dark:border-zinc-800 dark:bg-zinc-900/50"
            @submit.prevent="applyFilters"
        >
            <p v-if="draft.length === 0" class="text-zinc-500 dark:text-zinc-400">No filters. Rows match all of the filters you add.</p>
            <div v-for="(filter, index) in draft" :key="index" class="flex flex-wrap items-center gap-2">
                <label class="sr-only" :for="`dbx-filter-column-${index}`">Column</label>
                <select :id="`dbx-filter-column-${index}`" v-model="filter.column" class="dbx-input">
                    <option v-for="column in filterColumns" :key="column.name" :value="column.name">{{ column.name }}</option>
                </select>
                <label class="sr-only" :for="`dbx-filter-op-${index}`">Operator</label>
                <select :id="`dbx-filter-op-${index}`" v-model="filter.op" class="dbx-input">
                    <option v-for="op in FILTER_OPERATORS" :key="op" :value="op">{{ OPERATOR_LABELS[op] }}</option>
                </select>
                <template v-if="needsValue(filter.op)">
                    <label class="sr-only" :for="`dbx-filter-value-${index}`">Value</label>
                    <input
                        :id="`dbx-filter-value-${index}`"
                        v-model="filter.value"
                        type="text"
                        class="dbx-input w-56"
                        :placeholder="filter.op === 'in' ? 'a, b, c' : filter.op.endsWith('like') ? '%text%' : 'value'"
                    >
                </template>
                <button type="button" class="dbx-btn" :aria-label="`Remove filter ${index + 1}`" @click="draft.splice(index, 1)">✕</button>
            </div>
            <div class="flex gap-2">
                <button type="button" class="dbx-btn" :disabled="filterColumns.length === 0" @click="addFilter">Add filter</button>
                <button type="submit" class="dbx-btn dbx-btn-primary">Apply</button>
                <button v-if="filters.length || draft.length" type="button" class="dbx-btn" @click="clearFilters">Clear</button>
            </div>
        </form>

        <div class="relative min-h-0 flex-1 overflow-auto" :aria-busy="loading">
            <table class="min-w-full border-separate border-spacing-0 text-sm">
                <thead class="sticky top-0 z-10 bg-zinc-100 dark:bg-zinc-900">
                    <tr>
                        <th scope="col" class="w-px border-b border-zinc-200 px-2 py-1.5 text-right text-xs font-normal text-zinc-400 dark:border-zinc-800">#</th>
                        <th
                            v-for="{ column, index } in visible"
                            :key="index"
                            scope="col"
                            :aria-sort="sortable ? ariaSort(column) : undefined"
                            class="border-b border-l border-zinc-200 px-2 py-1.5 text-left font-medium whitespace-nowrap dark:border-zinc-800"
                        >
                            <button
                                v-if="sortable && !column.masked"
                                type="button"
                                class="inline-flex items-center gap-1 rounded hover:text-sky-700 focus-visible:ring-2 focus-visible:ring-sky-500 focus-visible:outline-none dark:hover:text-sky-400"
                                :title="`Sort by ${column.name}`"
                                @click="emit('sort', column.name)"
                            >
                                <span>{{ column.name }}</span>
                                <span aria-hidden="true" class="w-3 text-xs text-sky-600">{{ sort?.column === column.name ? (sort.direction === 'asc' ? '▲' : '▼') : '' }}</span>
                            </button>
                            <span v-else class="inline-flex items-center gap-1">
                                <span>{{ column.name }}</span>
                                <span v-if="column.masked" class="rounded bg-zinc-200 px-1 text-[10px] font-normal tracking-wide text-zinc-600 uppercase dark:bg-zinc-800 dark:text-zinc-400" title="Masked: can't be sorted or filtered">masked</span>
                            </span>
                            <span class="block text-xs font-normal text-zinc-400">{{ column.type }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, r) in rows" :key="rowOffset + r" class="odd:bg-white even:bg-zinc-50/60 hover:bg-sky-50 dark:odd:bg-zinc-950 dark:even:bg-zinc-900/40 dark:hover:bg-sky-950/40">
                        <td class="border-b border-zinc-100 px-2 py-1 text-right align-top text-xs text-zinc-400 tabular-nums dark:border-zinc-800/60">{{ rowOffset + r + 1 }}</td>
                        <td
                            v-for="{ column, index } in visible"
                            :key="index"
                            class="border-b border-l border-zinc-100 px-2 py-1 align-top whitespace-nowrap dark:border-zinc-800/60"
                            :class="isNumericType(column.type) ? 'text-right tabular-nums' : ''"
                        >
                            <div class="max-w-xl overflow-hidden text-ellipsis">
                                <GridCell :value="row[index] ?? null" :column="column" />
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-if="!loading && rows.length === 0" class="p-6 text-center text-sm text-zinc-500 dark:text-zinc-400">No rows.</p>
            <div v-if="loading" class="pointer-events-none absolute inset-0 flex items-start justify-center bg-white/50 pt-10 dark:bg-zinc-950/50">
                <span class="rounded bg-white px-3 py-1 text-sm shadow dark:bg-zinc-800">Loading…</span>
            </div>
        </div>
        <slot name="footer" />
    </div>
</template>
