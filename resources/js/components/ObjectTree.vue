<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { RouteLocationRaw } from 'vue-router';
import { api, describeError, segment } from '../api';
import * as catalog from '../composables/catalog';
import type { DbObject, Extension, ObjectType, Schema } from '../types';

/**
 * Schemas, then object groups by type, loaded per schema on first expand.
 * Keyboard: a WAI-ARIA tree with one tab stop; arrows move and expand,
 * Home/End jump, Enter opens.
 */
const props = defineProps<{ label: string }>();

const route = useRoute();
const router = useRouter();

interface Group {
    key: string;
    title: string;
    types: ObjectType[];
}

const GROUPS: Group[] = [
    { key: 'tables', title: 'Tables', types: ['table', 'foreign_table'] },
    { key: 'partitioned', title: 'Partitioned tables', types: ['partitioned_table'] },
    { key: 'views', title: 'Views', types: ['view'] },
    { key: 'matviews', title: 'Materialized views', types: ['materialized_view'] },
    { key: 'sequences', title: 'Sequences', types: ['sequence'] },
    { key: 'functions', title: 'Functions', types: ['function', 'procedure'] },
    { key: 'types', title: 'Types', types: ['enum', 'domain', 'composite_type'] },
];

interface Node {
    key: string;
    level: number;
    text: string;
    hint?: string;
    kind: 'schema' | 'group' | 'object' | 'extension' | 'message';
    expandable: boolean;
    expanded: boolean;
    selected: boolean;
    to?: RouteLocationRaw;
    /** For expandable nodes: what to toggle. */
    toggle?: string;
    /** Parent node key, for ArrowLeft. */
    parent?: string;
}

const schemas = ref<Schema[]>([]);
const extensions = ref<Extension[]>([]);
const objects = ref<Record<string, DbObject[] | 'loading' | { error: string }>>({});
const expanded = ref(new Set<string>());
const error = ref<string | null>(null);
const focusedKey = ref<string | null>(null);
const treeEl = ref<HTMLElement | null>(null);

const query = ref('');
const results = ref<DbObject[] | null>(null);
const searching = ref(false);
const searchError = ref<string | null>(null);
let searchTimer: ReturnType<typeof setTimeout> | undefined;
let searchSequence = 0;

function objectRoute(object: DbObject): RouteLocationRaw {
    return {
        name: 'object',
        params: { label: props.label, schema: object.schema, name: object.signature ?? object.name },
        query: object.signature !== null ? { fn: '1' } : {},
    };
}

function isCurrent(object: DbObject): boolean {
    return route.name === 'object'
        && route.params.schema === object.schema
        && route.params.name === (object.signature ?? object.name)
        && (route.query.fn === '1') === (object.signature !== null);
}

function typeHint(object: DbObject): string | undefined {
    if (object.parent) {
        return 'partition';
    }

    return { foreign_table: 'foreign', procedure: 'procedure', enum: 'enum', domain: 'domain', composite_type: 'composite' }[object.type as string];
}

const nodes = computed<Node[]>(() => {
    const list: Node[] = [];

    for (const schema of schemas.value) {
        const schemaKey = `s:${schema.name}`;
        const open = expanded.value.has(schemaKey);
        list.push({ key: schemaKey, level: 1, text: schema.name, kind: 'schema', expandable: true, expanded: open, selected: false, toggle: schemaKey });

        if (!open) {
            continue;
        }

        const loaded = objects.value[schema.name];

        if (loaded === undefined || loaded === 'loading') {
            list.push({ key: `${schemaKey}:loading`, level: 2, text: 'Loading…', kind: 'message', expandable: false, expanded: false, selected: false, parent: schemaKey });
            continue;
        }

        if (!Array.isArray(loaded)) {
            list.push({ key: `${schemaKey}:error`, level: 2, text: loaded.error, kind: 'message', expandable: false, expanded: false, selected: false, parent: schemaKey });
            continue;
        }

        const groups: { key: string; title: string; items: Node[] }[] = GROUPS.map((group) => ({
            key: group.key,
            title: group.title,
            items: loaded
                .filter((object) => group.types.includes(object.type))
                .map((object) => ({
                    key: `o:${schema.name}:${object.type}:${object.signature ?? object.name}`,
                    level: 3,
                    text: object.signature ?? object.name,
                    hint: typeHint(object),
                    kind: 'object' as const,
                    expandable: false,
                    expanded: false,
                    selected: isCurrent(object),
                    to: objectRoute(object),
                })),
        }));

        groups.push({
            key: 'extensions',
            title: 'Extensions',
            items: extensions.value
                .filter((extension) => extension.schema === schema.name)
                .map((extension) => ({
                    key: `e:${extension.name}`,
                    level: 3,
                    text: extension.name,
                    hint: extension.version,
                    kind: 'extension' as const,
                    expandable: false,
                    expanded: false,
                    selected: false,
                })),
        });

        const visibleGroups = groups.filter((group) => group.items.length > 0);

        if (visibleGroups.length === 0) {
            list.push({ key: `${schemaKey}:empty`, level: 2, text: 'No objects you can access', kind: 'message', expandable: false, expanded: false, selected: false, parent: schemaKey });
        }

        for (const group of visibleGroups) {
            const groupKey = `g:${schema.name}:${group.key}`;
            const groupOpen = expanded.value.has(groupKey);
            list.push({ key: groupKey, level: 2, text: group.title, hint: String(group.items.length), kind: 'group', expandable: true, expanded: groupOpen, selected: false, toggle: groupKey, parent: schemaKey });

            if (groupOpen) {
                list.push(...group.items.map((item) => ({ ...item, parent: groupKey })));
            }
        }
    }

    return list;
});

