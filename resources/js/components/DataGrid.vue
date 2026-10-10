<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import type { Cell, Filter, FilterOperator, ResultColumn } from '../types';
import { FILTER_OPERATORS } from '../types';
import { isNumericType } from './cells';
import EmptyState from './EmptyState.vue';
import GridCell from './GridCell.vue';
import BaseIcon from './BaseIcon.vue';
import SkeletonRows from './SkeletonRows.vue';

/**
 * The grid behind both a table's Data tab and SQL results. It renders exactly
 * the rows it is given (one page, at most the role's row cap), so a page stays
 * cheap without virtual scrolling. Sorting and filtering are server-side: the
 * grid only emits what the user picked. Column widths and visibility are
 * local view state.
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
        /** Rows can be picked, e.g. for editing. Emits `rowClick` with the index. */
        selectable?: boolean;
        /** The selected row index, for the highlight (-1 for none). */
        selected?: number;
        /** What an empty result means here, e.g. "This table has no rows". */
        emptyText?: string;
    }>(),
    { loading: false, rowOffset: 0, sort: null, sortable: false, filterable: false, filters: () => [], selectable: false, selected: -1, emptyText: 'No rows' },
);

const emit = defineEmits<{
    sort: [column: string];
    filter: [filters: Filter[]];
    rowClick: [index: number];
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

/** Column widths the user dragged, by name; the rest size to content (capped). */
const widths = ref<Record<string, number>>({});
const menuEl = ref<HTMLElement | null>(null);
const tableEl = ref<HTMLElement | null>(null);

function startResize(event: PointerEvent, name: string): void {
    const handle = event.currentTarget as HTMLElement;
    const th = handle.parentElement as HTMLElement;
    const startX = event.clientX;
    const startWidth = th.getBoundingClientRect().width;
    handle.setPointerCapture(event.pointerId);

    const move = (e: PointerEvent) => {
        widths.value = { ...widths.value, [name]: Math.max(60, Math.round(startWidth + e.clientX - startX)) };
    };
    const up = () => {
        handle.removeEventListener('pointermove', move);
        handle.removeEventListener('pointerup', up);
    };

    handle.addEventListener('pointermove', move);
    handle.addEventListener('pointerup', up);
    event.preventDefault();
}

function resetWidth(name: string): void {
    const next = { ...widths.value };
    delete next[name];
    widths.value = next;
}

/** A CSSOM binding (allowed by the CSP), only for columns the user resized. */
function widthStyle(name: string): Record<string, string> | undefined {
    const width = widths.value[name];

    return width === undefined ? undefined : { width: `${width}px`, minWidth: `${width}px`, maxWidth: `${width}px` };
}

// The columns menu closes on a click anywhere else.
function onDocumentPointer(event: PointerEvent): void {
    if (menuEl.value && !menuEl.value.contains(event.target as Node)) {
        showColumns.value = false;
    }
}

watch(showColumns, (open) => {
    if (open) {
        document.addEventListener('pointerdown', onDocumentPointer, true);
    } else {
        document.removeEventListener('pointerdown', onDocumentPointer, true);
    }
});
onBeforeUnmount(() => document.removeEventListener('pointerdown', onDocumentPointer, true));

/** Arrow keys move between selectable rows; Enter or Space picks one. */
function onRowKeydown(event: KeyboardEvent, index: number): void {
    if (event.target !== event.currentTarget) {
        return; // A button inside a cell (expand JSON) keeps its keys.
    }

    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        const rows = tableEl.value?.querySelectorAll<HTMLElement>('tbody tr');
        rows?.[index + (event.key === 'ArrowDown' ? 1 : -1)]?.focus();
        event.preventDefault();
    } else if (event.key === 'Enter' || event.key === ' ') {
        emit('rowClick', index);
        event.preventDefault();
    }
}

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
        <div class="dbx-toolbar">
            <slot name="toolbar" />
            <div class="ml-auto flex items-center gap-1.5">
                <button
                    v-if="filterable"
                    type="button"
                    class="dbx-btn"
                    :class="showFilters || filters.length ? 'dbx-btn-pressed' : ''"
                    :aria-expanded="showFilters"
                    aria-controls="dbx-grid-filters"
                    @click="showFilters = !showFilters"
                >
                    <BaseIcon name="filter" />Filter<span v-if="filters.length" class="rounded-full bg-accent-solid px-1.5 text-[11px] leading-4 text-on-accent tabular-nums"><span class="sr-only">active filters: </span>{{ filters.length }}</span>
                </button>
                <div ref="menuEl" class="relative">
                    <button type="button" class="dbx-btn" :class="hidden.size ? 'dbx-btn-pressed' : ''" :aria-expanded="showColumns" @click="showColumns = !showColumns">
                        <BaseIcon name="columns" />Columns<span v-if="hidden.size" class="text-xs tabular-nums">{{ columns.length - hidden.size }}/{{ columns.length }}</span>
                    </button>
                    <Transition name="dbx-pop">
                        <div v-if="showColumns" class="dbx-menu right-0 max-h-80 w-64" @keydown.esc="showColumns = false">
                            <fieldset>
                                <legend class="sr-only">Visible columns</legend>
                                <div class="flex items-center justify-between px-2 pt-1 pb-1.5 text-xs font-medium text-fg-muted">
                                    <span aria-hidden="true">Visible columns</span>
                                    <button v-if="hidden.size" type="button" class="dbx-link text-xs" @click="hidden = new Set()">Show all</button>
                                </div>
                                <label v-for="column in columns" :key="column.name" class="dbx-menu-item">
                                    <input type="checkbox" :checked="!hidden.has(column.name)" @change="toggleColumn(column.name)">
                                    <span class="truncate">{{ column.name }}</span>
                                    <span class="ml-auto shrink-0 font-mono text-[11px] text-fg-subtle">{{ column.type }}</span>
                                </label>
                            </fieldset>
                        </div>
                    </Transition>
                </div>
            </div>
        </div>

        <Transition name="dbx-reveal">
            <form
                v-if="filterable && showFilters"
                id="dbx-grid-filters"
                class="shrink-0 space-y-1.5 border-b border-line bg-sunken px-3 py-2.5"
                aria-label="Filters"
                @submit.prevent="applyFilters"
            >
                <p v-if="draft.length === 0" class="text-sm text-fg-muted">No filters yet. Rows must match every filter you add.</p>
                <div v-for="(filter, index) in draft" :key="index" class="flex flex-wrap items-center gap-1.5">
                    <span class="w-10 text-right text-xs font-medium text-fg-subtle">{{ index === 0 ? 'where' : 'and' }}</span>
                    <label class="sr-only" :for="`dbx-filter-column-${index}`">Column</label>
                    <select :id="`dbx-filter-column-${index}`" v-model="filter.column" class="dbx-select font-mono text-xs">
                        <option v-for="column in filterColumns" :key="column.name" :value="column.name">{{ column.name }}</option>
                    </select>
                    <label class="sr-only" :for="`dbx-filter-op-${index}`">Operator</label>
                    <select :id="`dbx-filter-op-${index}`" v-model="filter.op" class="dbx-select">
                        <option v-for="op in FILTER_OPERATORS" :key="op" :value="op">{{ OPERATOR_LABELS[op] }}</option>
                    </select>
                    <template v-if="needsValue(filter.op)">
                        <label class="sr-only" :for="`dbx-filter-value-${index}`">Value</label>
                        <input
                            :id="`dbx-filter-value-${index}`"
                            v-model="filter.value"
                            type="text"
                            class="dbx-input w-60 font-mono text-xs"
                            :placeholder="filter.op === 'in' ? 'a, b, c' : filter.op.endsWith('like') ? '%text%' : 'value'"
                        >
                    </template>
                    <button type="button" class="dbx-btn dbx-btn-ghost dbx-btn-icon" :aria-label="`Remove filter ${index + 1}`" title="Remove filter" @click="draft.splice(index, 1)"><BaseIcon name="x" /></button>
                </div>
                <div class="flex items-center gap-1.5 pt-1" :class="draft.length ? 'pl-11.5' : ''">
                    <button type="button" class="dbx-btn" :disabled="filterColumns.length === 0" @click="addFilter"><BaseIcon name="plus" />Add filter</button>
                    <button type="submit" class="dbx-btn dbx-btn-primary">Apply</button>
                    <button v-if="filters.length || draft.length" type="button" class="dbx-btn dbx-btn-ghost" @click="clearFilters">Clear all</button>
                </div>
            </form>
        </Transition>

        <div class="relative min-h-0 flex-1 overflow-auto bg-panel" :aria-busy="loading">
            <SkeletonRows v-if="loading && columns.length === 0" :rows="10" :columns="5" />
            <table v-else ref="tableEl" class="min-w-full border-separate border-spacing-0">
                <thead class="sticky top-0 z-10">
                    <tr>
                        <th scope="col" class="sticky left-0 z-20 w-px border-r border-b border-line bg-sunken px-2 py-1 text-right align-bottom text-[11px] font-normal text-fg-subtle">
                            <span class="sr-only">Row number</span><span aria-hidden="true">#</span>
                        </th>
                        <th
                            v-for="{ column, index } in visible"
                            :key="index"
                            scope="col"
                            :aria-sort="sortable ? ariaSort(column) : undefined"
                            class="relative border-r border-b border-line bg-sunken px-2.5 py-1 text-left align-bottom whitespace-nowrap"
                            :class="isNumericType(column.type) ? 'text-right' : ''"
                            :style="widthStyle(column.name)"
                        >
                            <button
                                v-if="sortable && !column.masked"
                                type="button"
                                class="inline-flex max-w-full cursor-default items-center gap-1 rounded-sm text-xs font-semibold text-fg transition-colors duration-100 hover:text-accent"
                                :title="`Sort by ${column.name}`"
                                @click="emit('sort', column.name)"
                            >
                                <span class="truncate">{{ column.name }}</span>
                                <BaseIcon v-if="sort?.column === column.name" :name="sort.direction === 'asc' ? 'arrow-up' : 'arrow-down'" :size="12" class="text-accent" />
                            </button>
                            <span v-else class="inline-flex max-w-full items-center gap-1.5 text-xs font-semibold text-fg">
                                <span class="truncate">{{ column.name }}</span>
                                <span v-if="column.masked" class="inline-flex items-center gap-0.5 text-[10px] font-medium tracking-wide text-fg-subtle uppercase" title="Masked: values are hidden, and the column can't be sorted or filtered"><BaseIcon name="lock" :size="10" />masked</span>
                            </span>
                            <span class="block font-mono text-[11px] leading-4 font-normal text-fg-subtle">{{ column.type }}</span>
                            <span
                                class="absolute top-0 -right-1 z-10 h-full w-2 cursor-col-resize after:absolute after:inset-y-1 after:left-1/2 after:w-px after:transition-colors hover:after:bg-focus"
                                aria-hidden="true"
                                title="Drag to resize, double-click to reset"
                                @pointerdown="startResize($event, column.name)"
                                @dblclick="resetWidth(column.name)"
                            />
                        </th>
                        <th aria-hidden="true" class="w-full border-b border-line bg-sunken" />
                    </tr>
                </thead>
                <tbody class="font-mono text-data">
                    <tr
                        v-for="(row, r) in rows"
                        :key="rowOffset + r"
                        :class="[
                            selectable ? 'outline-offset-[-2px]' : '',
                            selectable && r === selected ? 'bg-accent-soft text-accent-soft-fg' : 'bg-panel hover:bg-hover',
                        ]"
                        :aria-selected="selectable ? r === selected : undefined"
                        :tabindex="selectable ? 0 : undefined"
                        @click="selectable && emit('rowClick', r)"
                        @keydown="selectable && onRowKeydown($event, r)"
                    >
                        <td
                            class="sticky left-0 border-r border-b border-line-subtle bg-inherit px-2 py-1 text-right align-top font-sans text-[11px] tabular-nums"
                            :class="selectable && r === selected ? 'font-semibold' : 'text-fg-subtle'"
                        >
                            {{ rowOffset + r + 1 }}
                        </td>
                        <td
                            v-for="{ column, index } in visible"
                            :key="index"
                            class="border-r border-b border-line-subtle px-2.5 py-1 align-top whitespace-nowrap"
                            :class="isNumericType(column.type) ? 'text-right tabular-nums' : ''"
                            :style="widthStyle(column.name)"
                        >
                            <div class="overflow-hidden text-ellipsis" :class="widths[column.name] === undefined ? 'max-w-md' : ''">
                                <GridCell :value="row[index] ?? null" :column="column" />
                            </div>
                        </td>
                        <td aria-hidden="true" class="border-b border-line-subtle" />
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!loading && rows.length === 0 && columns.length > 0" :title="filters.length ? 'No rows match these filters' : emptyText">
                <template v-if="filters.length">Change or clear the filters to see more rows.</template>
                <template v-if="filters.length" #actions>
                    <button type="button" class="dbx-btn" @click="clearFilters"><BaseIcon name="x" />Clear filters</button>
                </template>
            </EmptyState>
            <Transition name="dbx-fade">
                <div v-if="loading && columns.length > 0" class="pointer-events-none absolute inset-0 flex items-start justify-center bg-panel/40 pt-12">
                    <span class="flex items-center gap-2 rounded-md border border-line bg-panel px-3 py-1.5 text-sm text-fg-muted shadow-pop" role="status"><span class="dbx-spinner" aria-hidden="true" />Loading…</span>
                </div>
            </Transition>
        </div>
        <slot name="footer" />
    </div>
</template>