/** The single tab stop: the focused node, else the selected one, else the first. */
const tabStop = computed(() => {
    const keys = nodes.value.filter((node) => node.kind !== 'message').map((node) => node.key);

    if (focusedKey.value !== null && keys.includes(focusedKey.value)) {
        return focusedKey.value;
    }

    return nodes.value.find((node) => node.selected)?.key ?? keys[0] ?? null;
});

async function loadSchema(schema: string): Promise<void> {
    if (Array.isArray(objects.value[schema]) || objects.value[schema] === 'loading') {
        return;
    }

    objects.value[schema] = 'loading';

    try {
        objects.value[schema] = await catalog.objects(props.label, schema);
    } catch (e) {
        objects.value[schema] = { error: describeError(e) };
    }
}

function expand(key: string, open = true): void {
    const next = new Set(expanded.value);

    if (open) {
        next.add(key);
    } else {
        next.delete(key);
    }

    if (open && key.startsWith('s:')) {
        // Tables are what people open most: show them right away.
        next.add(`g:${key.slice(2)}:tables`);
        void loadSchema(key.slice(2));
    }

    expanded.value = next;
}

function activate(node: Node): void {
    focusedKey.value = node.key;

    if (node.toggle) {
        expand(node.toggle, !node.expanded);
    } else if (node.to) {
        void router.push(node.to);
    }
}

function focusNode(key: string | undefined): void {
    if (key === undefined) {
        return;
    }

    focusedKey.value = key;
    void nextTick(() => {
        treeEl.value?.querySelector<HTMLElement>(`[data-key="${CSS.escape(key)}"]`)?.focus();
    });
}

function onKeydown(event: KeyboardEvent, node: Node): void {
    const list = nodes.value.filter((item) => item.kind !== 'message');
    const index = list.findIndex((item) => item.key === node.key);

    switch (event.key) {
        case 'ArrowDown':
            focusNode(list[index + 1]?.key);
            break;
        case 'ArrowUp':
            focusNode(list[index - 1]?.key);
            break;
        case 'Home':
            focusNode(list[0]?.key);
            break;
        case 'End':
            focusNode(list[list.length - 1]?.key);
            break;
        case 'ArrowRight':
            if (node.expandable && !node.expanded && node.toggle) {
                expand(node.toggle);
            } else if (node.expandable && list[index + 1]?.parent === node.key) {
                focusNode(list[index + 1]?.key);
            }
            break;
        case 'ArrowLeft':
            if (node.expandable && node.expanded && node.toggle) {
                expand(node.toggle, false);
            } else {
                focusNode(node.parent);
            }
            break;
        case 'Enter':
        case ' ':
            activate(node);
            break;
        default:
            return;
    }

    event.preventDefault();
}

function onSearch(): void {
    clearTimeout(searchTimer);
    const term = query.value.trim();

    if (term === '') {
        results.value = null;
        searchError.value = null;
        searching.value = false;
        return;
    }

    searching.value = true;
    searchTimer = setTimeout(async () => {
        const id = ++searchSequence;

        try {
            const found = await api<{ data: DbObject[] }>(`${segment(props.label)}/search`, { query: { q: term.slice(0, 100) } });
            if (id === searchSequence) {
                results.value = found.data;
                searchError.value = null;
            }
        } catch (e) {
            if (id === searchSequence) {
                searchError.value = describeError(e);
            }
        } finally {
            if (id === searchSequence) {
                searching.value = false;
            }
        }
    }, 250);
}

async function load(): Promise<void> {
    error.value = null;
    schemas.value = [];
    extensions.value = [];
    objects.value = {};
    expanded.value = new Set();

    try {
        const [list, extensionList] = await Promise.all([catalog.schemas(props.label), catalog.extensions(props.label).catch(() => [])]);
        schemas.value = list;
        extensions.value = extensionList;
        revealCurrent();

        // A single schema (often just public) is opened for you.
        if (list.length === 1 && list[0]) {
            expand(`s:${list[0].name}`);
        }
    } catch (e) {
        error.value = describeError(e, 'Could not load schemas.');
    }
}

/** Expand the schema and group of the object the route shows. */
function revealCurrent(): void {
    const schema = route.params.schema;

    if (route.name !== 'object' || typeof schema !== 'string' || !schemas.value.some((s) => s.name === schema)) {
        return;
    }

    const next = new Set(expanded.value);
    next.add(`s:${schema}`);
    expanded.value = next;

    void loadSchema(schema).then(() => {
        const loaded = objects.value[schema];
        const current = Array.isArray(loaded) ? loaded.find(isCurrent) : undefined;
        const group = current ? GROUPS.find((g) => g.types.includes(current.type)) : undefined;

        if (group) {
            const opened = new Set(expanded.value);
            opened.add(`g:${schema}:${group.key}`);
            expanded.value = opened;
        }
    });
}

watch(() => props.label, load, { immediate: true });
watch(() => route.fullPath, revealCurrent);

onBeforeUnmount(() => clearTimeout(searchTimer));

const levelPadding = ['pl-2', 'pl-2', 'pl-5', 'pl-9'];
</script>

<template>
    <nav class="flex min-h-0 flex-1 flex-col" aria-label="Database objects">
        <div class="border-b border-zinc-200 p-2 dark:border-zinc-800">
            <label for="dbx-object-search" class="sr-only">Search objects</label>
            <input
                id="dbx-object-search"
                v-model="query"
                type="search"
                placeholder="Search objects…"
                autocomplete="off"
                maxlength="100"
                class="dbx-input w-full"
                @input="onSearch"
                @keydown.esc="query = ''; onSearch()"
            >
        </div>

        <div class="min-h-0 flex-1 overflow-auto py-1 text-sm">
            <template v-if="query.trim() !== ''">
                <p v-if="searching && results === null" class="px-3 py-2 text-zinc-500">Searching…</p>
                <p v-else-if="searchError" role="alert" class="px-3 py-2 text-red-700 dark:text-red-300">{{ searchError }}</p>
                <p v-else-if="results && results.length === 0" class="px-3 py-2 text-zinc-500">No objects match.</p>
                <ul v-else-if="results" aria-label="Search results">
                    <li v-for="object in results" :key="`${object.schema}.${object.type}.${object.signature ?? object.name}`">
                        <router-link
                            :to="objectRoute(object)"
                            class="flex items-baseline gap-2 px-3 py-1 transition-colors duration-100 hover:bg-zinc-100 focus-visible:bg-sky-100 focus-visible:text-sky-900 focus-visible:outline-none dark:hover:bg-zinc-800 dark:focus-visible:bg-sky-950 dark:focus-visible:text-sky-100"
                        >
                            <span class="truncate">{{ object.signature ?? object.name }}</span>
                            <span class="ml-auto shrink-0 text-xs text-zinc-500 dark:text-zinc-400">{{ object.schema }} · {{ object.type.replace('_', ' ') }}</span>
                        </router-link>
                    </li>
                </ul>
            </template>

            <template v-else>
                <p v-if="error" role="alert" class="px-3 py-2 text-red-700 dark:text-red-300">{{ error }}</p>
                <p v-else-if="schemas.length === 0" class="px-3 py-2 text-zinc-500">Loading…</p>
                <ul v-else ref="treeEl" role="tree" aria-label="Schemas">
                    <li
                        v-for="node in nodes"
                        :key="node.key"
                        :data-key="node.key"
                        :role="node.kind === 'message' ? 'none' : 'treeitem'"
                        :aria-level="node.level"
                        :aria-expanded="node.expandable ? node.expanded : undefined"
                        :aria-selected="node.kind === 'object' ? node.selected : undefined"
                        :tabindex="node.kind === 'message' ? undefined : node.key === tabStop ? 0 : -1"
                        class="flex cursor-default items-center gap-1.5 py-1 pr-2 transition-colors duration-100 outline-none select-none"
                        :class="[
                            levelPadding[node.level],
                            node.kind === 'message' ? 'text-zinc-500 italic dark:text-zinc-400' : 'hover:bg-zinc-100 focus-visible:ring-2 focus-visible:ring-sky-500 focus-visible:ring-inset dark:hover:bg-zinc-800',
                            node.selected ? 'bg-sky-100 text-sky-900 dark:bg-sky-950 dark:text-sky-100' : '',
                            node.kind === 'object' ? 'cursor-pointer' : '',
                        ]"
                        @click="node.kind !== 'message' && activate(node)"
                        @focus="node.kind !== 'message' && (focusedKey = node.key)"
                        @keydown="onKeydown($event, node)"
                    >
                        <span aria-hidden="true" class="w-3 shrink-0 text-xs text-zinc-500 transition-transform duration-150 ease-pop motion-reduce:transition-none dark:text-zinc-400" :class="node.expanded ? 'rotate-90' : ''">{{ node.expandable ? '▸' : '' }}</span>
                        <span class="truncate" :class="node.kind === 'schema' ? 'font-medium' : node.kind === 'group' ? 'text-zinc-600 dark:text-zinc-300' : ''">{{ node.text }}</span>
                        <span v-if="node.hint" class="ml-auto shrink-0 text-xs text-zinc-500 dark:text-zinc-400">{{ node.hint }}</span>
                    </li>
                </ul>
            </template>
        </div>
    </nav>
</template>
